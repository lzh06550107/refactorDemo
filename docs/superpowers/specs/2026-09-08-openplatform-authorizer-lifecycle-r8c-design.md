# R8C — WeChat OpenPlatform Authorizer Lifecycle Design

Status: approved architecture, written specification
Base: `main@2f2dfcbe893b7e713caf2b2a952838b6fc24e141`
Target branch: `refactor/openplatform-authorizer-lifecycle-r8c`

## 1. Purpose

R8C extends the R8B Component Platform trust chain into the authorizer authorization lifecycle:

```text
ComponentPlatform
    -> component_verify_ticket
    -> component_access_token
    -> pre_auth_code
    -> authorization callback/event
    -> authorization_code exchange
    -> AuthorizerAuthorization
    -> authorizer_refresh_token
    -> authorizer_access_token
```

R8C also upgrades the R8B ticket-specific callback path into a unified authenticated OpenPlatform event ingress capable of dispatching `component_verify_ticket`, `authorized`, `updateauthorized`, and `unauthorized` events.

R8C binds an authorized WeChat AppId to an existing internal Account. Automatic creation/provisioning of new Tenant Accounts is explicitly deferred to R8D.

## 2. Scope

### In scope

- unified OpenPlatform event ingress;
- compatibility preservation for the existing R8B `/ticket` endpoint;
- pre-auth-code creation;
- opaque, one-time authorization state/intents;
- authorization callback completion;
- authorization-code exchange;
- authorizer authorization persistence;
- authorizer refresh-token rotation;
- authorizer access-token caching and refresh;
- DB-backed authorizer refresh lease/singleflight;
- authorized/updateauthorized/unauthorized event handling;
- binding an authorization to an existing MiniApp provider Account;
- encryption at rest for authorizer refresh/access tokens;
- replay/idempotency and stale-event protection;
- stable errors, audit, migration, rollback, tests, CI gates.

### Deferred to R8D or later

- automatic creation of new Tenant/Account after WeChat authorization;
- account-type discovery/provisioning workflows beyond validating an existing target Account;
- public-account business APIs unrelated to authorization lifecycle;
- code release/version management;
- payment APIs;
- materialization of broad authorizer metadata not required for trust or token lifecycle.

## 3. Ownership and isolation model

`ComponentPlatform` remains operator/platform-scoped infrastructure and is not Tenant-owned.

The canonical authorization identity is:

```text
(component_platform_id, authorizer_app_id)
```

`AuthorizerAuthorization` is a platform-level fact. Tenant/Account bindings reference that fact instead of owning duplicated refresh/access tokens.

Internal usage remains:

```text
Tenant
  -> Account
      -> miniapp_provider_accounts
          -> component_platform_id
          -> provider_app_id == authorizer_app_id
```

Rules:

1. the same `(component_platform_id, authorizer_app_id)` has one authorization lifecycle;
2. no authorizer token is keyed only by authorizer AppId without platform id;
3. no authorizer credential is persisted in plaintext;
4. OpenPlatform repositories never infer Tenant from authorizer credentials;
5. binding to an internal Account is an application-layer operation with explicit Tenant + Account validation.

## 4. Chosen architecture

R8C uses a central Authorizer Authorization Registry.

Rejected alternatives:

- storing authorizer credentials directly in `miniapp_provider_accounts`: would mix Tenant-owned provider configuration with shared platform credentials and duplicate refresh state;
- storing one authorization copy per Tenant: would duplicate a single WeChat credential lifecycle and create refresh races.

The central registry preserves the R8B trust boundary and allows deterministic cross-tenant isolation.

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
│   ├── AuthorizationCallbackService.php
│   ├── AuthorizationEventService.php
│   └── AuthorizerAccessTokenService.php
├── contract/
│   ├── AuthorizationIntentRepository.php
│   ├── AuthorizerAuthorizationRepository.php
│   ├── AuthorizerTokenRepository.php
│   ├── AuthorizerRefreshLeaseRepository.php
│   ├── AuthorizerClient.php
│   └── MiniAppAuthorizationBinding.php
├── security/
│   └── WechatComponentCallbackAuthenticator.php
└── infrastructure/
    ├── WechatAuthorizerClient.php
    ├── ThinkPhpAuthorizationIntentRepository.php
    ├── ThinkPhpAuthorizerAuthorizationRepository.php
    ├── ThinkPhpAuthorizerTokenRepository.php
    └── ThinkPhpAuthorizerRefreshLeaseRepository.php

