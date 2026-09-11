# Database Migration Standardization V1 — Design

Date: 2026-09-11
Status: Approved in-chat; written spec pending human review before implementation planning
Branch: `refactor/database-migration-standardization-v1`
Base: `refactor/admin-foundation-completion-v1` @ `8fbc4a615c717b9a61d9954dee4654f8630731f7`

## 1. Goal

Standardize database schema lifecycle around `topthink/think-migration` so local development, CI, acceptance, and deployment use one real migration entrypoint instead of custom SQL runners or manual `SOURCE` commands.

After this feature:

1. A clean checkout can initialize an empty MySQL database with `php think migrate:run`.
2. `php think migrate:status` is the authoritative migration-status command.
3. `php think migrate:rollback` can safely roll back the V1 baseline in dependency-safe reverse order.
4. Existing SQL migrations 001–009 remain the immutable source of truth for the already-validated V1 schema; their SQL semantics are not rewritten into approximate schema-builder calls.
5. Existing databases previously initialized by the legacy 001–009 SQL path can be adopted only after strict schema/seed verification.
6. Partial or drifted databases fail closed and are never silently marked migrated.
7. Acceptance and Admin production browser E2E use the same real migration path developers use.
8. `admin:bootstrap` can run immediately after migration on a fresh database.

## 2. Scope

### In scope

- Add `topthink/think-migration:^3.1` to the production dependency graph and commit the resulting exact `composer.lock` resolution.
- Make `migrate:run`, `migrate:status`, and `migrate:rollback` part of the supported project workflow.
- Preserve existing 001–009 SQL as an immutable V1 baseline.
- Add nine thin compatibility migrations that execute the corresponding validated V1 up/down SQL.
- Move historical SQL into a baseline-specific location that is not confused with new PHP migration files.
- Add strict existing-database V1 adoption.
- Replace the custom fresh-database SQL execution path in acceptance tests with the real migration CLI/runtime path.
- Make Admin production E2E prepare its database through the real migration system before `admin:bootstrap`.
- Add migration contracts, real-MySQL acceptance coverage, rollback/re-run coverage, and setup documentation.

### Out of scope

- Web-based installation wizard.
- Automatic production deployment/migration orchestration.
- Zero-downtime DDL guarantees.
- Database backup/restore tooling.
- Online tenant-by-tenant schema migration.
- Rewriting all historical SQL into schema-builder APIs.
- General seed/factory framework beyond seeds already encoded in the V1 SQL baseline.
- Changing business schema semantics unrelated to migration standardization.
- Merging or marking existing stacked PRs Ready.

## 3. Existing state and problem

The repository currently has:

- V1 SQL pairs under `database/migrations/*_up.sql` and `*_down.sql`.
- A real schema history spanning 001–009.
- Acceptance code that independently discovers `*_up.sql`, splits SQL statements, and executes them through PDO.
- Admin bootstrap and browser E2E that assume the test database has already been prepared.
- No supported developer-facing `php think migrate:run` workflow.

This creates two migration models:

```text
local/operator path
  -> manual SQL / SOURCE commands

acceptance path
  -> custom PHP runner
  -> glob *_up.sql
  -> PDO::exec(...)
```

The split allows application code and CI to be GREEN while a fresh local database is still uninitialized. The local Human Gate exposed this when Admin login failed because `weplatform.admin_users` did not exist.

The standardized model must be:

```text
local / CI / acceptance / browser E2E
  -> php think migrate:run
  -> topthink/think-migration
  -> one authoritative migration history
```

## 4. Selected approach

Use nine compatibility migrations backed by immutable V1 SQL.

Rejected alternatives:

- Rewriting 001–009 as schema-builder migrations risks changing already-validated MySQL details such as foreign keys, index names, `datetime(6)`, JSON, charset/collation, ordering, and seed behavior.
- Collapsing all 001–009 into one monolithic baseline loses the current nine-step domain/history boundary and makes rollback/diagnosis coarser.

Selected model:

```text
V001 PHP migration -> immutable 001 up/down SQL
V002 PHP migration -> immutable 002 up/down SQL
...
V009 PHP migration -> immutable 009 up/down SQL
```

Migration 010 and later should normally use native PHP migration APIs directly; the historical SQL-pair convention ends at V1/009.

## 5. Dependency and command contract

Target Composer constraint:

