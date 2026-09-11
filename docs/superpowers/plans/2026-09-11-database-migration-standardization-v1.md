# Database Migration Standardization V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `topthink/think-migration` the single supported schema lifecycle for local development, CI, acceptance, and Admin browser E2E while preserving the already-validated V1 SQL 001–009 exactly and safely adopting legacy V1 databases.

**Architecture:** Historical SQL 001–009 moves unchanged to `database/schema/v1/` and becomes an immutable baseline guarded by a SHA-256 manifest. Nine thin `think-migration` wrappers under `database/migrations/` execute those baseline files through the active Phinx adapter. Fresh databases use real `migrate:run`; legacy complete V1 databases use an explicit fail-closed `migration:adopt-v1` command that verifies schema/seed fingerprints before recording versions through the locked migration adapter.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, `topthink/think-migration:^3.1` locked by Composer, bundled Phinx runtime, MySQL 8.4 acceptance, PHPUnit/offline contract runner, GitHub Actions, existing Admin Playwright Chromium E2E.

**Spec:** `docs/superpowers/specs/2026-09-11-database-migration-standardization-v1-design.md`

## Global Constraints

- Base branch state is `refactor/admin-foundation-completion-v1` at `8fbc4a615c717b9a61d9954dee4654f8630731f7`.
- Work only on `refactor/database-migration-standardization-v1`.
- Keep PR #10 (`Admin Foundation Completion`) unchanged; its Human Gate remains independent and pending.
- Add `topthink/think-migration` with Composer constraint `^3.1`; commit the exact resolved `composer.lock`.
- `topthink/think-migration` 3.1 source code uses `database/migrations` for migration discovery/creation; do not relocate PHP migrations to `phinx/Migration`.
- Existing SQL 001–009 semantics must not change; move them byte-for-byte to `database/schema/v1/` and pin them with SHA-256 manifest checks.
- V1 wrappers must execute SQL through the active migration adapter (`Migrator::execute()` / underlying Phinx adapter), never through a second independent database connection.
- Migration 010+ uses normal PHP migrations; no new `*_up.sql`/`*_down.sql` convention after V1/009.
- Legacy adoption is explicit through `php think migration:adopt-v1`; ordinary `migrate:run` must not silently mark existing schema as migrated.
- Partial/drifted/history-inconsistent databases fail closed; no automatic repair and no history writes on failed verification.
- Real database verification uses MySQL 8.4 and repository acceptance database safety rules (`WEPLATFORM_ACCEPTANCE=1`, local host, database name contains `acceptance` or ends in `_test`).
- Automated gates do not replace Human Acceptance.

---

## File Structure

### New production files

- `database/schema/v1/*.sql` — immutable V1 SQL baseline moved from the current `database/migrations/*.sql` files.
- `database/schema/v1/manifest.sha256` — exactly 18 SHA-256 entries, one for every V1 up/down SQL file.
- `database/migrations/20260907000100_v001_iam_tenant_account.php`
- `database/migrations/20260907000200_v002_iam_module_platform.php`
- `database/migrations/20260907000300_v003_entitlement_quota.php`
- `database/migrations/20260907000400_v004_site_theme_runtime.php`
- `database/migrations/20260908000500_v005_member_oauth_webhook.php`
- `database/migrations/20260908000600_v006_miniapp_identity_session.php`
- `database/migrations/20260908000700_v007_openplatform_component_trust.php`
- `database/migrations/20260908000800_v008_openplatform_authorizer_lifecycle.php`
- `database/migrations/20260909000900_v009_openplatform_authorizer_provisioning.php`
- `app/common/migration/V1BaselineSql.php` — fixed-directory loader/splitter for immutable baseline SQL.
- `app/common/migration/V1DatabaseState.php` — enum-like values `EMPTY`, `MANAGED`, `LEGACY_V1_COMPLETE`, `DRIFTED_OR_PARTIAL`.
- `app/common/migration/V1SchemaVerifier.php` — schema/history/seed classifier and fingerprint verifier.
- `app/worker/command/MigrationAdoptV1Command.php` — explicit legacy adoption command, built on the locked think-migration adapter.

### New test files

