# OpenPlatform Authorizer Provisioning R8D Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add recoverable automatic SaaS Account provisioning for WeChat Official Accounts and Mini Programs on top of the released R8C authorization lifecycle, with trusted metadata, quota enforcement, global ownership, reconnect semantics, tenant/IAM isolation, and crash-safe worker execution.

**Architecture:** Preserve R8C as the provider-authorization source of truth. R8D adds trusted metadata, a tenant-scoped `AuthorizerProvisioning` aggregate, a DB-backed lease worker, global `AuthorizerAccountOwnership`, subtype provider bindings, and a two-stage quota/account Saga. Admin HTTP commands use authenticated tenant-scoped `RequestContext`; provider callback/events remain cryptographically authenticated and never derive Tenant from caller input.

**Tech Stack:** PHP 8.4, ThinkPHP 8, MySQL/InnoDB SQL migrations, custom offline test runner (`php tests/run.php`), PHPUnit bridge, GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-09-09-openplatform-authorizer-provisioning-r8d-design.md`

## Global Constraints

- Work only on `refactor/openplatform-authorizer-provisioning-r8d`, currently based on released R8C `499488b5911048a32d439a098f5c122ce4cfb974`.
- Preserve canonical authorizer identity `(component_platform_id, authorizer_app_id)` and R8C completion/event ordering semantics.
- `AuthorizerAuthorization` remains platform-level; Tenant ownership is never inferred from provider callback/query parameters.
- One canonical authorizer maps to at most one Tenant and one Account; disabled bindings retain ownership.
- `unauthorized` disables the provider connection but does not delete Account/ownership/metadata or release Account-creation quota.
- First provisioning consumes `QuotaResource::accountCreate(AccountType)` exactly once; reconnect consumes zero additional quota.
- `Account.name` is initialized once from trusted provider nickname or deterministic fallback and is never overwritten by metadata refresh.
- Trusted classification is `MiniProgramInfo present -> WECHAT_MINI_PROGRAM`, absent -> `OFFICIAL_ACCOUNT`; once a provisioning reaches `METADATA_READY`, its `account_type` is frozen.
- R8D uses a DB-backed worker, claim TTL 60 seconds, max 10 automatic attempts, exponential backoff capped around 30 minutes; no external MQ.
- Never persist or log plaintext state, pre-auth code, authorization code, component/authorizer access tokens, authorizer refresh token, credential plaintext, AES/session keys, decrypted callback XML, or complete encrypted callback payload.
- Each implementation task uses separate RED and GREEN commits. Push/verify the RED SHA fails only for the intended missing behavior; push/verify the GREEN SHA is all green before moving on.
- No force pushes. Final release is a fresh-main fast-forward only after feature exact-HEAD CI is green, followed by independent main CI on the exact released SHA.

## File Structure Map

New/expanded responsibilities are locked as follows:

- `app/iam/security/BearerTokenParser.php` — strict `Authorization: Bearer` parsing.
- `app/iam/contract/AdminTenantAccess.php` + `app/iam/infrastructure/ThinkPhpAdminTenantAccess.php` — validate admin membership in selected Tenant.
- `app/iam/contract/PermissionAuthorizer.php` + `app/iam/infrastructure/ThinkPhpPermissionAuthorizer.php` — action-level permission lookup through existing `permission_assignments -> roles -> role_permissions -> permissions` schema.
- `app/api/middleware/OpenPlatformAdminContextMiddleware.php` — hydrate tenant/principal only for admin OpenPlatform routes; never attach to provider callback/events/ticket.
- `app/openplatform/domain/OpenPlatformPermission.php` — stable R8D permission keys.
- `app/openplatform/application/OpenPlatformAdminGuard.php` — converts request context + permission authorizer into a trusted admin scope.
- `app/openplatform/domain/AuthorizationIntentMode.php` + modified `AuthorizationIntent.php` — explicit existing-account vs auto-provision modes.
- `app/openplatform/contract/AuthorizerAccountEligibility.php` — pre-provider local validation.
- `app/openplatform/domain/AuthorizerInfoResponse.php`, `AuthorizerMetadata.php`, `AuthorizerMetadataRecord.php` — provider-whitelisted response, normalized metadata, persisted current version.
- `app/openplatform/application/AuthorizerMetadataNormalizer.php` and `AuthorizerMetadataSyncService.php` — trusted type detection, stable JSON/hash, current/snapshot sync.
- `app/openplatform/contract/AuthorizerMetadataRepository.php` + `ThinkPhpAuthorizerMetadataRepository.php` — current projection and immutable snapshots.
- `app/openplatform/domain/AuthorizerAccountOwnership.php` + repository — canonical owner fact.
- `app/openplatform/contract/AuthorizerConnectionStore.php` — subtype binding enable/disable/reconnect.
- `app/openplatform/contract/AuthorizerAccountFinalizer.php` — single short transaction for Account + provider binding + ownership + provisioning finalization.
- `app/openplatform/domain/AuthorizerProvisioning.php`, `AuthorizerProvisioningStatus.php`, `ProvisioningJob.php`, `ProvisioningJobStatus.php` — workflow and durable job state.
- `app/openplatform/contract/AuthorizerProvisioningRepository.php`, `ProvisioningJobRepository.php` + ThinkPHP implementations — workflow persistence, CAS, lease claim/recovery.
- `app/openplatform/application/AuthorizerProvisioningWorker.php`, `AuthorizerProvisioningRetryService.php`, `AuthorizerProvisioningQueryService.php`, `AuthorizerConnectionService.php` — worker/read/retry/reconnect application services.
- `database/migrations/20260909_009_openplatform_authorizer_provisioning_{up,down}.sql` — all R8D schema changes and stable permission definitions.
- `app/api/controller/V1/OpenPlatformProvisioningController.php`, `OpenPlatformAuthorizerMetadataController.php` + `app/api/route/app.php` — query/retry/refresh HTTP contract.
- `app/AppService.php` — explicit DI bindings for production interfaces used by R8C/R8D API routes.

---

### Task 1: Admin Tenant Context and Action-Level Permission Guard

**Files:**
- Create: `app/iam/security/BearerTokenParser.php`
- Create: `app/iam/contract/AdminTenantAccess.php`
- Create: `app/iam/contract/PermissionAuthorizer.php`
- Create: `app/iam/infrastructure/ThinkPhpAdminTenantAccess.php`
- Create: `app/iam/infrastructure/ThinkPhpPermissionAuthorizer.php`
- Create: `app/api/middleware/OpenPlatformAdminContextMiddleware.php`
- Create: `app/openplatform/domain/OpenPlatformPermission.php`
- Create: `app/openplatform/application/OpenPlatformAdminGuard.php`
- Test: `tests/Unit/Iam/BearerTokenParserTest.php`
- Test: `tests/Component/Iam/OpenPlatformAdminContextMiddlewareTest.php`
- Test: `tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces:
```php
interface AdminTenantAccess
{
    public function assertMember(string $adminUserId, string $tenantId): void;
}

interface PermissionAuthorizer
{
    public function assertAllowed(
        string $adminUserId,
        string $tenantId,
        string $permissionKey,
        ?string $accountId = null,
    ): void;
}

enum OpenPlatformPermission: string
{
    case READ = 'openplatform.authorizer.read';
    case START = 'openplatform.authorizer.start';
    case BIND = 'openplatform.authorizer.bind';
    case PROVISION = 'openplatform.authorizer.provision';
    case REFRESH_METADATA = 'openplatform.authorizer.refresh_metadata';
    case RETRY_PROVISION = 'openplatform.authorizer.retry_provision';
}
```
- Consumes: existing `RestoreAdminSession`, `RequestContext`, `Principal`, `tenant_memberships`, `permission_assignments`, `roles`, `role_permissions`, `permissions`.

