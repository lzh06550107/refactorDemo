# R8B — WeChat OpenPlatform Component Trust Chain Design

Status: approved architecture, written specification pending implementation  
Base: R8A `e7dd986ccad055005db6372168b8571b78131385`  
Target branch: `refactor/openplatform-component-trust-r8b`

## 1. Purpose

R8B implements the upstream trust chain required by R8A component-mode MiniApp login:

```text
ComponentPlatform
    -> authenticated component_verify_ticket
    -> ComponentVerifyTicket
    -> component_access_token
    -> R8A ComponentAccessTokenProvider
```

R8B does **not** implement authorizer authorization or authorizer access tokens. Those remain R8C.

The security goal is that no component access token can be produced unless it derives from an authenticated, correctly scoped, non-ambiguous Component Platform ticket chain.

## 2. Verified R20 baseline

The R20 baseline establishes these facts:

1. OpenPlatform credentials are platform-level configuration, not per-business-account credentials.
2. `component_verify_ticket` is the upstream input to `component_access_token`.
3. The historical ticket handler reads the encrypted callback, checks an outer AppId, decrypts the payload, parses `component_verify_ticket`, and saves it.
4. The historical ticket path does not explicitly verify `msg_signature` before trusting the decrypted payload.
5. Historical `aes_decode()` is decryption logic, not protocol authentication; its optional AppId validation path is not a reliable trust boundary.
6. Historical component access-token caching uses a fixed key equivalent to `account_component_assesstoken`, not a key scoped by `component_appid`.
7. Component tokens and authorizer tokens are separate credential lifecycles.

R8B preserves the externally visible credential chain but fixes the trust and cache-scoping weaknesses.

## 3. Compatibility classification

### MUST_COMPAT

- WeChat `component_verify_ticket -> component_access_token` semantics.
- WeChat encrypted component callback protocol.
- Component access-token request to `/cgi-bin/component/api_component_token`.
- Provider `expires_in` as the source of token expiry.
- R8A component-mode `jscode2session` receives a component AppId and component access token.

### INTENTIONAL_SECURITY_FIX

- Verify `msg_signature` before accepting decrypted ticket content.
- Enforce a bounded timestamp freshness window.
- Add replay/idempotency protection.
- Validate the decrypted protocol receiver AppId and the inner payload AppId.
- Scope all ticket/token state by explicit `componentPlatformId` / `component_app_id`.
- Remove the historical global component-token cache key.
- Encrypt verify tickets and component access tokens at rest.
- Keep AppSecret, verify token, and EncodingAESKey out of database plaintext columns.

### DEFERRED_TO_R8C

- `pre_auth_code`.
- authorization callback handling.
- `authorization_code` exchange.
- `authorizer_refresh_token`.
- `authorizer_access_token`.
- creation/binding of public-account or MiniApp Accounts after authorization.
- authorization lifecycle events such as unauthorized/updateauthorized.

## 4. Ownership and isolation model

`ComponentPlatform` is an operator/platform-scoped integration resource, **not a Tenant-owned resource**.

This corrects an earlier provisional design that placed `tenant_id` on the component platform. The R20 platform configuration is shared infrastructure, and one Component Platform can authorize Accounts belonging to multiple tenants.

Tenant isolation remains enforced at the R8A binding boundary:

```text
Tenant
  -> Account
      -> miniapp_provider_accounts.account_id
      -> miniapp_provider_accounts.component_platform_id
          -> ComponentPlatform
```

Rules:

- `component_app_id` is globally unique among enabled Component Platforms in this deployment.
- An R8A provider Account may reference only an existing enabled Component Platform.
- OpenPlatform repositories never infer Tenant from a component token.
- R8B exposes only the public Application API required to obtain a component token; R8A must not read OpenPlatform repositories directly.

## 5. Package structure

```text
app/openplatform/
├── domain/
│   ├── ComponentPlatform.php
│   ├── ComponentVerifyTicket.php
│   ├── ComponentAccessToken.php
│   ├── ComponentTicketEnvelope.php
│   └── ComponentTokenRefreshLease.php
├── application/
│   ├── ComponentTicketService.php
│   └── ComponentAccessTokenService.php
├── contract/
│   ├── ComponentPlatformRepository.php
│   ├── ComponentTicketRepository.php
│   ├── ComponentTokenRepository.php
│   ├── ComponentRefreshLeaseRepository.php
│   ├── ComponentCredentialProvider.php
│   ├── OpenPlatformSecretCipher.php
│   └── OpenPlatformHttpTransport.php
├── security/
│   ├── WechatComponentSignatureVerifier.php
│   ├── WechatComponentEnvelopeParser.php
│   └── WechatComponentMessageDecryptor.php
└── infrastructure/
    ├── WechatComponentTokenClient.php
    ├── NativeOpenPlatformHttpTransport.php
    ├── OpenSslOpenPlatformSecretCipher.php
    ├── ThinkPhpComponentPlatformRepository.php
    ├── ThinkPhpComponentTicketRepository.php
    ├── ThinkPhpComponentTokenRepository.php
    └── ThinkPhpComponentRefreshLeaseRepository.php

app/miniapp/infrastructure/
└── OpenPlatformComponentAccessTokenProvider.php
```

