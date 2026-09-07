# IAM + Tenant + Account R2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first executable IAM/Tenant/Account domain slice that preserves confirmed WeEngine R20 account-role and account-type semantics while replacing `$_W`/`$_GPC` coupling with explicit immutable domain objects and legacy mappings.

**Architecture:** Keep compatibility decisions at the domain/application boundary. R20-specific role precedence, numeric account types, `uniacid`, and `acid` are isolated behind `Legacy*` objects; new Tenant/Account IDs are strings and never inferred from legacy IDs. Session restoration uses an opaque random token whose HMAC hash is stored server-side, intentionally replacing the R20 self-contained `__session` identity snapshot.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3 project skeleton, pure-PHP offline contract/unit tests, MySQL 8-compatible DDL.

**Spec:** `docs/design-source/WeEngine-ThinkPHP-Refactor-V4/10-epics/EPIC-02.md`, `docs/design-source/WeEngine-ThinkPHP-Refactor-V4/10-epics/EPIC-03.md`, `docs/design-source/WeEngine-ThinkPHP-Refactor-V4/01-architecture/04-security-authorization.md`.

## Global Constraints

- Preserve only behavior confirmed from R20 source/Golden Master; do not infer behavior from names.
- New application/domain code must not access `$_W` or `$_GPC`.
- Tenant-scoped records carry explicit `tenant_id` and indexes.
- New tables use InnoDB + utf8mb4 and explicit unique constraints.
- Legacy IDs (`uid`, `uniacid`, `acid`) are compatibility identifiers, not new primary keys.
- Confirmed R20 role precedence for a concrete account: main founder > expired > unbound > clerk-user-type > `uni_account_users.role`.
- Confirmed R20 cross-account priority: vice_founder > owner > manager > operator; historical no-role fallback to owner is preserved only in the compatibility resolver and marked `MUST_COMPAT`.
- Confirmed R20 account type mapping: 1/3=official account, 4/7=WeChat mini program, 5=webapp, 6=phoneapp, 11=Alipay mini app, 12=Baidu mini app, 13=Toutiao mini app.

---

### Task 1: R20 IAM compatibility role resolver

**Files:** `app/iam/domain/LegacyAccountRole.php`, `app/iam/domain/LegacyAdminState.php`, `app/iam/domain/LegacyAccountRoleResolver.php`, `tests/Unit/Iam/LegacyAccountRoleResolverTest.php`, `tests/run.php`.

**Interfaces:** `LegacyAccountRoleResolver::forAccount(LegacyAdminState, ?LegacyAccountRole): LegacyAccountRole`; `LegacyAccountRoleResolver::highest(LegacyAdminState, array): LegacyAccountRole`.

- [x] Write failing tests for founder/expired/unbound/clerk precedence, account role, cross-account priority and historical owner fallback.
- [x] Confirm RED on missing classes.
- [x] Implement confirmed R20 rules only.
- [x] Run focused and full offline suite green.
- [x] Commit `feat: model legacy IAM role precedence`.

### Task 2: Tenant, membership, account type and capability domain

**Files:** `app/tenant/domain/*`, `app/account/domain/Account*.php`, `app/account/domain/LegacyAccountTypeMap.php`, corresponding unit tests.

**Interfaces:** `LegacyAccountTypeMap::fromLegacyType(int): AccountType`; `LegacyAccountTypeMap::capabilities(int): array`; immutable `Tenant`, `TenantMembership`, `Account`.

- [x] Write failing domain/type-mapping tests.
- [x] Confirm RED.
- [x] Implement minimal domain enums/value objects/entities.
- [x] Run focused and full suite green.
- [x] Commit `feat: add tenant and account domain model`.

### Task 3: Explicit `uniacid` / `acid` compatibility mapping

**Files:** `app/account/domain/LegacyAccountMapping.php`, repository contract, resolver application service and tests.

**Interfaces:** `LegacyAccountMapping(accountId, tenantId, uniacid, acid, legacyType)`; repository lookups by uniacid/acid; `ResolveLegacyAccount::byUniacid(int, ?int)`.

- [x] Write failing unit/component tests for mapping and mismatch paths.
- [x] Confirm RED.
- [x] Implement mapping/repository/application contracts.
- [x] Run focused and full suite green.
- [x] Commit `feat: add legacy account mapping resolver`.

### Task 4: Server-side administrator session contract

**Files:** `app/iam/domain/AdminUser*.php`, `AdminSession.php`, session repository, HMAC token hasher, restoration service and tests.

**Interfaces:** immutable `AdminUser` with nullable expiry; `SessionTokenHasher::hash(string): string`; `AdminSessionRepository::findByTokenHash(string)`; `RestoreAdminSession::execute(string, DateTimeImmutable)`.

- [x] Write failing tests for identity expiry, token hashing/restoration and rejection paths.
- [x] Confirm RED.
- [x] Implement minimal contracts.
- [x] Run focused and full suite green.
- [x] Commit administrator identity/session changes.

### Task 5: MySQL migration and rollback contracts

**Files:** `database/migrations/20260907_001_iam_tenant_account_{up,down}.sql`, schema contract test, compatibility notes.

**Interfaces:** new tables `admin_users`, `admin_sessions`, `tenants`, `tenant_memberships`, `accounts`, `account_capabilities`, `legacy_mappings`.

- [x] Write failing DDL contract test.
- [x] Confirm RED.
- [x] Add exact up/down DDL and compatibility notes.
- [x] Run schema test, full suite, lint and legacy-global scan.
- [x] Commit `feat: add IAM tenant account schema`.

### Task 6: Verification and GitHub handoff

**Files:** `docs/verification/iam-tenant-account-r2.md`, `docs/verification/iam-tenant-account-r2-checks.txt`.

- [x] Run `php tests/run.php`.
- [x] Lint every PHP file with `php -l`.
- [x] Validate `composer.json` and scan `app/` for `$_W`/`$_GPC`.
- [x] Record exact results and known environment gaps.
- [x] Publish the tested R2 tree to GitHub `main` and re-fetch key files/commit SHA.
