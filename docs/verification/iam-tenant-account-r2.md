# IAM + Tenant + Account R2 Verification

Date: 2026-09-07
Branch: `refactor/iam-tenant-account-r2`
Baseline tree: `1d2b2e25e3827bceb1a79792b904b488ee2b50eb` (same file tree as GitHub R1 `main` before R2)

## Verified in this environment

- Offline contract/unit/component suite: **18/18 passing** via `php tests/run.php`.
- PHP syntax: **74 PHP files** under `app/`, `config/`, `public/`, `tests/` lint clean.
- `composer.json`: JSON parse succeeds; framework remains `topthink/framework 8.1.3`, multi-app `1.1.1`.
- New `app/` code contains no direct `$_W` / `$_GPC` access.
- No `TODO`, `TBD`, or `FIXME` placeholders exist under production `app/` or migration SQL.
- `git diff --check` passes.
- Migration contract confirms 7 new tables use InnoDB/utf8mb4, server-side session token hashes are unique, tenant-scoped tables expose tenant indexes, and legacy identity tuples are unique.

Exact command output is stored in `docs/verification/iam-tenant-account-r2-checks.txt`.

## R20 compatibility evidence used by R2

- `framework/model/permission.mod.php::permission_account_user_role()` for founder/expired/unbound/clerk/account-role precedence and cross-account priority.
- `framework/model/account.mod.php::uni_account_type()` for numeric `account.type` mapping and version support.
- `framework/const.inc.php` for exact role strings and numeric account type constants.
- `data/db.php` for R20 `ims_users`, `ims_uni_account_users`, `ims_account`, `ims_uni_account`, and `ims_users_permission` schema facts.
- `web/source/user/login.ctrl.php` for the old signed `__session` cookie behavior.

## Intentional security change

R20 embeds a minimal identity snapshot in a signed `__session` cookie. R2 defines an opaque session token whose HMAC-SHA256 hash is stored server-side in `admin_sessions`. The raw token is not persisted. This is classified as `INTENTIONAL_FIX`, not `MUST_COMPAT`.

## Not verified here

The sandbox still has no Composer installation/outbound dependency access, so the following remain pending for a Composer/MySQL-enabled environment:

- `composer install` and generated `composer.lock`.
- Real ThinkPHP boot and `php think route:list`.
- `vendor/bin/phpunit` through installed Composer dependencies.
- Applying the MySQL up/down migration against a disposable MySQL 8 database.
- ThinkPHP repository adapters / integration tests; R2 currently establishes domain/application contracts and DDL only.

R2 is therefore a verified offline implementation slice, not yet a production deployment gate.
