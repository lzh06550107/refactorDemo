# WePlatform ThinkPHP Refactor

Strangler-style refactor of WeEngine 2.7.4/R20 onto ThinkPHP 8, implemented incrementally from the supplied V4 design.

## Current implementation: R4

### R1 — Foundation Runtime
- ThinkPHP multi-app skeleton (`admin`, `web`, `api`, `common`).
- Immutable `RequestContext`, stable error envelope, audit/security foundations.
- R20 entrypoint Golden Master mapping.

### R2 — IAM + Tenant + Account
- `AdminUser`, server-side `AdminSession`, Tenant/Account boundaries.
- Explicit `LegacyAccountMapping` for `uniacid`/`acid`.
- R20 account-type and role compatibility rules.

### R3 — IAM Authorization + Module Platform
- Permission/ACL domain and R20 permission naming compatibility.
- Module definition/support/account overlay runtime.
- Binding router and legacy module adapter.
- Module-platform schema foundations.

### R4 — R20 Read Runtime + Plugin Dependency
- Directional `ModulePluginRelation`: a plugin is not runnable without its main module.
- Read-only `LegacyDatabase`/`ThinkPhpLegacyDatabase`; R20 tables are never written by this slice.
- Safe R20 serialized-value decoder with `allowed_classes=false`, including repair path.
- `R20ModuleRuntimeRepository` reads `modules`, `modules_recycle`, `modules_plugin`, `uni_account_modules`, and `modules_bindings` into domain objects.
- `R20ModulePermissionRepository` reads true R20 pipe-delimited `users_permission.permission` semantics.
- `RuntimeModuleService` and `ModuleAuthorizationService` enforce runtime availability before authorization.
- R20 binding compatibility now includes `page`, `webapp`, and `phoneapp`.
- GitHub Actions CI provides Composer/PHPUnit/ThinkPHP boot verification that the local offline sandbox cannot provide.

## Runtime rule order

```text
LegacyAccountMapping
  -> module definition + account overlay
  -> account type / recycle / enabled gate
  -> plugin main-module gate
  -> RuntimeModuleContext
  -> binding route

Authorization:
RuntimeModuleContext must exist
  -> users_permission assignment
  -> LegacyPermissionPolicy
  -> allow / deny
```

A permission row can never resurrect an unavailable module.

## Development verification

Local offline gate:

```bash
php tests/run.php
```

Networked/CI gate:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
php tests/run.php
php vendor/bin/phpunit
php think route:list
```

See `docs/verification/` for phase-specific evidence and limitations.
