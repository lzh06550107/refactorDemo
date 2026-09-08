# R8C — WeChat OpenPlatform Authorizer Lifecycle Design

Status: approved architecture, written specification ready for review  
Base: `main@2f2dfcbe893b7e713caf2b2a952838b6fc24e141`  
Target branch: `refactor/openplatform-authorizer-lifecycle-r8c`

## 1. Purpose

R8C extends the R8B Component Platform trust chain into the authorizer authorization lifecycle:

```text
ComponentPlatform
    -> component_verify_ticket
    -> component_access_token
    -> pre_auth_code
    -> authorization callback / authenticated authorization event
    -> authorization_code exchange
    -> AuthorizerAuthorization
    -> authorizer_refresh_token
    -> authorizer_access_token
```

R8C also upgrades the R8B ticket-specific callback path into one authenticated OpenPlatform event ingress capable of dispatching:

```text
component_verify_ticket
authorized
updateauthorized
unauthorized
```

R8C binds an authorized WeChat AppId to an **existing** internal Account. Automatic creation/provisioning of new Tenant Accounts is deferred to R8D.

## 2. Compatibility classification

### MUST_COMPAT

- R8B `component_verify_ticket -> component_access_token` behavior;
- encrypted OpenPlatform callback protocol and signature validation;
- existing R8B `/ticket` endpoint behavior;
- WeChat pre-auth-code creation and authorization-page flow;
- authorization-code exchange for authorizer credentials;
- authorizer access-token refresh using authorizer refresh token;
- provider `expires_in` as expiry source.

### INTENTIONAL_SECURITY_FIX

- one hardened authenticator for all Component Platform callback event types;
- replay protection for authorization events, not only verify-ticket events;
- opaque callback state, persisted only as SHA-256 hash;
- hashed `pre_auth_code` correlation so browser callback and `authorized` event cannot race into two authorization exchanges;
- platform + authorizer composite scoping for every authorizer credential;
- AES-256-GCM protection of authorizer refresh/access tokens at rest;
- DB lease + holder/version CAS for authorizer token refresh;
- stale-event protection so old `unauthorized`/update events cannot overwrite newer state;
- no raw authorization code, pre-auth code, callback state, token, encrypted XML, or decrypted XML in logs/audit/database plaintext.

### DEFERRED_TO_R8D_OR_LATER

- automatic creation of new Tenant/Account after authorization;
- broad authorizer metadata provisioning/synchronization;
- code release/version management;
- payment APIs;
- unrelated public-account business APIs.

## 3. Ownership and isolation model

`ComponentPlatform` remains operator/platform-scoped infrastructure and is not Tenant-owned.

The canonical authorization identity is:

```text
(component_platform_id, authorizer_app_id)
```

`AuthorizerAuthorization` is therefore a platform-level fact. Tenant/Account bindings reference that fact rather than owning duplicated refresh/access tokens.

Internal usage remains:

```text
Tenant
  -> Account
      -> miniapp_provider_accounts
          -> component_platform_id
          -> provider_app_id == authorizer_app_id
```

Rules:

1. one `(component_platform_id, authorizer_app_id)` has one credential lifecycle;
2. no authorizer token is keyed only by authorizer AppId;
3. no authorizer credential is persisted in plaintext;
4. OpenPlatform repositories never infer Tenant from credentials;
5. Tenant/Account exists only at the explicit account-binding boundary;
6. a Component Platform can authorize Accounts used by multiple tenants without duplicating the upstream authorization credential.

## 4. Chosen architecture

R8C uses a central **Authorizer Authorization Registry**.

Rejected alternatives:

- storing authorizer credentials directly in `miniapp_provider_accounts`: mixes Tenant-owned provider configuration with platform-owned secret lifecycle and creates duplicate refresh races;
- storing one authorization per Tenant: duplicates one WeChat credential lifecycle and breaks the R8B ownership boundary.

## 5. Package structure