- [ ] **Step 1: Write RED tests for bearer parsing, tenant membership, context hydration, and permission denial**

```php
$parser = new BearerTokenParser();
expectSame('abc123', $parser->parse('Bearer abc123'), 'Bearer token is extracted exactly');
expectThrows(fn () => $parser->parse('Basic abc123'), AppException::class, 'non-Bearer auth is rejected');

// Middleware fixture: valid session user-1 + X-Tenant-Id tenant-1 + membership.
// Assert resulting RequestContext tenantId=tenant-1 and principal=(user-1, admin).
// Missing session => 401; tenant not in tenant_memberships => 403.

$guard->require(OpenPlatformPermission::PROVISION);
expectSame(1, $permissionAuthorizer->callCount(), 'server-side permission check executes');
```

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Iam/BearerTokenParserTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/Iam/OpenPlatformAdminContextMiddlewareTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php';"
```
Expected: FAIL because the new classes/interfaces do not exist.

- [ ] **Step 3: Commit and push RED**

```bash
git add tests/run.php tests/Unit/Iam/BearerTokenParserTest.php tests/Component/Iam/OpenPlatformAdminContextMiddlewareTest.php tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php
git commit -m "test: define R8D admin authorization boundary"
git push origin HEAD
```
Verify the exact RED SHA CI fails only on these missing R8D types/behavior.

- [ ] **Step 4: Implement the minimal auth bridge**

`BearerTokenParser::parse()` must reject empty/malformed headers with `UNAUTHORIZED` 401. `ThinkPhpAdminTenantAccess` checks an exact `(tenant_id, admin_user_id)` membership row. `ThinkPhpPermissionAuthorizer` joins assignments to role permissions and accepts tenant-level assignments (`account_id IS NULL`) or an exact requested account assignment; absence throws `FORBIDDEN` 403.

`OpenPlatformAdminContextMiddleware` must:
```php
$base = $app->make(RequestContext::class);
$session = $restore->execute($parser->parse((string) $request->header('authorization', '')), $now);
$tenantId = trim((string) $request->header('x-tenant-id', ''));
$tenantAccess->assertMember($session->userId(), $tenantId);
$app->instance(RequestContext::class, new RequestContext(
    requestId: $base->requestId(),
    traceId: $base->traceId(),
    runtimeType: $base->runtimeType(),
    tenantId: $tenantId,
    accountId: null,
    siteId: $base->siteId(),
    principal: new Principal($session->userId(), 'admin'),
    locale: $base->locale(),
    clientIp: $base->clientIp(),
));
return $next($request);
```
`OpenPlatformAdminGuard::require()` rejects missing tenant/principal as `UNAUTHORIZED` and then calls `PermissionAuthorizer::assertAllowed()`.

- [ ] **Step 5: Run focused tests and full offline suite**

```bash
php tests/run.php
php vendor/bin/phpunit
```
Expected: PASS.

- [ ] **Step 6: Commit and push GREEN**

```bash
git add app/iam app/api/middleware/OpenPlatformAdminContextMiddleware.php app/openplatform/domain/OpenPlatformPermission.php app/openplatform/application/OpenPlatformAdminGuard.php tests/run.php tests/Unit/Iam tests/Component/Iam tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php
git commit -m "feat: add tenant-scoped OpenPlatform admin authorization"
git push origin HEAD
```
Verify exact GREEN SHA CI is fully green.

---

### Task 2: Explicit AuthorizationIntent Modes and Pre-Provider Eligibility

**Files:**
- Create: `app/openplatform/domain/AuthorizationIntentMode.php`
- Create: `app/openplatform/contract/AuthorizerAccountEligibility.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerAccountEligibility.php`
- Modify: `app/openplatform/domain/AuthorizationIntent.php`
- Modify: `app/openplatform/application/AuthorizationStartService.php`
- Modify: `app/openplatform/infrastructure/ThinkPhpAuthorizationIntentRepository.php`
- Modify: `app/api/controller/V1/OpenPlatformAuthorizationStartController.php`
- Test: `tests/Unit/OpenPlatform/AuthorizationIntentTest.php`
- Test: `tests/Component/OpenPlatform/AuthorizationStartServiceTest.php`
- Test: `tests/Component/OpenPlatform/OpenPlatformAuthorizationStartControllerTest.php`
- Modify: `tests/run.php`

**Interfaces:**
```php
enum AuthorizationIntentMode: string
{
    case BIND_EXISTING_ACCOUNT = 'bind_existing_account';
    case AUTO_PROVISION_ACCOUNT = 'auto_provision_account';
}