app/api/controller/V1/
├── OpenPlatformEventController.php
├── OpenPlatformAuthorizationStartController.php
└── OpenPlatformAuthorizationCallbackController.php
```

Domain/application code may not import ThinkPHP facades, `Db`, `$_W`, `$_GPC`, or legacy PDO helpers.

## 6. Unified authenticated event ingress

Canonical endpoint:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/events
```

R8B compatibility endpoint remains available:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/ticket
```

The compatibility route must preserve R8B ticket semantics while delegating through the same authenticated callback pipeline.

### Processing order

```text
1. resolve enabled ComponentPlatform from route id
2. enforce raw-body size limit (128 KiB)
3. hardened outer XML parse; extract Encrypt only
4. parse timestamp + nonce
5. enforce ±300 second freshness
6. resolve verify token
7. verify msg_signature over token/timestamp/nonce/Encrypt
8. compute replay identity and encrypted payload hash
9. decrypt WeChat AES envelope
10. validate framed receiver AppId == configured componentAppId
11. hardened inner XML parse
12. validate inner AppId == configured componentAppId
13. classify InfoType
14. persist replay decision + dispatch authenticated event
15. emit post-commit structured audit
16. return plain-text `success`
```

No event handler reimplements signature/AES/freshness logic.

## 7. AuthenticatedComponentEvent

The authenticator returns a typed event containing only validated fields:

```text
componentPlatformId
componentAppId
infoType
sourceTimestamp
replayKey
payloadHash
safe event-specific fields
```

Supported event payloads:

### `component_verify_ticket`

- `ComponentVerifyTicket`

### `authorized`

- `AuthorizerAppid`
- `AuthorizationCode`
- optional `PreAuthCode`

### `updateauthorized`

- `AuthorizerAppid`
- `AuthorizationCode`
- optional `PreAuthCode`

### `unauthorized`

- `AuthorizerAppid`

Authorization code values are treated as ephemeral secrets and must never be logged, audited, serialized, or persisted.

Unknown/unsupported `InfoType` fails with `INVALID_ARGUMENT / 400`.

## 8. Replay and idempotency

Transport replay identity remains:

```text
replay_key = SHA-256(
  componentPlatformId + "\n" + timestamp + "\n" + nonce
)

payload_hash = SHA-256(encryptedPayload)
```

R8C reuses or generalizes the R8B inbox so all supported event types receive the same authoritative replay protection.

Semantics:

- unseen replay key -> accept;
- same key + same payload -> semantic duplicate, return success without second mutation;
- same key + different payload -> `CONFLICT / 409`;
- concurrent identical callbacks converge on one inbox row.

Replay protection is transport-level. Authorizer state also uses provider event/source timestamps and version checks so an old `unauthorized` cannot overwrite a newer re-authorization.

## 9. AuthorizationIntent

R8C must not rely on an unsigned/guessable callback state containing raw Tenant/Account ids.

An authorization start creates a one-time intent:

```text
id
componentPlatformId
tenantId
targetAccountId
stateHash
requestedAuthType
createdAt
expiresAt
consumedAt
version
```

The caller receives a 32-byte random opaque state. Persistence stores only:

```text
SHA-256(state)
```

Rules:

1. state is non-reversible and never stored plaintext;
2. intent expiry is local and bounded;
3. an intent can be consumed exactly once;
4. consume uses an atomic state transition/CAS;
5. callback state lookup always includes the hashed state;
6. expired/consumed intents are rejected;
7. Tenant, Account, and ComponentPlatform are recovered only from the persisted intent.

Default local intent TTL: 10 minutes. This is independent from provider `pre_auth_code.expires_in` and exists only to bound local callback state lifetime.

## 10. Authorization start flow

Endpoint:

```text
POST /api/v1/openplatform/components/{componentPlatformId}/authorization-intents
```

Input must identify the existing `tenantId`, `targetAccountId`, callback URI, and requested auth type permitted by the application.

Flow:

```text
validate Tenant + target Account
  -> validate target MiniApp provider binding context
  -> resolve enabled ComponentPlatform
  -> create opaque AuthorizationIntent
  -> obtain R8B component_access_token
  -> call WeChat api_create_preauthcode outside DB transaction
  -> validate pre_auth_code + positive integer expires_in
  -> build provider authorization URL using opaque state
  -> return URL + local intent metadata (never provider secrets)