```text
app/openplatform/
├── domain/
│   ├── AuthorizationIntent.php
│   ├── AuthorizerAuthorization.php
│   ├── AuthorizerAccessToken.php
│   ├── AuthorizerTokenRefreshLease.php
│   ├── AuthenticatedComponentEvent.php
│   └── AuthorizerAuthorizationResult.php
├── application/
│   ├── AuthorizationStartService.php
│   ├── AuthorizationCompletionService.php
│   ├── AuthorizationCallbackService.php
│   ├── AuthorizationEventService.php
│   └── AuthorizerAccessTokenService.php
├── contract/
│   ├── AuthorizationIntentRepository.php
│   ├── AuthorizerAuthorizationRepository.php
│   ├── AuthorizerTokenRepository.php
│   ├── AuthorizerRefreshLeaseRepository.php
│   ├── AuthorizerClient.php
│   └── AuthorizerAccountBinding.php
├── security/
│   └── WechatComponentCallbackAuthenticator.php
└── infrastructure/
    ├── WechatAuthorizerClient.php
    ├── ThinkPhpAuthorizationIntentRepository.php
    ├── ThinkPhpAuthorizerAuthorizationRepository.php
    ├── ThinkPhpAuthorizerTokenRepository.php
    └── ThinkPhpAuthorizerRefreshLeaseRepository.php

app/miniapp/infrastructure/
└── OpenPlatformAuthorizerAccountBinding.php

app/api/controller/V1/
├── OpenPlatformEventController.php
├── OpenPlatformAuthorizationStartController.php
└── OpenPlatformAuthorizationCallbackController.php
```

Domain/application code must not import ThinkPHP facades, `Db`, `$_W`, `$_GPC`, or legacy PDO helpers.

## 6. Unified authenticated event ingress

Canonical endpoint:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/events
```

R8B compatibility endpoint remains:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/ticket
```

The compatibility route delegates through the same authentication pipeline and preserves R8B ticket response semantics.

### Security-sensitive processing order

```text
1. resolve ComponentPlatform from route id
2. reject missing/disabled platform
3. enforce raw-body limit: 128 KiB
4. hardened outer XML parse; extract Encrypt only
5. parse timestamp + nonce
6. enforce ±300 second freshness
7. resolve verify token
8. verify msg_signature over token/timestamp/nonce/Encrypt
9. compute replay key + encrypted-payload hash
10. decrypt WeChat AES envelope
11. validate framed receiver id == componentAppId
12. hardened inner XML parse
13. validate inner AppId == componentAppId
14. classify InfoType and required fields
15. authoritative replay/idempotency decision
16. dispatch typed authenticated event
17. emit post-commit structured audit
18. return text/plain `success`
```

No event handler reimplements signature, freshness, AES framing, or AppId authentication.

## 7. AuthenticatedComponentEvent

The authenticator returns a typed event containing only validated protocol fields:

```text
componentPlatformId
componentAppId
infoType
sourceTimestamp
replayKey
payloadHash
safe event-specific fields
```

Supported fields:

### `component_verify_ticket`

```text
ComponentVerifyTicket
```

### `authorized`

```text
AuthorizerAppid
AuthorizationCode
AuthorizationCodeExpiredTime (when present)
PreAuthCode (when present)
```

### `updateauthorized`

```text
AuthorizerAppid
AuthorizationCode
AuthorizationCodeExpiredTime (when present)
PreAuthCode (when present)
```

### `unauthorized`

```text
AuthorizerAppid
```

`AuthorizationCode` and `PreAuthCode` are ephemeral secret values. They may exist in request/application memory only and must never be logged, audited, serialized, or persisted plaintext.

Unknown/unsupported `InfoType` returns `INVALID_ARGUMENT / 400`.

## 8. Replay and semantic idempotency

Transport replay identity remains compatible with R8B:

```text
replay_key = SHA-256(
  componentPlatformId + "\n" + timestamp + "\n" + nonce
)

payload_hash = SHA-256(encryptedPayload)
```

Database uniqueness remains platform scoped.

Semantics:

- unseen replay key -> continue;
- same replay key + same payload hash -> semantic duplicate, return success with no second mutation;
- same replay key + different payload hash -> `CONFLICT / 409`;
- concurrent identical deliveries converge on one inbox row.

Replay is transport-level. Authorizer lifecycle state separately uses `sourceTimestamp`/version ordering so an older lifecycle event cannot overwrite a newer authorization state.

## 9. AuthorizationIntent

The browser callback must never carry raw Tenant or Account ids as trusted state.

An authorization intent contains:

```text
id
componentPlatformId
tenantId
targetAccountId
stateHash
preAuthCodeHash
providerPreAuthExpiresAt
requestedAuthType
createdAt
expiresAt
claimHolderId
claimExpiresAt
completedAt
completedAuthorizerAppId
version
```

The caller receives a 32-byte random opaque state. Database persistence stores only:

```text
state_hash = SHA-256(state)
pre_auth_code_hash = SHA-256(pre_auth_code)
```

Plaintext state and plaintext pre-auth code are never stored.

### Intent rules

1. local intent TTL defaults to 10 minutes;
2. provider pre-auth expiry is also recorded from provider `expires_in` and may shorten effective validity;
3. `stateHash` is globally unique;
4. `preAuthCodeHash` is unique per Component Platform;
5. completion is singleflight via a short claim (`claimHolderId`, `claimExpiresAt`);
6. claim TTL defaults to 30 seconds;
7. a completed intent never exchanges another authorization code;
8. a busy duplicate channel never performs provider exchange;
9. claim failure/expiry is recoverable; a crashed process cannot permanently consume the intent;
10. successful completion records only the safe `completedAuthorizerAppId`, not any code/token.

This claim is an authorization-completion concurrency guard, separate from the authorizer-token refresh lease.

## 10. Authorization start flow

Endpoint:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/authorization-intents
```

Input identifies an existing `tenantId`, existing `targetAccountId`, and an application-approved provider auth type. The WeChat redirect URI is server configuration; callers do not supply an arbitrary redirect URI.

Flow:

```text
validate Tenant + target Account
  -> validate target Account is eligible for MiniApp component binding
  -> resolve enabled ComponentPlatform
  -> generate opaque state in memory
  -> obtain R8B component_access_token
  -> call WeChat create-pre-auth-code outside DB transaction
  -> validate non-empty pre_auth_code + positive JSON-integer expires_in
  -> short transaction: persist intent with stateHash + preAuthCodeHash + expiries
  -> build WeChat authorization URL using plaintext pre_auth_code + opaque state + fixed redirect URI
  -> return authorization URL + non-secret local intent metadata
```

The plaintext `pre_auth_code` is discarded after URL construction. R8C does not create a reusable pre-auth-code cache.

Numeric-string `expires_in` values such as `"600"` are invalid.

## 11. Browser callback and authorization-event arbitration

WeChat can expose an authorization code through the browser redirect and can also include an authorization code in `authorized`/`updateauthorized` lifecycle notifications. R8C must not let these two channels independently exchange the same authorization flow.

### Authoritative rule

For a locally started first authorization, **AuthorizationIntent completion claim is the arbitration point**.

Both channels call the same `AuthorizationCompletionService`:

```text
browser callback
  -> find intent by SHA-256(state)
  -> try claim intent

`authorized` event
  -> find intent by (componentPlatformId, SHA-256(PreAuthCode)) when PreAuthCode is present
  -> try claim same intent
