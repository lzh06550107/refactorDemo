# WePlatform ThinkPHP Refactor

WeEngine 2.7.4 R20 strangler refactor on ThinkPHP 8.

## Current increments

### R1 — Foundation Runtime

- ThinkPHP multi-app foundation (`admin`, `web`, `api`).
- Immutable `RequestContext`, correlation IDs, stable error envelope.
- Audit/security primitives and legacy entrypoint Golden-Master mapping.

### R2 — IAM + Tenant + Account

- `AdminUser` / server-side `AdminSession` with HMAC token hashing.
- Tenant / membership / account domain boundaries.
- Explicit R20 role precedence compatibility.
- `LegacyAccountMapping` for `uniacid` / `acid` without using legacy IDs as new primary keys.
- R2 MySQL migrations.

### R3 — IAM Authorization + Module Platform

- R20-compatible permission assignment semantics: role-default, explicit all, explicit list and frame wildcard.
- Module registry definition, account-type support matrix and `uni_account_modules` overlay runtime.
- Binding router separates new runtime routes from `LEGACY_DELEGATE` dynamic callbacks.
- R20 `module_permission_fetch()` naming catalog plus server-side module action authorization.
- Legacy `modules` / `uni_account_modules` snapshot adapter.
- R3 IAM/module-platform MySQL schema and rollback.

## Dependency baseline

- PHP `^8.2`
- `topthink/framework` `8.1.3`
- `topthink/think-multi-app` `1.1.1`

## Run in a Composer-enabled development environment

```bash
composer install
php tests/run.php
php vendor/bin/phpunit
php think route:list
```

The offline runner currently contains the R1 + R2 + R3 contract/unit/Golden-Master entries. The sandbox used during the refactor has PHP but no usable Composer/networked dependency install, so ThinkPHP boot, MySQL migration execution, and Composer PHPUnit execution must still be verified in a normal development environment.

## Migration strategy

The project follows a Strangler approach. Confirmed R20 behavior is isolated in `Legacy*` adapters/policies; new domain/application code must not access `$_W` or `$_GPC`. Legacy dynamic module callbacks are delegated rather than executed inside the new runtime until their domains are migrated.