```text
topthink/think-migration:^3.1
```

At design time the current stable 3.1.x release is compatible with `topthink/framework:^8.0`; implementation must let Composer resolve and lock the exact compatible version in `composer.lock`.

The project-supported commands are:

```text
php think migrate:create <Name>
php think migrate:status
php think migrate:run
php think migrate:rollback
```

The installed v3.1 implementation uses the project-root `database/migrations` directory for create/discovery/run; the design follows that actual source behavior rather than the stale README path wording.

`composer install` followed by `php think list` must expose the migration commands on a clean checkout.

No project-specific custom command may replace `migrate:run` as the normal fresh-schema entrypoint.

## 6. Directory and ownership model

### 6.1 Historical V1 SQL baseline

Move existing SQL pairs to:

```text
database/schema/v1/
  20260907_001_iam_tenant_account_up.sql
  20260907_001_iam_tenant_account_down.sql
  20260907_002_iam_module_platform_up.sql
  20260907_002_iam_module_platform_down.sql
  ...
  20260909_009_openplatform_authorizer_provisioning_up.sql
  20260909_009_openplatform_authorizer_provisioning_down.sql
```

Rules:

- These files are the immutable V1 baseline.
- The expected V1 file set is fixed by contract tests.
- The content hash of every baseline SQL file is fixed by contract tests so edits are explicit and require a deliberate migration-design change.
- This feature must not change the SQL semantics of 001–009.

### 6.2 Authoritative migration directory

`database/migrations/` becomes the authoritative `topthink/think-migration` PHP migration directory.

V1 wrappers use ordered versions that preserve 001–009 ordering. Exact filenames/classes must be generated or validated against the installed package naming rules and then pinned by tests.

Conceptually:

```text
database/migrations/
  <version-001>_v001_iam_tenant_account.php
  <version-002>_v002_iam_module_platform.php
  ...
  <version-009>_v009_openplatform_authorizer_provisioning.php
```

No historical `*_up.sql` or `*_down.sql` files remain in `database/migrations/` after conversion.

### 6.3 Future migrations

Migration 010+ should normally be written directly in PHP through `topthink/think-migration`/Phinx APIs.

The V1 SQL execution adapter is compatibility infrastructure only.

## 7. V1 SQL execution adapter

The nine wrapper migrations share one focused helper rather than duplicating SQL-file handling.

Responsibilities:

1. Resolve a baseline file only from fixed repository-owned `database/schema/v1`.
2. Reject missing, unreadable, or empty baseline files.
3. Execute statements on the migration's active adapter/connection rather than opening an unrelated connection.
4. Preserve statement order.
5. Surface baseline filename and statement index in errors without leaking configured secrets.
6. Never accept arbitrary user-provided paths.

Implementation must use APIs supported by the locked package version so transaction and migration-history semantics remain coherent.

## 8. Fresh database behavior

For an empty target database:

```text
composer install
  -> php think migrate:status
  -> php think migrate:run
  -> V001 ... V009
  -> think-migration history recorded
  -> schema + seed assertions
  -> php think admin:bootstrap --username=admin
```

Fresh migration acceptance must verify the existing critical contracts, including at minimum:

- `admin_users`, `admin_sessions`, `tenants`;
- IAM/module/permission tables;
- site/theme runtime tables;
- member OAuth/webhook tables;
- mini-app identity/session tables;
- OpenPlatform component-trust tables;
- authorizer lifecycle/provisioning tables;
- the expected `openplatform.*` permission seed set.

Existing detailed schema contracts remain authoritative and must not be weakened.

## 9. Existing database adoption

### 9.1 Required states

A target database is classified into exactly one state:

- `EMPTY`: no V1 business schema and no migration history. Action: normal `migrate:run`.
- `MANAGED`: migration history exists and is consistent with the installed migration set. Action: normal `migrate:status/run`.
- `LEGACY_V1_COMPLETE`: no migration history, but business schema and required seeds exactly satisfy the V1 fingerprint. Action: explicit adoption is allowed.
- `DRIFTED_OR_PARTIAL`: partial objects exist, schema/seed fingerprint is wrong, or history/schema disagree. Action: fail closed.

### 9.2 Adoption verifier

The verifier must check more than table existence. Required evidence includes:

- expected tables;
- critical column names/types/nullability/defaults;
- primary/unique keys;
- critical named indexes;
- critical foreign keys;
- expected V1 permission seeds;
- existing acceptance assertions needed to prove V1 compatibility.

Reuse/extract current schema assertions where practical instead of creating a weaker duplicate definition.

### 9.3 Explicit adoption command

Adoption is never an automatic side effect of `migrate:run`.

Project command:

```text
php think migration:adopt-v1
```

Semantics:

1. Connect to configured DB.
2. Refuse `EMPTY` and direct operator to `migrate:run`.
3. Refuse already `MANAGED` DB.
4. Run complete V1 verification.
5. Only for `LEGACY_V1_COMPLETE`, record exactly V001–V009 as applied without replaying DDL.
6. Re-read migration history/status and verify the result.
7. Never modify business tables/data during adoption.

If v3.1 does not expose a stable public API for historical-version recording, use a small repository-owned adapter around the package migration-history table, pinned to the locked package version/schema by tests. Any unexpected package history schema must fail closed.

## 10. Rollback semantics

Rollback is primarily a development/test capability.

Requirements:

- V009 rolls back before V008, continuing in reverse order to V001.
- Each wrapper executes its matching immutable `_down.sql`.
- Foreign-key dependency order remains valid.
- Full V1 rollback leaves no business tables created by 001–009.
- Migration history reflects rolled-back state.
- Subsequent `migrate:run` recreates an equivalent V1 schema and seed set.

Documentation must warn that rollback is destructive and is not a production backup/restore strategy.

## 11. TDD and test strategy

Implementation follows strict RED -> GREEN with exact-head evidence.

### 11.1 Contract RED first

The first implementation commit is test-only and requires:

- Composer dependency `topthink/think-migration:^3.1`;
- nine PHP migration wrappers under `database/migrations`;
- immutable `database/schema/v1` file set and fixed content hashes;
- no historical `*_up.sql`/`*_down.sql` files under `database/migrations`;
- acceptance and Admin browser DB setup no longer rely on the custom `glob('*_up.sql')` runner;
- setup docs use real migrate commands.

The RED must fail only because the migration-standardization feature is absent.

### 11.2 Unit/component coverage

Cover V1 SQL adapter behavior:

- missing file fails;
- empty file fails;
- statement order is preserved;
- failure identifies baseline/version context;
- arbitrary/path-traversal access is impossible;
- error output does not leak secrets.

Cover adoption classification/verifier logic separately from CLI presentation.

### 11.3 Real MySQL fresh migration acceptance

Against fresh MySQL 8.4:

1. Start with an empty guarded test DB.
2. Run real `php think migrate:run` or the exact in-process command equivalent if required by the test harness.
3. Assert V001–V009 are recorded as applied.
4. Run all critical V1 schema/seed assertions.
5. Run `admin:bootstrap` and prove the administrator is persisted/authenticatable.
6. Run migration again and prove it is an idempotent no-op.

### 11.4 Real MySQL rollback/re-run

1. Fresh migrate V001–V009.
2. Roll back all V1 migrations through the supported command path.
3. Assert V1-created business tables are absent and history is coherent.
4. Run migration again.
5. Assert schema/seed fingerprint equals the first fresh-migrated fingerprint.

### 11.5 Legacy adoption acceptance

Build a DB by applying immutable V1 SQL directly with no migration history, then:

1. Run explicit adoption.
2. Prove business schema/data are unchanged.
3. Prove V001–V009 history is recorded.
4. Prove subsequent `migrate:run` is a no-op.

Negative matrix must include:

- missing expected table;
- critical column drift;
- missing/incorrect unique index;
- missing foreign key;
- required permission seed missing;
- partial migration history;
- history says applied while schema is missing.

Every negative case must fail without writing adoption history.

### 11.6 Admin production browser E2E

Database setup becomes:

```text
fresh MySQL 8.4
  -> real migrate:run
  -> admin:bootstrap
  -> production Admin build/server
  -> Chromium login -> dashboard -> reload/session restore -> logout
```

This makes the browser gate prove the complete operator chain rather than only UI/auth behavior.

### 11.7 Full regression

The same exact head must pass:

- Composer validation / locked install;
- migration contracts/unit tests;
- fresh migrate;
- rollback/re-run;
- legacy-adoption positive/negative matrix;
- Admin typecheck/Vitest/build;
- Web unit/build/Chromium E2E;
- Admin production Chromium E2E;
- PHPUnit bridge;
- PHP lint;
- multi-app HTTP smoke;
- R8D MySQL release gate.

