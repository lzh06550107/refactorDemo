# OpenPlatform Authorizer Provisioning R8D Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add recoverable automatic SaaS Account provisioning for WeChat Official Accounts and Mini Programs on top of the released R8C authorization lifecycle, with trusted metadata, quota enforcement, global ownership, reconnect semantics, tenant/IAM isolation, and crash-safe worker execution.

**Architecture:** Preserve R8C as the provider-authorization source of truth. R8D adds trusted metadata, a tenant-scoped `AuthorizerProvisioning` aggregate, a DB-backed lease worker, global `AuthorizerAccountOwnership`, subtype provider bindings, and a two-stage quota/account Saga. Admin HTTP commands use authenticated tenant-scoped `RequestContext`; provider callback/events remain cryptographically authenticated and never derive Tenant from caller input.

**Tech Stack:** PHP 8.4, ThinkPHP 8, MySQL/InnoDB SQL migrations, custom offline test runner (`php tests/run.php`), PHPUnit bridge, GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-09-09-openplatform-authorizer-provisioning-r8d-design.md`

## Global Constraints

- Work only on `refactor/openplatform-authorizer-provisioning-r8d`, based on released R8C `499488b5911048a32d439a098f5c122ce4cfb974`.
- Preserve canonical authorizer identity `(component_platform_id, authorizer_app_id)` and R8C completion/event ordering semantics.
- `AuthorizerAuthorization` remains platform-level; Tenant ownership is never inferred from provider callback/query/body values.
- One canonical authorizer maps to at most one Tenant and one Account; disabled bindings retain ownership.
- `unauthorized` disables the provider connection but does not delete Account/ownership/metadata or release Account-creation quota.
- First provisioning consumes `QuotaResource::accountCreate(AccountType)` exactly once; reconnect consumes zero additional quota.
- `Account.name` is initialized once from trusted provider nickname or deterministic fallback and is never overwritten by metadata refresh.
- Trusted classification is `MiniProgramInfo present -> WECHAT_MINI_PROGRAM`, absent -> `OFFICIAL_ACCOUNT`; after a provisioning reaches `METADATA_READY`, its `account_type` is immutable.
- R8D uses a DB-backed worker, claim TTL 60 seconds, maximum 10 automatic attempts, exponential retry delays `1m, 2m, 4m, 8m, 16m, 30m, 30m...`; no external MQ.
- Never persist or log plaintext state, pre-auth code, authorization code, component/authorizer access tokens, authorizer refresh token, credential plaintext, AES/session keys, decrypted callback XML, or complete encrypted callback payload.
- Each implementation task uses separate RED and GREEN commits. Push/verify each RED exact SHA fails only for the intended missing behavior; push/verify each GREEN exact SHA is fully green before proceeding.
- R8D migration registers permission definitions only. It does **not** silently grant them to existing roles; role/permission assignment remains an explicit IAM administrative action.
- No force pushes. Release is a fresh-main non-force fast-forward only after the feature exact HEAD is green, followed by independent main CI on the same SHA.

## Locked File Structure

- `app/iam/infrastructure/ThinkPhpAdminSessionRepository.php` — production adapter for existing `AdminSessionRepository`.
- `app/iam/security/BearerTokenParser.php` — strict Bearer token parsing.
- `app/iam/contract/AdminTenantAccess.php` + `app/iam/infrastructure/ThinkPhpAdminTenantAccess.php` — active Tenant membership validation.
- `app/iam/contract/PermissionAuthorizer.php` + `app/iam/infrastructure/ThinkPhpPermissionAuthorizer.php` — server-side permission evaluation.
- `app/api/middleware/OpenPlatformAdminContextMiddleware.php` — hydrate trusted Tenant/principal only for admin OpenPlatform routes.
- `app/openplatform/domain/OpenPlatformPermission.php` + `app/openplatform/application/OpenPlatformAdminGuard.php` — stable permissions and action-level guard.
- `app/openplatform/domain/AuthorizationIntentMode.php` + `AuthorizationIntent.php` — explicit existing-account versus auto-provision intent mode.
- `app/openplatform/contract/AuthorizerAccountEligibility.php` — local eligibility before provider pre-auth call.
- `app/openplatform/domain/AuthorizerInfoResponse.php`, `AuthorizerMetadata.php`, `AuthorizerMetadataRecord.php` — whitelisted provider response, normalized metadata, persisted current projection.
- `app/openplatform/application/AuthorizerMetadataNormalizer.php`, `AuthorizerMetadataSyncService.php` — trusted classification, normalization, semantic hash, synchronization.
- `app/openplatform/contract/AuthorizerMetadataRepository.php` + `ThinkPhpAuthorizerMetadataRepository.php` — current metadata and immutable snapshots.
- `app/openplatform/domain/AuthorizerAccountOwnership.php` + ownership repository — canonical ownership fact.
- `app/openplatform/contract/AuthorizerConnectionStore.php` + ThinkPHP adapter — subtype provider connection enable/disable.
- `app/openplatform/domain/AuthorizerProvisioning*.php`, `ProvisioningJob*.php` + repositories — durable provisioning state and claim lease.
- `app/openplatform/domain/AuthorizerOwnershipResolution.php` + `AuthorizerOwnershipResolver.php` — unowned/same-owner/other-owner decision.
- `app/openplatform/contract/AuthorizerAccountFinalizer.php` + ThinkPHP adapter — atomic Account + subtype binding + ownership + provisioning finalization.
- `app/openplatform/application/AuthorizerProvisioningWorker.php`, `AuthorizerProvisioningQuotaService.php`, `AuthorizerProvisioningRetryService.php`, `AuthorizerProvisioningQueryService.php`, `AuthorizerMetadataRefreshService.php`, `AuthorizerConnectionService.php` — workflow application services.
- `database/migrations/20260909_009_openplatform_authorizer_provisioning_{up,down}.sql` — R8D schema and permission catalog.
- `config/openplatform.php` + `.env.example` — callback URI, HTTP timeout, secret-cipher key configuration.
- `app/api/controller/V1/OpenPlatformProvisioningController.php`, `OpenPlatformAuthorizerMetadataController.php`, `app/api/route/app.php` — admin query/retry/refresh routes.
- `app/AppService.php` — explicit interface bindings/factories required for production R8C/R8D HTTP resolution.

---

### Task 1: Trusted Admin Tenant Context and Permission Boundary

**Files:**
- Create: `app/iam/infrastructure/ThinkPhpAdminSessionRepository.php`
- Create: `app/iam/security/BearerTokenParser.php`
- Create: `app/iam/contract/AdminTenantAccess.php`
- Create: `app/iam/contract/PermissionAuthorizer.php`
- Create: `app/iam/infrastructure/ThinkPhpAdminTenantAccess.php`
- Create: `app/iam/infrastructure/ThinkPhpPermissionAuthorizer.php`
- Create: `app/api/middleware/OpenPlatformAdminContextMiddleware.php`
- Create: `app/openplatform/domain/OpenPlatformPermission.php`
- Create: `app/openplatform/application/OpenPlatformAdminGuard.php`
- Test: `tests/Unit/Iam/BearerTokenParserTest.php`
- Test: `tests/Component/Iam/ThinkPhpAdminSessionRepositoryTest.php`
- Test: `tests/Component/Iam/OpenPlatformAdminContextMiddlewareTest.php`
- Test: `tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php`
- Modify: `tests/run.php`

**Produces:**
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

- [ ] **Step 1: Add RED tests** for: valid/invalid Bearer header; active versus expired admin session; inactive admin user returning no session; missing `X-Tenant-Id`; non-member Tenant -> 403; valid member context containing `Principal(userId, 'admin')`; missing permission -> 403; tenant-level permission and exact account permission -> allowed.

- [ ] **Step 2: Run RED tests**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Iam/BearerTokenParserTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/Iam/ThinkPhpAdminSessionRepositoryTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/Iam/OpenPlatformAdminContextMiddlewareTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php';"
```
Expected: RED because the adapters/guard do not exist.

