# AS-IS → TO-BE 总映射

| WeEngine AS-IS | ThinkPHP TO-BE |
|---|---|
| `/index.php` 分流 | Nginx + ThinkPHP Router + DomainResolver |
| `/web/index.php` | `app/admin` |
| `/app/index.php` | `app/web` |
| `/api.php` + `payment/*` | `app/api` Webhook/Payment |
| `$_W` | immutable RequestContext |
| `$_GPC` | typed validated DTO |
| `uniacid` | LegacyAccountMapping → Tenant/Account |
| `user_modules()` | User Authorization / entitlement source migration |
| `uni_modules_by_uniacid()` | Tenant/Account Module Entitlement + Compatibility |
| `module_fetch()` | ModuleRuntimeResolver composition |
| `permission_user_account_num()` | QuotaService + Ledger |
| `permission.inc.php + users_permission` | Capability Registry + Assignment + Policy |
| 动态 include | ModuleRegistry/Binding |
| Theme PHP | Safe Renderer |
| Queue 双写 | Outbox + Queue |