## 12. CI design

Use MySQL 8.4, matching existing real-database gates.

A permanent migration job must exercise:

```text
composer install
  -> fresh guarded test DB
  -> migrate:run
  -> schema/seed assertions
  -> admin:bootstrap smoke
  -> migrate no-op check
  -> rollback all
  -> verify rollback state
  -> migrate:run again
  -> schema/seed assertions again
```

Legacy adoption runs against a separate isolated guarded DB and must not share mutable state with the fresh-migration path.

Destructive reset/rollback helpers must preserve the repository's fail-closed test-database naming policy.

## 13. Developer workflow after completion

Fresh checkout:

```text
composer install
php think migrate:status
php think migrate:run
php think admin:bootstrap --username=admin
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
php think run -p 18080
```

Existing complete legacy V1 DB:

```text
php think migration:adopt-v1
php think migrate:status
php think migrate:run
```

Partial/drifted DBs are never auto-repaired. Recovery is a separate explicit reviewed operation.

## 14. Security and safety

- Migration/adoption commands never print database passwords or unrelated environment secrets.
- Production debug pages are not required for migration diagnosis.
- Adoption never modifies business data/schema; it only writes history after complete verification.
- Ordinary `migrate:run` never implicitly adopts legacy DBs.
- Partial/drifted states fail closed.
- Rollback is clearly documented as destructive.
- Destructive CI/test operations are restricted to guarded test database names.
- V1 SQL loader cannot resolve user-controlled paths.

## 15. Documentation requirements

Update project setup docs to make `migrate:run` the normal schema bootstrap path.

Document:

- fresh install flow;
- migrate status/run/rollback;
- legacy V1 adoption;
- fail-closed drift behavior;
- destructive rollback warning;
- MySQL 8.4 CI expectation;
- `admin:bootstrap` order after migration.

Manual execution of individual V1 SQL files becomes historical/recovery internals, not the normal developer workflow.

## 16. Acceptance criteria

AC1. `topthink/think-migration:^3.1` is in `composer.json`, exact dependency resolution is committed in `composer.lock`, and migration commands are available after clean `composer install`.

AC2. On a fresh MySQL 8.4 DB, `php think migrate:run` applies V001–V009 and produces the current validated V1 schema/seeds.

AC3. V001–V009 preserve historical SQL semantics through immutable baseline files whose file set and hashes are permanently contracted.

AC4. `php think migrate:status` accurately reports V001–V009 after fresh migration.

AC5. Re-running `migrate:run` after V001–V009 are applied performs no duplicate DDL and leaves schema/data unchanged.

AC6. Full V1 rollback executes in dependency-safe reverse order and updates history correctly.

AC7. Re-running migration after full rollback recreates an equivalent schema/seed fingerprint.

AC8. A complete legacy V1 DB with no migration history can be explicitly adopted without replaying DDL or changing business data/schema.

AC9. Partial/drifted legacy DBs are rejected fail-closed and no migration history is written.

AC10. Fresh migration followed by `php think admin:bootstrap --username=<name>` creates the initial administrator successfully.

AC11. Admin production browser E2E prepares its DB through the real migration path before bootstrap and still proves login -> Dashboard -> reload/session restore -> logout -> old-session rejection.

AC12. Existing Admin/Web/backend/R8D quality gates remain GREEN on the same exact head.

AC13. Human acceptance confirms the documented fresh local flow from an empty DB before the migration-standardization PR can be marked Ready.

## 17. Delivery and stacking

Develop only on:

```text
refactor/database-migration-standardization-v1
```

The branch is based exactly on the current Admin Foundation head at feature creation:

```text
refactor/admin-foundation-completion-v1
8fbc4a615c717b9a61d9954dee4654f8630731f7
```

Open a separate stacked Draft PR targeting `refactor/admin-foundation-completion-v1` only after written-spec review and implementation planning.

Do not modify PR #7, #8, or #9. PR #10 remains independently subject to its existing Human Gate; this feature must not be used to mark PR #10 Ready without explicit acceptance.

Do not mark the migration-standardization PR Ready or merge it until its exact-head automated gates and independent Human Gate are both GREEN.
