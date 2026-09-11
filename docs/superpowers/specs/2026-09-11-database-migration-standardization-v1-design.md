# Database Migration Standardization V1 — Design

Date: 2026-09-11
Status: Approved in-chat; written spec pending human review before implementation planning
Branch: `refactor/database-migration-standardization-v1`
Base: `refactor/admin-foundation-completion-v1` @ `8fbc4a615c717b9a61d9954dee4654f8630731f7`

## 1. Goal

Standardize database schema lifecycle around `topthink/think-migration` so local development, CI, acceptance, and deployment use one real migration entrypoint instead of custom SQL runners or manual `SOURCE` commands.

After this feature:

1. A clean checkout can initialize an empty MySQL database with `php think migrate:run`.
2. `php think migrate:status` reports authoritative migration state.
3. `php think migrate:rollback` can safely roll back the V1 baseline in dependency-safe reverse order.
4. Existing SQL migrations 001–009 remain the immutable source of truth for the already-validated V1 schema; their SQL semantics are not rewritten into approximate schema-builder calls.
5. Existing databases previously initialized by the legacy 001–009 SQL path can be adopted only after strict schema/seed verification.
6. Partial or drifted databases fail closed and are never silently marked migrated.
7. Acceptance and Admin production browser E2E use the same real `migrate:run` path developers use.
8. `admin:bootstrap` can run immediately after migration on a fresh database.

## 2. Scope

### In scope

- Add and lock `topthink/think-migration` compatible with the repository's ThinkPHP 8 dependency graph.
- Make `migrate:run`, `migrate:status`, and `migrate:rollback` part of the supported project workflow.
- Preserve existing 001–009 SQL as an immutable V1 baseline.
- Add nine thin compatibility migrations that execute the corresponding validated V1 up/down SQL.
- Move historical SQL into a baseline-specific location that is not confused with new PHP migration files.
- Add strict existing-database V1 adoption.
- Replace the custom fresh-database SQL execution path in acceptance tests with the real migration CLI/runtime path.
- Make Admin production E2E prepare its database through the real migration system before `admin:bootstrap`.
- Add migration contracts, real-MySQL acceptance coverage, rollback/re-run coverage, and documentation.

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

## 4. Options considered

### Option A — Rewrite 001–009 as native schema-builder migrations

Translate every current SQL file into PHP migration API calls.

Advantages:

- Pure PHP migration representation.
- New and old migrations use the same coding style.

Rejected for V1 because the existing SQL contains exact MySQL behavior including foreign keys, index names, `datetime(6)`, JSON, charset/collation, ordering, and seed statements. Rewriting creates unnecessary semantic-drift risk in already-validated schema.

### Option B — One monolithic V1 baseline migration

Create one migration that executes all 001–009 SQL.

Advantages:

- Small amount of migration wrapper code.

Rejected because it loses the existing nine-step domain/history boundary and makes rollback/all-or-nothing diagnosis coarser than the current design.

### Option C — Nine compatibility migrations backed by immutable V1 SQL — selected

Create nine `think-migration` migrations, one for each current version. Each migration delegates `up` and `down` to the corresponding historical SQL file.

Advantages:

- Preserves validated SQL semantics exactly.
- Preserves 001–009 history boundaries.
- Gives developers/CI one migration command.
- Supports reverse-order rollback.
- Allows migration 010+ to use normal PHP migration APIs without extending the legacy SQL convention.

Trade-off: V1 temporarily has a thin PHP wrapper plus SQL baseline files. This is intentional compatibility infrastructure, not the pattern for new migrations.

## 5. Dependency and command contract

Add `topthink/think-migration` to the production dependency graph and commit the resulting `composer.lock`.

The supported operator commands become:

```text
php think migrate:status
php think migrate:run
php think migrate:rollback
```

The exact installed package version is determined and locked by Composer; `composer.json` should use the narrowest practical compatible constraint rather than an unbounded range.

`composer install` followed by `php think list` must expose the migration commands on a clean checkout.

No project-specific custom command may replace `migrate:run` as the normal fresh-schema entrypoint.

## 6. Directory and ownership model

### 6.1 Historical V1 SQL baseline

Move the existing SQL pairs to:

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
- Their SQL semantics must not change as part of this feature except for a proven compatibility defect that has its own migration/regression story.
- Contract tests should pin the expected V1 file set and, preferably, content hashes so future edits are explicit rather than accidental.

