# R8A MiniApp Identity Session Design

**Status:** proposed / design-approved-in-chat, pending written-spec review

**Base:** `main` at `e99c3f57161748031c7a83ae08b0a765b75f0569`

**V4 scope:** EPIC-07 / ST-07-04 MiniApp/OpenPlatform, first delivery slice only.

## 1. Goal

Implement the MiniApp identity/session half of ST-07-04 without carrying forward R20's weak "client supplies openid to restore identity" path.

R8A must provide:

- WeChat Mini Program `code -> jscode2session -> openid/unionid/session_key` exchange;
- strict Tenant + Account + provider-account boundaries;
- reuse of R7 `MemberIdentityService` and provider-account-scoped `ExternalIdentity`;
- a short-lived opaque server session token whose persisted form is only a SHA-256 hash;
- encrypted-at-rest storage of `session_key`, never returned to the client;
- encrypted user-data verification/decryption with `watermark.appid` validation;
- compatibility adapters for R20 MiniApp account configuration;
- stable application errors, audit events, schema migration/rollback, and RED->GREEN executable tests.

R8A intentionally prepares, but does not complete, the OpenPlatform trust chain. R8B will implement `component_verify_ticket -> component_access_token`; R8C will implement authorizer grant/lifecycle persistence.

## 2. Why R8A is separated from R8B/R8C

ST-07-04 combines two different trust domains:

1. **End-user identity trust:** login code, openid/unionid, session_key, encrypted user data.
2. **Third-party platform trust:** component ticket, component token, authorizer refresh/access tokens.

Combining them into one release would couple user authentication, secret handling, OpenPlatform ticket ingestion, token refresh and account authorization writes into one large transaction boundary. R8A therefore finishes the end-user identity path first and exposes a narrow `ComponentAccessTokenProvider` port that R8B can implement later.

## 3. Verified R20 behavior

Source evidence was re-checked against `we7-2.7.4-deep-source-annotated-r20-20260904.zip`.

### 3.1 MiniApp login

`app/source/auth/session.ctrl.php`:

- `do=openid` accepts either `code` or a client-supplied `openid`.
- Client-supplied `openid` only writes `$_SESSION['openid']` and checks local `mc_mapping_fans`; it does **not** re-authenticate with WeChat.
- The `code` path calls the account implementation and stores `openid`, `unionid`, and `session_key` in PHP Session.

`framework/class/account/wxapp.account.class.php`:

- manual MiniApp login calls `https://api.weixin.qq.com/sns/jscode2session` with `appid + secret + js_code`;
- encrypted data uses the session key and rejects a decrypted payload when `watermark.appid` differs from the MiniApp appid.

`framework/class/account/wxapp.platform.class.php`:

- OpenPlatform-authorized MiniApps call the component form of `jscode2session`, requiring `component_appid + component_access_token`.

### 3.2 OpenPlatform trust chain reserved for R8B

`web/source/account/auth.ctrl.php` and `framework/class/account/weixin.platform.class.php` confirm:

- `component_verify_ticket` is the root used to obtain `component_access_token`;
- the R20 ticket endpoint validates outer AppId and decrypts AES payload but does not explicitly verify WeChat `msg_signature` before decryption;
- R20's component-token cache key is global and does not include component appid.

These two findings are security fixes for R8B, not implementation work for R8A.

## 4. Compatibility classification

| R20 behavior | R8 classification | R8A decision |
| --- | --- | --- |
| `code -> jscode2session -> openid/session_key` | MUST_COMPAT | preserve |
| `unionid` when WeChat returns it | MUST_COMPAT | preserve as optional provider identity attribute |
| MiniApp identity scoped by current account | MUST_COMPAT | preserve explicitly as `provider_account_id = Account.id` |
| decrypted `watermark.appid` must equal current MiniApp appid | MUST_COMPAT | preserve and test |
| rawData signature `sha1(rawData + session_key)` on legacy encrypted-profile flow | MUST_COMPAT | preserve for the legacy-profile decrypt command |
| client-supplied `openid` restores authenticated Session | UNSUPPORTED_LEGACY / SECURITY_FIX | remove; no equivalent endpoint |
| raw `session_key` stored in PHP Session | REPLACED_BY_SECURE_SESSION | encrypted server-side session credential |
| client receives generic PHP `sessionid` | REPLACED_BY_SECURE_SESSION | return a new opaque 256-bit MiniApp session token |
| non-encrypted client profile fields accepted as identity evidence | UNSUPPORTED_LEGACY | never use them to establish identity |
| component jscode2session endpoint | MUST_COMPAT | support through an injected component-token port; concrete trust chain lands in R8B |

## 5. Architecture

Keep the established dependency direction:

