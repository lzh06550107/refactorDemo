# IAM Authorization + Module Platform R3 Verification

R3 is an offline-verified domain/schema increment. It does not claim Composer-backed ThinkPHP boot, MySQL migration execution, or real legacy addons execution in this sandbox.

## Verified locally

- R1 baseline contract tests plus all R3 tests.
- PHP syntax for every PHP file in the local R1+R3 validation tree.
- `composer.json` JSON parsing and pinned ThinkPHP dependency metadata.
- No `$_W` / `$_GPC` access in new `app/` code.
- R3 migration structure and rollback contract.
- No production execution of `modules_bindings.call`; dynamic bindings return `LEGACY_DELEGATE` metadata.

## Not verified in this sandbox

- `composer install` / `vendor/bin/phpunit`.
- ThinkPHP application boot and route list.
- MySQL execution of R2 + R3 migrations and foreign keys.
- Real database adapters for `modules`, `uni_account_modules`, `users_permission`.
- Execution of legacy `addons/*` module PHP.
- EPIC-05 entitlement/quota and ST-04-03 plugin/main dependency.