interface AuthorizerAccountEligibility
{
    public function assertTenantEligible(string $tenantId): void;
    public function assertExistingAccountEligible(string $tenantId, string $accountId): AccountType;
}

public function AuthorizationStartService::start(
    string $componentPlatformId,
    string $tenantId,
    AuthorizationIntentMode $mode,
    ?string $targetAccountId,
    string $requestedAuthType,
    DateTimeImmutable $now,
): AuthorizationStartResult;
```

- [ ] **Step 1: Extend unit/component tests for mode invariants and zero-provider-call eligibility**

```php
$auto = AuthorizationIntent::pending(
    'intent-auto', 'platform-1', 'tenant-1', AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT,
    null, hash('sha256', 'state'), hash('sha256', 'pre'), '1', $now, $localExpiry, $providerExpiry,
);
expectSame(null, $auto->targetAccountId(), 'auto provision has no target Account');

expectThrows(
    fn () => AuthorizationIntent::pending(
        'bad', 'platform-1', 'tenant-1', AuthorizationIntentMode::BIND_EXISTING_ACCOUNT,
        null, hash('sha256', 'state'), hash('sha256', 'pre'), '1', $now, $localExpiry, $providerExpiry,
    ),
    InvalidArgumentException::class,
    'existing-account mode requires targetAccountId',
);

// Eligibility fake rejects local Account.
expectThrows(fn () => $service->start(...), AppException::class, 'invalid local Account rejected');
expectSame(0, $authorizerClient->preAuthCalls(), 'invalid local data causes zero provider pre-auth calls');
```

Controller test must assert Tenant comes from hydrated `RequestContext`, compatibility body `tenant_id/tenantId` mismatch returns 403, `mode=auto_provision_account` requires `START + PROVISION`, and `bind_existing_account` requires `START + BIND`.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/AuthorizationIntentTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizationStartServiceTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/OpenPlatformAuthorizationStartControllerTest.php';"
```
Expected: FAIL on missing mode/nullable target/eligibility and old controller tenant sourcing.

- [ ] **Step 3: Commit RED**

```bash
git add tests/run.php tests/Unit/OpenPlatform/AuthorizationIntentTest.php tests/Component/OpenPlatform/AuthorizationStartServiceTest.php tests/Component/OpenPlatform/OpenPlatformAuthorizationStartControllerTest.php
git commit -m "test: define R8D authorization intent modes"
git push origin HEAD
```

- [ ] **Step 4: Implement dual-mode domain/start flow**

`AuthorizationIntent` constructor/reconstitute/pending/getter signatures use `AuthorizationIntentMode $mode` and `?string $targetAccountId`; validate the exact mode/target invariant. `ThinkPhpAuthorizationIntentRepository` reads/writes `intent_mode` and nullable `target_account_id`.

`AuthorizationStartService` performs local eligibility before `ComponentAccessTokenService::forPlatform()` and before `AuthorizerClient::createPreAuthCode()`:
```php
match ($mode) {
    AuthorizationIntentMode::BIND_EXISTING_ACCOUNT =>
        $this->eligibility->assertExistingAccountEligible($tenantId, (string) $targetAccountId),
    AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT =>
        $this->eligibility->assertTenantEligible($tenantId),
};
```
Controller request compatibility:
```php
$mode = AuthorizationIntentMode::from((string) $request->param('mode', 'bind_existing_account'));
$target = $request->param('targetAccountId', $request->param('target_account_id'));
$authType = (string) $request->param('requestedAuthType', $request->param('auth_type', '1'));
```
A supplied `tenantId`/`tenant_id` is only an equality assertion against `RequestContext.tenantId`.

- [ ] **Step 5: Run tests**

```bash
php tests/run.php
php vendor/bin/phpunit
```
Expected: PASS except migration contract work intentionally deferred to Task 3.

- [ ] **Step 6: Commit GREEN**

```bash
git add app/openplatform app/api/controller/V1/OpenPlatformAuthorizationStartController.php tests
git commit -m "feat: add explicit OpenPlatform authorization intent modes"
git push origin HEAD
```
Verify exact SHA CI green.

---

### Task 3: R8D Database Migration and Schema Contracts

**Files:**
- Create: `database/migrations/20260909_009_openplatform_authorizer_provisioning_up.sql`
- Create: `database/migrations/20260909_009_openplatform_authorizer_provisioning_down.sql`
- Create: `tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php`
- Modify: `tests/run.php`

**Produces:** tables `authorizer_metadata_current`, `authorizer_metadata_snapshots`, `authorizer_account_ownerships`, `official_account_provider_accounts`, `authorizer_provisionings`, `authorizer_provisioning_jobs`; intent-mode alteration; stable permission rows; FK strengthening for component-mode provider bindings.

- [ ] **Step 1: Write static SQL contract RED test**

Assertions must search the migration text for all of the following exact semantics:
```php
expectTrue(str_contains($up, 'ADD COLUMN `intent_mode`'), 'intent mode added');
expectTrue(str_contains($up, 'MODIFY `target_account_id` varchar(64) DEFAULT NULL'), 'target Account becomes nullable');
expectTrue(str_contains($up, 'CREATE TABLE `authorizer_metadata_current`'), 'metadata current table exists');
expectTrue(str_contains($up, 'PRIMARY KEY (`component_platform_id`,`authorizer_app_id`)'), 'canonical ownership/metadata key exists');
expectTrue(str_contains($up, 'CREATE TABLE `authorizer_account_ownerships`'), 'ownership table exists');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_authorizer_ownership_account` (`account_id`)'), 'one Account per ownership');
expectTrue(str_contains($up, 'CREATE TABLE `official_account_provider_accounts`'), 'official-account provider table exists');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_authorizer_provisioning_source_intent` (`source_intent_id`)'), 'one provisioning per intent');
expectTrue(str_contains($up, 'PRIMARY KEY (`provisioning_id`)'), 'one durable job per provisioning');
```
Also assert all six OpenPlatform permission keys and symmetric down-table drops.