```

Only the claim winner may call `api_query_auth`.

### Loser behavior

- intent already completed -> idempotent success; never exchange again;
- intent currently claimed by another holder -> no provider call; browser receives a stable "processing" result, event ingress immediately acknowledges `success`;
- claim expired -> a later delivery may reacquire;
- invalid/expired intent -> browser fails `UNAUTHORIZED`; event may still use the event-only reconciliation path described below.

### Event-only authorized fallback

A valid authenticated `authorized` event that cannot correlate to a local intent may exchange its event authorization code and create/update the **platform-level** AuthorizerAuthorization. It must not invent a Tenant/Account binding.

This supports authorizations initiated outside the local authorization-start endpoint while preserving explicit Tenant ownership at the binding boundary.

### `updateauthorized`

`updateauthorized` is not an Account-binding flow. It exchanges its authenticated event authorization code and updates the existing platform-level authorization if event ordering permits.

### Browser callback after event completion

If the authenticated `authorized` event won the intent claim and completed the binding first, the later browser callback resolves the already-completed intent and returns idempotent completion without re-exchanging its `auth_code`.

## 12. Authorization completion transaction

After the claim winner performs provider exchange **outside** a DB transaction, one short transaction:

```text
re-check intent claim holder + non-expired claim
validate returned authorizer_app_id
upsert platform-level AuthorizerAuthorization
upsert encrypted initial authorizer access token
bind existing Account through AuthorizerAccountBinding
mark intent completed with completedAuthorizerAppId
clear completion claim
commit
```

If transaction/CAS validation fails, stale work does not overwrite newer authorization state.

The authorization code is never persisted before or after exchange.

Provider/network failure releases the completion claim when safe so another still-valid delivery can retry; the code itself is never cached locally. If no valid delivery remains, the user starts a new authorization intent.

## 13. AuthorizerAuthorization

Canonical identity:

```text
(componentPlatformId, authorizerAppId)
```

Domain metadata:

```text
componentPlatformId
authorizerAppId
status                  active | unauthorized
refreshTokenHash        SHA-256 of plaintext token
scopeSet                 normalized safe scope ids
providerUpdatedAt
firstAuthorizedAt
lastAuthorizedAt
unauthorizedAt
version
```

Plaintext refresh/access tokens are never public DTO fields.

### Ordering rules

- first successful authorization -> active, version 1;
- browser completion uses completion time as `providerUpdatedAt`;
- event-driven authorization/update uses authenticated `sourceTimestamp`;
- newer `authorized` or `updateauthorized` may replace credential/scope state and increment version;
- same timestamp + same normalized non-secret result -> idempotent;
- same timestamp + conflicting result -> `CONFLICT / 409`;
- older lifecycle event -> stale no-op;
- `unauthorized` accepted only when not older than current state;
- newer re-authorization after unauthorized may reactivate the same canonical row.

Because callback freshness already requires a correctly synchronized server clock, browser completion time can safely establish the lower bound that protects it from an older subsequently delivered lifecycle event.

## 14. Existing Account binding

R8C binds only to an existing internal Account through the port:

```text
AuthorizerAccountBinding
```

Production MiniApp adapter:

```text
app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php
```

It validates:

- intent Tenant exists;
- target Account belongs to that Tenant;
- target Account is eligible for WeChat Mini Program component mode;
- returned `authorizer_app_id` becomes `miniapp_provider_accounts.provider_app_id`;
- `component_platform_id` equals the intent platform;
- component mode contains no manual `credential_ref`;
- conflicting existing provider binding fails closed rather than silently moving an authorization between Accounts.

The adapter may create or update the MiniApp provider configuration row for the **existing Account**. It never creates a Tenant or Account.

## 15. Persistence schema

Migration pair:

```text
database/migrations/20260908_008_openplatform_authorizer_lifecycle_up.sql
database/migrations/20260908_008_openplatform_authorizer_lifecycle_down.sql
```

### 15.1 `openplatform_authorization_intents`

```text
id                           varchar(64) PK
component_platform_id        varchar(64) NOT NULL FK
tenant_id                    varchar(64) NOT NULL FK
target_account_id            varchar(64) NOT NULL FK
state_hash                   char(64) NOT NULL UNIQUE
pre_auth_code_hash           char(64) NOT NULL
provider_pre_auth_expires_at datetime(6) NOT NULL
requested_auth_type          varchar(32) NOT NULL
created_at                   datetime(6) NOT NULL
expires_at                   datetime(6) NOT NULL
claim_holder_id              varchar(128) NULL
claim_expires_at             datetime(6) NULL
completed_at                 datetime(6) NULL
completed_authorizer_app_id  varchar(128) NULL
version                      bigint unsigned NOT NULL
UNIQUE(component_platform_id, pre_auth_code_hash)
```

No plaintext state, pre-auth code, authorization code, or provider token column exists.

### 15.2 `authorizer_authorizations`

```text
component_platform_id       varchar(64) NOT NULL
authorizer_app_id           varchar(128) NOT NULL
status                      varchar(32) NOT NULL
refresh_token_ciphertext    text NULL
refresh_token_key_version   varchar(64) NULL
refresh_token_hash          char(64) NULL
scope_json                  text NOT NULL
provider_updated_at         datetime(6) NOT NULL
first_authorized_at         datetime(6) NOT NULL
last_authorized_at          datetime(6) NOT NULL
unauthorized_at             datetime(6) NULL
version                     bigint unsigned NOT NULL
updated_at                  datetime(6) NOT NULL
PRIMARY KEY(component_platform_id, authorizer_app_id)
```

`active` requires a protected refresh token. `unauthorized` stores no usable refresh-token ciphertext/hash/key version.

### 15.3 `authorizer_access_tokens`

```text
component_platform_id       varchar(64) NOT NULL
authorizer_app_id           varchar(128) NOT NULL
token_ciphertext            text NOT NULL
token_key_version           varchar(64) NOT NULL
issued_at                   datetime(6) NOT NULL
expires_at                  datetime(6) NOT NULL
version                     bigint unsigned NOT NULL
updated_at                  datetime(6) NOT NULL
PRIMARY KEY(component_platform_id, authorizer_app_id)
```

### 15.4 `authorizer_token_refresh_leases`

```text
component_platform_id       varchar(64) NOT NULL
authorizer_app_id           varchar(128) NOT NULL
holder_id                   varchar(128) NULL
lease_expires_at            datetime(6) NULL
version                     bigint unsigned NOT NULL
updated_at                  datetime(6) NOT NULL
PRIMARY KEY(component_platform_id, authorizer_app_id)
```

All authorizer tables reference the appropriate Component Platform/authorization keys with restrictive/cascading behavior chosen to avoid orphan credentials.

## 16. Secret protection

R8C reuses R8B `OpenPlatformSecretCipher` and AES-256-GCM configuration.

Encrypted at rest:

```text
authorizer_refresh_token
authorizer_access_token
```

Never persisted plaintext:

```text
callback state
pre_auth_code
authorization_code
authorizer_refresh_token
authorizer_access_token
```

Never logged/audited/serialized:

```text
component appsecret
verify token
EncodingAESKey
component_verify_ticket
component_access_token
pre_auth_code
authorization_code
authorizer_refresh_token
authorizer_access_token
raw encrypted callback body
decrypted callback XML
```

## 17. AuthorizerClient

Provider port:

```text
createPreAuthCode(componentAccessToken)
queryAuthorization(componentAccessToken, authorizationCode)
refreshAuthorizerToken(componentAccessToken, authorizerAppId, authorizerRefreshToken)
```

Strict response validation:

- required tokens are non-empty strings;
- `expires_in` is a positive JSON integer, never a numeric string;
- returned authorizer AppId is non-empty;
- provider error/malformed response -> `BAD_GATEWAY / 502` with sanitized message;
- network/transport exception never includes request body or returned credential material.

All network I/O occurs outside DB transactions.

## 18. Initial authorization result

`queryAuthorization()` returns a typed non-serializable secret-bearing result used only inside Application/Infrastructure boundaries:

```text
authorizerAppId
authorizerAccessToken
authorizerRefreshToken
expiresIn
normalizedScopeIds
```

The service computes:

```text
issuedAt = now
expiresAt = now + expiresIn
refreshTokenHash = SHA-256(refreshToken)
```

Repository persistence protects tokens before DB write.

## 19. AuthorizerAccessTokenService

Public API:

```text
forAuthorizer(componentPlatformId, authorizerAppId, now = UTC-now)
```

Default refresh skew: 300 seconds.  
Default refresh lease: 30 seconds.

Flow:

```text
resolve enabled ComponentPlatform
  -> load active AuthorizerAuthorization
  -> load/decrypt cached authorizer access token
  -> valid outside refresh skew? return
  -> try authorizer-specific refresh lease
       loser -> reread token
                -> still unexpired: return old token
                -> expired/missing: SERVICE_UNAVAILABLE 503
       winner -> double-check token
              -> obtain R8B component_access_token
              -> decrypt current authorizer_refresh_token
              -> provider refresh outside transaction
              -> validate access token + expires_in + optional rotated refresh token
              -> holder/version-scoped CAS transaction
              -> atomically write access token + rotated refresh token
              -> release lease
              -> audit after commit