```text
HTTP/API Entry
    -> MiniApp Application Services
        -> MiniApp Domain + R7 Member Domain
            <- Repository / Provider Ports
                <- ThinkPHP / WeChat / R20 adapters
```

R8A adds a new `app/miniapp` module. Domain/application code must not import ThinkPHP facades, `Db`, `$_W`, `$_GPC`, or legacy globals.

### 5.1 Core components

#### `MiniAppProviderAccount`

Represents the provider-facing configuration for one internal Account:

- `tenantId`
- `accountId`
- `providerType` (`wechat_mini_program` in R8A)
- `providerAppId`
- `connectionMode`: `manual` or `component_authorized`
- `credentialRef` for manual appsecret resolution, nullable for component mode
- `componentPlatformId`, nullable for manual mode

Invariants:

- Account must be `AccountType::WECHAT_MINI_PROGRAM`;
- tenant/account ids and provider appid are non-empty;
- manual mode requires `credentialRef` and forbids `componentPlatformId`;
- component mode requires `componentPlatformId` and does not expose appsecret.

#### `MiniAppCodeExchangeResult`

Contains:

- `externalSubject` = WeChat openid
- optional `unionId`
- `SecretValue sessionKey`

It must never serialize the session key to arrays/logs/errors.

#### `MiniAppSession`

Contains only session metadata and the token hash at the domain boundary:

- `id`
- `tenantId`
- `accountId`
- `memberId`
- `externalIdentityId`
- `tokenHash`
- `issuedAt`
- `expiresAt`
- optional `revokedAt`

The plaintext opaque token exists only in the application result. The plaintext `session_key` is passed directly to the persistence protection boundary and is never part of a public DTO.

### 5.2 Contracts

R8A introduces these ports:

- `MiniAppProviderAccountRepository`
  - resolve provider configuration by `(tenantId, accountId)`;
- `MiniAppCredentialResolver`
  - resolve a manual MiniApp appsecret from an opaque `credentialRef` as `SecretValue`;
- `MiniAppCodeExchangeClient`
  - exchange `code` using manual or component-authorized mode;
- `ComponentAccessTokenProvider`
  - returns a component access token for `componentPlatformId`; R8B supplies the production OpenPlatform implementation;
- `MiniAppSessionRepository`
  - insert session;
  - find active session by token hash and `(tenantId, accountId)`;
  - revoke session;
  - load protected session credential for decrypt operations;
- `MiniAppSessionSecretProtector`
  - encrypt/decrypt `session_key` at the infrastructure boundary;
- `AuditSink`
  - existing audit abstraction/pattern reused where available; no secrets in metadata.

## 6. Application flows

### 6.1 Login

`MiniAppLoginService::login(command): MiniAppLoginResult`

Input:

- tenant id
- account id
- one-time WeChat login code
- request context
- current time

Flow:

```text
validate non-empty code/context
  -> load internal Account and MiniAppProviderAccount
  -> assert tenant/account match and AccountType == WECHAT_MINI_PROGRAM
  -> exchange code with WeChat
  -> validate provider result contains openid + session_key
  -> create R7 ProviderIdentity(
       providerType = wechat_mini_program,
       providerAccountId = internal Account.id,
       externalSubject = openid,
       unionId = optional unionid
     )
  -> TransactionManager::run
       -> MemberIdentityService::resolveOrCreateWithinTransaction
       -> issue 32 random bytes
       -> persist SHA-256 token hash + encrypted session_key + identity links
  -> return opaque token + member/external-identity ids + expiry
```

The remote `jscode2session` call occurs **outside** the database transaction.

The Member/ExternalIdentity resolution and MiniApp session insert occur in one database transaction so a successful login cannot return a session whose identity write was rolled back.

### 6.2 Session authentication

`MiniAppSessionService::authenticate(tenantId, accountId, opaqueToken, now)`:

- hash the opaque token with SHA-256;
- query by token hash **and** tenant/account boundary;
- reject missing, expired or revoked sessions as `UNAUTHORIZED` 401;
- never accept an `openid` as a session credential;
- return an authenticated MiniApp session principal containing member/account identity only, not `session_key`.

### 6.3 Encrypted profile data

`MiniAppUserDataService::decryptLegacyProfile(...)`:

- require an authenticated MiniApp session;
- load/decrypt its protected `session_key` internally;
- require `rawData`, signature, `encryptedData`, and IV for this legacy-compatible command;
- verify `sha1(rawData . session_key)` with `hash_equals`;
- AES-128-CBC decrypt and PKCS#7 validate;
- parse JSON;
- require `watermark.appid == MiniAppProviderAccount.providerAppId`;
- strip watermark before returning a typed profile DTO;
- openid/unionid from decrypted data may be cross-checked but must never replace the authenticated provider identity without an explicit identity-binding rule.

