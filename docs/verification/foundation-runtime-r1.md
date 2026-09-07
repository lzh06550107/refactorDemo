# Foundation Runtime R1 verification

## Verified locally

- Offline contract/unit suite passes.
- All PHP source/test/config files pass `php -l` under PHP 8.4.23.
- `composer.json` parses successfully.
- New `app/` code contains no `$_W` or `$_GPC` references.
- `app/common` and `app/worker` expose no HTTP route directories.
- Input archive SHA-256 values are recorded in `input-sha256.txt`.

## Not verified in this sandbox

- `composer install` / dependency resolution.
- Generated `composer.lock`.
- ThinkPHP runtime boot and `php think route:list`.
- HTTP smoke tests against `/health`, `/admin/health`, `/api/v1/health`.
- PHPUnit execution through `vendor/bin/phpunit`.

Reason: Composer is not installed and outbound DNS/network access is unavailable in the execution container.

## Release classification

R1 is a **development foundation increment**, not a production release. The next gate is to run Composer-enabled integration tests, then start IAM + Tenant + Account migration.