- [ ] **Step 3: Commit/push RED**
```bash
git add tests/run.php tests/Unit/Iam/BearerTokenParserTest.php tests/Component/Iam/ThinkPhpAdminSessionRepositoryTest.php tests/Component/Iam/OpenPlatformAdminContextMiddlewareTest.php tests/Component/OpenPlatform/OpenPlatformAdminGuardTest.php
git commit -m "test: define R8D admin authorization boundary"
git push origin HEAD
```

- [ ] **Step 4: Implement `ThinkPhpAdminSessionRepository`**. `findByTokenHash()` joins `admin_sessions` to `admin_users`; only `admin_users.status='active'` returns an `AdminSession`; map `id`, `admin_user_id`, `token_hash`, `issued_at`, `expires_at` exactly.

- [ ] **Step 5: Implement Tenant/permission adapters**. `ThinkPhpAdminTenantAccess` requires an active `tenants` row and exact `tenant_memberships(tenant_id, admin_user_id)`. `ThinkPhpPermissionAuthorizer` evaluates `permission_assignments -> roles -> role_permissions -> permissions`, requires matching `tenant_id`, and allows either tenant-level assignment (`account_id IS NULL`) or exact requested `account_id`; no match -> `FORBIDDEN` 403.

- [ ] **Step 6: Implement middleware/guard**. Parse `Authorization: Bearer <token>`, restore session, require `X-Tenant-Id`, assert membership, replace current container `RequestContext` while preserving request/trace/runtime/site/locale/clientIp and setting trusted tenant/principal. `OpenPlatformAdminGuard::require()` rejects missing tenant/principal with `UNAUTHORIZED` 401 and calls `PermissionAuthorizer::assertAllowed()`.

- [ ] **Step 7: Run GREEN gate and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/iam app/api/middleware/OpenPlatformAdminContextMiddleware.php app/openplatform/domain/OpenPlatformPermission.php app/openplatform/application/OpenPlatformAdminGuard.php tests
git commit -m "feat: add tenant-scoped OpenPlatform admin authorization"
git push origin HEAD
```
Verify exact GREEN SHA CI.

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
- Create: `tests/Component/OpenPlatform/OpenPlatformAuthorizationStartControllerTest.php`
- Modify: `tests/run.php`

**Produces:**
```php
enum AuthorizationIntentMode: string
{
    case BIND_EXISTING_ACCOUNT = 'bind_existing_account';
    case AUTO_PROVISION_ACCOUNT = 'auto_provision_account';
}

