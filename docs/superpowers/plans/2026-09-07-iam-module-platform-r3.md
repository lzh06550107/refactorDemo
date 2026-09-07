# IAM Authorization + Module Platform R3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add server-side IAM authorization plus a registry/binding/account-overlay module runtime that preserves confirmed WeEngine R20 module and permission semantics.

**Architecture:** R20-specific behavior is isolated in `Legacy*` policy/adapter classes. New module code consumes immutable `ModuleDefinition`, `AccountModuleConfig`, `RuntimeModule`, and `ModuleBinding` objects. Authorization requires module runtime availability before permission evaluation; menu projection is downstream of authorization.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3 project conventions, pure-PHP offline tests, MySQL 8-compatible DDL.

**Spec:** `docs/superpowers/specs/2026-09-07-iam-module-platform-r3-design.md`

## Global Constraints

- Do not access `$_W` or `$_GPC` from new `app/` code.
- Preserve only R20 behavior confirmed from source/Golden Master.
- Keep legacy IDs and row shapes behind compatibility adapters.
- Do not execute `modules_bindings.call` in the new runtime in R3; return a legacy-delegate route decision.
- Module availability is checked before user permission.
- No `users_permission` row means role-default ACL, not deny-all.
- New tables use InnoDB + utf8mb4 and explicit tenant/account indexes.

---

### Task 1: IAM permission value objects and R20 permission policy

**Files:** Create `app/iam/domain/Permission.php`, `PermissionSet.php`, `LegacyPermissionAssignment.php`, `LegacyPermissionPolicy.php`; test `tests/Unit/Iam/LegacyPermissionPolicyTest.php`.

**Interfaces:** `LegacyPermissionPolicy::allows(Permission $permission, LegacyPermissionAssignment $assignment, bool $roleDefaultAllows, ?string $frame = null): bool`.

- [ ] Write failing tests for DEFAULT_ROLE, EXPLICIT_ALL, exact permission, frame wildcard and explicit deny.
- [ ] Run the test and verify missing classes fail.
- [ ] Implement the minimal immutable objects and policy.
- [ ] Re-run and verify green.

### Task 2: Module definition/support/account overlay runtime

**Files:** Create `app/module/domain/ModuleLifecycleStatus.php`, `ModuleSupportMatrix.php`, `ModuleDefinition.php`, `AccountModuleConfig.php`, `RuntimeModule.php`, `RuntimeModuleResolver.php`; test `tests/Unit/Module/RuntimeModuleResolverTest.php`.

**Interfaces:** `RuntimeModuleResolver::resolve(ModuleDefinition $definition, ?AccountModuleConfig $config, AccountType $accountType, bool $enabledOnly = true): ?RuntimeModule`.

- [ ] Write failing tests for missing config default enabled, explicit disable, system-module override, recycled filtering and account-type support.
- [ ] Verify RED.
- [ ] Implement minimal runtime resolution.
- [ ] Verify GREEN.

### Task 3: Binding runtime router

**Files:** Create `app/module/domain/ModuleBindingType.php`, `ModuleBinding.php`, `BindingRouteKind.php`, `BindingRouteDecision.php`, `BindingRuntimeRouter.php`; test `tests/Unit/Module/BindingRuntimeRouterTest.php`.

**Interfaces:** `BindingRuntimeRouter::resolve(array $bindings, string $moduleName, ModuleBindingType $entryType, string $do): BindingRouteDecision`.

- [ ] Write failing tests for static route, missing route, and dynamic callback legacy delegation.
- [ ] Verify RED.
- [ ] Implement router without executing callbacks.
- [ ] Verify GREEN.

### Task 4: R20 module permission catalog and server-side module action authorization

**Files:** Create `app/module/domain/ModuleCustomPermission.php`, `ModulePermission.php`, `LegacyModulePermissionCatalog.php`, `ModuleActionAuthorizer.php`; test `tests/Unit/Module/LegacyModulePermissionCatalogTest.php`, `tests/Unit/Module/ModuleActionAuthorizerTest.php`.

**Interfaces:** `LegacyModulePermissionCatalog::build(ModuleDefinition $module, array $bindings): array`; `ModuleActionAuthorizer::allows(RuntimeModule $module, Permission $permission, LegacyPermissionAssignment $assignment, bool $roleDefaultAllows, ?string $frame = null): bool`.

- [ ] Write failing tests matching R20 permission names for settings/rule/home/profile/shortcut/cover/menu/custom/sub-permission and skipping multilevel containers.
- [ ] Write failing authorization-order tests proving unavailable modules deny before user permission.
- [ ] Implement minimal catalog and authorizer.
- [ ] Verify both tests green.

### Task 5: Legacy module snapshot adapter

**Files:** Create `app/module/compat/LegacyModuleAdapter.php`; test `tests/GoldenMaster/LegacyModuleAdapterTest.php`.

**Interfaces:** `LegacyModuleAdapter::definition(array $moduleRow): ModuleDefinition`; `LegacyModuleAdapter::accountConfig(string $accountId, string $tenantId, string $moduleName, ?array $legacyConfig): ?AccountModuleConfig`.

- [ ] Write failing Golden-Master fixtures for representative R20 `modules` and `uni_account_modules` snapshots.
- [ ] Verify RED.
- [ ] Implement strict mapping and input validation.
- [ ] Verify GREEN.

### Task 6: R3 database migration and contract

**Files:** Create `database/migrations/20260907_002_iam_module_platform_up.sql`, `_down.sql`; test `tests/Contract/IamModulePlatformSchemaContractTest.php`.

**Interfaces:** SQL tables listed in the R3 design spec.

- [ ] Write failing schema contract for tables, InnoDB/utf8mb4, tenant/account indexes and uniqueness.
- [ ] Verify RED.
- [ ] Write forward and rollback SQL.
- [ ] Verify GREEN.

### Task 7: Offline runner, compatibility notes and verification

**Files:** Modify `tests/run.php`; create `docs/migration/r20-module-permission-compatibility.md`, `docs/verification/iam-module-platform-r3.md`, `docs/verification/iam-module-platform-r3-checks.txt`.

**Interfaces:** Existing offline test runner plus R3 tests.

- [ ] Add R3 tests to the offline runner.
- [ ] Run R3 suite and all available local regression tests.
- [ ] Run PHP lint, Composer JSON parse, `$_W/$_GPC` scan, migration contract and git diff checks.
- [ ] Record exact evidence and unverified Composer/ThinkPHP/MySQL gates.
