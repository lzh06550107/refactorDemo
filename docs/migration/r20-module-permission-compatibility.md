# R20 Module / Permission Compatibility Notes — R3

## Confirmed source mappings

| R20 source | R3 target | Classification |
| --- | --- | --- |
| `permission_build()` | `LegacyPermissionPolicy` + server-side authorizers | MUST_COMPAT |
| absent `users_permission` row | `LegacyPermissionAssignment::roleDefault()` | MUST_COMPAT |
| explicit permission `all` | `LegacyPermissionAssignment::all()` | MUST_COMPAT |
| `FRAME . '*'` wildcard | `LegacyPermissionPolicy` frame wildcard | MUST_COMPAT |
| `module_fetch()` global `modules` row | `ModuleDefinition` | MUST_COMPAT |
| `uni_account_modules` overlay | `AccountModuleConfig` + `RuntimeModuleResolver` | MUST_COMPAT |
| missing account module config defaults enabled | `RuntimeModuleResolver` default | MUST_COMPAT |
| system module ignores account `enabled=0` | `RuntimeModuleResolver` system override | MUST_COMPAT |
| `modules_recycle` effective deletion | `ModuleLifecycleStatus::RECYCLED` | MUST_COMPAT |
| account-type support fields | `ModuleSupportMatrix` | MUST_COMPAT |
| app/wechat-mini account accepts `wxapp_support` OR `account_support` | `LegacyModuleAdapter` | MUST_COMPAT |
| `module_permission_fetch()` | `LegacyModulePermissionCatalog` | MUST_COMPAT |
| menu visibility used as security | never used as security in R3 | INTENTIONAL_FIX |
| `modules_bindings.call` internal HTTP callback | `LEGACY_DELEGATE` route decision | UNSUPPORTED_LEGACY in R3 |

## Separation of concerns

`TenantModule`/`tenant_modules` records whether the tenant has the module in its platform inventory. `AccountModuleConfig` controls account-local runtime settings. User authorization is evaluated independently. Commercial entitlement/quota remains EPIC-05 and is not inferred from either table.

## R20 source evidence reviewed

- `framework/model/module.mod.php`: `module_fetch()`, `module_permission_fetch()`, `module_entries()`.
- `framework/model/permission.mod.php`: `permission_build()`, `permission_account_user_permission_exist()`, `permission_account_user_menu()`, `permission_check_account_user_module()`.
- `framework/model/account.mod.php`: `uni_modules_by_uniacid()`.
- `framework/table/Uni/AccountModules.php`, `framework/table/Modules/Bindings.php`, `framework/table/Modules/Modules.php`.
