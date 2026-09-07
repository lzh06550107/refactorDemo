# R6 Site + Domain + Theme Runtime Implementation Plan

**Base:** `main` at `d9b22d1c5eef283a9763ef093661aac8c282c115`

**Objective:** implement the runtime foundation for EPIC-06 while preserving verified R20 site/theme semantics and deliberately removing arbitrary server-side template execution.

## Task 1 — Site and domain identity

Create Site/Domain value objects, repository contracts, domain resolver and ThinkPHP read repositories.

Tests:
- `tests/Unit/Site/SiteTest.php`
- `tests/Unit/Site/DomainNameTest.php`
- `tests/Component/Site/SiteDomainResolverTest.php`

Required outcomes: immutable Site updates, default-site enable invariant, normalized hosts, stable cross-tenant conflict.

## Task 2 — R20 site compatibility

Create `R20SiteSnapshotMapper` and compatibility DTOs.

Test:
- `tests/GoldenMaster/R20SiteSnapshotTest.php`

Required outcomes: preserve `uniacid`, `multiid`, `styleid` separately; R20 default-site status drift is normalized to enabled; account bind-domain preserves default module.

## Task 3 — Theme/version/style snapshots

Create ThemeDefinition, ThemeVersion, StyleInstance and StyleSnapshot.

Tests:
- `tests/Unit/Theme/ThemeVersionTest.php`
- `tests/Unit/Theme/StyleSnapshotTest.php`

Required outcomes: safe lexical template root, safe variable grammar, immutable revisions, deterministic canonical snapshot hashes.

## Task 4 — Publish/rollback transaction boundary

Create SiteThemeRelease, ThemePublication, repository contract, ThemeReleaseService and ThinkPHP persistence repository.

Test:
- `tests/Component/Theme/ThemeReleaseServiceTest.php`

Required outcomes: RequestContext isolation, AuditEvent emission, semantic idempotency, immutable rollback, row-locked current-release pointer, stale-write conflict.

## Task 5 — Safe renderer

Create ViewContract and SafeThemeRenderer.

Test:
- `tests/Unit/Theme/SafeThemeRendererTest.php`

Required outcomes: required scalar fields, HTML escaping, fail-closed undeclared fields, reject legacy server-execution/control directives.

## Task 6 — R20 style compatibility

Create `R20ThemeStyleSnapshotMapper`.

Test:
- `tests/GoldenMaster/R20ThemeStyleSnapshotTest.php`

Required outcomes: preserve template ID/name/title/version, style ID/name/uniacid, and keyed style variables; reject style/template mismatch.

## Task 7 — MySQL schema

Create `_004_site_theme_runtime_up.sql` and down migration.

Test:
- `tests/Contract/SiteThemeSchemaContractTest.php`

Required outcomes: seven InnoDB/utf8mb4 tables, unique host, one default site per account, unique publish idempotency, transactional/locking repository source contract.

## Task 8 — Full quality gate

Update `tests/run.php` from 41 to 51 entries. Validate in GitHub Actions:

1. `composer validate --strict`
2. `composer install`
3. `php tests/run.php`
4. PHPUnit bridge
5. all PHP lint
6. `/health`, `/admin/health`, `/api/v1/health` smoke routes

Use a validation branch/PR first. If green and `main` has not moved, non-force fast-forward the exact tested commit to `main`, then require the `main` push CI to be green again.
