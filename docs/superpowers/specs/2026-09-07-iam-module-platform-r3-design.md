# IAM Authorization + Module Platform R3 Design

## Scope

R3 implements the first authorization-aware module runtime slice on top of R1 Foundation Runtime and R2 IAM/Tenant/Account. It covers V4 ST-02-02, ST-02-03, ST-04-01, ST-04-02, ST-04-04 and ST-04-05.

Out of scope for R3: plugin/main-module dependency execution (ST-04-03), commercial entitlement/quota (EPIC-05), marketplace lifecycle, and direct execution of legacy `addons/*` PHP inside the new runtime.

## Confirmed R20 compatibility rules

1. `permission_build()` has two layers: code-level ACL and per-user/account `users_permission`. Hiding a menu is not authorization.
2. No `users_permission` record means use the role's default ACL. It does not mean deny-all.
3. Explicit permission lists authorize exact permission names; R20 also recognizes a frame wildcard such as `account*`.
4. Module permission checks are only meaningful after the module belongs to the account's runnable module set.
5. `module_fetch()` combines a global module definition with current-account `uni_account_modules` settings.
6. Missing account-module settings default to enabled; system modules remain enabled even if account settings say otherwise.
7. Recycled/deleted modules are hidden when callers request enabled modules only.
8. Module support is account-type specific; a module that cannot run on the current account type must be rejected before user permission evaluation.
9. `module_permission_fetch()` derives permission names from settings/rule flags, bindings, and custom manifest permissions. Multilevel menu container bindings are not direct permission entries.
10. Dynamic binding callbacks (`modules_bindings.call`) are not executed by R3. They are explicitly classified as `LEGACY_DELEGATE` for the Strangler bridge.

## Target model

### IAM authorization

- `Permission`: stable permission key value object.
- `PermissionSet`: immutable explicit permission set with optional `all` state.
- `LegacyPermissionAssignment`: distinguishes DEFAULT_ROLE, EXPLICIT_ALL, and EXPLICIT_LIST. This distinction is required because an absent R20 row is not equivalent to an empty explicit list.
- `LegacyPermissionPolicy`: evaluates exact permission and optional frame wildcard while preserving the role-default fallback.
- `ModuleActionAuthorizer`: server-side decision that first requires runtime module availability, then applies the permission assignment. Menu projection consumes the same decision output and is never the security boundary.

### Module registry/runtime

- `ModuleDefinition`: global registry identity and immutable manifest-level behavior for a published version.
- `ModuleSupportMatrix`: account-type compatibility.
- `AccountModuleConfig`: account-scoped overlay corresponding to `uni_account_modules`.
- `RuntimeModule`: resolved module snapshot used by application code.
- `RuntimeModuleResolver`: merges global definition + account overlay and applies lifecycle/support rules.
- `ModuleBinding`: static or legacy-dynamic runtime entry metadata.
- `BindingRuntimeRouter`: resolves a binding by module/entry/do and emits NEW_RUNTIME or LEGACY_DELEGATE.
- `LegacyModulePermissionCatalog`: reproduces R20 permission-name generation without calling legacy globals.
- `LegacyModuleAdapter`: maps R20 array snapshots into the new domain objects. It is a compatibility boundary, not a new source of truth.

## Data model

R3 adds new tables only; legacy tables stay unchanged:

- `roles`
- `permissions`
- `role_permissions`
- `permission_assignments`
- `module_definitions`
- `module_versions`
- `module_bindings`
- `module_capabilities`
- `tenant_modules`
- `account_module_configs`

Tenant/account-scoped tables contain explicit `tenant_id`/`account_id` indexes. All tables use InnoDB + utf8mb4. Migrations have an explicit reverse-order rollback.

## Error contract

- Missing module definition or binding: `NOT_FOUND`.
- Module exists but is recycled, disabled for this account, or unsupported by account type: `FORBIDDEN` at authorization boundary; runtime resolution returns no runnable module.
- Explicit permission assignment that does not contain the required permission: `FORBIDDEN`.
- Invalid legacy snapshot or invalid identifiers: `INVALID_ARGUMENT`.

## Testing

R3 uses pure-PHP RED/GREEN tests for domain/application behavior and static SQL contract tests. Golden-Master fixtures capture R20 permission names and `module_fetch()` overlay semantics. Full ThinkPHP/MySQL integration remains gated by Composer/network availability and will not be claimed as verified in the offline sandbox.

## Compatibility classification

- MUST_COMPAT: absent `users_permission` row uses role default; account config overlay defaults; system module enabled override; permission-name generation; account-type support gate.
- INTENTIONAL_FIX: menu visibility cannot grant access; all server-side module actions use explicit authorization service.
- UNSUPPORTED_LEGACY in R3: executing `modules_bindings.call` callback over internal HTTP. R3 routes it to a legacy-delegate decision instead.
