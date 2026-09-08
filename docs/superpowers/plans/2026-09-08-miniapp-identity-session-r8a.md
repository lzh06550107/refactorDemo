# R8A MiniApp Identity Session Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the MiniApp end-user identity/session slice of ST-07-04 with provider-account-scoped identities, secure server-side MiniApp sessions, R20-compatible `jscode2session` results, and no client-supplied-openid authentication path.

**Architecture:** `MiniAppLoginService` resolves an exact Tenant+Account provider configuration, performs WeChat code exchange outside the database transaction, then atomically reuses R7 `MemberIdentityService` and persists a short-lived `MiniAppSession`. Raw `session_key` never enters Member/ExternalIdentity or the client response; it is protected by a dedicated cipher port and stored only as ciphertext + key version. Encrypted user data is decrypted through a dedicated service that first authenticates the server session and then validates R20-compatible signature/watermark rules.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, MySQL 8 / InnoDB / utf8mb4, existing R7 Member identity layer, SHA-256 opaque-token hashes, OpenSSL AES-256-GCM infrastructure cipher.

**Spec:** `docs/superpowers/specs/2026-09-08-miniapp-identity-session-r8a-design.md`

## Global Constraints

- Base implementation is `main` at `e99c3f57161748031c7a83ae08b0a765b75f0569`.
- Only `AccountType::WECHAT_MINI_PROGRAM` is valid for this slice.
- Provider identity key remains `providerType + providerAccountId + externalSubject`; never global `openid`.
- Client-supplied `openid` must not establish or restore authenticated state.
- Login token is 256-bit opaque random data; only SHA-256 token hash is persisted.
- Default MiniApp session TTL is exactly 1800 seconds.
- Raw `session_key` must never be logged, audited, returned, or stored plaintext.
- `jscode2session` external I/O happens before the final database transaction.
- Member/ExternalIdentity resolution and MiniAppSession insert happen inside the same final transaction.
- R20 `watermark.appid` and legacy `sha1(rawData + session_key)` validation are MUST_COMPAT for encrypted-profile processing.
- Domain/Application code must not depend on ThinkPHP Facades, `$_W`, `$_GPC`, or `pdo_*` helpers.
- R8B/R8C OpenPlatform ticket/token/authorizer lifecycle is out of scope.

---

### Task 1: Provider account and session domain + schema

**Files:**
- Create: `app/miniapp/domain/MiniAppConnectionMode.php`
- Create: `app/miniapp/domain/MiniAppProviderAccount.php`
- Create: `app/miniapp/domain/MiniAppCodeSession.php`
- Create: `app/miniapp/domain/MiniAppSession.php`
- Create: `app/miniapp/domain/ProtectedSessionKey.php`
- Create: `database/migrations/20260908_006_miniapp_identity_session_up.sql`
- Create: `database/migrations/20260908_006_miniapp_identity_session_down.sql`
- Test: `tests/Unit/MiniApp/MiniAppProviderAccountTest.php`
- Test: `tests/Unit/MiniApp/MiniAppSessionTest.php`
- Test: `tests/Contract/MiniAppIdentitySessionSchemaContractTest.php`

**Interfaces:**
- `MiniAppProviderAccount(string $tenantId, string $accountId, string $providerAppId, MiniAppConnectionMode $mode, ?string $credentialRef, ?string $componentPlatformId)`
- Manual mode requires `credentialRef` and forbids `componentPlatformId`.
- Component mode requires `componentPlatformId`; `credentialRef` is null.
- `MiniAppSession::issue(...)` accepts an already-hashed client token and protected session key and sets `expiresAt = issuedAt + 1800 seconds`.
- `MiniAppSession::isActiveAt(DateTimeImmutable $at): bool` is false after expiry or revocation.

- [ ] **Step 1: Write failing tests** for mode invariants, exact 1800-second TTL, revoke/expiry behavior, and schema keys.
- [ ] **Step 2: Run test-only PR CI.** Expected failure: missing R8A classes and `_006` migration; all prior 62 runner entries remain green.
- [ ] **Step 3: Implement minimal domain + migration.** Schema creates `miniapp_provider_accounts` and `miniapp_sessions`; unique provider app id is tenant-scoped, `token_hash` is globally unique, and foreign keys target `tenants`, `accounts`, `members`, and `external_identities`.
- [ ] **Step 4: Run the same CI.** Expected: Task 1 tests green plus prior suite.
- [ ] **Step 5: Commit** `feat: add MiniApp provider and secure session domain`.

### Task 2: R20 MiniApp provider snapshot