### 6.2 Migration directory

`database/migrations/` becomes the authoritative `think-migration` PHP migration directory.

V1 wrappers use ordered timestamps/names that preserve 001–009 ordering, for example:

```text
database/migrations/
  20260907000100_V001IamTenantAccount.php
  20260907000200_V002IamModulePlatform.php
  20260907000300_V003EntitlementQuota.php
  20260907000400_V004SiteThemeRuntime.php
  20260908000500_V005MemberOAuthWebhook.php
  20260908000600_V006MiniAppIdentitySession.php
  20260908000700_V007OpenPlatformComponentTrust.php
  20260908000800_V008OpenPlatformAuthorizerLifecycle.php
  20260909000900_V009OpenPlatformAuthorizerProvisioning.php
```

The exact class/file naming must satisfy the installed `think-migration` conventions and be locked by tests.

### 6.3 Future migrations

Migration 010 and later should normally be written directly in PHP using the migration API.

The legacy `*_up.sql` / `*_down.sql` pairing convention ends at V1/009.

## 7. V1 SQL execution adapter

The nine wrapper migrations should share one focused helper rather than each duplicating file loading and SQL splitting.

Responsibilities:

1. Resolve a baseline file only from the fixed repository-owned `database/schema/v1` directory.
2. Reject missing, unreadable, or empty SQL files.
3. Execute statements on the migration's active database connection.
4. Preserve statement order.
5. Surface the baseline filename and failing statement index in errors without leaking secrets.
6. Never accept arbitrary user-provided file paths.

The helper is compatibility infrastructure for V1 only; new migration 010+ code must not be forced through SQL files.

The implementation must use the connection semantics supported by the installed `think-migration`/Phinx version rather than opening a second unrelated connection that could break transaction or migration-state assumptions.

## 8. Fresh database behavior

For an empty target database:

```text
composer install
  -> php think migrate:status
  -> php think migrate:run
  -> V001 ... V009
  -> migration history recorded by think-migration
  -> schema + seeds verified
  -> php think admin:bootstrap --username=admin
```

The fresh migration acceptance gate must verify at minimum the existing critical schema contracts, including:

- `admin_users`
- `admin_sessions`
- `tenants`
- IAM/module/permission tables
- site/theme runtime tables
- member OAuth/webhook tables
- mini-app identity/session tables
- OpenPlatform component-trust tables
- authorizer lifecycle/provisioning tables
- existing permission seed set such as `openplatform.*`

The existing detailed schema contracts remain authoritative; migration standardization must not weaken them.

## 9. Existing database adoption

### 9.1 Why adoption is required

A database may already have been initialized by manually applying V1 SQL before `think-migration` existed. Such a database has business tables but no `think-migration` history.

Blindly running V001–V009 would attempt duplicate `CREATE TABLE` statements. Blindly inserting migration-history rows would risk certifying an incomplete or drifted schema.

### 9.2 Database-state classification

Before adoption, classify the database into exactly one state:

#### EMPTY

No V1 business schema exists and no migration history exists.

Action: normal `migrate:run`.

#### MANAGED

Migration history exists and is internally consistent with the installed migration set.

Action: normal `migrate:status` / `migrate:run`.

#### LEGACY_V1_COMPLETE

No migration history exists, but the database matches the complete validated V1 schema and required seeds.

Action: explicit adoption workflow may mark V001–V009 as applied without replaying DDL.

#### DRIFTED_OR_PARTIAL

Some expected V1 objects exist but the complete V1 fingerprint does not match, or history/schema disagree.

Action: fail closed. Do not apply, repair, or mark history automatically.

### 9.3 Adoption verifier

The verifier must inspect more than table names. It must validate enough structural and seed evidence to distinguish a true V1 database from a partial/manual lookalike.

Required evidence includes:

- expected tables;
- critical column names/types/nullability/defaults;
- primary and unique keys;
- critical named indexes;
- critical foreign keys;
- expected V1 permission seeds;
- any other existing acceptance assertions needed to prove compatibility.

The verifier should reuse or extract existing schema assertions where practical instead of creating a weaker duplicate definition.

### 9.4 Adoption mutation

Adoption is explicit, not automatic as a side effect of ordinary `migrate:run`.

Recommended project command contract:

```text
php think migration:adopt-v1
```

Semantics:

1. Connect to the configured database.
2. Refuse if it is EMPTY (operator should use `migrate:run`).
3. Refuse if it is already MANAGED.
4. Run the complete V1 verifier.
5. If and only if classification is `LEGACY_V1_COMPLETE`, write the exact migration-history rows expected for V001–V009 using a supported `think-migration` integration boundary.
6. Re-run `migrate:status`/history verification after adoption.
7. Never modify business tables during adoption.

If the package does not expose a stable API for recording historical versions, the implementation may use a small repository-owned adapter around the package's migration-history table, but this must be pinned by tests to the installed package version/schema and must fail closed on mismatch.

## 10. Rollback semantics

Rollback is supported primarily for development/test databases.

Requirements:

- V009 rolls back before V008, continuing in reverse order through V001.
- Each wrapper executes its matching immutable `_down.sql`.
- Foreign-key dependency order must remain valid.
- A complete rollback leaves no V1 business tables created by 001–009.
- Migration history reflects the rolled-back state.
- A subsequent `migrate:run` recreates an equivalent V1 schema and seed set.

Production documentation must warn that rollback is destructive and is not a substitute for backup/restore or forward-fix deployment practices.

## 11. Test and TDD strategy

Implementation follows strict RED → GREEN with exact-head evidence.

### 11.1 Contract RED first

The first implementation commit must add only tests/contracts requiring:

- `topthink/think-migration` dependency;
- migration commands as the supported path;
- nine PHP migration wrappers;
- immutable V1 SQL baseline directory/file set;
- no new `*_up.sql` migration convention under `database/migrations`;
- acceptance and Admin browser database setup no longer use the custom `glob('*_up.sql')` runner.

The RED must fail because the feature is absent, not because of unrelated syntax/configuration problems.

### 11.2 Unit/component tests

Cover the V1 SQL adapter:

- missing file fails;
- empty file fails;
- ordered statements execute in order;
- error identifies migration/baseline context;
- path traversal/arbitrary paths are impossible;
- adapter does not expose SQL content/secrets unnecessarily.

Cover adoption classification/verifier logic with focused fixtures or test doubles where real MySQL is not required.

### 11.3 Real MySQL fresh migration acceptance

Against a fresh MySQL 8.4 database:

1. Ensure target database starts empty.
2. Run the real migration command/path equivalent to `php think migrate:run`.
3. Assert migration status shows V001–V009 applied.
4. Re-run all critical V1 schema/seed assertions.
5. Run `admin:bootstrap` and verify the administrator can be read/authenticated through existing IAM persistence.
6. Run `migrate:run` again and prove idempotent no-op behavior.

### 11.4 Real MySQL rollback/re-run acceptance

On an isolated test database:

1. Fresh migrate V001–V009.
2. Roll back all V1 migrations through the supported command/path.
3. Verify V1-created business tables are absent and history is consistent.
4. Re-run migration.
5. Verify schema/seed fingerprint matches the initial migrated state.

### 11.5 Legacy adoption acceptance

Create a database by applying the immutable V1 SQL baseline without migration history, then:

1. Prove ordinary migration is not used to replay duplicate DDL.
2. Run explicit V1 adoption.
3. Verify business data/schema is unchanged.
4. Verify V001–V009 history is recorded.
5. Verify subsequent `migrate:run` is a no-op.

Add negative cases:

- one table missing;
- critical column drift;
- missing/incorrect unique index;
- missing foreign key;
- required permission seed missing;
- partial migration history;
- history says applied while schema is missing.

Every negative case must fail closed without writing adoption history.

### 11.6 Admin production browser E2E

Replace custom/prepared schema setup with:

```text
fresh MySQL 8.4
  -> real migrate:run
  -> admin:bootstrap
  -> production Admin build/server
  -> Chromium login/dashboard/reload/logout
```

The browser gate therefore proves the complete operator chain, not merely the UI/auth layer.

### 11.7 Full regression

Same exact head must pass:

- Composer validation / locked install;
- migration contract/unit/acceptance gates;
- fresh migrate;
- rollback/re-run;
- legacy V1 adoption positive/negative matrix;
- Admin typecheck/Vitest/build;
- Web unit/build/Chromium E2E;
- Admin production Chromium E2E;
- PHPUnit bridge;
- PHP lint;
- multi-app HTTP smoke;
- R8D MySQL release gate.

## 12. CI design