The MiniApp adapter implements the existing R8A contract:

```php
ComponentAccessTokenProvider::forPlatform(string $componentPlatformId)
```

and delegates to the public R8B `ComponentAccessTokenService`. It does not access R8B repositories.

## 6. ComponentPlatform

Required domain fields:

```text
id
componentAppId
appSecretRef
verifyTokenRef
encodingAesKeyRef
enabled
```

No raw secret is carried by the domain object.

Secret references are resolved through `ComponentCredentialProvider`. The provider returns secret values only at the point of cryptographic or provider-API use.

The following values must never appear in `toArray()`, logs, audit metadata, exception messages, or database plaintext configuration columns:

```text
component appsecret
verify token
encoding AES key
component_verify_ticket
component_access_token
```

## 7. Ticket ingress endpoint

The external callback endpoint is platform-specific:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/ticket
```

Using an explicit platform identifier avoids treating unauthenticated outer XML as the authority for platform selection.

The endpoint is a direct external integration route and does not depend on Admin or Member login state.

Successful first delivery and semantic duplicate delivery both return:

```text
HTTP 200
Content-Type: text/plain
success
```

## 8. Ticket ingress processing order

The exact order is security-sensitive:

```text
1. resolve ComponentPlatform from route id
2. reject disabled/missing platform
3. enforce raw-body size limit
4. hardened outer-envelope parse: extract Encrypt only
5. parse timestamp and nonce from query
6. enforce freshness window
7. resolve verify token from secret provider
8. verify msg_signature over token/timestamp/nonce/Encrypt
9. compute replay key + encrypted-payload hash
10. decrypt WeChat AES envelope
11. validate framed receiver id == componentAppId
12. hardened parse of decrypted inner XML
13. validate inner AppId == componentAppId
14. require InfoType == component_verify_ticket
15. extract ComponentVerifyTicket
16. encrypt ticket for at-rest storage
17. short transaction: inbox idempotency + latest-ticket compare/update + audit write
18. return success
```

No decrypted business field is trusted before steps 8–13 complete.

## 9. Hardened XML handling

The outer parser exists only because WeChat `msg_signature` includes the encrypted payload. It is **not** a business XML parser.

Requirements:

- raw body maximum: 128 KiB;
- reject `DOCTYPE` and entity declarations;
- no network/entity expansion;
- outer parser extracts only `Encrypt` and may read outer AppId only for diagnostics;
- outer AppId is never an authentication decision;
- inner parser is invoked only after signature verification and AES decryption;
- inner parser extracts only the fields required by the ticket event.

## 10. Signature verification

For encrypted component callbacks, R8B preserves WeChat protocol semantics:

```text
sort lexicographically:
  verifyToken
  timestamp
  nonce
  encryptedPayload

sha1(concatenated values)
```

Comparison uses constant-time `hash_equals()`.

Default freshness window:

```text
±300 seconds
```

The freshness window is constructor/configuration controlled for deterministic tests but defaults to 300 seconds in production.

Invalid signature or stale timestamp fails before decrypting the ticket payload.

## 11. Replay and semantic idempotency

Replay identity is deliberately independent from the signature value:

```text
replay_key = SHA-256(
  componentPlatformId + "\n" + timestamp + "\n" + nonce
)

