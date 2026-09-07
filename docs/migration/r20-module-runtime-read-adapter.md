# R20 Module Runtime Read Adapter

R4 adds a read-only Strangler bridge from WeEngine 2.7.4/R20 tables into the new module domain. It does not write or alter legacy tables.

## Read sources

- `modules` → `ModuleDefinition`
- `modules_recycle` → aggregate lifecycle/recycle status
- `uni_account_modules` → `AccountModuleConfig`
- `modules_plugin` → directional `ModulePluginRelation(main_module -> name)`
- `modules_bindings` → `ModuleBinding`
- `users_permission` → `LegacyPermissionAssignment`

All SQL access is isolated behind `LegacyDatabase`; production reads use `ThinkPhpLegacyDatabase`. Application and domain code do not know table names.

## Compatibility rules

- Missing `uni_account_modules` row means the module uses R20 default-enabled semantics.
- A plugin is runnable only when its required main module resolves as runnable for the same account.
- `modules_recycle` is aggregated per support dimension; a module is fully recycled only when every support dimension it declares is unavailable.
- `users_permission.permission` is a pipe-delimited string in R20, not a PHP-serialized value.
- No `(uid, uniacid)` permission rows means role-default ACL. Once any row exists, missing module-specific permission is explicit empty unless `type=modules` grants `all`.
- `page` bindings may have empty `do`; their stored `url` is preserved. `webapp` and `phoneapp` entry types are recognized.

## Security differences

Legacy serialized settings/permission metadata are decoded with `allowed_classes=false` on both the normal and damaged-string repair path. Object payloads fail closed. This is an `INTENTIONAL_FIX` relative to R20's historical repair fallback.

## Cutover contract

The new runtime path is:

```text
LegacyAccountMapping
  -> R20ModuleRuntimeRepository
  -> RuntimeModuleResolver
  -> plugin/main dependency gate
  -> RuntimeModuleContext
  -> BindingRuntimeRouter
```

Authorization is deliberately separate and ordered after runtime availability:

```text
RuntimeModuleService
  -> R20ModulePermissionRepository
  -> LegacyPermissionPolicy
  -> allow / deny
```

No permission record can make an unavailable module runnable.
