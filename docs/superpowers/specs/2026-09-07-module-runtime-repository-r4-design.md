# Module Runtime Repository R4 Design

## Goal

Complete the first end-to-end transitional module runtime read path from confirmed WeEngine R20 tables into the ThinkPHP-side domain, including plugin/main dependency, runtime bindings, and module action authorization.

## Scope

R4 includes ST-04-03 Plugin/Main Dependency; read-only adapters for `modules`, `modules_recycle`, `modules_plugin`, `uni_account_modules`, `modules_bindings`, and `users_permission`; runtime/authorization composition; R20 `page`, `webapp`, `phoneapp` binding compatibility; and GitHub CI.

R4 excludes package install/uninstall writes, marketplace lifecycle, Entitlement/Quota, payment, and arbitrary addon PHP execution.

## Confirmed R20 rules

1. `modules_plugin.name` is the plugin and `modules_plugin.main_module` is its required main module.
2. A plugin must not be runnable when its required main module is missing or unavailable for the same account.
3. `module_fetch()` overlays global `modules` with account-level `uni_account_modules`; absent account config keeps default-enabled behavior, while system modules remain enabled.
4. `modules_recycle` is support-dimension based; the adapter marks a module recycled only when every support dimension it declares is unavailable.
5. No `users_permission` row for `(uid, uniacid)` means role-default ACL. Once any row exists, the account is in explicit ACL mode; missing module-specific permission is explicit empty unless `type=modules` grants `all`.
6. `users_permission.permission` is a `|`-delimited string in R20.
7. `page` bindings may have empty `do` because R20 installation moves the original page target into `url`.

## Architecture

```text
LegacyAccountMapping
  -> RuntimeModuleService
       -> ModuleRuntimeRepository
            -> R20ModuleRuntimeRepository
                 -> LegacyDatabase
                      -> ThinkPhpLegacyDatabase
       -> RuntimeModuleResolver
       -> plugin/main dependency gate
       -> BindingRuntimeRouter

ModuleAuthorizationService
  -> RuntimeModuleService (must be runnable first)
  -> ModulePermissionRepository
       -> R20ModulePermissionRepository
  -> LegacyPermissionPolicy
```

Only infrastructure classes know R20 table names and ThinkPHP database APIs. Application/domain code never uses `$_W`, `$_GPC`, or `pdo_*`.

## Security

`LegacySerializedValueDecoder` uses `unserialize(..., ['allowed_classes' => false])` in both normal and damaged-string repair paths. Object payloads fail closed. R20 tables are read-only in this phase.

## Error policy

Missing runtime/binding uses stable null/`NOT_FOUND` behavior. Plugin dependency cycles use `CONFLICT`. Malformed serialization and unsupported binding entries fail with controlled exceptions instead of PHP warnings.

## Testing

- Unit: plugin relation, safe serialization, binding compatibility.
- Component: R20 runtime repository, permission repository, runtime service, authorization service.
- Contract: legacy DB is read-only; no legacy globals/direct `pdo_*` in new code.
- CI: PHP 8.4, Composer validation/install, 33-item offline runner, PHPUnit bridge, PHP lint, and `php think route:list`.

## Compatibility classification

- MUST_COMPAT: plugin requires main module; account overlay; R20 explicit-permission mode; page empty-`do` behavior.
- INTENTIONAL_FIX: repaired serialized data still forbids class instantiation; malformed legacy rows fail closed.
- UNSUPPORTED_LEGACY: arbitrary addon callback execution remains delegated to the legacy runtime.