```

`pre_auth_code` is short-lived and is not persisted after building the authorization URL unless a deterministic retry requirement later proves necessary. R8C does not create a reusable local pre-auth-code cache.

Provider `expires_in` must be a positive JSON integer; numeric strings are invalid.

## 11. Authorization callback flow

Endpoint:

```text
GET /api/v1/openplatform/authorization/callback
```

The callback receives opaque `state` and provider `auth_code`.

Flow:

```text
validate non-empty state/auth_code
  -> hash state
  -> atomically consume AuthorizationIntent
  -> recover platform/tenant/target account from intent
  -> obtain current R8B component_access_token
  -> exchange auth_code through AuthorizerClient
  -> validate provider authorizer_appid/access token/refresh token/expires_in
  -> persist platform-level AuthorizerAuthorization + access token
  -> bind existing MiniApp provider Account to the returned authorizer_appid
  -> audit after successful commit
```

The authorization code exists only in request/application memory for the provider exchange and is never stored.

If provider exchange fails after intent consumption, the state remains consumed. The caller must restart authorization rather than replay the same intent.

## 12. AuthorizerAuthorization

Canonical identity:

```text
(componentPlatformId, authorizerAppId)
```

Domain fields:

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

The domain object does not expose plaintext refresh/access token through array serialization.

### Replacement rules

- first successful authorization -> active, version 1;
- newer `authorized` or `updateauthorized` -> replace refresh credential/scope and increment version;
- same source timestamp + same normalized safe result -> semantic duplicate;
- same source timestamp + conflicting result -> `CONFLICT / 409`;
- older provider event cannot overwrite newer state;
- `unauthorized` marks status unauthorized and clears all usable authorizer credentials;
- newer re-authorization after unauthorized may reactivate the same canonical row.

## 13. Existing Account binding

R8C binds only to an existing internal Account.

The binding service validates:

- intent Tenant exists;
- target Account belongs to that Tenant;
- target Account is eligible for WeChat Mini Program component mode;
- returned `authorizer_app_id` becomes the MiniApp provider `provider_app_id`;
- `component_platform_id` equals the intent platform;
- no manual `credential_ref` is retained for component mode;
- conflicting existing provider bindings fail closed rather than silently move credentials between Accounts.

R8C does not auto-create Tenant or Account rows.

## 14. Persistence schema

Migration pair:

```text
database/migrations/20260908_008_openplatform_authorizer_lifecycle_up.sql
database/migrations/20260908_008_openplatform_authorizer_lifecycle_down.sql
```

### 14.1 `openplatform_authorization_intents`

```text
id                         varchar(64) PK
component_platform_id      varchar(64) NOT NULL FK
tenant_id                  varchar(64) NOT NULL FK
target_account_id          varchar(64) NOT NULL FK
state_hash                 char(64) NOT NULL UNIQUE
requested_auth_type        varchar(32) NOT NULL
created_at                 datetime(6) NOT NULL
expires_at                 datetime(6) NOT NULL
consumed_at                datetime(6) NULL
version                    bigint unsigned NOT NULL
```

No plaintext state or pre-auth/auth code column exists.

### 14.2 `authorizer_authorizations`

```text
component_platform_id      varchar(64) NOT NULL
authorizer_app_id          varchar(128) NOT NULL
status                     varchar(32) NOT NULL
refresh_token_ciphertext   text NULL
refresh_token_key_version  varchar(64) NULL
refresh_token_hash         char(64) NULL
scope_json                 text NOT NULL
provider_updated_at        datetime(6) NOT NULL
first_authorized_at        datetime(6) NOT NULL
last_authorized_at         datetime(6) NOT NULL
unauthorized_at            datetime(6) NULL
version                    bigint unsigned NOT NULL
updated_at                 datetime(6) NOT NULL
PRIMARY KEY(component_platform_id, authorizer_app_id)
```

Status `active` requires a protected refresh token. Status `unauthorized` stores no usable refresh-token ciphertext/hash.

### 14.3 `authorizer_access_tokens`

```text
component_platform_id      varchar(64) NOT NULL
authorizer_app_id          varchar(128) NOT NULL
token_ciphertext           text NOT NULL
token_key_version          varchar(64) NOT NULL
issued_at                  datetime(6) NOT NULL
expires_at                 datetime(6) NOT NULL
version                    bigint unsigned NOT NULL
updated_at                 datetime(6) NOT NULL
PRIMARY KEY(component_platform_id, authorizer_app_id)
```

### 14.4 `authorizer_token_refresh_leases`

```text
component_platform_id      varchar(64) NOT NULL
authorizer_app_id          varchar(128) NOT NULL
holder_id                  varchar(128) NULL
lease_expires_at           datetime(6) NULL
version                    bigint unsigned NOT NULL
updated_at                 datetime(6) NOT NULL
PRIMARY KEY(component_platform_id, authorizer_app_id)
```

## 15. Secret protection

R8C reuses R8B `OpenPlatformSecretCipher` and production AES-256-GCM configuration.

Protected at rest:

- `authorizer_refresh_token`;
- `authorizer_access_token`.

Never persisted:

- `authorization_code`;
- plaintext callback `state`;
- `pre_auth_code` after URL generation.

Never logged/audited/serialized:

- component appsecret;
- verify token;
- EncodingAESKey;
- component verify ticket;
- component access token;
- pre-auth code;
- auth code;
- authorizer refresh/access token;
- raw encrypted/decrypted callback body.

## 16. AuthorizerClient

The provider client exposes:

```text
createPreAuthCode(componentAccessToken)
queryAuthorization(componentAccessToken, authorizationCode)
refreshAuthorizerToken(componentAccessToken, authorizerAppId, authorizerRefreshToken)
```

Provider response validation is strict:

- all required tokens are non-empty strings;
- `expires_in` is a positive JSON integer;
- returned authorizer AppId must be non-empty;
- provider errors/malformed responses map to `BAD_GATEWAY / 502` with sanitized messages;
- transport/network failures never include request bodies or returned credentials in exceptions.

Network I/O never occurs inside a DB transaction.

## 17. AuthorizerAccessTokenService

Public API:

```text
forAuthorizer(componentPlatformId, authorizerAppId, now = UTC-now)
```

Default refresh skew: 300 seconds.
Default lease duration: 30 seconds.

Flow:

```text
resolve enabled ComponentPlatform
  -> load active AuthorizerAuthorization
  -> load/decrypt cached access token
  -> outside refresh skew? return
  -> try authorizer-specific DB lease
       loser -> reread cached token
                -> still unexpired: return old token
                -> expired/missing: SERVICE_UNAVAILABLE 503
       winner -> double-check cached token
              -> obtain R8B component_access_token
              -> decrypt current authorizer_refresh_token
              -> provider refresh outside transaction
              -> validate access token + expires_in + optional rotated refresh token
              -> holder/version-scoped CAS transaction
              -> atomically write new access token and rotated refresh token
              -> release lease
              -> audit after commit