- [ ] **Step 2: Run contract test and confirm RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php';"
```
Expected: FAIL because migration files do not exist.

- [ ] **Step 3: Commit RED**

```bash
git add tests/run.php tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php
git commit -m "test: define R8D provisioning schema"
git push origin HEAD
```

- [ ] **Step 4: Write `_009` up/down migrations**

Key SQL invariants in `up.sql`:
```sql
ALTER TABLE `openplatform_authorization_intents`
  ADD COLUMN `intent_mode` varchar(32) NOT NULL DEFAULT 'bind_existing_account' AFTER `tenant_id`,
  MODIFY `target_account_id` varchar(64) DEFAULT NULL;

ALTER TABLE `openplatform_authorization_intents`
  ADD CONSTRAINT `chk_openplatform_intent_mode_target`
  CHECK ((`intent_mode`='bind_existing_account' AND `target_account_id` IS NOT NULL)
      OR (`intent_mode`='auto_provision_account' AND `target_account_id` IS NULL));
```
Create all tables from the approved Spec with InnoDB/FKs/indexes; `authorizer_provisionings.account_type` nullable; `source_intent_id` unique; job claim fields nullable; ownership canonical PK; snapshot unique `(component_platform_id, authorizer_app_id, version)` but `metadata_hash` non-unique.

Insert stable permissions using deterministic ids:
```sql
INSERT INTO `permissions` (`id`,`permission_key`,`description`) VALUES
('perm-openplatform-authorizer-read','openplatform.authorizer.read','Read OpenPlatform authorizer state'),
('perm-openplatform-authorizer-start','openplatform.authorizer.start','Start OpenPlatform authorization'),
('perm-openplatform-authorizer-bind','openplatform.authorizer.bind','Bind an authorizer to an existing Account'),
('perm-openplatform-authorizer-provision','openplatform.authorizer.provision','Auto-provision an Account from an authorizer'),
('perm-openplatform-authorizer-refresh','openplatform.authorizer.refresh_metadata','Refresh trusted authorizer metadata'),
('perm-openplatform-authorizer-retry','openplatform.authorizer.retry_provision','Retry failed authorizer provisioning');
```
`down.sql` removes R8D tables/FKs/checks/permission definitions and restores `target_account_id NOT NULL` only after deleting/guarding any auto-provision intents according to migration policy used by the project; contract must make the destructive precondition explicit rather than silently coercing nulls.

- [ ] **Step 5: Run schema contract and full suite**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php';"
php tests/run.php
```
Expected: PASS.

- [ ] **Step 6: Commit GREEN**

```bash
git add database/migrations/20260909_009_openplatform_authorizer_provisioning_* tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php tests/run.php
git commit -m "feat: add R8D authorizer provisioning schema"
git push origin HEAD
```

---

### Task 4: Trusted Authorizer Metadata Provider Contract and Normalization

**Files:**
- Create: `app/openplatform/domain/AuthorizerInfoResponse.php`
- Create: `app/openplatform/domain/AuthorizerMetadata.php`
- Create: `app/openplatform/application/AuthorizerMetadataNormalizer.php`
- Modify: `app/openplatform/contract/AuthorizerClient.php`
- Modify: `app/openplatform/infrastructure/WechatAuthorizerClient.php`
- Test: `tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php`
- Create: `tests/Unit/OpenPlatform/AuthorizerMetadataNormalizerTest.php`
- Create: `tests/GoldenMaster/OpenPlatformAuthorizerMetadataNormalizationTest.php`
- Create fixtures: `tests/GoldenMaster/fixtures/openplatform/official-account-authorizer-info.json`
- Create fixtures: `tests/GoldenMaster/fixtures/openplatform/mini-program-authorizer-info.json`
- Modify: `tests/run.php`

**Interfaces:**
```php
public function AuthorizerClient::getAuthorizerInfo(
    string $componentAppId,
    string $componentAccessToken,
    string $authorizerAppId,
): AuthorizerInfoResponse;

public function AuthorizerMetadataNormalizer::normalize(
    string $componentPlatformId,
    string $authorizerAppId,
    AuthorizerInfoResponse $provider,
): AuthorizerMetadata;
```

- [ ] **Step 1: Write RED provider/normalizer tests**

Assert exact endpoint/body:
```text
POST /cgi-bin/component/api_get_authorizer_info?component_access_token=...
{"component_appid":"wx-component","authorizer_appid":"wx-authorizer"}
```
Normalizer assertions:
```php
expectSame(AccountType::WECHAT_MINI_PROGRAM, $mini->accountType(), 'MiniProgramInfo classifies mini program');
expectSame(AccountType::OFFICIAL_ACCOUNT, $official->accountType(), 'absence of MiniProgramInfo classifies official account');
expectSame($a->metadataHash(), $sameMeaningDifferentKeyOrder->metadataHash(), 'semantic hash is stable');
```
Fixtures contain metadata only and no tokens.

- [ ] **Step 2: Run focused tests, confirm RED, commit RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/AuthorizerMetadataNormalizerTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php';"
git add tests
git commit -m "test: define trusted OpenPlatform authorizer metadata"
git push origin HEAD
```

- [ ] **Step 3: Implement whitelisted provider DTO + normalizer**

`AuthorizerInfoResponse` stores only fields required by the Spec (`nick_name`, `head_img`, `user_name/original_id`, `principal_name`, `alias`, service/verify type, business info, qrcode, optional MiniProgramInfo). `AuthorizerMetadata` stores normalized whitelisted values and exposes `normalizedJson()` generated after deterministic key ordering plus `metadataHash() = hash('sha256', normalizedJson)`.

`WechatAuthorizerClient` must not log or retain the full raw response beyond parsing into `AuthorizerInfoResponse`.

- [ ] **Step 4: Run tests/full suite and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: normalize trusted authorizer metadata"
git push origin HEAD
```

---

### Task 5: Metadata Current Projection and Immutable Snapshot Persistence

**Files:**
- Create: `app/openplatform/domain/AuthorizerMetadataRecord.php`
- Create: `app/openplatform/contract/AuthorizerMetadataRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerMetadataRepository.php`
- Create: `app/openplatform/application/AuthorizerMetadataSyncService.php`
- Create: `tests/Component/OpenPlatform/AuthorizerMetadataSyncServiceTest.php`
- Create: `tests/Contract/ThinkPhpOpenPlatformMetadataPersistenceContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
```php
interface AuthorizerMetadataRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord;
    public function observe(AuthorizerMetadata $metadata, DateTimeImmutable $fetchedAt, string $source): AuthorizerMetadataRecord;
}