- `tests/Contract/DatabaseMigrationStandardizationContractTest.php`
- `tests/Unit/Migration/V1BaselineSqlTest.php`
- `tests/Component/Migration/V1SchemaVerifierTest.php`
- `tests/Acceptance/DatabaseMigrationRuntimeTest.php`
- `tests/Acceptance/V1AdoptionRuntimeTest.php`

### Modified files

- `composer.json`, `composer.lock` — add/lock think-migration.
- `config/console.php` — register `MigrationAdoptV1Command` only; package migration commands remain service-discovered.
- `tests/run.php` — include new contract/unit/component tests.
- `tests/Acceptance/bootstrap.php` — add a safe child-process runner for real `php think ...` commands.
- `tests/Acceptance/FreshDatabaseMigrationTest.php` — stop parsing SQL itself; assert schema after real migration runtime.
- `tests/Acceptance/run.php` — include fresh migration, rollback/re-run, bootstrap smoke, and adoption tests.
- `tests/E2E/PrepareAdminBrowserDatabase.php` — prepare DB via real `migrate:run` instead of custom SQL runner.
- `tests/Release/run.php` — require migration commands in `php think list` and keep real acceptance gate authoritative.
- `.github/workflows/ci.yml` — add permanent MySQL migration-standardization job and switch Admin browser DB setup to real migration CLI.
- `README.md` — document the supported fresh-install path and destructive rollback warning.

---

### Task 1: Architecture Contract RED

**Files:**
- Create: `tests/Contract/DatabaseMigrationStandardizationContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: current repository layout and `composer.json`.
- Produces: permanent fail-closed contract `databaseMigrationStandardizationContractTest(string $root): void`.

- [ ] **Step 1: Write the failing contract**

Create `tests/Contract/DatabaseMigrationStandardizationContractTest.php` with assertions in this order so the initial RED is deterministic:

```php
<?php

declare(strict_types=1);

