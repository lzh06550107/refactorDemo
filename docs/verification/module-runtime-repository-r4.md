# Module Runtime Repository R4 Verification

## Local sandbox evidence

The sandbox cannot reach GitHub/Packagist DNS and therefore cannot run `composer install`. R4 is verified locally through the framework-independent contracts plus PHP lint/static gates; GitHub Actions supplies the networked Composer/ThinkPHP gate.

Verified locally before publication:

- R1 baseline offline suite: 7/7 pass.
- R4 focused RED/GREEN tests: 8/8 pass after implementation.
- PHP syntax lint across local `app`, `config`, and `tests` tree.
- `composer.json` JSON parse.
- no `$_W`, `$_GPC`, or `pdo_*` references in new `app/` code.
- read-only `LegacyDatabase` contract exposes no insert/update/delete method.
- Git diff whitespace check.

## R4 focused tests

1. `ModulePluginRelationTest.php`
2. `LegacySerializedValueDecoderTest.php`
3. `LegacyDatabaseReadOnlyContractTest.php`
4. `R20ModuleRuntimeRepositoryTest.php`
5. `ModuleBindingCompatibilityTest.php`
6. `R20ModulePermissionRepositoryTest.php`
7. `RuntimeModuleServiceTest.php`
8. `ModuleAuthorizationServiceTest.php`

## GitHub CI gate

`.github/workflows/ci.yml` runs on push to `main` and pull requests with PHP 8.4:

1. `composer validate --strict`
2. `composer install --no-interaction --prefer-dist --no-progress`
3. `php tests/run.php`
4. `php vendor/bin/phpunit`
5. PHP lint for `app`, `config`, and `tests`
6. `php think route:list`

The workflow result must be inspected after publication; a green local sandbox is not treated as proof of Composer/ThinkPHP boot.