public function AuthorizerMetadataSyncService::sync(
    string $componentPlatformId,
    string $authorizerAppId,
    DateTimeImmutable $now,
    string $source,
): AuthorizerMetadataRecord;
```

- [ ] **Step 1: RED tests for same-hash/no-version and A->B->A snapshots**

```php
$first = $service->sync('platform-1', 'wx1', $t1, 'authorization');
$second = $service->sync('platform-1', 'wx1', $t2, 'manual_refresh');
expectSame($first->version(), $second->version(), 'same semantics do not create version');

// Provider changes A->B->A.
expectSame(3, $repository->snapshotCount('platform-1', 'wx1'), 'A-B-A preserves all semantic changes');
```
Also assert provider timeout leaves existing authorization untouched; the service has no write dependency on `AuthorizerAuthorizationRepository` except eligibility/read if needed.

- [ ] **Step 2: Run RED and commit**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerMetadataSyncServiceTest.php';"
git add tests
git commit -m "test: define versioned authorizer metadata persistence"
git push origin HEAD
```

- [ ] **Step 3: Implement transactionally**

`ThinkPhpAuthorizerMetadataRepository::observe()` locks the current row, compares `metadata_hash`, updates only `provider_fetched_at` for same hash, otherwise increments version, inserts snapshot, and updates current in one DB transaction.

- [ ] **Step 4: Run tests/full suite and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: persist versioned authorizer metadata"
git push origin HEAD
```

---

### Task 6: Global Ownership, Existing-Account Binding, and Provider Connection Stores

**Files:**
- Create: `app/openplatform/domain/AuthorizerAccountOwnership.php`
- Create: `app/openplatform/contract/AuthorizerOwnershipRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerOwnershipRepository.php`
- Create: `app/openplatform/contract/AuthorizerConnectionStore.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerConnectionStore.php`
- Modify: `app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php`
- Create: `tests/Component/OpenPlatform/AuthorizerOwnershipResolverTest.php`
- Modify: `tests/Component/MiniApp/OpenPlatformAuthorizerAccountBindingTest.php`
- Modify: `tests/run.php`

**Interfaces:**
```php
interface AuthorizerOwnershipRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccountOwnership;
}

interface AuthorizerConnectionStore
{
    public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void;
    public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void;
}
```
Existing `AuthorizerAccountBinding::bindExistingAccount(...)` signature remains unchanged to avoid breaking R8C fakes.

- [ ] **Step 1: RED tests for ownership exclusivity and reconnect**

```php
// Existing Account A first binds wxABC => ownership A.
$binding->bindExistingAccount('tenant-a', 'account-a', 'platform-1', 'wxABC');
// Tenant B tries same canonical authorizer => conflict; no binding move.
expectThrows(
    fn () => $binding->bindExistingAccount('tenant-b', 'account-b', 'platform-1', 'wxABC'),
    AppException::class,
    'canonical authorizer is globally exclusive',
);
```
Add official-account connection store coverage and disabled-binding ownership persistence.

- [ ] **Step 2: Run RED/commit RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerOwnershipResolverTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/MiniApp/OpenPlatformAuthorizerAccountBindingTest.php';"
git add tests
git commit -m "test: define global authorizer ownership"
git push origin HEAD
```

- [ ] **Step 3: Implement ownership-aware binding**

Inside the existing-account transaction, lock canonical ownership before provider tables. Unowned -> insert ownership for explicit Account; exact same owner -> idempotent/re-enable; any other Account/Tenant -> `CONFLICT` 409. Route by existing Account type: `wechat_mini_program` writes `miniapp_provider_accounts`; `official_account` writes `official_account_provider_accounts`; never cross-write.

- [ ] **Step 4: Run full suite and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform app/miniapp tests
git commit -m "feat: enforce canonical authorizer ownership"
git push origin HEAD
```

---

### Task 7: Provisioning Aggregate, Durable Job, and Lease Persistence

**Files:**
- Create: `app/openplatform/domain/AuthorizerProvisioningStatus.php`
- Create: `app/openplatform/domain/AuthorizerProvisioning.php`
- Create: `app/openplatform/domain/ProvisioningJobStatus.php`
- Create: `app/openplatform/domain/ProvisioningJob.php`
- Create: `app/openplatform/contract/AuthorizerProvisioningRepository.php`
- Create: `app/openplatform/contract/ProvisioningJobRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerProvisioningRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpProvisioningJobRepository.php`
- Create: `tests/Unit/OpenPlatform/AuthorizerProvisioningTest.php`
- Create: `tests/Component/OpenPlatform/ProvisioningJobClaimTest.php`
- Modify: `tests/run.php`

**Interfaces:**
```php
interface ProvisioningJobRepository
{
    public function tryClaim(string $provisioningId, string $holderId, DateTimeImmutable $now, int $ttlSeconds): ?ProvisioningJob;
    public function release(string $provisioningId, string $holderId, DateTimeImmutable $nextAttemptAt, ?string $errorCode): bool;
    public function complete(string $provisioningId, string $holderId): bool;
    public function dead(string $provisioningId, string $holderId, string $errorCode): bool;
}
```
`AuthorizerProvisioning` exposes explicit transition methods; no public arbitrary `setStatus()`.

- [ ] **Step 1: RED tests for state transitions and claim competition**

Assert allowed path `PENDING_METADATA -> METADATA_READY -> QUOTA_CONSUMED -> PROVISIONED`; terminal/blocked states cannot jump directly to success. Two workers at same time: exactly one claim; expired claim recoverable; stale holder late-save rejected.

- [ ] **Step 2: Run RED/commit RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/AuthorizerProvisioningTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/ProvisioningJobClaimTest.php';"
git add tests
git commit -m "test: define durable provisioning state and lease"
git push origin HEAD
```

- [ ] **Step 3: Implement domain and repositories**