payload_hash = SHA-256(encryptedPayload)
```

Database uniqueness:

```text
UNIQUE(component_platform_id, replay_key)
```

Semantics:

- unseen replay key -> continue and persist;
- same replay key + same payload hash -> semantic duplicate, return `success`, no second ticket mutation;
- same replay key + different payload hash -> `CONFLICT / 409`;
- concurrent identical callbacks converge on one persisted inbox row.

The final uniqueness decision occurs inside the ticket write transaction. A read-only precheck may optimize exact duplicates but is never the authoritative gate.

## 12. WeChat component AES decryption

`WechatComponentMessageDecryptor` implements the protocol framing explicitly rather than copying the historical R20 helper.

Requirements:

- EncodingAESKey resolves to exactly 32 bytes after WeChat Base64 normalization;
- AES-256-CBC;
- IV is the first 16 bytes of the AES key, per WeChat protocol;
- PKCS#7 unpadding is validated, not blindly truncated;
- decrypted frame is parsed as:

```text
16 random bytes
4-byte network-order message length
XML payload
receiver id / AppId
```

- frame length must be internally consistent;
- framed receiver id must equal current `componentAppId` using constant-time comparison;
- malformed padding, framing, or AppId fails closed.

The decryptor never logs plaintext or secret material.

## 13. Latest ticket semantics

R8B stores only the current usable verify ticket, encrypted at rest, plus non-secret metadata.

`ComponentVerifyTicket` metadata:

```text
componentPlatformId
ticketHash           SHA-256 of plaintext ticket
sourceTimestamp      signed callback timestamp
receivedAt
version
```

Replacement rule under row lock:

- newer `sourceTimestamp` replaces the current encrypted ticket and increments `version`;
- same `sourceTimestamp` + same `ticketHash` is idempotent;
- same `sourceTimestamp` + different `ticketHash` is `CONFLICT`;
- older authenticated delivery must not overwrite a newer stored ticket.

R8B does not invent a local ticket expiry interval that is not established by the R20/WeChat contract. The latest authenticated ticket remains the source used for component-token refresh until replaced or rejected by the provider.

## 14. At-rest encryption

`OpenPlatformSecretCipher` is an R8B port intended to be reusable by R8C authorizer credentials.

Production implementation uses AES-256-GCM:

```text
32-byte encryption key
12-byte random IV
16-byte authentication tag
explicit key version
```

Stored protected values contain ciphertext and key version only. Decrypt/authentication failure fails closed.

Key bytes come from external key configuration/secret management and are never persisted in the OpenPlatform tables.

## 15. Component access-token API

Provider endpoint:

```text
POST https://api.weixin.qq.com/cgi-bin/component/api_component_token
```

Request body semantics:

```json
{
  "component_appid": "<component appid>",
  "component_appsecret": "<resolved secret>",
  "component_verify_ticket": "<decrypted current ticket>"
}
```

Provider response must contain:

```text
component_access_token: non-empty string
expires_in: positive integer
```

The HTTP client/transport must never include request secrets or returned tokens in thrown exception messages.

Default provider timeout is bounded; production implementation must not allow an unbounded network wait.

## 16. ComponentAccessTokenService

Public behavior:

```text
forPlatform(componentPlatformId, now)
```

Conceptual flow:

```text
load enabled ComponentPlatform
        ↓
load/decrypt current token
        ↓
valid outside refresh-skew?
   yes -> return
   no
        ↓
try short-lived refresh lease
        ↓
lease acquired?
  no -> reread token
        ├─ still unexpired -> return existing token
        └─ expired -> SERVICE_UNAVAILABLE / 503
  yes
        ↓
double-check token after lease
        ↓
load/decrypt latest verify ticket
        ↓
resolve appsecret
        ↓
network call outside DB transaction
        ↓
validate provider response
        ↓
short compare-and-set transaction
        ↓
store encrypted new token + expiry + version
        ↓
clear lease
        ↓