**Files:**
- Create: `app/miniapp/compat/LegacyMiniAppProviderSnapshot.php`
- Create: `app/miniapp/compat/R20MiniAppProviderSnapshotRepository.php`
- Test: `tests/GoldenMaster/R20MiniAppProviderSnapshotTest.php`

**Interfaces:**
- `R20MiniAppProviderSnapshotRepository(LegacyDatabase $db)` is read-only.
- `forUniacid(int $uniacid): ?LegacyMiniAppProviderSnapshot` preserves `uniacid`, MiniApp `acid`, `appid`, account mode, and OpenPlatform component relation independently.
- R20 secret/token values are represented only by presence/reference metadata in the snapshot test; the adapter must not expose them through `toArray()` or diagnostics.

- [ ] **Step 1: Write a failing Golden Master** with manual and platform-authorized rows.
- [ ] **Step 2: Run CI.** Expected failure only because snapshot classes are missing.
- [ ] **Step 3: Implement read-only adapter** using only `LegacyDatabase::fetchOne/fetchAll`.
- [ ] **Step 4: Re-run CI** and require Golden Master green.
- [ ] **Step 5: Commit** `feat: add R20 MiniApp provider snapshot`.

### Task 3: Code exchange and provider isolation

**Files:**
- Create: `app/miniapp/contract/MiniAppProviderAccountRepository.php`
- Create: `app/miniapp/contract/MiniAppCodeExchangeClient.php`
- Create: `app/miniapp/contract/ComponentAccessTokenProvider.php`
- Create: `app/miniapp/contract/MiniAppHttpTransport.php`
- Create: `app/miniapp/infrastructure/WechatMiniAppCodeExchangeClient.php`
- Create: `app/miniapp/infrastructure/NativeMiniAppHttpTransport.php`
- Test: `tests/Unit/MiniApp/WechatMiniAppCodeExchangeClientTest.php`

**Interfaces:**
- `MiniAppProviderAccountRepository::findForTenantAccount(string $tenantId, string $accountId): ?MiniAppProviderAccount`.
- `MiniAppCodeExchangeClient::exchange(MiniAppProviderAccount $provider, string $code): MiniAppCodeSession`.
- Manual exchange sends `appid`, resolved secret, `js_code`, `grant_type=authorization_code`.
- Component exchange sends `appid`, `js_code`, `grant_type=authorization_code`, `component_appid`, `component_access_token` from `ComponentAccessTokenProvider`.
- WeChat error response with non-zero `errcode`, missing `openid`, or missing `session_key` maps to stable `UNAUTHORIZED / 401`; provider configuration mismatch maps to `FORBIDDEN / 403`.

- [ ] **Step 1: Write failing unit tests** for manual/component query construction and provider mismatch/error normalization.
- [ ] **Step 2: Run CI** and verify missing client classes are the only new failures.
- [ ] **Step 3: Implement client + transport ports**; no network call appears in Domain/Application.
- [ ] **Step 4: Run tests** and require both modes green.
- [ ] **Step 5: Commit** `feat: add MiniApp code exchange client`.

### Task 4: Atomic MiniApp login and secure token issuance

**Files:**
- Create: `app/miniapp/contract/MiniAppSessionRepository.php`
- Create: `app/miniapp/contract/SessionKeyCipher.php`
- Create: `app/miniapp/application/MiniAppLoginService.php`
- Create: `app/miniapp/domain/MiniAppLoginResult.php`
- Test: `tests/Component/MiniApp/MiniAppLoginServiceTest.php`

**Interfaces:**
- `SessionKeyCipher::protect(string $sessionKey): ProtectedSessionKey` and `reveal(ProtectedSessionKey $protected): string`.
- `MiniAppSessionRepository::insert(MiniAppSession $session): void`.
- `MiniAppSessionRepository::findByTokenHash(string $tokenHash): ?MiniAppSession`.
- `MiniAppLoginService::login(RequestContext $context, string $code, DateTimeImmutable $now): MiniAppLoginResult`.
- Login requires context tenant/account, non-empty code, and exact provider config.
- External `exchange()` happens before `TransactionManager::run()`.
- Inside one transaction: `MemberIdentityService::resolveOrCreateWithinTransaction(...)`, protect `session_key`, insert session, emit success audit.
- Returned DTO contains `sessionToken`, `memberId`, `externalIdentityId`, `expiresAt`; never `openid`, `session_key`, secret, ciphertext, or credential ref.
- Audit action is `miniapp.login`; metadata contains provider type/app id only, never session token/session key.

- [ ] **Step 1: Write failing component tests** proving transaction ordering, provider-account identity scope, 256-bit token entropy/64-char hash-at-rest, 1800-second expiry, and no secret leakage.
- [ ] **Step 2: Run CI** and verify failure is only missing application/session contracts.
- [ ] **Step 3: Implement minimal service** using R7 MemberIdentityService and TransactionManager.
- [ ] **Step 4: Re-run CI** and require component test green.
- [ ] **Step 5: Commit** `feat: add atomic MiniApp login session issuance`.