Use MySQL 8.4, matching the repository's current real-database release/browser gates.

At least one permanent migration job must exercise:

```text
composer install
  -> fresh DB
  -> migrate:run
  -> schema/seed assertions
  -> admin:bootstrap smoke
  -> migrate no-op check
  -> rollback all
  -> verify empty/expected state
  -> migrate:run again
  -> schema/seed assertions again
```

Legacy adoption should run in the same job or a separate isolated database in the same workflow. It must not share mutable state with the fresh-migration path.

CI database names must continue following the repository's fail-closed test-database naming policy.

## 13. Developer workflow after completion

Fresh checkout/local setup becomes:

```text
composer install
php think migrate:status
php think migrate:run
php think admin:bootstrap --username=admin
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
php think run -p 18080
```

An operator with an old SQL-initialized complete V1 database uses:

```text
php think migration:adopt-v1
php think migrate:status
php think migrate:run
```

A partial/drifted database must be repaired through an explicit, reviewed recovery process; the migration standardization feature does not guess or auto-repair it.

## 14. Security and safety requirements

- Migration/adoption commands must never print configured database passwords or unrelated environment secrets.
- Production debug pages must not be required for diagnosing migration failures.
- Adoption never modifies business data/schema; it only records history after successful verification.
- Ordinary `migrate:run` must not implicitly adopt a legacy database.
- Partial/drifted states fail closed.
- Rollback documentation must identify destructive behavior.
- Test/CI databases must be guarded so destructive reset/rollback operations cannot target an arbitrary production-like database name.
- Historical V1 SQL cannot resolve user-controlled paths.

## 15. Documentation requirements

Update project setup documentation so the normal path uses `migrate:run` rather than manual SQL commands.

Document:

- fresh install flow;
- migration status/run/rollback commands;
- V1 legacy adoption flow;
- fail-closed partial/drift behavior;
- destructive rollback warning;
- MySQL version expectation used by CI;
- first-admin bootstrap order after migration.

Manual execution of individual V1 SQL files should be documented only as historical/recovery internals, not the normal developer workflow.

## 16. Acceptance criteria

AC1. `topthink/think-migration` is present in the locked Composer dependency graph and migration commands are available after `composer install`.

AC2. On a fresh MySQL 8.4 database, `php think migrate:run` alone applies V001–V009 and produces the current validated V1 schema/seeds.

AC3. V001–V009 preserve the existing historical SQL semantics through immutable baseline files; this feature does not approximate/rewrite the V1 schema through schema-builder calls.

AC4. `php think migrate:status` accurately reports V001–V009 after fresh migration.

AC5. Re-running `migrate:run` after V001–V009 are applied performs no duplicate DDL and leaves schema/data unchanged.

AC6. Full V1 rollback executes in dependency-safe reverse order, leaves expected V1-created business objects absent, and updates migration history correctly.

AC7. Re-running migration after full rollback recreates a schema/seed fingerprint equivalent to the initial fresh migration.

AC8. A complete legacy V1 database with no migration history can be explicitly adopted without replaying DDL or modifying business data/schema.

AC9. Partial or drifted legacy databases are rejected fail-closed and no V1 migration history is written.

AC10. Fresh migration followed by `php think admin:bootstrap --username=<name>` creates the initial administrator successfully.

AC11. Admin production browser E2E prepares the database through the real migration path before bootstrap and still verifies login → Dashboard → reload/session restore → logout → old-session rejection.

AC12. Existing Admin/Web/backend/R8D gates remain GREEN on the same exact head.

AC13. Human acceptance confirms the documented local flow works from a clean/empty database before the migration-standardization PR can be marked Ready.

## 17. Delivery and stacking

Develop only on:

```text
refactor/database-migration-standardization-v1
```

Base exactly on the current Admin Foundation head at feature creation:

```text
refactor/admin-foundation-completion-v1
8fbc4a615c717b9a61d9954dee4654f8630731f7
```

Open a separate stacked Draft PR targeting `refactor/admin-foundation-completion-v1` only after the written spec and implementation plan are approved and implementation begins.

Do not modify PR #7, #8, or #9. PR #10 remains independently subject to its existing Human Gate; this feature must not be used to mark PR #10 Ready without that explicit acceptance.

Do not mark the migration-standardization PR Ready or merge it until its exact-head automated gates and independent Human Gate are both GREEN.