Use 60-second default claim TTL at worker layer. Repository claim uses atomic conditional update/CAS on READY or expired CLAIMED row; increment attempt count when a new holder acquires the job. `account_type` may be set exactly once when metadata becomes ready; later different type transitions to `METADATA_TYPE_CONFLICT`, never rewrites the frozen type.

- [ ] **Step 4: Run full suite and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: add durable authorizer provisioning state"
git push origin HEAD
```

---

### Task 8: Authorization Completion Branch and Atomic Durable Trigger

**Files:**
- Modify: `app/openplatform/application/AuthorizationCompletionService.php`
- Modify: `app/openplatform/domain/AuthorizerAuthorizationResult.php`
- Modify: `app/openplatform/application/AuthorizationCallbackService.php`
- Modify: `app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationCallbackServiceTest.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationEventServiceTest.php`

**Produces:** auto-provision callback result with `provisioningId`; exactly one provisioning + job is committed atomically with intent completion.

- [ ] **Step 1: RED tests for mode branch and callback/event competition**

For `AUTO_PROVISION_ACCOUNT`, assert:
```text
AuthorizerAuthorization saved = 1
AuthorizerProvisioning inserted = 1
ProvisioningJob inserted = 1
Intent completed = 1
AuthorizerAccountBinding calls = 0
```
Inject exceptions after authorization save, after provisioning insert, and after job insert in a transactional fake; each must leave no completed intent without durable trigger. Run callback and matching `authorized` event concurrently/serially against same claim and assert provider `queryAuthorization` call count exactly 1.

- [ ] **Step 2: Run RED and commit**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php';"
git add tests/Component/OpenPlatform
git commit -m "test: define atomic auto-provision completion trigger"
git push origin HEAD
```

- [ ] **Step 3: Implement branch**

Inside the existing completion transaction:
```php
if ($claimed->mode() === AuthorizationIntentMode::BIND_EXISTING_ACCOUNT) {
    $this->binding->bindExistingAccount(...);
    $result = AuthorizerAuthorizationResult::completed($provider->authorizerAppId());
} else {
    $provisioning = AuthorizerProvisioning::pendingMetadata(...);
    $this->provisionings->insert($provisioning);
    $this->jobs->insert(ProvisioningJob::ready($provisioning->id(), $now));
    $result = AuthorizerAuthorizationResult::provisioning($provider->authorizerAppId(), $provisioning->id());
}
$this->intents->complete(...);
```
No metadata/quota/Account calls occur in this transaction.

- [ ] **Step 4: Run regression and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php tests/Component/OpenPlatform
git commit -m "feat: trigger R8D provisioning after authorization"
git push origin HEAD
```

---

### Task 9: Provisioning Worker Through Metadata and Ownership Resolution

**Files:**
- Create: `app/openplatform/application/AuthorizerOwnershipResolver.php`
- Create: `app/openplatform/application/AuthorizerConnectionService.php`
- Create: `app/openplatform/application/AuthorizerProvisioningWorker.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php`
- Modify: `tests/run.php`

**Interfaces:**
```php
enum AuthorizerOwnershipResolution: string
{
    case UNOWNED = 'unowned';
    case SAME_OWNER = 'same_owner';
    case OTHER_OWNER = 'other_owner';
}

public function AuthorizerProvisioningWorker::runOne(
    string $provisioningId,
    DateTimeImmutable $now,
): void;
```

- [ ] **Step 1: RED tests for first worker stages**

Cases:
- authorization inactive -> `AUTHORIZATION_INACTIVE`, zero metadata/quota;
- metadata provider timeout -> job released with backoff, authorization remains ACTIVE;
- metadata ready -> frozen AccountType + metadata version;
- existing same owner -> `RECONNECTED`, zero quota, existing Account id;
- other owner -> `BINDING_CONFLICT`, zero quota/Account writes;
- unowned -> stops at ready-for-quota path handled in Task 10.

- [ ] **Step 2: Run RED/commit RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php';"
git add tests
git commit -m "test: define provisioning metadata and ownership stages"
git push origin HEAD
```

- [ ] **Step 3: Implement worker stage orchestration**

The worker generates a random holder, claims for 60 seconds, reloads authorization/provisioning after claim, calls metadata sync only when needed, persists frozen type/version, resolves ownership, and delegates same-owner reconnect to `AuthorizerConnectionService`. Retryable exceptions schedule backoff `1m,2m,4m,8m,16m,30m...`; business terminal states mark job complete/dead without looping.

- [ ] **Step 4: Run suite/commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: advance provisioning through metadata and ownership"
git push origin HEAD
```

---

### Task 10: Quota Consumption, Atomic Account Finalization, Reconciliation, and Compensation

**Files:**
- Create: `app/openplatform/contract/AuthorizerAccountFinalizer.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerAccountFinalizer.php`
- Create: `app/openplatform/application/AuthorizerProvisioningQuotaService.php`
- Modify: `app/openplatform/application/AuthorizerProvisioningWorker.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningQuotaTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerAccountFinalizerTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningRecoveryTest.php`
- Modify: `tests/run.php`

**Interfaces:**
```php
interface AuthorizerAccountFinalizer
{
    public function provision(AuthorizerProvisioning $provisioning, AuthorizerMetadataRecord $metadata, DateTimeImmutable $now): string;
    public function reconcile(AuthorizerProvisioning $provisioning): ?string;
}
```
Consume key:
```text
openplatform-provision:<componentPlatformId>:<authorizerAppId>:<tenantId>
```
Release key:
```text
openplatform-provision-release:<provisioningId>
```

- [ ] **Step 1: RED tests for exactly-once quota and Account finalization**

Assert first unowned provisioning calls:
```php
QuotaService::consume($tenantId, QuotaResource::accountCreate($accountType), 1, $consumeKey, $now);
```
Repeated worker runs return the same ledger entry and never create a second Account. `QUOTA_BLOCKED` creates no Account/binding/ownership. Reconnect consumes zero.

Finalizer transaction must prove Account + subtype binding + ownership + provisioning final status commit/rollback together. Official Account goes only to `official_account_provider_accounts`; Mini Program only to `miniapp_provider_accounts`.

- [ ] **Step 2: RED crash recovery/compensation tests**

Cover:
- consume succeeded before `quota_consume_entry_id` saved -> recover same ledger entry by idempotency key;
- consume entry saved before Account create -> no second consume;
- Account transaction committed but worker missed result -> `reconcile()` returns original Account and worker marks PROVISIONED;
- terminal failure with proven no Account -> release exactly once;
- transient DB error after quota -> no release.

- [ ] **Step 3: Run RED/commit RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningQuotaTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningRecoveryTest.php';"
git add tests
git commit -m "test: define quota and Account provisioning recovery"
git push origin HEAD
```