function databaseMigrationStandardizationContractTest(string $root): void
{
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $required = $composer['require']['topthink/think-migration'] ?? null;
    if ($required !== '^3.1') {
        throw new RuntimeException('topthink/think-migration:^3.1 is required.');
    }

    $expectedPhpMigrations = [
        '20260907000100_v001_iam_tenant_account.php',
        '20260907000200_v002_iam_module_platform.php',
        '20260907000300_v003_entitlement_quota.php',
        '20260907000400_v004_site_theme_runtime.php',
        '20260908000500_v005_member_oauth_webhook.php',
        '20260908000600_v006_miniapp_identity_session.php',
        '20260908000700_v007_openplatform_component_trust.php',
        '20260908000800_v008_openplatform_authorizer_lifecycle.php',
        '20260909000900_v009_openplatform_authorizer_provisioning.php',
    ];
    $actualPhpMigrations = array_map('basename', glob($root . '/database/migrations/*.php') ?: []);
    sort($actualPhpMigrations);
    if ($actualPhpMigrations !== $expectedPhpMigrations) {
        throw new RuntimeException('database/migrations must contain exactly the nine V1 PHP wrappers.');
    }

    if ((glob($root . '/database/migrations/*_up.sql') ?: []) !== []
        || (glob($root . '/database/migrations/*_down.sql') ?: []) !== []) {
        throw new RuntimeException('Legacy SQL files must not remain under database/migrations.');
    }

    $manifest = $root . '/database/schema/v1/manifest.sha256';
    if (!is_file($manifest)) {
        throw new RuntimeException('Immutable V1 SHA-256 manifest is required.');
    }

    $fresh = (string) file_get_contents($root . '/tests/Acceptance/FreshDatabaseMigrationTest.php');
    if (str_contains($fresh, "glob($directory . '/*_up.sql')") || str_contains($fresh, 'PDO::exec')) {
        throw new RuntimeException('Fresh acceptance must use the real migration runtime, not the legacy SQL runner.');
    }

    $browser = (string) file_get_contents($root . '/tests/E2E/PrepareAdminBrowserDatabase.php');
    if (!str_contains($browser, "'migrate:run'")) {
        throw new RuntimeException('Admin browser database setup must execute real migrate:run.');
    }
}
```

Append to `tests/run.php` immediately after the Admin/Web architecture contracts:

```php
__DIR__ . '/Contract/DatabaseMigrationStandardizationContractTest.php',
```

The file is a normal offline contract file: execute its function at file load using the repository root, matching the existing contract-test style.

- [ ] **Step 2: Run the offline suite and prove RED**

Run:

```bash
php tests/run.php
```

Expected: existing tests remain PASS and this contract fails first with:

```text
topthink/think-migration:^3.1 is required.
```

- [ ] **Step 3: Commit test-only RED**

```bash
git add tests/Contract/DatabaseMigrationStandardizationContractTest.php tests/run.php
git commit -m "test: contract migration standardization"
```

- [ ] **Step 4: Push and capture exact-head CI RED evidence**

Push the branch and record the workflow run where the offline contract fails for the expected missing dependency while pre-existing Admin/Web gates remain healthy.

---

### Task 2: Locked Dependency, Immutable Baseline, and V1 Wrapper Runtime

**Files:**
- Modify: `composer.json`, `composer.lock`
- Move byte-for-byte: current 18 `database/migrations/*_up.sql` / `*_down.sql` files → `database/schema/v1/`
- Create: `database/schema/v1/manifest.sha256`
- Create: `app/common/migration/V1BaselineSql.php`
- Create: nine PHP migration wrappers listed in File Structure
- Create: `tests/Unit/Migration/V1BaselineSqlTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces: `V1BaselineSql::__construct(string $directory)` and `V1BaselineSql::statements(string $file): array<int,string>`.
- Produces: nine migration classes extending `think\migration\Migrator`, each exposing `up(): void` and `down(): void`.
- Later tasks rely on migration versions `20260907000100` through `20260909000900` exactly.

- [ ] **Step 1: Add unit RED for fixed-path SQL loading**

Test these exact behaviors in `tests/Unit/Migration/V1BaselineSqlTest.php`:

```php
$loader = new V1BaselineSql($fixtureDir);
assert($loader->statements('001_up.sql') === ['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)']);
```

Also assert:

```php
$loader->statements('../outside.sql'); // throws RuntimeException: Invalid V1 baseline filename.
$loader->statements('missing.sql');    // throws RuntimeException naming missing.sql
$loader->statements('empty.sql');      // throws RuntimeException naming empty.sql
```

Use a temporary directory created by the test and remove it in `finally`.

- [ ] **Step 2: Run the unit test and verify RED**

Run:

```bash
php tests/Unit/Migration/V1BaselineSqlTest.php
```

Expected: FAIL because `app/common/migration/V1BaselineSql.php` does not exist.

- [ ] **Step 3: Add the package and lock it**

Run:

```bash
composer require topthink/think-migration:^3.1 --no-interaction
composer validate --strict
```

Verify:

```bash
php think list | grep -E 'migrate:(create|run|rollback|status)'
```

Expected: all four commands are present.

- [ ] **Step 4: Move the V1 SQL without semantic edits and generate manifest**

Move all 18 current SQL files into `database/schema/v1/` without changing bytes. Then generate the manifest from the moved files:

```bash
php -r '$files=glob("database/schema/v1/*.sql"); sort($files); foreach($files as $f){echo hash_file("sha256", $f)."  ".basename($f).PHP_EOL;}' > database/schema/v1/manifest.sha256
```

Verify exactly 18 lines:

```bash
php -r '$l=file("database/schema/v1/manifest.sha256", FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); exit(count($l)===18?0:1);'
```

Update the architecture contract so it parses every manifest line with:

```php
preg_match('/^[a-f0-9]{64}  ([A-Za-z0-9_]+\.sql)$/', $line, $m)
```

and verifies `hash_file('sha256', $path) === $hash` for all 18 entries.

- [ ] **Step 5: Implement `V1BaselineSql` minimally**

Implementation contract:

```php
final class V1BaselineSql
{
    public function __construct(private readonly string $directory) {}

    /** @return list<string> */
    public function statements(string $file): array
    {
        if (basename($file) !== $file || preg_match('/^[A-Za-z0-9_]+\.sql$/', $file) !== 1) {
            throw new RuntimeException('Invalid V1 baseline filename.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $file;
        $sql = @file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('V1 baseline SQL is missing or empty: ' . $file);
        }
        $parts = preg_split('/;\s*(?:\R|$)/', trim($sql));
        $statements = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            is_array($parts) ? $parts : [],
        ), static fn (string $value): bool => $value !== ''));
        if ($statements === []) {
            throw new RuntimeException('V1 baseline SQL has no executable statements: ' . $file);
        }
        return $statements;
    }
}
```

- [ ] **Step 6: Add nine thin wrappers using the active migration adapter**

Use the exact file/class mapping implied by Phinx filename mapping. Example V001:

```php
<?php