return token
```

Default refresh skew is 300 seconds. A token remains usable until its actual `expiresAt`; being inside the refresh-skew window makes it eligible for refresh but does not make it expired.

## 17. Refresh lease / singleflight

A database-backed lease prevents refresh storms across multiple PHP processes/hosts.

Lease fields:

```text
componentPlatformId
holderId
leaseExpiresAt
version
```

Default lease duration: 30 seconds.

Rules:

1. lease acquisition occurs in a short transaction;
2. network I/O never runs while holding a database transaction/row lock;
3. winner double-checks the token after obtaining the lease;
4. token replacement requires the same lease holder and expected token version;
5. if the lease expires before write-back, the stale winner may not overwrite a newer token;
6. a crashed process cannot hold the lease forever;
7. release is attempted after success/failure, while lease expiry remains the final recovery mechanism.

If refresh fails:

- an existing token that is still actually unexpired may be returned as a degraded fallback;
- an expired/missing token plus provider failure yields `BAD_GATEWAY / 502`;
- a missing verify ticket yields `SERVICE_UNAVAILABLE / 503`;
- failure never overwrites an existing usable token.

## 18. R8A integration

R8A already defines:

```text
MiniAppProviderAccount.connectionMode = COMPONENT
MiniAppProviderAccount.componentPlatformId
ComponentAccessTokenProvider::forPlatform(componentPlatformId)
```

R8B adds:

```text
app/miniapp/infrastructure/OpenPlatformComponentAccessTokenProvider
```

The adapter:

1. calls R8B `ComponentAccessTokenService`;
2. converts the public R8B token result to R8A `app\miniapp\domain\ComponentAccessToken`;
3. exposes only `componentAppId` and access-token value required by `jscode2session`;
4. never exposes verify ticket or component appsecret to MiniApp code.

## 19. Persistence schema

Migration pair:

```text
database/migrations/20260908_007_openplatform_component_trust_up.sql
database/migrations/20260908_007_openplatform_component_trust_down.sql
```

### 19.1 `component_platforms`

```text
id                         varchar(64) PK
component_app_id           varchar(128) NOT NULL UNIQUE
app_secret_ref             varchar(255) NOT NULL
verify_token_ref           varchar(255) NOT NULL
encoding_aes_key_ref       varchar(255) NOT NULL
enabled                    tinyint(1) NOT NULL
created_at                 datetime(6)
updated_at                 datetime(6)
```

There is intentionally no `tenant_id`.

### 19.2 `component_ticket_inbox`

```text
id                         varchar(64) PK
component_platform_id      varchar(64) NOT NULL FK
replay_key                 char(64) NOT NULL
payload_hash               char(64) NOT NULL
source_timestamp           datetime(6) NOT NULL
received_at                datetime(6) NOT NULL
result                     varchar(32) NOT NULL
UNIQUE(component_platform_id, replay_key)
```

No raw body, decrypted XML, ticket, verify token, or EncodingAESKey is stored here.

### 19.3 `component_verify_tickets`

One current row per platform:

```text
component_platform_id      varchar(64) PK/FK
ticket_ciphertext          text NOT NULL
ticket_key_version         varchar(64) NOT NULL
ticket_hash                char(64) NOT NULL
source_timestamp           datetime(6) NOT NULL
received_at                datetime(6) NOT NULL
version                    bigint unsigned NOT NULL
updated_at                 datetime(6)
```

### 19.4 `component_access_tokens`

One current row per platform:

```text
component_platform_id      varchar(64) PK/FK
token_ciphertext           text NOT NULL
token_key_version          varchar(64) NOT NULL
issued_at                  datetime(6) NOT NULL
expires_at                 datetime(6) NOT NULL
version                    bigint unsigned NOT NULL
updated_at                 datetime(6)
```

### 19.5 `component_token_refresh_leases`

One lease anchor per platform:

```text
component_platform_id      varchar(64) PK/FK
holder_id                  varchar(128) NULL
lease_expires_at           datetime(6) NULL
version                    bigint unsigned NOT NULL
updated_at                 datetime(6)
```

All tables use InnoDB + utf8mb4.

## 20. Transaction boundaries

### Ticket write

All cryptographic verification/decryption occurs before the write transaction.

Final short transaction:

```text
BEGIN
  lock component platform/current ticket as needed
  insert inbox replay key
  resolve duplicate/conflict race
  compare sourceTimestamp/version
  update encrypted latest ticket if newer
  write audit event