```

### Failure fallback

- provider refresh failure + existing authorizer token still actually unexpired -> return old token;
- provider refresh failure + expired/missing token -> `BAD_GATEWAY / 502`;
- authorization missing/unauthorized -> `FORBIDDEN / 403`;
- missing component token dependency follows R8B error semantics;
- refresh lease busy with no usable token -> `SERVICE_UNAVAILABLE / 503`.

## 18. Refresh token rotation and CAS

Provider refresh may rotate the authorizer refresh token. R8C treats rotation as first-class state.

The refresh write transaction verifies:

```text
lease holder matches
lease not expired
expected authorization version matches
expected access-token version matches
```

Then atomically:

```text
replace authorizer access token
replace refresh token when provider supplied a rotated one
bump token version
bump authorization version when refresh token rotates
```

A stale worker cannot overwrite a newer access token or rotated refresh token.

## 19. Authorization event handling

### `authorized`

- authenticated by the unified ingress;
- authorization code is exchanged immediately;
- result upserts an active AuthorizerAuthorization;
- if an active AuthorizationIntent can be correlated through callback state flow, bind the existing target Account there;
- event-only delivery without a local intent updates platform authorization state but does not invent a Tenant binding.

### `updateauthorized`

- authorization code must be exchanged again;
- safe scope metadata and credentials are replaced only if event ordering rules allow;
- old event cannot overwrite newer authorization state.

### `unauthorized`

Immediately:

- mark authorization `unauthorized`;
- clear refresh-token ciphertext/hash/key version;
- delete/clear cached authorizer access token;
- clear refresh lease;
- increment state version;
- future `forAuthorizer()` calls fail `FORBIDDEN / 403` until a newer re-authorization succeeds.

Credential clearing occurs in one short transaction.

## 20. Error mapping

| Condition | Error |
| --- | --- |
| malformed callback/XML/AES/event fields | `INVALID_ARGUMENT` 400 |
| callback signature/freshness failure | `UNAUTHORIZED` 401 |
| receiver/inner AppId mismatch | `FORBIDDEN` 403 |
| ComponentPlatform missing/disabled | `NOT_FOUND` 404 |
| invalid/expired/consumed authorization state | `UNAUTHORIZED` 401 |
| target Tenant/Account/binding mismatch | `FORBIDDEN` 403 |
| authorization missing/unauthorized | `FORBIDDEN` 403 |
| replay/same-timestamp semantic conflict | `CONFLICT` 409 |
| provider/network/malformed provider response | `BAD_GATEWAY` 502 |
| refresh busy with no usable access token | `SERVICE_UNAVAILABLE` 503 |
| cipher/configuration failure | `INTERNAL_ERROR` 500 |

## 21. Audit

Platform-level actions use null Tenant/Account unless an explicit existing Account binding is being mutated.

Actions include:

```text
openplatform.authorization.intent.created
openplatform.authorization.completed
openplatform.authorization.updated
openplatform.authorization.unauthorized
openplatform.authorizer_token.refreshed
```

Safe metadata may include:

- component platform id;
- authorizer AppId or a stable hash according to local audit convention;
- Tenant/Account ids only for explicit binding actions;
- result classification;
- authorization/token version numbers.

Audit never contains secret material or raw provider payloads.

## 22. Test strategy

### Unit

- AuthorizationIntent invariants/expiry/one-time state hashing;
- AuthorizerAuthorization ordering/status rules;
- WeChat authorizer client strict response parsing;
- `expires_in` rejects numeric strings/zero/negative values;
- authorizer token usability/skew rules.

### Component

- create authorization intent and provider URL;
- state plaintext is never persisted;
- expired state rejected;
- state consumed exactly once;
- authorization code never reaches repository/audit;
- successful authorization creates active registry state and binds existing MiniApp Account;
- exact authorization event replay returns success without second mutation;
- same replay key/different payload -> 409;
- old unauthorized cannot overwrite newer active state;
- newer unauthorized clears all authorizer credentials;
- newer reauthorization reactivates state;
- Platform A token cannot be read from Platform B;
- authorizer A refresh cannot mutate authorizer B;
- refresh lease singleflight winner/loser behavior;
- stale lease holder CAS rejected;
- rotated refresh token + access token commit atomically;
- provider failure fallback only while old access token is actually unexpired.

### Contract/security

- `_008` schema ownership/FKs/compound keys;
- no plaintext `authorization_code`, `pre_auth_code`, `state`, refresh token, or access token columns;
- domain/application layer contains no ThinkPHP/legacy globals;
- canonical `/events` route exists;
- R8B `/ticket` compatibility route remains;
- controller delegates to application services, not repositories;
- secret-pattern scan;
- all R8A/R8B tests remain green.

## 23. TDD and release gates

Implementation must follow RED -> GREEN for each task.

Required gates:

1. existing full offline suite remains green before R8C changes;
2. each new behavior test is observed RED before production implementation;
3. Composer validate/install;
4. full offline contract suite;
5. PHPUnit bridge;
6. full PHP lint;
7. multi-app HTTP smoke;
8. branch PR CI success for the exact candidate SHA;
9. fresh race-check proves current main is still ancestor of candidate;
10. non-force fast-forward main to the exact candidate SHA;
11. independent exact-SHA `main` push CI succeeds.

No force push is used for branch integration into main.

## 24. Acceptance criteria

R8C is complete only when all of the following are true:

- one authenticated ingress safely handles ticket and authorization lifecycle events;
- R8B ticket behavior remains compatible;
- authorization state is opaque, hashed, expiring, and one-time;
- auth code is never persisted;
- authorizer authorization is canonical per `(platform, authorizerAppId)`;
- existing Account binding is explicit and Tenant-safe;
- refresh/access tokens are encrypted at rest;
- provider refresh token rotation is atomic with access-token replacement;
- refresh singleflight prevents cross-process storms;
- stale workers cannot overwrite newer credentials;
- unauthorized events immediately revoke local authorizer credential usability;
- old events cannot overwrite newer authorization state;
- platform/authorizer cross-isolation tests pass;
- R8A/R8B regression suite remains green;
- branch and final main push CI are green for the exact same SHA.

## 25. Deferred R8D boundary

R8D may add Account Provisioning after authorization, including account-type discovery, creation of new internal Accounts, richer metadata synchronization, and explicit operator workflows.

R8C intentionally stops at secure authorization lifecycle plus binding to an already existing Account.