- [ ] **Step 4: Implement quota/finalizer Saga**

`AuthorizerProvisioningQuotaService` wraps existing `QuotaService` only; it does not duplicate allocation rules. `ThinkPhpAuthorizerAccountFinalizer` generates/persists one Account id and uses one short `Db::transaction()` to lock provisioning + ownership, re-check ACTIVE authorization and frozen type, insert `accounts`, subtype provider binding, ownership, and finalize provisioning.

Fallback name:
```php
$name = trim($metadata->nickName());
if ($name === '') {
    $suffix = substr($provisioning->authorizerAppId(), -8);
    $name = $provisioning->accountType() === AccountType::WECHAT_MINI_PROGRAM
        ? '微信小程序 · ' . $suffix
        : '微信公众号 · ' . $suffix;
}
```
Later metadata sync never updates `accounts.name`.

- [ ] **Step 5: Run full suite and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: provision Accounts with idempotent quota recovery"
git push origin HEAD
```

---

### Task 11: Unauthorized, Reauthorization, Event-Only, and Metadata Type Conflict Lifecycle

**Files:**
- Modify: `app/openplatform/application/AuthorizationEventService.php`
- Modify: `app/openplatform/application/AuthorizerConnectionService.php`
- Modify: `app/openplatform/application/AuthorizerMetadataSyncService.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationEventServiceTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerReconnectLifecycleTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerMetadataTypeConflictTest.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Write RED lifecycle tests**

`unauthorized` after provision must result in:
```text
AuthorizerAuthorization = unauthorized
Account = unchanged
Ownership = retained
Binding.enabled = 0
Metadata = retained
Quota consume = retained
```
Same canonical authorizer reauthorization restores same Account/binding with zero additional quota. SUSPENDED Account reconnects binding but stays SUSPENDED. DELETED Account does not resurrect and produces conflict/manual-review. Event-only `authorized` without ownership creates no provisioning/Account/quota; with ownership it reconnects. `updateauthorized` never moves/creates ownership.

After a provisioned Mini Program metadata refresh classifies as Official Account, assert `METADATA_TYPE_CONFLICT`, `accounts.type` unchanged, no second Account, no new quota.

- [ ] **Step 2: Run RED and commit**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerReconnectLifecycleTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerMetadataTypeConflictTest.php';"
git add tests
git commit -m "test: define R8D disconnect and reconnect lifecycle"
git push origin HEAD
```

- [ ] **Step 3: Implement event projection hooks without changing R8C ordering**

Keep existing newer-wins/same-timestamp conflict/older-no-op logic intact. Only after the authorization state transition is accepted, call connection projection logic. Provider connection update failure must not roll back authoritative `AuthorizerAuthorization`; audit/reconcile can repair the projection later.

- [ ] **Step 4: Run R8C/R8D regression and commit GREEN**

```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: preserve Account ownership across authorizer lifecycle"
git push origin HEAD
```

---

### Task 12: Provisioning Query/Retry/Metadata Refresh HTTP APIs and Production DI Wiring

**Files:**
- Create: `app/openplatform/application/AuthorizerProvisioningQueryService.php`
- Create: `app/openplatform/application/AuthorizerProvisioningRetryService.php`
- Create: `app/api/controller/V1/OpenPlatformProvisioningController.php`
- Create: `app/api/controller/V1/OpenPlatformAuthorizerMetadataController.php`
- Modify: `app/api/route/app.php`
- Modify: `app/AppService.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningQueryServiceTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningRetryServiceTest.php`
- Create: `tests/Component/OpenPlatform/OpenPlatformProvisioningControllerTest.php`
- Create: `tests/Contract/R8DOpenPlatformWiringContractTest.php`
- Modify: `tests/run.php`
- Modify: `.github/workflows/ci.yml`

**HTTP:**
```text
GET  /api/v1/openplatform/provisionings/:id
POST /api/v1/openplatform/provisionings/:id/retry
POST /api/v1/openplatform/components/:platform/authorizers/:appid/metadata/refresh
```
Attach `OpenPlatformAdminContextMiddleware` only to authorization-start, provisioning query/retry, and metadata-refresh routes. Do not attach it to provider callback/events/ticket.

- [ ] **Step 1: RED tenant/IAM HTTP tests**

Assert no session -> 401; no required permission -> 403; cross-Tenant provisioning lookup -> 404; valid read -> 200 even for `QUOTA_BLOCKED`; retry accepted -> 202; metadata refresh is allowed only when current Tenant owns the authorizer or has a provisioning for it, otherwise 404.

- [ ] **Step 2: RED wiring test**

Contract test must instantiate/check bindings for at least:
```text
AuthorizationIntentRepository -> ThinkPhpAuthorizationIntentRepository
AuthorizerAuthorizationRepository -> ThinkPhpAuthorizerAuthorizationRepository
AuthorizerClient -> WechatAuthorizerClient
AuthorizerMetadataRepository -> ThinkPhpAuthorizerMetadataRepository
AuthorizerOwnershipRepository -> ThinkPhpAuthorizerOwnershipRepository
AuthorizerProvisioningRepository -> ThinkPhpAuthorizerProvisioningRepository
ProvisioningJobRepository -> ThinkPhpProvisioningJobRepository
AuthorizerConnectionStore -> ThinkPhpAuthorizerConnectionStore
AuthorizerAccountFinalizer -> ThinkPhpAuthorizerAccountFinalizer
AdminSessionRepository -> production ThinkPHP implementation
AdminTenantAccess -> ThinkPhpAdminTenantAccess
PermissionAuthorizer -> ThinkPhpPermissionAuthorizer
```
Do not rely on ThinkPHP guessing interface implementations.

- [ ] **Step 3: Run RED/commit RED**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/R8DOpenPlatformWiringContractTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/OpenPlatformProvisioningControllerTest.php';"
git add tests .github/workflows/ci.yml
git commit -m "test: define R8D admin API and runtime wiring"
git push origin HEAD
```