COMMIT
```

No secret-provider lookup, XML parsing, AES decryption, or network call runs under this transaction.

### Token refresh

Lease acquisition and token compare-and-set are separate short transactions around an out-of-transaction provider HTTP call.

## 21. Stable errors

R8B extends the common error catalog with:

```text
BAD_GATEWAY
SERVICE_UNAVAILABLE
```

Mappings:

```text
invalid msg_signature                         UNAUTHORIZED / 401
stale/future timestamp outside window         UNAUTHORIZED / 401
same replay key + different payload           CONFLICT / 409
framed receiver id mismatch                   FORBIDDEN / 403
inner AppId mismatch                          FORBIDDEN / 403
InfoType != component_verify_ticket           INVALID_ARGUMENT / 400
malformed encrypted/XML payload               INVALID_ARGUMENT / 400
missing/disabled ComponentPlatform             NOT_FOUND / 404
missing current verify ticket                 SERVICE_UNAVAILABLE / 503
refresh lease busy + no usable token          SERVICE_UNAVAILABLE / 503
provider token endpoint failure/no usable old BAD_GATEWAY / 502
secret/key configuration failure              INTERNAL_ERROR / 500
at-rest authentication/decrypt failure        INTERNAL_ERROR / 500
```

Error messages are generic and never include secrets, ciphertext, raw XML, provider response bodies, or tokens.

## 22. Audit and observability

Critical successful writes emit audit events through the existing `AuditLogger` port.

External ticket actor:

```text
actor_id = external:wechat-openplatform
```

Token-refresh actor:

```text
actor_id = system:openplatform-token-refresh
```

`tenant_id` and `account_id` are null for Component Platform infrastructure operations.

Allowed metadata:

```text
component_platform_id
component_app_id
action outcome
replay/duplicate boolean
ticket version
token version
provider expires_in
```

Forbidden metadata:

```text
verify token
EncodingAESKey
AppSecret
verify ticket
component access token
raw XML
decrypted XML
ciphertext
```

Ticket requests retain request/trace correlation IDs. Internal token refresh may create an internal request/trace context when the R8A provider contract does not carry a request context.

## 23. Security invariants

1. Domain/Application code under `app/openplatform` does not import ThinkPHP facades.
2. No R20 globals (`$_W`, `$_GPC`, `pdo_*`) appear in new Domain/Application code.
3. No OpenPlatform secret is committed in source/tests/docs.
4. No plaintext verify ticket/access token is persisted.
5. No global component-token cache key exists.
6. Platform A ticket/token cannot be read using Platform B id.
7. Signature/freshness are verified before decrypted ticket content is trusted.
8. Provider network failure cannot erase a still-usable token.
9. Concurrent refreshes converge to one effective winner.
10. R8A interacts with R8B only through public Application API / its provider adapter.

## 24. TDD / verification matrix

At minimum R8B must add executable coverage for:

### Ticket security

- valid signature + fresh timestamp -> accepted;
- invalid signature -> 401;
- timestamp older/newer than freshness window -> 401;
- outer `DOCTYPE` / entity payload -> reject;
- malformed AES padding/frame -> reject;
- framed receiver AppId mismatch -> 403;
- inner AppId mismatch -> 403;
- wrong `InfoType` -> 400;
- exact replay -> semantic 200 `success`;
- same replay key + different payload -> 409;
- concurrent identical inbox inserts converge idempotently;
- older signed ticket cannot overwrite newer ticket.

### Token lifecycle

- valid token outside skew -> no provider HTTP call;
- token inside skew -> refresh eligible;
- lease loser with still-valid token -> returns existing token;
- lease loser with expired token -> 503;
- winner double-check avoids redundant refresh;
- provider refresh success -> encrypted new token persisted;
- provider refresh failure + old token valid -> old token preserved/returned;
- provider refresh failure + old token expired -> 502;
- stale lease owner cannot overwrite newer token;
- Platform A and Platform B never share ticket/token/lease state.

### Integration / architecture

- R8A `OpenPlatformComponentAccessTokenProvider` delegates to R8B service;
- `_007` schema constraints/FKs/unique keys;
- ThinkPHP persistence contract verifies platform-scoped queries and row locks/CAS;
- architecture scan rejects ThinkPHP/legacy dependencies from Domain/Application;
- secret scan covers OpenPlatform source/tests/docs;
- unified offline runner grows from the R8A 72-entry baseline without removing prior tests;
- PHPUnit bridge, PHP lint, and multi-app HTTP smoke remain green.

## 25. Migration and rollout

R8B does not automatically import the historical global R20 component platform secret configuration into plaintext database fields.

Migration is explicit:

1. create one `component_platforms` row per configured Component Platform;
2. store only secret references in DB;
3. place AppSecret/verify token/EncodingAESKey in the configured secret provider;
4. R8A component-mode provider accounts are updated to reference the correct `component_platform_id`;
5. the next authenticated WeChat ticket push seeds `component_verify_tickets`;
6. the first token demand after a ticket exists seeds `component_access_tokens`.

Historical global cached component access tokens are not imported because their platform scope is ambiguous.

## 26. Explicit non-goals

R8B does not implement:

- authorizer account binding;
- authorizer refresh/access token;
- `pre_auth_code`;
- public-account or MiniApp authorization callback;
- code upload/review/release;
- payment;
- module message processors;
- Redis or external distributed-cache infrastructure.

The database lease is the R8B concurrency primitive. A future cache may optimize reads but cannot become the source of truth.

## 27. Completion criteria

R8B is complete only when:

1. this spec is implemented through a written implementation plan and RED -> GREEN evidence;
2. migration `_007` exists with the documented isolation constraints;
3. ticket ingress cannot persist a ticket without successful signature/freshness/AES/AppId checks;
4. component token refresh is platform-scoped and lease/singleflight protected;
5. R8A component-mode login uses the R8B production provider adapter;
6. no secret material is logged or persisted in plaintext;
7. the validation branch CI is fully green;
8. `main` is updated only by non-force fast-forward after branch validation;
9. the exact `main` push SHA passes the full CI again.
