# R8C OpenPlatform Authorizer Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the released R8B Component Platform trust chain into a secure WeChat OpenPlatform authorizer lifecycle covering pre-auth creation, callback/event completion arbitration, authorizer grant persistence, refresh/access-token rotation, unified event ingress, and binding to an existing MiniApp Account.

**Architecture:** Keep `ComponentPlatform` and every authorizer credential platform-scoped. Add a central `(componentPlatformId, authorizerAppId)` authorization registry, a one-time `AuthorizationIntent` with hashed state/pre-auth correlation and a 30-second completion claim, one authenticated `/events` ingress, and a DB-backed authorizer token refresh lease/CAS path modeled on R8B. Tenant/Account state appears only at the explicit MiniApp binding adapter; R8C never creates Tenant or Account rows.

**Tech Stack:** PHP 8.2+, ThinkPHP 8, MySQL/InnoDB, AES-256-GCM through existing `OpenPlatformSecretCipher`, native HTTP transport, offline `tests/run.php` contract harness, PHPUnit bridge, GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-09-08-openplatform-authorizer-lifecycle-r8c-design.md`

## Global Constraints

- Base implementation branch: `refactor/openplatform-authorizer-lifecycle-r8c`, created from released `main@2f2dfcbe893b7e713caf2b2a952838b6fc24e141`.
- Preserve R8B `/api/v1/openplatform/components/{componentPlatformId}/ticket` semantics and all prior tests.
- Canonical authorizer identity is `(component_platform_id, authorizer_app_id)`; never key authorizer credentials by AppId alone.
- Unified callback body limit is 128 KiB; callback freshness is ±300 seconds.
- Raw callback XML, decrypted XML, component secrets/tickets/tokens, `pre_auth_code`, `authorization_code`, callback state, authorizer refresh/access tokens must never appear in logs, audit metadata, exception messages, public DTO serialization, or database plaintext columns.
- Callback state is 32 random bytes; persist only SHA-256 state hash.
- Persist only SHA-256 of `pre_auth_code`; never persist plaintext pre-auth code or authorization code.
- AuthorizationIntent local TTL is 600 seconds; completion claim TTL is 30 seconds.
- Authorizer access-token refresh skew is 300 seconds; refresh lease TTL is 30 seconds.
- Provider `expires_in` must be a positive JSON integer; numeric strings are rejected.
- Network I/O never executes inside a database transaction.
- Refresh-token rotation and authorizer access-token replacement commit atomically under lease-holder + expected-version CAS.
- `unauthorized` clears every usable authorizer credential and blocks token access immediately.
- Older lifecycle events never overwrite newer provider state.
- Domain/application code must not import ThinkPHP facades, `Db`, `$_W`, `$_GPC`, or legacy PDO helpers.
- R8C may create/update `miniapp_provider_accounts` for an existing eligible Account but must never create Tenant or Account rows.
- Release gate: old suite + R8C unit/component/contract tests + Composer validate/install + PHPUnit + PHP lint + HTTP smoke + branch CI + non-force fast-forward `main` + exact main push CI.

---

## File Structure Locked By This Plan

### OpenPlatform domain
- `app/openplatform/domain/AuthorizationIntent.php` — hashed local authorization state, provider pre-auth expiry, completion claim and completed-authorizer metadata.
- `app/openplatform/domain/AuthorizerAuthorization.php` — platform-scoped grant metadata and ordering/version invariants; no plaintext credential serialization.
- `app/openplatform/domain/AuthorizerAccessToken.php` — short-lived authorizer token value plus expiry/version used only inside OpenPlatform application/infrastructure.
- `app/openplatform/domain/AuthorizerTokenRefreshLease.php` — holder/expiry/version value object.
- `app/openplatform/domain/AuthenticatedComponentEvent.php` — authenticated, typed provider event after signature/AES/AppId checks.
- `app/openplatform/domain/PreAuthCodeResponse.php` — provider pre-auth code + strict integer expiry.
- `app/openplatform/domain/AuthorizerAuthorizationResponse.php` — query-auth result carrying authorizer AppId, initial access token, refresh token, expiry and normalized scope.
- `app/openplatform/domain/AuthorizerRefreshResponse.php` — refresh result carrying access token, optional rotated refresh token and expiry.

### OpenPlatform contracts
- `app/openplatform/contract/AuthorizationIntentRepository.php`
- `app/openplatform/contract/AuthorizerAuthorizationRepository.php`
- `app/openplatform/contract/AuthorizerTokenRepository.php`
- `app/openplatform/contract/AuthorizerRefreshLeaseRepository.php`
- `app/openplatform/contract/AuthorizerClient.php`
- `app/openplatform/contract/AuthorizerAccountBinding.php`
- `app/openplatform/contract/ComponentEventInboxRepository.php`

### OpenPlatform application/security/infrastructure
- `app/openplatform/security/WechatComponentCallbackAuthenticator.php`
- `app/openplatform/application/OpenPlatformEventService.php`
- `app/openplatform/application/AuthorizationStartService.php`
- `app/openplatform/application/AuthorizationCompletionService.php`
- `app/openplatform/application/AuthorizationCallbackService.php`
- `app/openplatform/application/AuthorizationEventService.php`
- `app/openplatform/application/AuthorizerAccessTokenService.php`
- `app/openplatform/infrastructure/WechatAuthorizerClient.php`
- `app/openplatform/infrastructure/ThinkPhpAuthorizationIntentRepository.php`
- `app/openplatform/infrastructure/ThinkPhpAuthorizerAuthorizationRepository.php`
- `app/openplatform/infrastructure/ThinkPhpAuthorizerTokenRepository.php`
- `app/openplatform/infrastructure/ThinkPhpAuthorizerRefreshLeaseRepository.php`
- `app/openplatform/infrastructure/ThinkPhpComponentEventInboxRepository.php`

### MiniApp/API integration
- `app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php`
- `app/api/controller/V1/OpenPlatformEventController.php`
- `app/api/controller/V1/OpenPlatformAuthorizationStartController.php`
- `app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php`
- `app/api/controller/V1/OpenPlatformTicketController.php` — modify only to delegate through the unified event service while preserving response semantics.
- `app/api/route/app.php` — add `/events`, authorization-intents and browser callback routes; preserve `/ticket`.

### Persistence/docs/tests
- `database/migrations/20260908_008_openplatform_authorizer_lifecycle_up.sql`
- `database/migrations/20260908_008_openplatform_authorizer_lifecycle_down.sql`
- `docs/migration/r20-openplatform-authorizer-lifecycle-r8c.md`
- `docs/verification/openplatform-authorizer-lifecycle-r8c.md`
- `README.md`
- `tests/run.php`

---

### Task 1: Schema, Domain Objects, and Repository Contracts

**Files:**
- Create: `database/migrations/20260908_008_openplatform_authorizer_lifecycle_up.sql`
- Create: `database/migrations/20260908_008_openplatform_authorizer_lifecycle_down.sql`
- Create: `app/openplatform/domain/AuthorizationIntent.php`
- Create: `app/openplatform/domain/AuthorizerAuthorization.php`
- Create: `app/openplatform/domain/AuthorizerAccessToken.php`
- Create: `app/openplatform/domain/AuthorizerTokenRefreshLease.php`
- Create: `app/openplatform/domain/PreAuthCodeResponse.php`
- Create: `app/openplatform/domain/AuthorizerAuthorizationResponse.php`
- Create: `app/openplatform/domain/AuthorizerRefreshResponse.php`
- Create: `app/openplatform/contract/AuthorizationIntentRepository.php`
- Create: `app/openplatform/contract/AuthorizerAuthorizationRepository.php`
- Create: `app/openplatform/contract/AuthorizerTokenRepository.php`
- Create: `app/openplatform/contract/AuthorizerRefreshLeaseRepository.php`
- Test: `tests/Contract/OpenPlatformAuthorizerLifecycleSchemaContractTest.php`
- Test: `tests/Unit/OpenPlatform/AuthorizationIntentTest.php`
- Test: `tests/Unit/OpenPlatform/AuthorizerAuthorizationTest.php`

**Interfaces:**
- Produces:
```php
interface AuthorizationIntentRepository
{
    public function insert(AuthorizationIntent $intent): void;
    public function findByStateHash(string $stateHash): ?AuthorizationIntent;
    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent;
    public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent;
    public function releaseClaim(string $intentId, string $holderId): void;
    public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool;
}

interface AuthorizerAuthorizationRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization;
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool;
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool;
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool;
}

interface AuthorizerTokenRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccessToken;
}

interface AuthorizerRefreshLeaseRepository
{
    public function tryAcquire(string $componentPlatformId, string $authorizerAppId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?AuthorizerTokenRefreshLease;
    public function release(string $componentPlatformId, string $authorizerAppId, string $holderId): void;
}
```

- [ ] **Step 1: Write failing schema/domain tests**

The schema contract must assert all four tables, composite keys/FKs, unique `(component_platform_id, pre_auth_code_hash)`, no plaintext secret columns, and rollback order. Domain tests must assert intent expiry/claim/completion invariants and authorizer status/order invariants.

```php
$intent = AuthorizationIntent::pending(
    'intent-1', 'platform-1', 'tenant-1', 'account-1',
    hash('sha256', 'state'), hash('sha256', 'pre-auth'), '1',
    $now, $now->modify('+10 minutes'), $now->modify('+10 minutes')
);
assert($intent->claimableAt($now));
assert(!$intent->completed());

$authorization = AuthorizerAuthorization::active(
    'platform-1', 'wx-authorizer', hash('sha256', 'refresh'), ['17'],
    $now, $now, $now, 1
);
assert($authorization->active());
```

- [ ] **Step 2: Run focused tests and verify RED**

Run:
```bash
php tests/Contract/OpenPlatformAuthorizerLifecycleSchemaContractTest.php
php tests/Unit/OpenPlatform/AuthorizationIntentTest.php
php tests/Unit/OpenPlatform/AuthorizerAuthorizationTest.php
```
Expected: FAIL because `_008` migration and R8C domain classes/contracts do not exist.

- [ ] **Step 3: Add migration and minimal domain/contracts**

Create exactly these tables:
```text
openplatform_authorization_intents
authorizer_authorizations
authorizer_access_tokens
authorizer_token_refresh_leases
```
Use the exact columns from the approved spec, with FKs to `component_platforms`, `tenants`, and `accounts`, and composite authorizer FKs from access-token/lease rows to `authorizer_authorizations`.

`AuthorizationIntent` must expose immutable metadata and helpers `effectiveExpiresAt()`, `validAt()`, `claimableAt()`, `completed()`, `version()` without exposing plaintext state/pre-auth code.

`AuthorizerAuthorization` must permit only `active` or `unauthorized`; `active` requires a refresh-token hash, `unauthorized` requires it to be null.

- [ ] **Step 4: Run focused tests and full offline suite**

Run:
```bash
php tests/Contract/OpenPlatformAuthorizerLifecycleSchemaContractTest.php
php tests/Unit/OpenPlatform/AuthorizationIntentTest.php
php tests/Unit/OpenPlatform/AuthorizerAuthorizationTest.php
php tests/run.php
```
Expected: PASS.

- [ ] **Step 5: Commit Task 1**

```bash
git add database/migrations/20260908_008_openplatform_authorizer_lifecycle_* app/openplatform/domain app/openplatform/contract tests/Contract/OpenPlatformAuthorizerLifecycleSchemaContractTest.php tests/Unit/OpenPlatform/AuthorizationIntentTest.php tests/Unit/OpenPlatform/AuthorizerAuthorizationTest.php tests/run.php
git commit -m "feat: add R8C authorizer lifecycle domain schema"
```

---

### Task 2: Unified Authenticated Component Event Ingress

**Files:**
- Create: `app/openplatform/domain/AuthenticatedComponentEvent.php`
- Create: `app/openplatform/contract/ComponentEventInboxRepository.php`
- Create: `app/openplatform/security/WechatComponentCallbackAuthenticator.php`
- Create: `app/openplatform/application/OpenPlatformEventService.php`
- Modify: `app/openplatform/security/WechatComponentEnvelopeParser.php`
- Modify: `app/openplatform/application/ComponentTicketService.php`
- Test: `tests/Unit/OpenPlatform/WechatComponentCallbackAuthenticatorTest.php`
- Test: `tests/Component/OpenPlatform/OpenPlatformEventServiceTest.php`
- Test: `tests/Component/OpenPlatform/ComponentTicketCompatibilityTest.php`

**Interfaces:**
- Consumes existing `ComponentPlatformRepository`, `ComponentCredentialProvider`, `WechatComponentSignatureVerifier`, `WechatComponentMessageDecryptor`, `ComponentTicketRepository`.
- Produces:
```php
final readonly class WechatComponentCallbackAuthenticator
{
    public function authenticate(
        string $componentPlatformId,
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $msgSignature,
        DateTimeImmutable $now,
    ): AuthenticatedComponentEvent;
}

interface ComponentEventInboxRepository
{
    public function accept(string $componentPlatformId, string $replayKey, string $payloadHash, string $infoType, DateTimeImmutable $sourceTimestamp, DateTimeImmutable $receivedAt): bool;
}
```
`accept()` returns `true` for first delivery and `false` for exact duplicate; same replay identity/different payload throws `CONFLICT / 409`.

- [ ] **Step 1: Write failing authenticator/event tests**

Cover:
- valid `component_verify_ticket`, `authorized`, `updateauthorized`, `unauthorized` typed parsing;
- malformed/unknown InfoType -> 400;
- invalid signature/stale timestamp -> 401 before dispatch;
- framed/inner AppId mismatch -> 403;
- exact replay -> idempotent success;
- same replay key/different payload -> 409;
- old `/ticket` behavior still accepts valid ticket and rejects non-ticket callback.

- [ ] **Step 2: Run tests and verify RED**

Run:
```bash
php tests/Unit/OpenPlatform/WechatComponentCallbackAuthenticatorTest.php
php tests/Component/OpenPlatform/OpenPlatformEventServiceTest.php
php tests/Component/OpenPlatform/ComponentTicketCompatibilityTest.php
```
Expected: FAIL because unified authenticator/event types do not exist.

- [ ] **Step 3: Implement one authentication pipeline**

`WechatComponentCallbackAuthenticator` must perform exactly:
```text
platform lookup -> body size -> outer parse -> freshness -> signature -> replay/payload hashes -> AES decrypt -> framed AppId -> inner parse -> inner AppId -> typed event
```
Extend parser with a hardened generic inner-event parser; do not create alternate XML parsing code in event handlers.

`ComponentTicketService` must delegate authentication to the new authenticator but retain ticket-specific persistence/audit semantics. `/ticket` must reject authenticated non-ticket InfoTypes as `INVALID_ARGUMENT / 400`.

- [ ] **Step 4: Run focused tests plus all R8B tests**

Run:
```bash
php tests/Unit/OpenPlatform/WechatComponentCallbackAuthenticatorTest.php
php tests/Component/OpenPlatform/OpenPlatformEventServiceTest.php
php tests/Component/OpenPlatform/ComponentTicketCompatibilityTest.php
php tests/Component/OpenPlatform/ComponentTicketServiceTest.php
php tests/run.php
```
Expected: PASS.

- [ ] **Step 5: Commit Task 2**

```bash
git add app/openplatform/domain/AuthenticatedComponentEvent.php app/openplatform/contract/ComponentEventInboxRepository.php app/openplatform/security app/openplatform/application/ComponentTicketService.php app/openplatform/application/OpenPlatformEventService.php tests
git commit -m "feat: unify OpenPlatform component event authentication"
```

---

### Task 3: Pre-Auth Creation and AuthorizationIntent Completion Claim

**Files:**
- Create: `app/openplatform/contract/AuthorizerClient.php`
- Create: `app/openplatform/application/AuthorizationStartService.php`
- Create: `app/openplatform/infrastructure/WechatAuthorizerClient.php`
- Test: `tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php`
- Test: `tests/Component/OpenPlatform/AuthorizationStartServiceTest.php`
- Test: `tests/Component/OpenPlatform/AuthorizationIntentClaimTest.php`

**Interfaces:**
- Consumes `ComponentAccessTokenService`, `ComponentPlatformRepository`, `AuthorizationIntentRepository`.
- Produces:
```php
interface AuthorizerClient
{
    public function createPreAuthCode(string $componentAccessToken): PreAuthCodeResponse;
    public function queryAuthorization(string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse;
    public function refreshAuthorizerToken(string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse;
}

final readonly class AuthorizationStartService
{
    public function start(
        string $componentPlatformId,
        string $tenantId,
        string $targetAccountId,
        string $requestedAuthType,
        DateTimeImmutable $now,
    ): AuthorizationStartResult;
}
```
`AuthorizationStartResult` returns the provider authorization URL, opaque state, intent id, and effective local expiry; it must never expose a standalone pre-auth code field.

- [ ] **Step 1: Write strict provider/start RED tests**

Provider client tests must reject:
```php
['pre_auth_code' => 'code', 'expires_in' => '600']
```
and accept only positive integer expiry. Start-service tests must prove:
- state is 32 random bytes represented as opaque URL-safe/hex text;
- repository sees only `hash('sha256', $state)` and `hash('sha256', $preAuthCode)`;
- provider expiry can shorten the 600-second local TTL;
- redirect URI comes from service configuration, not arbitrary request input;
- no network call occurs inside repository transaction/claim operation.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php
php tests/Component/OpenPlatform/AuthorizationStartServiceTest.php
php tests/Component/OpenPlatform/AuthorizationIntentClaimTest.php
```
Expected: FAIL on missing client/service/claim semantics.

- [ ] **Step 3: Implement provider client and start flow**

Use endpoints:
```text
POST /cgi-bin/component/api_create_preauthcode?component_access_token=...
POST /cgi-bin/component/api_query_auth?component_access_token=...
POST /cgi-bin/component/api_authorizer_token?component_access_token=...
```
Sanitize every provider failure to `BAD_GATEWAY / 502` without embedding request or response secrets.

Authorization URL construction must use fixed configured redirect URI and the plaintext pre-auth code only in memory; after URL creation, plaintext pre-auth code is discarded.

- [ ] **Step 4: Run tests/full suite**

```bash
php tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php
php tests/Component/OpenPlatform/AuthorizationStartServiceTest.php
php tests/Component/OpenPlatform/AuthorizationIntentClaimTest.php
php tests/run.php
```
Expected: PASS.

- [ ] **Step 5: Commit Task 3**

```bash
git add app/openplatform/contract/AuthorizerClient.php app/openplatform/application/AuthorizationStartService.php app/openplatform/infrastructure/WechatAuthorizerClient.php app/openplatform/domain tests
git commit -m "feat: add OpenPlatform authorization intent start flow"
```

---

### Task 4: Authorization Completion Arbitration, Query-Auth, and Existing-Account Binding Port

**Files:**
- Create: `app/openplatform/contract/AuthorizerAccountBinding.php`
- Create: `app/openplatform/application/AuthorizationCompletionService.php`
- Create: `app/openplatform/application/AuthorizationCallbackService.php`
- Test: `tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php`
- Test: `tests/Component/OpenPlatform/AuthorizationCallbackServiceTest.php`

**Interfaces:**
- Consumes `AuthorizationIntentRepository`, `ComponentAccessTokenService`, `AuthorizerClient`, `AuthorizerAuthorizationRepository`, `AuthorizerAccountBinding`, `AuditLogger`.
- Produces:
```php
interface AuthorizerAccountBinding
{
    public function bindExistingAccount(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void;
}

final readonly class AuthorizationCompletionService
{
    public function completeIntent(
        AuthorizationIntent $intent,
        string $authorizationCode,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): AuthorizerAuthorizationResult;
}
}
```

- [ ] **Step 1: Write RED arbitration/completion tests**

Prove:
- browser state resolves only by SHA-256 hash;
- first channel obtains claim, second busy channel performs zero `queryAuthorization()` calls;
- completed intent returns same safe authorizer result without another provider exchange;
- expired intent -> 401;
- provider failure releases a still-valid claim but never persists code;
- query-auth response with missing/empty refresh/access token or numeric-string `expires_in` -> 502;
- transaction re-checks claim holder/version before saving;
- initial refresh/access tokens are protected by repository boundary and never appear in audit metadata;
- account binding receives exact `(tenantId, accountId, platformId, returned authorizerAppId)`.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php
php tests/Component/OpenPlatform/AuthorizationCallbackServiceTest.php
```
Expected: FAIL on missing completion services/binding contract.

- [ ] **Step 3: Implement completion flow**

Use this order:
```text
find/validate intent -> try 30s completion claim -> get component token -> query auth outside transaction -> validate response -> short CAS transaction: authorization + initial access token + binding + intent complete -> audit
```
Do not mark the intent completed before provider exchange and DB write both succeed.

Busy claim maps to a typed processing result for browser callback; it is not an error for provider event ingress.

- [ ] **Step 4: Run focused/full suite**

```bash
php tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php
php tests/Component/OpenPlatform/AuthorizationCallbackServiceTest.php
php tests/run.php
```
Expected: PASS.

- [ ] **Step 5: Commit Task 4**

```bash
git add app/openplatform/contract/AuthorizerAccountBinding.php app/openplatform/application/AuthorizationCompletionService.php app/openplatform/application/AuthorizationCallbackService.php tests
git commit -m "feat: complete OpenPlatform authorizer grants safely"
```

---

### Task 5: Authorized / UpdateAuthorized / Unauthorized Lifecycle Events and Ordering

**Files:**
- Create: `app/openplatform/application/AuthorizationEventService.php`
- Modify: `app/openplatform/application/OpenPlatformEventService.php`
- Test: `tests/Component/OpenPlatform/AuthorizationEventServiceTest.php`
- Test: `tests/Component/OpenPlatform/OpenPlatformEventOrderingTest.php`

**Interfaces:**
- Consumes `AuthorizationCompletionService`, `AuthorizationIntentRepository`, `ComponentAccessTokenService`, `AuthorizerClient`, `AuthorizerAuthorizationRepository`, `ComponentEventInboxRepository`.
- Produces:
```php
final readonly class AuthorizationEventService
{
    public function handle(
        AuthenticatedComponentEvent $event,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): void;
}
```

- [ ] **Step 1: Write RED lifecycle tests**

Cover:
- `authorized` + correlated `PreAuthCode` claims the same intent as browser callback;
- browser callback after event completion is idempotent with zero second query-auth call;
- authenticated `authorized` without local intent may create platform-level authorization but never binds a Tenant/Account;
- `updateauthorized` updates existing platform authorization and never creates Account binding;
- `unauthorized` clears refresh credential, deletes/invalidates access token and lease, status becomes unauthorized;
- token retrieval after unauthorized fails 403 immediately;
- older unauthorized/update event is stale no-op against newer authorization;
- same timestamp + conflicting normalized lifecycle result -> 409;
- exact event replay -> success/no second mutation.

- [ ] **Step 2: Run tests and verify RED**

```bash
php tests/Component/OpenPlatform/AuthorizationEventServiceTest.php
php tests/Component/OpenPlatform/OpenPlatformEventOrderingTest.php
```
Expected: FAIL until event lifecycle application logic exists.

- [ ] **Step 3: Implement event arbitration/order rules**

For correlated first `authorized`, call the same completion claim path as browser callback. For event-only `authorized` and every `updateauthorized`, exchange the event authorization code outside the DB transaction then apply source-timestamp ordering under repository CAS. `unauthorized` never calls provider API.

- [ ] **Step 4: Run R8B/R8C event suites**

```bash
php tests/Component/OpenPlatform/AuthorizationEventServiceTest.php
php tests/Component/OpenPlatform/OpenPlatformEventOrderingTest.php
php tests/Component/OpenPlatform/ComponentTicketServiceTest.php
php tests/run.php
```
Expected: PASS.

- [ ] **Step 5: Commit Task 5**

```bash
git add app/openplatform/application/AuthorizationEventService.php app/openplatform/application/OpenPlatformEventService.php tests
git commit -m "feat: handle OpenPlatform authorizer lifecycle events"
```

---

### Task 6: Authorizer Access-Token Refresh, Rotation, and Singleflight

**Files:**
- Create: `app/openplatform/application/AuthorizerAccessTokenService.php`
- Test: `tests/Component/OpenPlatform/AuthorizerAccessTokenServiceTest.php`
- Test: `tests/Component/OpenPlatform/AuthorizerAccessTokenIsolationTest.php`

**Interfaces:**
- Consumes `ComponentPlatformRepository`, `AuthorizerAuthorizationRepository`, `AuthorizerTokenRepository`, `AuthorizerRefreshLeaseRepository`, `ComponentAccessTokenService`, `AuthorizerClient`, `AuditLogger`.
- Produces:
```php
final readonly class AuthorizerAccessTokenService
{
    public function forAuthorizer(
        string $componentPlatformId,
        string $authorizerAppId,
        ?DateTimeImmutable $now = null,
    ): AuthorizerAccessToken;
}
```

- [ ] **Step 1: Write RED refresh tests**

Cover all of these separately:
```text
cached token outside 300s skew -> return without lease/provider call
inside skew -> lease winner refreshes
lease loser + still-unexpired cached token -> return old token
lease loser + expired/missing token -> 503
provider failure + old token still valid -> degraded fallback
provider failure + expired token -> 502
missing/unauthorized grant -> 403
rotated refresh token + new access token commit atomically
stale holder/version CAS -> no overwrite, reread winner token
platform A same authorizer AppId cannot read platform B token
Authorizer A refresh cannot mutate Authorizer B
release failure does not undo successful CAS
```

- [ ] **Step 2: Run tests and verify RED**

```bash
php tests/Component/OpenPlatform/AuthorizerAccessTokenServiceTest.php
php tests/Component/OpenPlatform/AuthorizerAccessTokenIsolationTest.php
```
Expected: FAIL because service is missing.

- [ ] **Step 3: Implement R8B-style refresh algorithm with dual-version CAS**

Exact order:
```text
load enabled platform -> load active authorization -> load token -> fast return -> acquire authorizer-specific lease -> double-check token -> obtain component token -> decrypt/read refresh credential through repository boundary -> provider refresh outside transaction -> validate -> compareAndSetRefresh(authorizationVersion, tokenVersion, holder) -> audit -> release finally
```
A rotated provider refresh token replaces the encrypted refresh token and its hash in the same commit as the new access token.

- [ ] **Step 4: Run focused/full suite**

```bash
php tests/Component/OpenPlatform/AuthorizerAccessTokenServiceTest.php
php tests/Component/OpenPlatform/AuthorizerAccessTokenIsolationTest.php
php tests/Component/OpenPlatform/ComponentAccessTokenServiceTest.php
php tests/run.php
```
Expected: PASS.

- [ ] **Step 5: Commit Task 6**

```bash
git add app/openplatform/application/AuthorizerAccessTokenService.php tests
git commit -m "feat: refresh OpenPlatform authorizer access tokens"
```

---

### Task 7: ThinkPHP Persistence, MiniApp Binding, API Routes, Security Contracts, and Release Gates

**Files:**
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizationIntentRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerAuthorizationRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerTokenRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerRefreshLeaseRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpComponentEventInboxRepository.php`
- Create: `app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php`
- Create: `app/api/controller/V1/OpenPlatformEventController.php`
- Create: `app/api/controller/V1/OpenPlatformAuthorizationStartController.php`
- Create: `app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php`
- Modify: `app/api/controller/V1/OpenPlatformTicketController.php`
- Modify: `app/api/route/app.php`
- Modify: `tests/run.php`
- Create: `tests/Contract/ThinkPhpOpenPlatformAuthorizerPersistenceContractTest.php`
- Create: `tests/Contract/R8COpenPlatformArchitectureSecurityContractTest.php`
- Create: `tests/Contract/R8COpenPlatformSecretScanContractTest.php`
- Create: `tests/Component/MiniApp/OpenPlatformAuthorizerAccountBindingTest.php`
- Create: `docs/migration/r20-openplatform-authorizer-lifecycle-r8c.md`
- Create: `docs/verification/openplatform-authorizer-lifecycle-r8c.md`
- Modify: `README.md`

**Interfaces:**
- Implements every Task 1–6 repository/binding port with ThinkPHP DB operations.
- API routes:
```text
POST /api/v1/openplatform/components/:componentPlatformId/events
POST /api/v1/openplatform/components/:componentPlatformId/ticket
POST /api/v1/openplatform/components/:componentPlatformId/authorization-intents
GET  /api/v1/openplatform/authorization/callback
```

- [ ] **Step 1: Write RED persistence/security/API contracts**

Contracts must prove:
- intent claim is one atomic row-lock/CAS operation and stale holder cannot complete;
- event inbox insert-first uniqueness is authoritative; no read-before-insert race gate;
- authorizer authorization/access-token refresh is atomic and holder/version scoped;
- unauthorized clears token/lease/refresh secret state in one transaction;
- network classes are never invoked from repository transaction closures;
- MiniApp binding validates existing tenant/account and component mode, clears manual credential ref, and rejects conflicting binding;
- no R8C domain/application file imports ThinkPHP/legacy globals;
- no plaintext secret field names/fixtures leak into SQL/audit/controller responses;
- `/ticket` remains registered and `/events` plus authorization routes are registered.

- [ ] **Step 2: Run contract tests and verify RED**

```bash
php tests/Contract/ThinkPhpOpenPlatformAuthorizerPersistenceContractTest.php
php tests/Contract/R8COpenPlatformArchitectureSecurityContractTest.php
php tests/Contract/R8COpenPlatformSecretScanContractTest.php
php tests/Component/MiniApp/OpenPlatformAuthorizerAccountBindingTest.php
```
Expected: FAIL on missing persistence/controllers/routes/binding.

- [ ] **Step 3: Implement persistence and adapters**

Repository transactions must be short and deterministic. Use `lock(true)`/equivalent row locking only around claim/order/CAS decisions. Encrypt refresh/access tokens through existing `OpenPlatformSecretCipher`; persist ciphertext + key version + non-secret hash only. Do not add a new DI framework or broad composition-root refactor.

`OpenPlatformAuthorizerAccountBinding` must update/create only `miniapp_provider_accounts` for an existing Account; it cannot insert into `accounts` or `tenants`.

Controllers map spec errors to the existing `AppException`/`ErrorCode` envelope and never serialize provider secret values.

- [ ] **Step 4: Update runner/docs and run all local-equivalent gates**

Append all R8C tests to `tests/run.php` and document migration/rollback plus security invariants.

Run:
```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app tests -name '*.php' -print0 | xargs -0 -n1 php -l
```
Then run the repository's existing HTTP smoke commands from `.github/workflows/ci.yml` unchanged.
Expected: every gate PASS.

- [ ] **Step 5: Commit Task 7**

```bash
git add app/openplatform/infrastructure app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php app/api database/migrations tests docs README.md
git commit -m "feat: wire R8C authorizer lifecycle into ThinkPHP"
```

- [ ] **Step 6: Open PR and verify exact branch-head CI**

Create PR:
```text
R8C: OpenPlatform authorizer lifecycle
```
Verify the successful pull-request CI run's `head_sha` is exactly the branch HEAD and all jobs/steps pass: Composer validate/install, offline suite, PHPUnit, PHP lint, HTTP smoke.

- [ ] **Step 7: Final race check and release**

Immediately before integration:
```text
fetch main SHA
fetch feature SHA
compare main...feature
require status=ahead and behind_by=0
```
Advance `main` with a non-force fast-forward only (`force=false`). Never force-push.

- [ ] **Step 8: Verify exact main push CI**

Fetch the independent `push` workflow run whose:
```text
head_branch = main
head_sha = exact released R8C SHA
```
Require every CI step PASS before declaring R8C released.

---

## Plan Self-Review Result

- Spec coverage: unified ingress, compatibility route, intent/state/pre-auth hashing, dual-channel claim arbitration, event-only recovery, ordering, unauthorized cleanup, existing-account binding, token rotation/singleflight, persistence, API, docs and release gates all have explicit tasks.
- Placeholder scan: no TBD/TODO/"implement later" steps remain.
- Type consistency: `componentPlatformId + authorizerAppId` composite scope, `AuthorizationIntentRepository` completion claim, `AuthorizerClient` three provider methods, and `AuthorizerAccessTokenService::forAuthorizer()` signatures are used consistently across tasks.
- Scope remains R8C only; automatic Account provisioning remains outside this plan.