- [ ] **Step 4: Implement query/retry/controllers/routes/bindings**

`QueryService` always queries by `(id, RequestContext.tenantId)` so cross-Tenant ids are indistinguishable from missing ids. `RetryService` revalidates current authorization/ownership/quota/account facts and resumes at the persisted stage; it never blindly resets status.

`AppService::register()` explicitly binds all interfaces used by R8C/R8D HTTP routes. Add configuration factories for `WechatAuthorizerClient`, callback URI, secret cipher, etc. using existing R8C configuration sources rather than embedding secrets.

- [ ] **Step 5: Extend HTTP smoke**

CI should start the API app and verify an unauthenticated admin provisioning route resolves to the application and returns the expected auth contract (401), while `/events`, `/ticket`, and `/authorization/callback` remain provider/public ingress rather than being blocked by admin middleware.

- [ ] **Step 6: Run full suite and commit GREEN**

```bash
composer validate --strict
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
git add app config tests .github/workflows/ci.yml
git commit -m "feat: expose and wire R8D provisioning APIs"
git push origin HEAD
```

---

### Task 13: Concurrency, Crash Matrix, Secret Leakage, Audit, Golden Master, and Final Release Gate

**Files:**
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningConcurrencyTest.php`
- Expand: `tests/Component/OpenPlatform/AuthorizerProvisioningRecoveryTest.php`
- Create: `tests/Contract/R8DOpenPlatformSecretScanContractTest.php`
- Create: `tests/Contract/R8DOpenPlatformArchitectureSecurityContractTest.php`
- Modify/Create audit assertions in: `tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php`
- Modify: `tests/run.php`
- Modify: `README.md` only if the repository keeps release-stage capability docs there; otherwise keep architecture documentation in the existing Spec/Plan.

- [ ] **Step 1: Add RED concurrency/crash tests covering every approved crash point**

Matrix:
```text
metadata returned before save
metadata saved before provisioning status update
before quota consume
after quota consume before consume-entry ref persisted
after consume-entry ref before Account create
Account transaction before commit
Account transaction committed but worker missed result
binding/ownership committed before provisioning final status observed
reconnect binding enabled before final status observed
```
For each, assert eventual:
```text
Account count <= 1
Ownership count <= 1
semantic quota consume count <= 1
```
Two simultaneous workers: one claim winner; loser performs zero provider/quota/Account writes. Expired holder late-save rejected.

- [ ] **Step 2: Add RED secret leakage scan**

Use sentinels:
```text
SECRET_STATE_123
SECRET_PREAUTH_123
SECRET_AUTH_CODE_123
SECRET_REFRESH_TOKEN_123
SECRET_ACCESS_TOKEN_123
```
Scan audit payloads, serialized jobs, normalized metadata, exceptions, and captured logs; plaintext must never appear. Raw provider metadata fixtures must contain no credentials.

- [ ] **Step 3: Commit RED**

```bash
git add tests
git commit -m "test: gate R8D crash safety and secret isolation"
git push origin HEAD
```
Verify exact RED SHA fails only where resilience/audit/redaction behavior is missing.

- [ ] **Step 4: Add minimal production hardening/audit needed for GREEN**

Audit actor ids:
```text
admin:<principalId>
external:wechat-openplatform
system:openplatform-provisioning-worker
```
Required action names include authorization start/complete, metadata refresh/change, provisioning created/metadata-ready/quota-consumed/quota-released/provisioned/reconnected/binding-conflict/failed/retry-requested, and connection disconnected. Metadata contains only safe ids, versions, count fields, quota ledger ids, sanitized error code/stage, request/trace ids.

- [ ] **Step 5: Run complete local release gate**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```
Expected: all PASS.

- [ ] **Step 6: Commit/push final GREEN hardening**

```bash
git add app tests README.md
git commit -m "test: complete R8D provisioning release gates"
git push origin HEAD
```
If `README.md` was not changed, omit it from `git add`.

- [ ] **Step 7: Verify feature exact-HEAD CI**

Record:
```text
feature SHA
PR/workflow run id
all required jobs/status = success
```
Do not proceed on partial/cancelled/stale CI.

- [ ] **Step 8: Fresh-main race check**

```bash
git fetch origin main
git merge-base --is-ancestor origin/main HEAD
git rev-list --left-right --count origin/main...HEAD
```
Expected: feature `behind = 0`; merge base is the fresh main tip or main is an ancestor of feature. If main advanced, integrate/retest without force pushing.

- [ ] **Step 9: Release by non-force fast-forward only after green exact head**

```bash
# Conceptual release gate; use repository-approved ref update/merge mechanism.
# Never force.
```
After main points to the exact feature SHA, independently verify push/main CI on that exact SHA. Only then report R8D released.

## Plan Self-Review Checklist

Before execution begins, the executor must preserve these explicit task-to-spec links:

- Spec §§4,18,19 -> Tasks 1–3,12 (trusted tenant, intent modes, IAM/API).
- Spec §5 -> Tasks 4–5 (provider metadata, normalization, snapshots).
- Spec §§7–8 -> Task 6 (ownership/provider bindings).
- Spec §§9–10 -> Task 7 (provisioning/job state and lease).
- Spec §11 -> Task 8 (R8C completion branch and durable trigger).
- Spec §§12–14 -> Tasks 9–10 (worker, quota Saga, finalization/recovery).
- Spec §§15–16 -> Task 11 (unauthorized/reconnect/event-only/updateauthorized).
- Spec §§20–21 -> Tasks 1,12,13 (error/IAM/audit/security).
- Spec §§22–25 -> Tasks 3–13 with Task 13 as final concurrency/security/release gate.

No implementation task may introduce authorizer sharing, transfer, remote revocation, code-release/payment APIs, external MQ, a generic `wechat_provider_accounts` identity layer, or automatic Tenant creation.