```

### Failure fallback

- provider refresh failure + existing authorizer token actually unexpired -> return old token;
- provider refresh failure + expired/missing token -> `BAD_GATEWAY / 502`;
- authorization missing/unauthorized -> `FORBIDDEN / 403`;
- refresh busy with no usable token -> `SERVICE_UNAVAILABLE / 503`;
- R8B component-token dependency keeps its own established error semantics.

## 20. Refresh-token rotation and CAS

Provider refresh may rotate `authorizer_refresh_token`.

Refresh write-back verifies:

```text
lease holder matches
lease has not expired
expected authorization version matches
expected access-token version matches
```

Then atomically:

```text
replace authorizer access token
replace refresh token if provider rotated it
update refreshTokenHash/key version/ciphertext
bump access-token version
bump authorization version when refresh token rotates
```

A stale worker cannot overwrite a newer access token or rotated refresh token.

## 21. Lifecycle event semantics

### `authorized`

1. authenticated by unified ingress;
2. exact replay deduplicated before business mutation;
3. if `PreAuthCode` correlates to a pending local intent, participate in the shared intent-completion claim;
4. claim winner exchanges code and may complete existing-Account binding;
5. if no local intent matches, event may create/update platform-level authorization only;
6. if matching intent is already completed, event is idempotent and must not exchange again.

### `updateauthorized`

1. authenticated and replay protected;
2. exchange event authorization code outside transaction;
3. update active platform authorization only if source timestamp is not stale;
4. do not move/create Account bindings;
5. refresh/access credentials are replaced atomically with authorization version checks.

### `unauthorized`

Accepted only when event ordering allows. One short transaction:

```text
status = unauthorized
clear refresh-token ciphertext/hash/key version
delete/clear cached authorizer access token
clear authorizer refresh lease
set unauthorizedAt/providerUpdatedAt
bump authorization version
```

After commit, `forAuthorizer()` must immediately fail `FORBIDDEN / 403` until a newer re-authorization succeeds.

## 22. Error mapping

| Condition | Error |
| --- | --- |
| malformed callback/XML/AES/event fields | `INVALID_ARGUMENT` 400 |
| callback signature/freshness failure | `UNAUTHORIZED` 401 |
| framed/inner AppId mismatch | `FORBIDDEN` 403 |
| ComponentPlatform missing/disabled | `NOT_FOUND` 404 |
| invalid/expired authorization state | `UNAUTHORIZED` 401 |
| target Tenant/Account/binding mismatch | `FORBIDDEN` 403 |
| authorization missing/unauthorized | `FORBIDDEN` 403 |
| replay or same-timestamp semantic mismatch | `CONFLICT` 409 |
| provider/network/malformed provider response | `BAD_GATEWAY` 502 |
| refresh/completion claim busy with no usable synchronous result | `SERVICE_UNAVAILABLE` 503 or controller-specific processing response |
| cipher/configuration failure | `INTERNAL_ERROR` 500 |

The external event endpoint must still acknowledge an authenticated duplicate/busy event with plain `success` when no unsafe mutation occurred, so provider retries do not create a second exchange.

## 23. Audit

Platform-level actions normally use null Tenant/Account. Explicit Account-binding mutations include their real Tenant/Account context.

Actions:

```text
openplatform.authorization.intent.created
openplatform.authorization.completed
openplatform.authorization.updated
openplatform.authorization.unauthorized
openplatform.authorizer_token.refreshed
```

Safe metadata may include:

- component platform id;
- authorizer AppId or stable hash according to audit convention;
- Tenant/Account ids only for explicit binding action;
- outcome/error classification;
- authorization/token versions.

No secret-bearing request/response field enters audit metadata.

## 24. Test strategy

### Unit

- AuthorizationIntent validity, effective expiry, claim and completion rules;
- state/pre-auth hashes use SHA-256 and no plaintext serialization;
- AuthorizerAuthorization ordering/status rules;
- strict provider response parsing;
- numeric-string/zero/negative `expires_in` rejection;
- authorizer token usability and refresh-skew semantics.

### Component

- authorization start creates URL while DB stores only state/pre-auth hashes;
- fixed/configured redirect URI is used; request cannot inject arbitrary redirect URI;
- expired intent rejected;
- callback and `authorized` event racing on same intent produce exactly one provider exchange;
- completed callback/event duplicate is idempotent;
- busy loser makes no provider call;
- completion claim expiry permits recovery;
- authorization code never reaches repository/audit;
- successful local authorization binds existing MiniApp Account;
- event-only authorization creates platform state but no Tenant binding;
- exact event replay -> success/no second mutation;
- same replay identity/different payload -> `409`;
- old unauthorized cannot overwrite newer active state;
- accepted unauthorized clears refresh/access credential usability;
- newer re-authorization reactivates state;
- Platform A authorizer token cannot leak to Platform B;
- authorizer A refresh cannot mutate authorizer B;
- refresh singleflight has one winner;
- stale refresh holder CAS rejected;
- rotated refresh token + access token commit atomically;
- provider refresh failure falls back only while old access token is truly unexpired.

### Contract/security

- `_008` schema, FKs, composite keys, rollback order;
- no plaintext state/pre-auth/auth-code/token columns;
- OpenPlatform domain/application has no ThinkPHP/legacy dependency;
- canonical `/events` route exists;
- R8B `/ticket` compatibility route remains;
- controllers delegate to Application services, not repositories;
- MiniApp binding adapter does not read authorizer secret repositories directly;
- secret-pattern scan;
- all R8A/R8B tests remain green.

## 25. TDD and release gates

Implementation follows RED -> GREEN.

Required gates:

1. existing full offline suite is green before R8C production changes;
2. every new behavior is observed RED before its production implementation;
3. Composer validate/install;
4. full offline contract suite;
5. PHPUnit bridge;
6. full PHP lint;
7. multi-app HTTP smoke;
8. branch PR CI success for the exact candidate SHA;
9. fresh race-check proves current main is still the candidate ancestor;
10. non-force fast-forward main to the exact candidate SHA;
11. independent exact-SHA `main` push CI success.

No force push is used to integrate R8C into main.

## 26. Acceptance criteria

R8C is complete only when:

- one authenticated ingress safely handles ticket and authorization lifecycle events;
- R8B ticket behavior remains compatible;
- local authorization state is opaque, hashed, expiring, and singleflight;
- browser callback and `authorized` event cannot trigger duplicate code exchange for one local intent;
- auth/pre-auth codes are never persisted plaintext;
- authorizer authorization is canonical per `(platform, authorizerAppId)`;
- existing Account binding is explicit and Tenant-safe;
- refresh/access tokens are encrypted at rest;
- refresh-token rotation is atomic with access-token replacement;
- refresh singleflight prevents process/host refresh storms;
- stale workers cannot overwrite newer credentials;
- unauthorized events immediately revoke local credential usability;
- old events cannot overwrite newer authorization state;
- platform/authorizer cross-isolation tests pass;
- R8A/R8B regression suite remains green;
- branch and final main push CI are green for the exact same SHA.

## 27. Deferred R8D boundary

R8D may add Account Provisioning after authorization, including account-type discovery, creation of new internal Accounts, richer authorizer metadata synchronization, and explicit operator provisioning workflows.

R8C intentionally stops at secure authorization lifecycle plus binding to an already existing Account.