### Task 5: Encrypted user data and authenticated session restore

**Files:**
- Create: `app/miniapp/application/MiniAppSessionService.php`
- Create: `app/miniapp/application/MiniAppEncryptedDataService.php`
- Create: `app/miniapp/domain/MiniAppEncryptedProfile.php`
- Create: `app/miniapp/contract/MiniAppDataDecryptor.php`
- Create: `app/miniapp/infrastructure/OpenSslMiniAppDataDecryptor.php`
- Test: `tests/Component/MiniApp/MiniAppSessionServiceTest.php`
- Test: `tests/Unit/MiniApp/MiniAppEncryptedDataServiceTest.php`

**Interfaces:**
- Session restore accepts only the opaque session token, hashes it, loads the session, and rejects missing/expired/revoked sessions with `UNAUTHORIZED / 401`.
- No API accepts a client `openid` as authentication evidence.
- `MiniAppEncryptedDataService::decryptProfile(...)` requires an active server session, reveals `session_key` only in memory, validates optional legacy `sha1(rawData + session_key)` using `hash_equals`, decrypts AES payload, and requires `watermark.appid === MiniAppProviderAccount::providerAppId()`.
- Signature mismatch, decrypt failure, malformed JSON, or watermark mismatch returns `UNAUTHORIZED / 401` and never emits raw plaintext/session key in the error.

- [ ] **Step 1: Write failing tests** covering active/expired/revoked restore, signature pass/fail, watermark pass/fail, malformed ciphertext, and direct-openid absence contract.
- [ ] **Step 2: Run CI** and confirm precise RED failures.
- [ ] **Step 3: Implement session/decrypt services** and OpenSSL decryptor.
- [ ] **Step 4: Run CI** and require all R8A application tests green.
- [ ] **Step 5: Commit** `feat: add authenticated MiniApp encrypted data flow`.

### Task 6: ThinkPHP persistence, encryption, compatibility docs, and release gate

**Files:**
- Create: `app/miniapp/infrastructure/ThinkPhpMiniAppProviderAccountRepository.php`
- Create: `app/miniapp/infrastructure/ThinkPhpMiniAppSessionRepository.php`
- Create: `app/miniapp/infrastructure/OpenSslSessionKeyCipher.php`
- Test: `tests/Contract/ThinkPhpMiniAppPersistenceContractTest.php`
- Test: `tests/Contract/R8AMiniAppArchitectureSecurityContractTest.php`
- Modify: `tests/run.php`
- Modify: `README.md`
- Create: `docs/migration/r20-miniapp-identity-session-compatibility.md`
- Create: `docs/verification/miniapp-identity-session-r8a.md`

**Interfaces:**
- Provider repository query always includes both tenant id and account id and returns no cross-tenant fallback.
- Session repository only stores `token_hash` and protected session-key fields; no raw token/session key columns exist.
- `OpenSslSessionKeyCipher` uses AES-256-GCM with random 96-bit IV, authenticates ciphertext, supports explicit key version, and takes keys by constructor/configuration rather than hard-coding secrets.
- Release contract scans `app/miniapp/domain` and `app/miniapp/application` for ThinkPHP Facades/legacy globals and scans R8A source/migrations/docs for committed private keys or obvious credentials.
- Unified runner grows from 62 by the exact number of R8A tests; all prior entries remain.

- [ ] **Step 1: Write failing persistence/security contract tests.**
- [ ] **Step 2: Run CI** and confirm missing adapters are the only new failures.
- [ ] **Step 3: Implement ThinkPHP repositories + AES-GCM cipher and update docs/runner/README.**
- [ ] **Step 4: Run full branch CI:** `composer validate --strict`, install, unified offline suite, PHPUnit bridge, PHP lint, `/health`, `/admin/health`, `/api/v1/health` smoke.
- [ ] **Step 5: Mark PR ready only when branch CI is fully green.** Re-read `main`, require branch `behind_by=0`, then non-force fast-forward the exact tested R8A head to `main`.
- [ ] **Step 6: Require the `main` push CI to be fully green again** before declaring R8A complete.

## Definition of Done

R8A is complete only when the exact candidate commit has passed branch/PR CI, `main` has been updated with `force=false`, and the same `main` SHA has passed push CI. The release must contain no client-openid authentication route, no plaintext `session_key`, no provider-account-global identity lookup, and no OpenPlatform ticket/token implementation that belongs to R8B/R8C.