interface AuthorizerAccountEligibility
{
    public function assertTenantEligible(string $tenantId, string $componentPlatformId): void;
    public function assertExistingAccountEligible(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
    ): AccountType;
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

- [ ] **Step 1: Add RED domain tests** proving `BIND_EXISTING_ACCOUNT` requires non-null target, `AUTO_PROVISION_ACCOUNT` requires null target, and old claim/completion/expiry behavior remains unchanged.

- [ ] **Step 2: Add RED application/controller tests** proving eligibility failure performs zero component-token/pre-auth provider calls; Tenant comes from `RequestContext`; supplied `tenantId`/`tenant_id` is only an equality assertion; auto mode requires `START + PROVISION`; bind mode requires `START + BIND`.

- [ ] **Step 3: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/AuthorizationIntentTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizationStartServiceTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/OpenPlatformAuthorizationStartControllerTest.php';"
git add tests
git commit -m "test: define R8D authorization intent modes"
git push origin HEAD
```

- [ ] **Step 4: Implement mode/nullable target** in `AuthorizationIntent` constructor, factories, reconstitution, getters and repository mapping. Add `mode(): AuthorizationIntentMode` and `targetAccountId(): ?string`.

- [ ] **Step 5: Implement local eligibility before any provider access**. Existing-account validation requires active Tenant, active Account belonging to Tenant, supported type (`official_account` or `wechat_mini_program`), and enabled Component Platform. Auto mode validates active Tenant and enabled Component Platform.

- [ ] **Step 6: Update controller request contract**: `mode` default `bind_existing_account`; accept camelCase and legacy snake_case target/auth-type keys; never source Tenant ownership from body. Convert invalid enum/shape to `INVALID_ARGUMENT` 400.

- [ ] **Step 7: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform app/api/controller/V1/OpenPlatformAuthorizationStartController.php tests
git commit -m "feat: add explicit OpenPlatform authorization intent modes"
git push origin HEAD
```

---

### Task 3: R8D Migration and Schema Contract

**Files:**
- Create: `database/migrations/20260909_009_openplatform_authorizer_provisioning_up.sql`
- Create: `database/migrations/20260909_009_openplatform_authorizer_provisioning_down.sql`
- Create: `tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Add RED SQL contract** checking intent mode/nullable target/check constraint; metadata current/snapshot keys; canonical ownership PK and unique account; Official Account provider table; provisioning source-intent uniqueness; job PK; all six permission definitions; down migration symmetry.

- [ ] **Step 2: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php';"
git add tests/Contract/OpenPlatformAuthorizerProvisioningSchemaContractTest.php tests/run.php
git commit -m "test: define R8D provisioning schema"
git push origin HEAD
```

- [ ] **Step 3: Implement `_009` up migration**. Backfill all existing intents as `bind_existing_account`; make `target_account_id` nullable; add exact mode/target CHECK. Create `authorizer_metadata_current`, `authorizer_metadata_snapshots`, `authorizer_account_ownerships`, `official_account_provider_accounts`, `authorizer_provisionings`, `authorizer_provisioning_jobs` with the approved fields/FKs/indexes. Snapshot uniqueness is `(component_platform_id, authorizer_app_id, version)`, not hash uniqueness.

- [ ] **Step 4: Register permission catalog only** with deterministic ids for the six `openplatform.authorizer.*` permission keys. Do not insert `role_permissions` rows.

- [ ] **Step 5: Implement exact destructive down order**:
```sql
DROP TABLE IF EXISTS `authorizer_provisioning_jobs`;
DROP TABLE IF EXISTS `authorizer_provisionings`;
DROP TABLE IF EXISTS `official_account_provider_accounts`;
DROP TABLE IF EXISTS `authorizer_account_ownerships`;
DROP TABLE IF EXISTS `authorizer_metadata_snapshots`;
DROP TABLE IF EXISTS `authorizer_metadata_current`;
DELETE FROM `permissions` WHERE `permission_key` IN (
  'openplatform.authorizer.read',
  'openplatform.authorizer.start',
  'openplatform.authorizer.bind',
  'openplatform.authorizer.provision',
  'openplatform.authorizer.refresh_metadata',
  'openplatform.authorizer.retry_provision'
);
DELETE FROM `openplatform_authorization_intents`
 WHERE `intent_mode`='auto_provision_account' OR `target_account_id` IS NULL;
ALTER TABLE `openplatform_authorization_intents`
  DROP CHECK `chk_openplatform_intent_mode_target`,
  DROP COLUMN `intent_mode`,
  MODIFY `target_account_id` varchar(64) NOT NULL;
```
If the up migration adds an FK to the existing Mini Program provider table, drop that FK before dropping other R8D objects.

- [ ] **Step 6: Run GREEN and commit**
```bash
php tests/run.php
git add database/migrations/20260909_009_openplatform_authorizer_provisioning_up.sql database/migrations/20260909_009_openplatform_authorizer_provisioning_down.sql tests
git commit -m "feat: add R8D authorizer provisioning schema"
git push origin HEAD
```

---

### Task 4: Trusted Provider Metadata and Normalization

**Files:**
- Create: `app/openplatform/domain/AuthorizerInfoResponse.php`
- Create: `app/openplatform/domain/AuthorizerMetadata.php`
- Create: `app/openplatform/application/AuthorizerMetadataNormalizer.php`
- Modify: `app/openplatform/contract/AuthorizerClient.php`
- Modify: `app/openplatform/infrastructure/WechatAuthorizerClient.php`
- Modify: `tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php`
- Create: `tests/Unit/OpenPlatform/AuthorizerMetadataNormalizerTest.php`
- Create: `tests/GoldenMaster/OpenPlatformAuthorizerMetadataNormalizationTest.php`
- Create: `tests/GoldenMaster/fixtures/openplatform/official-account-authorizer-info.json`
- Create: `tests/GoldenMaster/fixtures/openplatform/mini-program-authorizer-info.json`
- Modify: `tests/run.php`

**Produces:**
```php
public function AuthorizerClient::getAuthorizerInfo(
    string $componentAppId,
    string $componentAccessToken,
    string $authorizerAppId,
): AuthorizerInfoResponse;
```

- [ ] **Step 1: Add RED provider test** for exact `POST https://api.weixin.qq.com/cgi-bin/component/api_get_authorizer_info?component_access_token=<token>` with body `component_appid` + `authorizer_appid`, provider non-zero `errcode` -> `BAD_GATEWAY` 502.

- [ ] **Step 2: Add RED normalizer/Golden Master tests**: `MiniProgramInfo` present -> `WECHAT_MINI_PROGRAM`; absent -> `OFFICIAL_ACCOUNT`; same semantic data with different JSON key order -> same SHA-256 metadata hash; fixtures contain no token/credential fields.

- [ ] **Step 3: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/AuthorizerMetadataNormalizerTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/WechatAuthorizerClientTest.php';"
git add tests
git commit -m "test: define trusted OpenPlatform authorizer metadata"
git push origin HEAD
```

- [ ] **Step 4: Implement whitelisted DTO/normalizer/client method**. Persistable whitelist: nickname, head image, original/user id, principal name, alias, service type, verify type, business info, qrcode, optional MiniProgramInfo. Normalize recursively with deterministic key order before JSON encoding/hash. Never retain raw provider JSON as domain state.

- [ ] **Step 5: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: normalize trusted authorizer metadata"
git push origin HEAD
```

---

### Task 5: Metadata Current Projection and Immutable History

**Files:**
- Create: `app/openplatform/domain/AuthorizerMetadataRecord.php`
- Create: `app/openplatform/contract/AuthorizerMetadataRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerMetadataRepository.php`
- Create: `app/openplatform/application/AuthorizerMetadataSyncService.php`
- Create: `tests/Component/OpenPlatform/AuthorizerMetadataSyncServiceTest.php`
- Create: `tests/Contract/ThinkPhpOpenPlatformMetadataPersistenceContractTest.php`
- Modify: `tests/run.php`

**Produces:**
```php
final readonly class AuthorizerMetadataRecord
{
    public function componentPlatformId(): string;
    public function authorizerAppId(): string;
    public function accountType(): AccountType;
    public function nickName(): string;
    public function normalizedMetadataJson(): string;
    public function metadataHash(): string;
    public function providerFetchedAt(): DateTimeImmutable;
    public function version(): int;
}

interface AuthorizerMetadataRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord;
    public function observe(AuthorizerMetadata $metadata, DateTimeImmutable $fetchedAt, string $source): AuthorizerMetadataRecord;
}
```

- [ ] **Step 1: Add RED tests** proving first observation creates v1 snapshot; identical semantic hash only updates `provider_fetched_at`; A->B->A produces versions 1/2/3; provider failure does not mutate `AuthorizerAuthorization`.

- [ ] **Step 2: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerMetadataSyncServiceTest.php';"
git add tests
git commit -m "test: define versioned authorizer metadata persistence"
git push origin HEAD
```

- [ ] **Step 3: Implement repository transaction** locking current canonical row; same hash -> fetched timestamp only; changed hash -> increment version, insert immutable snapshot, update current projection in one transaction.

- [ ] **Step 4: Implement sync service** using `ComponentAccessTokenService` + `AuthorizerClient::getAuthorizerInfo()` + normalizer + repository. The service has no API that updates `accounts.name`.

- [ ] **Step 5: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: persist versioned authorizer metadata"
git push origin HEAD
```

---

### Task 6: Canonical Ownership and Subtype Provider Connections

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

**Produces:**
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
Existing `AuthorizerAccountBinding::bindExistingAccount()` signature stays unchanged.

- [ ] **Step 1: Add RED tests**: first explicit Account binding creates ownership; same owner is idempotent/reconnect; same canonical authorizer to another Account/Tenant -> `CONFLICT` 409 and no move; disabled binding still owns; Mini Program writes only miniapp table; Official Account writes only official-account table.

- [ ] **Step 2: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerOwnershipResolverTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/MiniApp/OpenPlatformAuthorizerAccountBindingTest.php';"
git add tests
git commit -m "test: define global authorizer ownership"
git push origin HEAD
```

- [ ] **Step 3: Implement ownership-aware binding** with canonical ownership lock before provider-table lock. Validate explicit Account type and route to the correct subtype provider table. `enabled=0` never deletes ownership.

- [ ] **Step 4: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform app/miniapp tests
git commit -m "feat: enforce canonical authorizer ownership"
git push origin HEAD
```

---

### Task 7: Provisioning Aggregate and Durable Lease Job

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

**Produces:** statuses exactly `PENDING_METADATA`, `METADATA_READY`, `QUOTA_CONSUMED`, `PROVISIONED`, `RECONNECTED`, `QUOTA_BLOCKED`, `BINDING_CONFLICT`, `METADATA_FAILED`, `METADATA_TYPE_CONFLICT`, `PROVISION_FAILED`, `AUTHORIZATION_INACTIVE`; no `CANCELLED` state.

```php
interface AuthorizerProvisioningRepository
{
    public function insert(AuthorizerProvisioning $provisioning): void;
    public function find(string $id): ?AuthorizerProvisioning;
    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning;
    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning;
    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool;
}

interface ProvisioningJobRepository
{
    public function insert(ProvisioningJob $job): void;
    public function tryClaim(string $provisioningId, string $holderId, DateTimeImmutable $now, int $ttlSeconds): ?ProvisioningJob;
    public function release(string $provisioningId, string $holderId, DateTimeImmutable $nextAttemptAt, ?string $errorCode): bool;
    public function complete(string $provisioningId, string $holderId): bool;
    public function dead(string $provisioningId, string $holderId, string $errorCode): bool;
}
```

- [ ] **Step 1: Add RED state-transition tests** for approved normal/terminal paths and rejection of invalid jumps. Verify `account_type` can be frozen once; a later different trusted type yields `METADATA_TYPE_CONFLICT` without replacing it.

- [ ] **Step 2: Add RED lease tests**: two simultaneous claims -> one winner; live claim blocks loser; expired claim can be acquired; stale old holder cannot release/complete after ownership/version changed.

- [ ] **Step 3: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/OpenPlatform/AuthorizerProvisioningTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/ProvisioningJobClaimTest.php';"
git add tests
git commit -m "test: define durable provisioning state and lease"
git push origin HEAD
```

- [ ] **Step 4: Implement aggregate/repositories**. Job acquisition accepts READY due now or CLAIMED with expired lease, sets holder/expiry=`now+60s`, increments attempt count, and uses conditional update/CAS. Maximum attempt policy is enforced by the worker, not repository.

- [ ] **Step 5: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: add durable authorizer provisioning state"
git push origin HEAD
```

---

### Task 8: R8C Completion Branch and Atomic Durable Provisioning Trigger

**Files:**
- Modify: `app/openplatform/application/AuthorizationCompletionService.php`
- Modify: `app/openplatform/domain/AuthorizerAuthorizationResult.php`
- Modify: `app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationCallbackServiceTest.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationEventServiceTest.php`

- [ ] **Step 1: Add RED branch tests**. Existing-account mode still binds synchronously. Auto mode must atomically write AuthorizerAuthorization + one Provisioning(`PENDING_METADATA`) + one Job(`READY`) + intent completion and make zero `AuthorizerAccountBinding` calls.

- [ ] **Step 2: Add RED rollback/arbitration tests**. Inject failure after authorization write, provisioning insert, and job insert; completed intent without job must never remain. Browser callback versus matching authorized event shares the R8C claim and causes exactly one `queryAuthorization()` provider call.

- [ ] **Step 3: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php';"
git add tests/Component/OpenPlatform
git commit -m "test: define atomic auto-provision completion trigger"
git push origin HEAD
```

- [ ] **Step 4: Implement `AuthorizerAuthorizationResult::provisioning(string $authorizerAppId, string $provisioningId)`** plus getters/status serialization.

- [ ] **Step 5: Implement completion branch inside the existing short transaction**. Auto branch creates deterministic durable records and completes intent; it performs no metadata, quota or Account calls.

- [ ] **Step 6: Update callback controller response** to return `{status:'provisioning', authorizer_app_id, provisioning_id}` for auto mode while keeping completed/processing compatibility.

- [ ] **Step 7: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php tests
git commit -m "feat: trigger R8D provisioning after authorization"
git push origin HEAD
```

---

### Task 9: Worker Metadata and Ownership Stages

**Files:**
- Create: `app/openplatform/domain/AuthorizerOwnershipResolution.php`
- Create: `app/openplatform/application/AuthorizerOwnershipResolver.php`
- Create: `app/openplatform/application/AuthorizerConnectionService.php`
- Create: `app/openplatform/application/AuthorizerProvisioningWorker.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php`
- Modify: `tests/run.php`

**Produces:**
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

- [ ] **Step 1: Add RED worker tests**: inactive authorization -> `AUTHORIZATION_INACTIVE` and zero metadata/quota; provider metadata timeout -> retry schedule and auth stays ACTIVE; metadata success freezes type/version; same owner -> `RECONNECTED` with existing Account and zero quota; other owner -> `BINDING_CONFLICT` and zero quota/Account writes; unowned remains eligible for Task 10 quota stage.

- [ ] **Step 2: Add RED backoff/attempt tests** for exact delays and maximum 10 automatic acquisitions; after max retryable attempts mark `METADATA_FAILED` or `PROVISION_FAILED` according to current stage and job DEAD.

- [ ] **Step 3: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php';"
git add tests
git commit -m "test: define provisioning metadata and ownership stages"
git push origin HEAD
```

- [ ] **Step 4: Implement resolver/connection service/worker orchestration**. Worker generates 16-byte random holder id, claims for 60s, reloads current facts after claim, and never trusts prior process state. SAME_OWNER reconnects through connection service; OTHER_OWNER records conflict; retryable exceptions release job with deterministic delay.

- [ ] **Step 5: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: advance provisioning through metadata and ownership"
git push origin HEAD
```

---

### Task 10: Quota Saga, Atomic Account Finalization, Recovery and Compensation

**Files:**
- Create: `app/openplatform/contract/AuthorizerAccountFinalizer.php`
- Create: `app/openplatform/infrastructure/ThinkPhpAuthorizerAccountFinalizer.php`
- Create: `app/openplatform/application/AuthorizerProvisioningQuotaService.php`
- Modify: `app/openplatform/application/AuthorizerProvisioningWorker.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningQuotaTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerAccountFinalizerTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningRecoveryTest.php`
- Modify: `tests/run.php`

**Produces:**
```php
interface AuthorizerAccountFinalizer
{
    public function provision(
        AuthorizerProvisioning $provisioning,
        AuthorizerMetadataRecord $metadata,
        DateTimeImmutable $now,
    ): string;

    public function reconcile(AuthorizerProvisioning $provisioning): ?string;
}
```
Consume idempotency key: `openplatform-provision:<componentPlatformId>:<authorizerAppId>:<tenantId>`.
Release idempotency key: `openplatform-provision-release:<provisioningId>`.

- [ ] **Step 1: Add RED quota tests**: first unowned provisioning consumes exactly `1` of `QuotaResource::accountCreate(frozenType)`; repeated worker runs return same semantic ledger entry; `QUOTA_BLOCKED` creates no Account; reconnect consumes zero.

- [ ] **Step 2: Add RED finalizer tests**: one DB transaction inserts `accounts`, exactly one subtype provider row, canonical ownership and provisioning final status; injected failure at each write rolls back all four. Official Account never enters miniapp table and vice versa.

- [ ] **Step 3: Add RED recovery tests**: consume succeeds before consume-entry reference saved -> re-call consume with same key recovers same entry; consume ref saved before Account create -> no second consume; Account commit but worker misses result -> reconcile canonical ownership/binding to original Account; transient DB error after quota -> no release; terminal failure with proven no Account -> one idempotent release.

- [ ] **Step 4: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningQuotaTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerAccountFinalizerTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningRecoveryTest.php';"
git add tests
git commit -m "test: define quota and Account provisioning recovery"
git push origin HEAD
```

- [ ] **Step 5: Implement quota adapter** as a thin wrapper over existing `QuotaService`; do not duplicate allocation rules. Persist returned consume/release ledger ids on provisioning using CAS.

- [ ] **Step 6: Implement finalizer transaction**. Lock provisioning then canonical ownership; re-check authorization ACTIVE and frozen type; create Account with provider nickname or fallback (`微信小程序 · <last8>` / `微信公众号 · <last8>`); create subtype binding + ownership; finalize `PROVISIONED`. Later metadata refresh never writes `accounts.name`.

- [ ] **Step 7: Implement reconciliation before release**. If canonical ownership/binding points to the same provisioning Tenant/authorizer, repair provisioning to `PROVISIONED`; only when no Account/ownership/binding exists may a terminal path release quota.

- [ ] **Step 8: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: provision Accounts with idempotent quota recovery"
git push origin HEAD
```

---

### Task 11: Unauthorized, Reauthorization, Event-Only and Type-Conflict Lifecycle

**Files:**
- Modify: `app/openplatform/application/AuthorizationEventService.php`
- Modify: `app/openplatform/application/AuthorizerConnectionService.php`
- Modify: `app/openplatform/application/AuthorizerMetadataSyncService.php`
- Modify: `tests/Component/OpenPlatform/AuthorizationEventServiceTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerReconnectLifecycleTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerMetadataTypeConflictTest.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Add RED unauthorized/reconnect tests** proving unauthorized keeps Account/ownership/metadata/quota but disables subtype binding; same canonical reauthorization restores same Account with zero quota; SUSPENDED stays suspended; DELETED is not resurrected.

- [ ] **Step 2: Add RED event-only tests** proving no-intent/no-ownership authorized event only updates platform authorization/metadata, with zero provisioning/Account/quota; historical ownership permits reconnect; `updateauthorized` never moves/creates ownership.

- [ ] **Step 3: Add RED type-conflict test**: provision Mini Program, then trusted metadata reports Official Account -> `METADATA_TYPE_CONFLICT`; Account type/id unchanged; no second Account/quota.

- [ ] **Step 4: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerReconnectLifecycleTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerMetadataTypeConflictTest.php';"
git add tests
git commit -m "test: define R8D disconnect and reconnect lifecycle"
git push origin HEAD
```

- [ ] **Step 5: Implement projection hooks only after accepted R8C ordering transition**. Do not alter newer-wins/same-timestamp/older-no-op semantics. Connection projection failure after authoritative authorization update is audited/reconciled and does not roll back provider truth.

- [ ] **Step 6: Run GREEN and commit**
```bash
php tests/run.php
php vendor/bin/phpunit
git add app/openplatform tests
git commit -m "feat: preserve Account ownership across authorizer lifecycle"
git push origin HEAD
```

---

### Task 12: Admin Query/Retry/Metadata Refresh APIs and Production Wiring

**Files:**
- Create: `app/openplatform/application/AuthorizerProvisioningQueryService.php`
- Create: `app/openplatform/application/AuthorizerProvisioningRetryService.php`
- Create: `app/openplatform/application/AuthorizerMetadataRefreshService.php`
- Create: `app/api/controller/V1/OpenPlatformProvisioningController.php`
- Create: `app/api/controller/V1/OpenPlatformAuthorizerMetadataController.php`
- Create: `config/openplatform.php`
- Modify: `.env.example`
- Modify: `app/api/route/app.php`
- Modify: `app/AppService.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningQueryServiceTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningRetryServiceTest.php`
- Create: `tests/Component/OpenPlatform/AuthorizerMetadataRefreshServiceTest.php`
- Create: `tests/Component/OpenPlatform/OpenPlatformProvisioningControllerTest.php`
- Create: `tests/Contract/R8DOpenPlatformWiringContractTest.php`
- Modify: `tests/run.php`
- Modify: `.github/workflows/ci.yml`

**HTTP routes:**
```text
GET  /api/v1/openplatform/provisionings/:id
POST /api/v1/openplatform/provisionings/:id/retry
POST /api/v1/openplatform/components/:componentPlatformId/authorizers/:authorizerAppId/metadata/refresh
```
Admin middleware attaches to authorization-start and these three admin endpoints only. It must not attach to `/events`, `/ticket`, or `/authorization/callback`.

**Runtime config:**
```php
// config/openplatform.php
return [
    'authorization_callback_uri' => env('WEPLATFORM_OPENPLATFORM_AUTHORIZATION_CALLBACK_URI', ''),
    'http_timeout_seconds' => (int) env('WEPLATFORM_OPENPLATFORM_HTTP_TIMEOUT_SECONDS', 10),
    'secret_key_version' => env('WEPLATFORM_OPENPLATFORM_SECRET_KEY_VERSION', 'v1'),
    'secret_key_base64' => env('WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64', ''),
];
```
`.env.example` adds the four matching keys. `AppService` base64-decodes `secret_key_base64` and requires exactly 32 decoded bytes before constructing `OpenSslOpenPlatformSecretCipher`; plaintext key is never logged.

- [ ] **Step 1: Add RED service/controller tests**: no session -> 401; missing action permission -> 403; cross-Tenant provisioning id -> 404; valid query -> 200 even for business failure status; accepted retry -> 202 and resumes current stage rather than resetting; metadata refresh requires current Tenant ownership **or** a current Tenant provisioning for that canonical authorizer, otherwise 404.

- [ ] **Step 2: Add RED wiring contract**. Require explicit mappings for new IAM/R8D interfaces plus existing OpenPlatform transport/cipher/authorizer client and transaction manager. Also assert `app()->make()` can construct authorization-start, callback/event, provisioning and metadata-refresh controllers with test configuration.

Required mappings include:
```text
AdminSessionRepository -> ThinkPhpAdminSessionRepository
AdminTenantAccess -> ThinkPhpAdminTenantAccess
PermissionAuthorizer -> ThinkPhpPermissionAuthorizer
AuthorizationIntentRepository -> ThinkPhpAuthorizationIntentRepository
AuthorizerAccountEligibility -> ThinkPhpAuthorizerAccountEligibility
AuthorizerAccountBinding -> OpenPlatformAuthorizerAccountBinding
AuthorizerAuthorizationRepository -> ThinkPhpAuthorizerAuthorizationRepository
AuthorizerMetadataRepository -> ThinkPhpAuthorizerMetadataRepository
AuthorizerOwnershipRepository -> ThinkPhpAuthorizerOwnershipRepository
AuthorizerProvisioningRepository -> ThinkPhpAuthorizerProvisioningRepository
ProvisioningJobRepository -> ThinkPhpProvisioningJobRepository
AuthorizerConnectionStore -> ThinkPhpAuthorizerConnectionStore
AuthorizerAccountFinalizer -> ThinkPhpAuthorizerAccountFinalizer
OpenPlatformHttpTransport -> NativeOpenPlatformHttpTransport
AuthorizerClient -> WechatAuthorizerClient
OpenPlatformSecretCipher -> OpenSslOpenPlatformSecretCipher
TransactionManager -> ThinkPhpTransactionManager
```
For any additional R8C interface constructor dependency discovered by the controller-resolution assertion, add its already-existing ThinkPHP/WeChat implementation to the same explicit binding table; do not create a second abstraction for it.

- [ ] **Step 3: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/R8DOpenPlatformWiringContractTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/OpenPlatformProvisioningControllerTest.php';"
git add tests
git commit -m "test: define R8D admin API and runtime wiring"
git push origin HEAD
```

- [ ] **Step 4: Implement tenant-scoped query/retry/refresh services**. Query repository always uses `(id, contextTenantId)`; retry revalidates current authorization, ownership, quota reference, Account/binding facts and queues/resumes the persisted stage; metadata refresh first proves ownership or Tenant provisioning, then calls metadata sync only—never quota/provision.

- [ ] **Step 5: Implement routes and middleware attachment** using `Route::...()->middleware(OpenPlatformAdminContextMiddleware::class)` on admin routes only.

- [ ] **Step 6: Implement config/DI factories** in `AppService::register()`. Bind interface-to-class mappings above, construct `WechatAuthorizerClient` with configured timeout/transport, construct `AuthorizationStartService` with configured HTTPS callback URI, construct secret cipher only from validated decoded key material.

- [ ] **Step 7: Extend CI HTTP smoke** to prove an unauthenticated admin provisioning route resolves and returns 401, while provider ingress routes are not transformed into admin-auth 401. No live WeChat call is made.

- [ ] **Step 8: Run GREEN and commit**
```bash
composer validate --strict
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
git add .env.example app config tests .github/workflows/ci.yml
git commit -m "feat: expose and wire R8D provisioning APIs"
git push origin HEAD
```

---

### Task 13: Concurrency, Crash Matrix, Secret/Audit Gates and Release

**Files:**
- Create: `tests/Component/OpenPlatform/AuthorizerProvisioningConcurrencyTest.php`
- Expand: `tests/Component/OpenPlatform/AuthorizerProvisioningRecoveryTest.php`
- Create: `tests/Contract/R8DOpenPlatformSecretScanContractTest.php`
- Create: `tests/Contract/R8DOpenPlatformArchitectureSecurityContractTest.php`
- Modify: `tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Add RED crash-matrix tests** for: metadata returned before save; metadata saved before provisioning transition; before quota; quota success before consume-ref persistence; consume-ref persisted before Account transaction; transaction rollback; transaction commit but worker misses result; reconnect binding enabled before final status. Every recovery must end with `Account count <= 1`, `ownership count <= 1`, semantic quota consume count `<= 1`.

- [ ] **Step 2: Add RED two-worker concurrency test**. Exactly one 60s claim winner; loser performs zero provider/quota/Account writes; expired claim recoverable; expired holder late-save rejected.

- [ ] **Step 3: Add RED secret scan** with sentinels `SECRET_STATE_123`, `SECRET_PREAUTH_123`, `SECRET_AUTH_CODE_123`, `SECRET_REFRESH_TOKEN_123`, `SECRET_ACCESS_TOKEN_123`; assert absent from audit payloads, jobs, normalized metadata, exceptions and captured logs.

- [ ] **Step 4: Add RED audit assertions** for actors `admin:<principalId>`, `external:wechat-openplatform`, `system:openplatform-provisioning-worker` and actions: authorization start/complete, metadata refresh/change, provisioning created/metadata-ready/quota-consumed/quota-released/provisioned/reconnected/binding-conflict/failed/retry-requested, connection disconnected. Audit metadata contains safe ids/versions/counts/ledger ids/error code+stage/request+trace only.

- [ ] **Step 5: Run/commit RED**
```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningConcurrencyTest.php';"
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/R8DOpenPlatformSecretScanContractTest.php';"
git add tests
git commit -m "test: gate R8D crash safety and secret isolation"
git push origin HEAD
```

- [ ] **Step 6: Implement only the hardening/audit changes required by those RED tests**, preserving all prior domain boundaries and never putting secret/raw provider payloads into audit/job metadata.

- [ ] **Step 7: Run complete local release gate**
```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```
Expected: all PASS.

- [ ] **Step 8: Commit/push final GREEN**
```bash
git add app tests
git commit -m "test: complete R8D provisioning release gates"
git push origin HEAD
```

- [ ] **Step 9: Verify feature exact-HEAD CI**. Record exact feature SHA and workflow/PR run; all required jobs must be success, not stale/cancelled/partial.

- [ ] **Step 10: Fresh-main race check**
```bash
git fetch origin main
git merge-base --is-ancestor origin/main HEAD
git rev-list --left-right --count origin/main...HEAD
```
Expected: feature behind count `0`. If main advanced incompatibly, integrate fresh main and rerun the full release gate; never force push.

- [ ] **Step 11: Non-force fast-forward release**
```bash
git push origin HEAD:main
```
This command is permitted only after Step 10 proves fast-forward. Never use `--force` or `--force-with-lease`.

- [ ] **Step 12: Verify independent main CI** on the exact released SHA. Only after push/main CI is fully green report R8D released.

## Self-Review Result

Spec coverage is complete:
- Spec §§4,18,19 -> Tasks 1–3,12.
- Spec §5 -> Tasks 4–5.
- Spec §§7–8 -> Task 6.
- Spec §§9–10 -> Task 7.
- Spec §11 -> Task 8.
- Spec §§12–14 -> Tasks 9–10.
- Spec §§15–16 -> Task 11.
- Spec §§20–21 -> Tasks 1,12,13.
- Spec §§22–25 -> Tasks 3–13, with Task 13 as final concurrency/security/release gate.

Self-review corrections incorporated into this file:
- added concrete production `ThinkPhpAdminSessionRepository`;
- removed undefined `CANCELLED` status from implementation plan;
- made `_009` down migration rollback behavior explicit and destructive rather than vague;
- added missing `AuthorizerOwnershipResolution` file/type;
- froze `AuthorizerMetadataRecord` getter names used by later tasks;
- made Tenant metadata-refresh anti-enumeration rule explicit;
- made OpenPlatform configuration keys and secret-key validation explicit;
- clarified permission definitions are not auto-granted to roles;
- removed conditional README work;
- replaced conceptual release text with exact non-force `git push origin HEAD:main` gate.

No task may introduce authorizer sharing/transfer, remote revoke, code-release/payment APIs, automatic Tenant creation, an external MQ, or a generic `wechat_provider_accounts` identity layer.