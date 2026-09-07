# WePlatform ThinkPHP Refactor — Foundation Runtime R1

This is the first Strangler-style implementation increment based on the supplied WeEngine 2.7.4 R20 source and the V4 ThinkPHP refactor design.

## Implemented in R1

- ThinkPHP 8 project manifest and standard `public/index.php` / `think` entrypoints.
- Multi-app structure: `app/admin`, `app/web`, `app/api`; `app/worker` and `app/common` are non-HTTP.
- Immutable `RequestContext`, runtime type and principal value objects.
- Request/trace correlation IDs and request-context middleware.
- Stable JSON envelope and `AppException` adaptation through ThinkPHP `ExceptionHandle`.
- Secret redaction and audit event/logger foundations.
- Legacy R20 entrypoint Golden-Master mapping without executing or mutating legacy code.
- Offline test runner for framework-independent contracts.

## Implemented in R2

- IAM compatibility model for confirmed R20 account-role precedence.
- Explicit `AdminUser` identity and server-side administrator sessions.
- Tenant, tenant membership, Account, AccountType and capability domain models.
- `LegacyAccountMapping` for new account/tenant IDs versus R20 `uniacid` / `acid`.
- Stable legacy-account resolution errors through existing `AppException` contracts.
- MySQL 8-compatible up/down DDL for IAM/Tenant/Account foundations.
- Expanded offline suite covering R1 + R2 contracts.

## Dependency baseline

`composer.json` currently pins `topthink/framework` to `8.1.3` and `topthink/think-multi-app` to `1.1.1`. Framework 8.1.4 has a currently open multi-app/root-route regression report, so R1 intentionally avoids it until the project's real route suite can verify a fixed patch.

## Run in a normal development environment

```bash
composer install
php tests/run.php
php vendor/bin/phpunit
php think route:list
php think run
```

Expected smoke endpoints after Composer dependencies are installed:

- `GET /health` → web
- `GET /admin/health` → admin
- `GET /api/v1/health` → api

## Current sandbox limitation

The build sandbox used for R1/R2 has PHP 8.4 but no Composer binary and no outbound DNS/network. Therefore `vendor/`, `composer.lock`, real ThinkPHP boot, route-list, PHPUnit-through-Composer, and disposable MySQL migration execution could not be produced/verified here. Do not treat these slices as production-ready until those checks pass in a Composer/MySQL-enabled environment.

## Next implementation slice

Continue IAM authorization policy and Tenant/Account infrastructure adapters, then Module Registry/Binding + Legacy Adapter. Do not jump directly to payment or marketplace migration.