declare(strict_types=1);

use app\common\migration\V1BaselineSql;
use think\migration\Migrator;

final class V001IamTenantAccount extends Migrator
{
    public function up(): void
    {
        $this->runBaseline('20260907_001_iam_tenant_account_up.sql');
    }

    public function down(): void
    {
        $this->runBaseline('20260907_001_iam_tenant_account_down.sql');
    }

    private function runBaseline(string $file): void
    {
        $loader = new V1BaselineSql(dirname(__DIR__) . '/schema/v1');
        foreach ($loader->statements($file) as $statement) {
            $this->execute($statement);
        }
    }
}
```

Repeat with the matching V002–V009 class/file/baseline names. Do not open PDO/ThinkPHP DB inside wrappers.

- [ ] **Step 7: Run focused and offline GREEN checks**

Run:

```bash
php tests/Unit/Migration/V1BaselineSqlTest.php
php tests/run.php
php think migrate:status
```

Expected: loader unit PASS; offline contract advances past dependency/layout checks; `migrate:status` discovers exactly nine migrations without class-name or duplicate-version errors.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock database app/common/migration/V1BaselineSql.php tests
git commit -m "feat: add V1 think-migration wrappers"
```

---

### Task 3: Real Fresh Migration Runtime and Admin Bootstrap Smoke

**Files:**
- Modify: `tests/Acceptance/bootstrap.php`
- Rewrite: `tests/Acceptance/FreshDatabaseMigrationTest.php`
- Create: `tests/Acceptance/DatabaseMigrationRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`
- Modify: `tests/Release/run.php`

**Interfaces:**
- Produces: `AcceptanceCommandResult` with `exitCode`, `stdout`, `stderr`.
- Produces: `AcceptanceRuntime::runThink(array $arguments, array $extraEnvironment = []): AcceptanceCommandResult`.
- Produces: reusable `acceptanceAssertV1Schema(PDO $db, AcceptanceConfig $config): void` extracted from the old fresh-migration assertions.

- [ ] **Step 1: Add RED for real `migrate:run`**

In `DatabaseMigrationRuntimeTest.php`, require:

```php
$runtime->resetDatabase();
$result = $runtime->runThink(['migrate:run']);
acceptanceAssert($result->exitCode === 0, 'migrate:run failed: ' . $result->stderr);
acceptanceAssertV1Schema($runtime->reconnectDatabase(), $runtime->config);
$status = $runtime->runThink(['migrate:status']);
acceptanceAssert(substr_count($status->stdout, 'up') >= 9, 'Expected nine applied migrations.');
$again = $runtime->runThink(['migrate:run']);
acceptanceAssert($again->exitCode === 0, 'Second migrate:run must be a no-op success.');
```

The test must fail initially because `runThink()` / reconnect support is absent.

- [ ] **Step 2: Add safe child-process support**

Add:

```php
final readonly class AcceptanceCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}
```

Implement `runThink()` using `proc_open([PHP_BINARY, $root.'/think', ...$arguments], ..., $root, array_merge($config->childEnvironment(), $extraEnvironment))`. Capture stdout/stderr, close pipes, return the result; never inject secrets into command arguments.

Add `reconnectDatabase(): PDO` that opens the configured test database without dropping it and stores the new PDO in `$this->database`.

- [ ] **Step 3: Remove the custom SQL executor from `FreshDatabaseMigrationTest.php`**

Keep schema/seed assertions, but extract them into:

```php
function acceptanceAssertV1Schema(PDO $db, AcceptanceConfig $config): void
```

Delete all logic that discovers `*_up.sql`, splits statements, and calls `$db->exec($statement)`.

- [ ] **Step 4: Add real bootstrap smoke after migration**

In `DatabaseMigrationRuntimeTest.php`, generate a per-test password:

```php
$password = bin2hex(random_bytes(16));
$bootstrap = $runtime->runThink(
    ['admin:bootstrap', '--username=migration-test-admin'],
    ['WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD' => $password],
);
acceptanceAssert($bootstrap->exitCode === 0, 'admin:bootstrap must succeed after fresh migration.');
```

Then assert one active `admin_users` row exists and `password_verify($password, $hash)` is true. Never print `$password`.

- [ ] **Step 5: Require migration commands in release gate**

After current `admin:bootstrap` assertion in `tests/Release/run.php`, assert `php think list` contains all of:

```text
migrate:create
migrate:run
migrate:rollback
migrate:status
```

- [ ] **Step 6: Run real MySQL acceptance GREEN**

Against an isolated MySQL 8.4 database:

```bash
WEPLATFORM_ACCEPTANCE=1 \
DATABASE_HOSTNAME=127.0.0.1 \
DATABASE_DATABASE=weplatform_migration_test \
DATABASE_USERNAME=root \
DATABASE_PASSWORD='<local-test-password>' \
php tests/Acceptance/run.php
```

Expected: fresh `migrate:run`, status, no-op re-run, schema/seed assertions, and `admin:bootstrap` smoke all PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/Acceptance tests/Release/run.php
git commit -m "test: run fresh database through migration CLI"
```

---

### Task 4: Destructive Rollback and Re-run Acceptance

**Files:**
- Extend: `tests/Acceptance/DatabaseMigrationRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`

**Interfaces:**
- Consumes: `AcceptanceRuntime::runThink()`, `acceptanceAssertV1Schema()`.
- Produces: permanent proof of V009→V001 reverse rollback and deterministic rebuild.

- [ ] **Step 1: Add rollback RED**

After a fresh migration, execute:

```php
$rollback = $runtime->runThink(['migrate:rollback', '--target=0']);
acceptanceAssert($rollback->exitCode === 0, 'Full rollback failed: ' . $rollback->stderr);
```

Then assert every V1 business table checked by `acceptanceAssertV1Schema()` is absent. `phinxlog` may remain because it belongs to the migration framework; assert it contains zero applied migration rows.

- [ ] **Step 2: Run acceptance and capture first dependency-order failure if any**

Run the isolated acceptance command from Task 3. Expected before any wrapper/down correction: either PASS or a precise first down-SQL dependency error. Do not reorder/drop unrelated objects speculatively.

- [ ] **Step 3: Fix only proven rollback defects**

If a down file fails because of dependency order, change only the corresponding V1 `_down.sql` if and only if the defect is proven by the real MySQL test and record that change as an explicit baseline compatibility correction in the manifest diff. Otherwise leave SQL bytes unchanged.

- [ ] **Step 4: Re-run migration and compare schema/seed fingerprint**

After full rollback:

```php
$rerun = $runtime->runThink(['migrate:run']);
acceptanceAssert($rerun->exitCode === 0, 'Re-run after rollback failed.');
acceptanceAssertV1Schema($runtime->reconnectDatabase(), $runtime->config);
```

Also assert the exact ordered `openplatform.*` permission seed set remains identical to the pre-rollback set.

- [ ] **Step 5: Commit**

```bash
git add tests/Acceptance database/schema/v1/manifest.sha256 database/schema/v1/*_down.sql
git commit -m "test: verify V1 rollback and rebuild"
```

Only include baseline SQL in the commit if a real defect required a correction; otherwise commit only tests.

---

### Task 5: V1 Database State Classifier and Strict Schema Verifier

**Files:**
- Create: `app/common/migration/V1DatabaseState.php`
- Create: `app/common/migration/V1SchemaVerifier.php`
- Create: `tests/Component/Migration/V1SchemaVerifierTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces enum:

```php
enum V1DatabaseState: string
{
    case EMPTY = 'empty';
    case MANAGED = 'managed';
    case LEGACY_V1_COMPLETE = 'legacy_v1_complete';
    case DRIFTED_OR_PARTIAL = 'drifted_or_partial';
}
```

- Produces `V1SchemaVerifier::classify(PDO $db, string $database): V1DatabaseState`.
- Produces `V1SchemaVerifier::assertLegacyV1Complete(PDO $db, string $database): void`.

- [ ] **Step 1: Add classifier RED using focused fake-query fixtures**

Cover these exact cases:

```text
no phinx versions + no V1 tables -> EMPTY
nine expected phinx versions     -> MANAGED
no phinx versions + full V1      -> LEGACY_V1_COMPLETE
some V1 tables/columns missing   -> DRIFTED_OR_PARTIAL
non-empty unexpected phinx set   -> DRIFTED_OR_PARTIAL
```

The component test can use a lightweight fake inspection gateway extracted inside `V1SchemaVerifier` only if direct PDO mocking becomes unreadable. Do not weaken real-MySQL acceptance in Task 6.

- [ ] **Step 2: Implement structural fingerprint checks**

At minimum verify through `information_schema`:

```text
expected V1 table set
admin_users.id varchar(64) NOT NULL primary key
admin_users.username varchar(64) NOT NULL unique key uk_admin_users_username
admin_sessions.admin_user_id FK -> admin_users.id
accounts.tenant_id FK -> tenants.id
critical OpenPlatform provisioning tables
critical named indexes already asserted by existing schema contract tests
```

Verify seeds through the application database:

```sql
SELECT permission_key
FROM permissions
WHERE permission_key LIKE 'openplatform.%'
ORDER BY permission_key
```

Expected exactly:

```text
openplatform.authorizer.bind
openplatform.authorizer.provision
openplatform.authorizer.read
openplatform.authorizer.refresh_metadata
openplatform.authorizer.retry_provision
openplatform.authorizer.start
```

Use the nine exact migration versions from Task 2 when inspecting migration history.

- [ ] **Step 3: Fail closed on ambiguity**

`assertLegacyV1Complete()` must throw a safe `RuntimeException` for anything except `LEGACY_V1_COMPLETE`. The error may list missing/mismatched object names but must not dump credentials or full environment state.

- [ ] **Step 4: Run focused/offline tests**

```bash
php tests/Component/Migration/V1SchemaVerifierTest.php
php tests/run.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/common/migration tests/Component/Migration tests/run.php
git commit -m "feat: classify V1 database migration state"
```

---

### Task 6: Explicit Legacy V1 Adoption Through the Locked Migration Adapter

**Files:**
- Create: `app/worker/command/MigrationAdoptV1Command.php`
- Modify: `config/console.php`
- Create: `tests/Acceptance/V1AdoptionRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`
- Modify: `tests/Release/run.php`

**Interfaces:**
- Produces CLI `php think migration:adopt-v1`.
- Command extends `think\migration\command\Migrate` so it can reuse protected `getMigrations()` and `getAdapter()` from the pinned package runtime.
- Consumes `V1SchemaVerifier` and exactly nine wrapper migration objects.

- [ ] **Step 1: Add command-contract RED**

Require `php think list` to contain:

```text
migration:adopt-v1
```

Add acceptance that a complete legacy database with no applied migration history becomes managed without replaying DDL.

- [ ] **Step 2: Implement the command on the package integration boundary**

Command shape:

```php
final class MigrationAdoptV1Command extends \think\migration\command\Migrate
{
    protected function configure(): void
    {
        $this->setName('migration:adopt-v1')
            ->setDescription('Adopt a verified legacy V1 schema into think-migration history');
    }

    protected function execute(Input $input, Output $output): void
    {
        // 1. classify with V1SchemaVerifier
        // 2. refuse EMPTY / MANAGED / DRIFTED_OR_PARTIAL
        // 3. require exactly the nine expected migrations
        // 4. call $this->getAdapter()->migrated($migration, MigrationInterface::UP, $now, $now)
        // 5. re-read adapter versions and assert exact nine-version set
    }
}
```

Replace the comments above with executable code in implementation; they describe the exact required sequence. Do not insert `phinxlog` rows with project SQL.

Return non-zero by throwing a safe exception on refusal/failure; do not change business tables.

- [ ] **Step 3: Build real legacy database fixtures from immutable SQL**

In `V1AdoptionRuntimeTest.php`, create the test database via `resetDatabase()`, then apply the immutable baseline SQL directly only to simulate a pre-standardization legacy installation. This is the one test-only place where direct baseline application remains allowed.

After applying 001–009, record table/row fingerprints, run:

```php
$adopt = $runtime->runThink(['migration:adopt-v1']);
```

Assert:

```text
exitCode == 0
nine expected migration versions recorded
business table count unchanged
permission seeds unchanged
subsequent migrate:run is a no-op
```

- [ ] **Step 4: Add the negative adoption matrix**

For each case, rebuild a fresh legacy fixture and prove adoption exits non-zero and migration history remains empty:

```text
drop one required table
alter admin_users.username away from varchar(64)
drop uk_admin_users_username
remove one critical foreign key
remove openplatform.authorizer.start permission seed
insert only one unexpected migration-history version
create migration history claiming V001–V009 while drop admin_users
```

- [ ] **Step 5: Register and release-gate the command**

Add `MigrationAdoptV1Command::class` to `config/console.php` project commands. Add `migration:adopt-v1` to `tests/Release/run.php` console assertions.

- [ ] **Step 6: Run real MySQL adoption GREEN**

Run `tests/Acceptance/run.php` against MySQL 8.4. Expected: positive adoption and all negative fail-closed cases PASS.

- [ ] **Step 7: Commit**

```bash
git add app/worker/command/MigrationAdoptV1Command.php config/console.php tests/Acceptance tests/Release/run.php
git commit -m "feat: adopt verified legacy V1 databases"
```

---

### Task 7: Admin Browser E2E and Permanent CI Use Real Migration CLI

**Files:**
- Rewrite: `tests/E2E/PrepareAdminBrowserDatabase.php`
- Modify: `.github/workflows/ci.yml`
- Extend: `tests/Contract/DatabaseMigrationStandardizationContractTest.php`

**Interfaces:**
- Admin browser setup becomes: reset safe DB → `php think migrate:run` → schema assertion → existing randomized/masked `admin:bootstrap` → Playwright.
- Adds permanent job `Database migration V1 gate` on MySQL 8.4.

- [ ] **Step 1: Add CI/E2E contract RED**

Require the workflow to contain these literal step/job names:

```text
Database migration V1 gate
Run fresh migrate rollback adoption gate
Prepare Admin browser database with migrate:run
```

Require `tests/E2E/PrepareAdminBrowserDatabase.php` not to require/call the old `acceptanceFreshDatabaseMigrationTest()` executor.

- [ ] **Step 2: Rewrite Admin browser DB setup**

Use `AcceptanceRuntime` only for safety/reset/process execution:

```php
$runtime->resetDatabase();
$migrate = $runtime->runThink(['migrate:run']);
acceptanceAssert($migrate->exitCode === 0, 'Admin browser migrate:run failed: ' . $migrate->stderr);
acceptanceAssertV1Schema($runtime->reconnectDatabase(), $config);
```

Print only:

```text
[PASS] Admin browser database prepared by migrate:run
```

- [ ] **Step 3: Add dedicated MySQL 8.4 migration job**

Add CI job with safe test DB names, for example:

```yaml
migration-v1:
  name: Database migration V1 gate
  needs: test
  runs-on: ubuntu-latest
  services:
    mysql:
      image: mysql:8.4
      env:
        MYSQL_ROOT_PASSWORD: ci-root-password
  env:
    WEPLATFORM_ACCEPTANCE: '1'
    DATABASE_HOSTNAME: 127.0.0.1
    DATABASE_DATABASE: weplatform_migration_test
    DATABASE_USERNAME: root
    DATABASE_PASSWORD: ci-root-password
    DATABASE_HOSTPORT: '3306'
```

After checkout/setup/composer install, run:

```yaml
- name: Run fresh migrate rollback adoption gate
  run: php tests/Acceptance/run.php
```

If runtime is too broad for this job, introduce an explicit `tests/Acceptance/run_migration.php` only if execution evidence shows unacceptable duplicate runtime; do not preemptively split it.

- [ ] **Step 4: Rename Admin setup step and retain secret handling**

Change existing Admin browser workflow step to:

```yaml
- name: Prepare Admin browser database with migrate:run
  run: php tests/E2E/PrepareAdminBrowserDatabase.php
```

Keep the existing per-run random admin password generation, `::add-mask::`, and `GITHUB_ENV` propagation unchanged.

- [ ] **Step 5: Run exact-head CI and inspect all jobs**

Expected GREEN on the same head:

```text
main test
Database migration V1 gate
Admin production browser E2E
R8D MySQL release gate
```

Inspect Admin browser logs to confirm sequence is real migration → bootstrap → browser login/dashboard/reload/logout, with password masked.

- [ ] **Step 6: Commit**

```bash
git add tests/E2E/PrepareAdminBrowserDatabase.php tests/Contract/DatabaseMigrationStandardizationContractTest.php .github/workflows/ci.yml
git commit -m "ci: gate real database migration lifecycle"
```

---

### Task 8: Operator Documentation and Final Exact-Head Verification

**Files:**
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-09-11-database-migration-standardization-v1-design.md` only if implementation evidence required a factual correction; otherwise leave the approved spec unchanged.
- PR metadata: create/update stacked Draft PR from `refactor/database-migration-standardization-v1` to `refactor/admin-foundation-completion-v1`.

**Interfaces:**
- Produces the supported operator workflow and final release evidence.

- [ ] **Step 1: Document fresh install commands exactly**

README must show:

```bash
composer install
php think migrate:status
php think migrate:run
php think admin:bootstrap --username=admin
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
php think run -p 18080
```

Also document:

```bash
php think migration:adopt-v1
```

for a verified pre-standardization V1 database, with explicit warning not to run it on partial/drifted schemas.

Document rollback as development/test-destructive:

```bash
php think migrate:rollback --target=0
```

and state that production rollback is not a replacement for backup/restore or forward-fix deployment.

- [ ] **Step 2: Run local/static verification commands**

Run:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php think list
php tests/run.php
php vendor/bin/phpunit
find app modules config tests database/migrations -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all commands exit 0; console lists migrate commands plus `migration:adopt-v1` and `admin:bootstrap`.

- [ ] **Step 3: Run the full real-MySQL gate**

Run:

```bash
WEPLATFORM_ACCEPTANCE=1 \
DATABASE_HOSTNAME=127.0.0.1 \
DATABASE_DATABASE=weplatform_migration_test \
DATABASE_USERNAME=root \
DATABASE_PASSWORD='<local-test-password>' \
php tests/Release/run.php
```

Expected: offline, PHPUnit, lint, command contracts, fresh migration, no-op migration, rollback/re-run, legacy adoption positive/negative matrix, existing R8D acceptance all PASS.

- [ ] **Step 4: Push and require fresh exact-head GitHub Actions GREEN**

Do not reuse earlier Run #508 because the dependency graph and migration runtime changed. Require a new exact-head workflow where every job is SUCCESS.

- [ ] **Step 5: Review scope/diff before completion claim**

Verify the diff contains only migration-standardization work, documentation, and required CI/test changes. Confirm PR #10 branch/head was not modified by this feature.

- [ ] **Step 6: Open/update stacked Draft PR**

Base:

```text
refactor/admin-foundation-completion-v1
```

Head:

```text
refactor/database-migration-standardization-v1
```

PR body must record:

```text
Automated Quality Gate = GREEN only after new exact-head evidence
Human Gate = PENDING
```

Do not mark Ready and do not merge.

---

## Final Acceptance Checklist

- [ ] AC1: `topthink/think-migration:^3.1` is committed and lockfile-pinned.
- [ ] AC2: `migrate:create/run/rollback/status` are real supported commands.
- [ ] AC3: fresh MySQL 8.4 database reaches the complete V1 schema only through `migrate:run`.
- [ ] AC4: historical SQL 001–009 is preserved under `database/schema/v1` and SHA-256 pinned.
- [ ] AC5: nine PHP wrappers preserve versions/history and use the active migration adapter.
- [ ] AC6: repeated `migrate:run` is a no-op success.
- [ ] AC7: full rollback is dependency-safe and re-run recreates equivalent schema/seeds.
- [ ] AC8: `migration:adopt-v1` adopts only a strictly verified legacy complete V1 database.
- [ ] AC9: partial/drifted/history-inconsistent databases fail closed without history mutation.
- [ ] AC10: fresh migration is immediately compatible with real `admin:bootstrap`.
- [ ] AC11: Admin browser E2E uses `migrate:run → admin:bootstrap → login/dashboard/reload/logout`.
- [ ] AC12: main, migration, Admin browser, R8D release gates are GREEN at the same exact head.
- [ ] AC13: independent Human Acceptance remains required before any Ready/merge decision.
