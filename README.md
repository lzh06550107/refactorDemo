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

The build sandbox used for R1 has PHP 8.4 but no Composer binary and no outbound DNS/network. Therefore `vendor/`, `composer.lock`, real ThinkPHP boot, route-list, and PHPUnit-through-Composer could not be produced/verified here. Do not treat R1 as production-ready until those checks pass in a Composer-enabled environment.

## Next implementation slice

Continue M1 with IAM + Tenant + Account, including explicit `LegacyAccountMapping` for `uniacid`; do not jump directly to module/payment migration.