Plain client profile JSON without encrypted data is outside R8A identity trust and is not accepted as authentication evidence.

## 7. Provider adapters

### 7.1 Manual MiniApp

`WechatMiniAppCodeExchangeClient` resolves appsecret by `credentialRef` and calls the normal `jscode2session` endpoint.

Provider errors are normalized:

- invalid/used code -> `UNAUTHORIZED` 401;
- account/credential configuration missing -> `FORBIDDEN` or `INTERNAL_ERROR` according to whether the account is disabled/misconfigured;
- network/provider availability failure -> stable `INTERNAL_ERROR` 502 at the HTTP adapter boundary;
- malformed provider response -> `INTERNAL_ERROR`, never PHP warning/notice.

Neither code nor appsecret is logged.

### 7.2 Component-authorized MiniApp

The same client uses the component `jscode2session` endpoint when `connectionMode=component_authorized`.

It depends only on `ComponentAccessTokenProvider`. R8A defines and tests the port with fakes. Production component-ticket/token acquisition is deliberately deferred to R8B. Until R8B is wired, component mode must fail closed with a stable configuration/provider error rather than silently falling back to manual credentials.

## 8. Persistence model

R8A migration `_006_miniapp_identity_session` adds two tables.

### 8.1 `miniapp_provider_accounts`

Purpose: public provider identity/configuration, not raw secrets.

Required columns:

- `account_id` PK/FK -> `accounts.id`
- `tenant_id` FK -> `tenants.id`
- `provider_type`
- `provider_app_id`
- `connection_mode`
- `credential_ref` nullable
- `component_platform_id` nullable
- timestamps

Required constraints:

- unique `(provider_type, provider_app_id)`;
- index `(tenant_id, account_id)`;
- row must be validated by domain/application rules for mode-specific nullable fields.

Raw appsecret is never persisted in this table.

### 8.2 `miniapp_sessions`

Required columns:

- `id` PK
- `tenant_id` FK
- `account_id` FK
- `member_id` FK
- `external_identity_id` FK
- `token_hash` char(64), unique
- `session_key_ciphertext` text/blob
- `session_key_key_version`
- `issued_at`
- `expires_at`
- `revoked_at` nullable
- timestamps as needed

Required indexes:

- unique token hash;
- `(tenant_id, account_id, expires_at)`;
- `(member_id, account_id)`.

The ciphertext must be produced by `MiniAppSessionSecretProtector`; tests must prove plaintext `session_key` is not written to repository rows or audit metadata.

## 9. Secret protection

R8A must not invent a database-stored master key.

The production protector uses an externally supplied key reference/version and authenticated encryption. Preferred implementation is libsodium `secretbox`/equivalent available in the PHP runtime. Configuration supplies the active key outside repository data.

Rules:

- no plaintext `session_key` in logs, exceptions, audit events, DTO serialization, or database rows;
- ciphertext is bound to key version so rotation is possible;
- decrypt failures are stable `UNAUTHORIZED`/`INTERNAL_ERROR` according to context, never partial user data;
- application/domain code sees `SecretValue` or an opaque protector result, not a raw persisted secret string.

## 10. R20 compatibility adapter

`R20MiniAppProviderAccountRepository` is read-only and uses existing `LegacyDatabase`.

It maps verified R20 fields from `account_wxapp`/account metadata into `MiniAppProviderAccount` while preserving:

- legacy `uniacid`/acid mapping through existing `LegacyAccountMapping`;
- MiniApp appid (`key`);
- manual vs authorized connection mode;
- manual secret only through a legacy credential resolver boundary;
- OpenPlatform-authorized accounts without pretending they own a manual appsecret.

R20 tables remain read-only.

No adapter may implement the old direct-openid session restoration path.

## 11. Session lifetime and replay policy

Default R8A MiniApp session TTL is **30 minutes**, configurable by application config.

Reasoning:

- it is a new server-side credential, not R20's generic PHP session id;
- short lifetime limits exposure of the cached/decryptable WeChat `session_key`;
- clients can obtain a new one through a fresh WeChat login code.

Multiple active sessions for the same member/account are allowed to preserve multi-device behavior. Explicit revoke revokes only the named session unless a later account/member-wide revoke use case is added.

Login code replay is ultimately rejected by WeChat; R8A does not create a local code cache containing raw codes.

## 12. Errors

Stable error outcomes:

| Condition | Error |
| --- | --- |
| empty/invalid login command | `INVALID_ARGUMENT` 400 |
| invalid/used WeChat login code | `UNAUTHORIZED` 401 |
| session missing/expired/revoked | `UNAUTHORIZED` 401 |
| tenant/account mismatch | `FORBIDDEN` 403 |
| account is not WeChat Mini Program | `FORBIDDEN` 403 |
| provider account disabled/missing binding | `FORBIDDEN` 403 |
| watermark appid mismatch | `FORBIDDEN` 403 |
| rawData signature mismatch | `UNAUTHORIZED` 401 |
| provider/network malformed/unavailable | normalized infrastructure failure, HTTP adapter maps to 502/internal envelope |
| session-key decrypt/protector configuration failure | fail closed; never expose plaintext or provider response |

No failure path depends on PHP warnings/notices.

## 13. Audit

R8A emits audit records for security-relevant outcomes:

- `miniapp.login.success`
- `miniapp.login.failure`
- `miniapp.session.revoke`
- `miniapp.userdata.decrypt.success`
- `miniapp.userdata.decrypt.failure`

Before identity resolution, actor id uses a stable anonymous MiniApp actor label; after identity resolution it uses the member id.

Every audit event contains:

- actor
- tenant
- account
- action
- result
- request_id / trace_id

Metadata may include provider type, provider appid hash, member id, external identity id and error classification. It must not include raw code, appsecret, component access token, session token, `session_key`, encrypted-data plaintext or raw decrypted profile.

## 14. Test strategy

R8A is high-risk authentication work and must include negative/cross-tenant cases.

### Unit

- `MiniAppProviderAccountTest`
- `MiniAppSessionTest`
- `MiniAppSessionTokenHasherTest`
- `MiniAppEncryptedDataDecryptorTest`

Prove mode invariants, TTL/revoke semantics, signature comparison, AES/PKCS7 behavior, watermark appid mismatch, and secret redaction.

### Component

- `MiniAppLoginServiceTest`
  - successful manual login;
  - same openid under two provider accounts resolves to different external identities;
  - tenant/account mismatch;
  - invalid provider code;
  - remote exchange outside DB transaction;
  - identity + session persistence inside one transaction;
  - plaintext session key never persisted/logged.
- `MiniAppSessionServiceTest`
  - valid, expired, revoked, wrong-tenant, wrong-account token cases.
- `MiniAppUserDataServiceTest`
  - valid legacy encrypted profile;
  - bad signature;
  - bad padding/ciphertext;
  - watermark appid mismatch.

### Golden Master

- `R20MiniAppProviderAccountSnapshotTest`
  - manual MiniApp mapping;
  - authorized MiniApp mapping;
  - keep `uniacid`, appid, account type and connection mode distinct.

### Contract

- `_006` schema/rollback contract;
- ThinkPHP persistence source contract for transaction boundary and unique token hash;
- architecture/security contract extending the R7 rule to R8A domain/application;
- no direct-openid authentication endpoint/command.

### CI gate

The exact tested R8A head must pass:

1. `composer validate --strict`
2. `composer install`
3. unified offline runner
4. PHPUnit bridge
5. PHP lint
6. multi-app HTTP smoke
7. R8A architecture/secret scan

Release flow remains: branch PR CI -> verify `main` has not moved incompatibly -> non-force fast-forward exact tested SHA -> main push CI.

## 15. Proposed implementation boundary

Expected new areas after this spec is approved and converted to an implementation plan:

```text
app/miniapp/domain/
app/miniapp/contract/
app/miniapp/application/
app/miniapp/infrastructure/
app/miniapp/compat/
database/migrations/20260908_006_miniapp_identity_session_{up,down}.sql
tests/Unit/MiniApp/
tests/Component/MiniApp/
tests/GoldenMaster/R20MiniAppProviderAccountSnapshotTest.php
tests/Contract/MiniAppIdentitySessionSchemaContractTest.php
```

R7 `app/member` is reused, not duplicated.

## 16. Explicit non-goals for R8A

Deferred to R8B/R8C or later slices:

- production ingestion/validation/storage of `component_verify_ticket`;
- component access-token caching/rotation;
- authorizer refresh/access-token lifecycle;
- authorization callback and account creation/update transaction;
- fast-register MiniApp;
- code upload/review/release;
- arbitrary MiniApp module reply processors;
- payment/refund/fulfillment;
- long-lived refresh-token style user sessions;
- client-supplied openid login compatibility.

## 17. Definition of Done

R8A is complete only when:

- all R8A acceptance behavior above is executable in tests;
- manual MiniApp login is production-wired;
- component-authorized login is correctly modeled and can use the injected component-token port, but fails closed until R8B provides that port's production trust-chain implementation;
- Member/ExternalIdentity remains provider-account scoped;
- no plaintext MiniApp secret/session key is persisted or logged;
- R20 adapter is read-only;
- branch PR CI and final `main` push CI both pass on the same exact release SHA.
