# R20 IAM / Account Compatibility Notes

## Confirmed source behavior

The R2 compatibility rules are based on the uploaded annotated WeEngine 2.7.4 R20 source, not inferred from names.

- `framework/model/permission.mod.php::permission_account_user_role()` confirms the concrete-account precedence: main founder → expired → unbound → clerk user type → `uni_account_users.role`.
- The same function confirms cross-account priority `vice_founder > owner > manager > operator`, plus the historical fallback that a bound normal user with no role rows is treated as `owner`. R2 preserves this only inside `LegacyAccountRoleResolver` and classifies it as **MUST_COMPAT**, not as a new authorization rule.
- `framework/model/account.mod.php::uni_account_type()` confirms the R20 numeric type mapping and `support_version` behavior used by `LegacyAccountTypeMap`.
- R20 schema confirms `ims_account.acid` is the primary key while `uniacid` is indexed, and `ims_uni_account.uniacid` is the logical unified account identity. New code therefore does not reuse either value as the new `accounts.id`.
- R20 `web/source/user/login.ctrl.php` stores an identity snapshot in the signed `__session` cookie. R2 intentionally replaces that design with an opaque token + server-side `admin_sessions.token_hash`; this is classified as **INTENTIONAL_FIX**.
- R20 `users.endtime` expiration is represented explicitly by nullable `admin_users.expires_at`; the new `AdminUser` domain treats `NULL` as unlimited.

## Mapping rules

`legacy_mappings` stores compatibility identifiers as separate rows:

| entity_type | legacy_key | legacy_value | target_id |
| --- | --- | --- | --- |
| `admin_user` | `uid` | legacy `users.uid` | new `admin_users.id` |
| `account` | `uniacid` | legacy `uniacid` | new `accounts.id` |
| `account` | `acid` | legacy `acid` | new `accounts.id` |

`legacy_value` is text on purpose: it keeps the compatibility table generic and prevents old numeric IDs from becoming new primary keys.

## Migration safety

- New tables are InnoDB/utf8mb4; legacy R20 core tables are left untouched.
- Every tenant-scoped new table has an explicit `tenant_id` index.
- A legacy identity tuple `(entity_type, legacy_key, legacy_value)` is unique, preventing the same R20 ID from mapping to two new objects.
- Session raw tokens never enter the database; only HMAC-SHA256 hashes are stored.
