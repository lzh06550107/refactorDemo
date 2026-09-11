# Database Migration Standardization V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `topthink/think-migration` the single supported schema lifecycle for local development, CI, acceptance, and Admin browser E2E while preserving the validated V1 SQL 001–009 exactly and safely adopting legacy V1 databases.

**Architecture:** Move the existing 001–009 SQL byte-for-byte to `database/schema/v1/`, pin it with a SHA-256 manifest, and add nine thin PHP migrations under `database/migrations/`. Those migrations execute baseline SQL through the active Phinx adapter. Fresh databases use `php think migrate:run`; pre-standardization V1 databases use an explicit `php think migration:adopt-v1` path that verifies schema/history/seeds first and records all nine versions atomically through the same locked migration adapter.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, `topthink/think-migration:^3.1` locked by Composer, bundled Phinx runtime, MySQL 8.4, existing offline/PHPUnit/release gates, GitHub Actions, Admin Playwright Chromium E2E.

**Spec:** `docs/superpowers/specs/2026-09-11-database-migration-standardization-v1-design.md`

## Global Constraints

- Base is `refactor/admin-foundation-completion-v1` at `8fbc4a615c717b9a61d9954dee4654f8630731f7`.
- Work only on `refactor/database-migration-standardization-v1`; do not modify PR #10's branch/head.
- Add `topthink/think-migration` with Composer constraint exactly `^3.1`; commit the resolved `composer.lock`.
- `topthink/think-migration` 3.1 discovers and creates migrations in project `database/migrations`.
- Existing V1 SQL 001–009 is immutable in this Feature: move it byte-for-byte only. If a real rollback test proves a historical SQL defect, STOP this plan and amend the design rather than editing the baseline silently.
- V1 wrappers execute statements through the active migration adapter; never open a second PDO/ThinkPHP DB connection inside migration classes.
- Migration 010+ uses normal PHP migration classes; no new `*_up.sql` / `*_down.sql` convention after V1/009.
- Legacy adoption is explicit; ordinary `migrate:run` never auto-adopts an existing business schema.
- Adoption must be atomic: verification failure writes no history, and a mid-history-write failure rolls back all history writes.
- Partial/drifted/history-inconsistent databases fail closed; no automatic repair.
- Real database tests use MySQL 8.4 and existing safety rules: `WEPLATFORM_ACCEPTANCE=1`, local DB host, safe test DB name.
- Automated gates do not replace Human Acceptance.

---

## File Structure

### Production

- `database/schema/v1/*.sql` — the 18 immutable V1 up/down SQL files.
- `database/schema/v1/manifest.sha256` — exactly 18 SHA-256 entries.
- `database/migrations/20260907000100_v001_iam_tenant_account.php`
- `database/migrations/20260907000200_v002_iam_module_platform.php`
- `database/migrations/20260907000300_v003_entitlement_quota.php`
- `database/migrations/20260907000400_v004_site_theme_runtime.php`
- `database/migrations/20260908000500_v005_member_oauth_webhook.php`
- `database/migrations/20260908000600_v006_miniapp_identity_session.php`
- `database/migrations/20260908000700_v007_openplatform_component_trust.php`
- `database/migrations/20260908000800_v008_openplatform_authorizer_lifecycle.php`
- `database/migrations/20260909000900_v009_openplatform_authorizer_provisioning.php`
- `app/common/migration/V1BaselineSql.php` — fixed-directory SQL loader/splitter.
- `app/common/migration/V1SqlMigration.php` — shared active-adapter execution base for V1 wrappers.
- `app/common/migration/V1DatabaseState.php` — `EMPTY|MANAGED|LEGACY_V1_COMPLETE|DRIFTED_OR_PARTIAL`.
- `app/common/migration/V1SchemaInspector.php` — read-only inspection contract.
- `app/common/migration/PdoV1SchemaInspector.php` — MySQL/PDO inspection implementation.
- `app/common/migration/V1SchemaVerifier.php` — state classifier/fingerprint verifier.
- `app/worker/command/MigrationAdoptV1Command.php` — explicit atomic legacy adoption command.

### Tests

- `tests/Contract/DatabaseMigrationStandardizationContractTest.php`
- `tests/Unit/Migration/V1BaselineSqlTest.php`
- `tests/Component/Migration/V1SchemaVerifierTest.php`
- `tests/Acceptance/DatabaseMigrationRuntimeTest.php`
- `tests/Acceptance/V1AdoptionRuntimeTest.php`

### Modified

- `composer.json`, `composer.lock`
- `config/console.php`
- `tests/run.php`
- `tests/Acceptance/bootstrap.php`
- `tests/Acceptance/FreshDatabaseMigrationTest.php`
- `tests/Acceptance/run.php`
- `tests/E2E/PrepareAdminBrowserDatabase.php`
- `tests/Release/run.php`
- `.github/workflows/ci.yml`
- `README.md`

---

## Task 1 — Architecture Contract RED

**Files**
- Create: `tests/Contract/DatabaseMigrationStandardizationContractTest.php`
- Modify: `tests/run.php`

**Produces**
- `databaseMigrationStandardizationContractTest(string $root): void`

- [ ] Write the contract so its first assertion is the missing dependency:

```php
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['require']['topthink/think-migration'] ?? null) !== '^3.1') {
    throw new RuntimeException('topthink/think-migration:^3.1 is required.');
}
```

Then require the exact nine PHP migration filenames listed above, forbid `database/migrations/*_up.sql` and `*_down.sql`, require `database/schema/v1/manifest.sha256`, require `tests/Acceptance/FreshDatabaseMigrationTest.php` not to contain its old `glob('*_up.sql')`/manual SQL execution path, and require Admin browser DB preparation to contain `migrate:run`.

- [ ] Register the contract in `tests/run.php` immediately after the frontend architecture contracts.

- [ ] Run:

```bash
php tests/run.php
```

Expected: existing tests stay healthy and this contract fails with exactly `topthink/think-migration:^3.1 is required.`

- [ ] Commit only the test/runner:

```bash
git add tests/Contract/DatabaseMigrationStandardizationContractTest.php tests/run.php
git commit -m "test: contract migration standardization"
```

- [ ] Push and record an exact-head CI RED run before implementation.

---

## Task 2 — Dependency, Immutable Baseline, and Nine V1 Wrappers

**Files**
- Modify: `composer.json`, `composer.lock`, `tests/run.php`
- Move byte-for-byte: current 18 SQL files → `database/schema/v1/`
- Create: `database/schema/v1/manifest.sha256`
- Create: `app/common/migration/V1BaselineSql.php`
- Create: `app/common/migration/V1SqlMigration.php`
- Create: nine migration wrapper files
- Create: `tests/Unit/Migration/V1BaselineSqlTest.php`

**Produces**

```php
final class V1BaselineSql
{
    public function __construct(string $directory);
    /** @return list<string> */
    public function statements(string $file): array;
}

abstract class V1SqlMigration extends \think\migration\Migrator
{
    final protected function runBaseline(string $file): void;
}
```

- [ ] Add a unit RED proving `V1BaselineSql` rejects path traversal, rejects missing/empty files, and preserves ordered statements. Example success assertion:

```php
$loader = new V1BaselineSql($fixtureDir);
assert($loader->statements('001_up.sql') === [
    'CREATE TABLE a (id INT)',
    'INSERT INTO a VALUES (1)',
]);
```

- [ ] Run `php tests/Unit/Migration/V1BaselineSqlTest.php`; expected RED because the class is absent.

- [ ] Install/lock the package:

```bash
composer require topthink/think-migration:^3.1 --no-interaction
composer validate --strict
php think list
```

Expected console commands include `migrate:create`, `migrate:run`, `migrate:rollback`, `migrate:status`.

- [ ] Move all 18 SQL files byte-for-byte to `database/schema/v1/`; do not edit SQL text.

- [ ] Generate the immutable manifest:

```bash
php -r '$files=glob("database/schema/v1/*.sql"); sort($files); foreach($files as $f){echo hash_file("sha256",$f)."  ".basename($f).PHP_EOL;}' > database/schema/v1/manifest.sha256
```

Assert exactly 18 entries. Extend the contract to parse each line with `/^[a-f0-9]{64}  ([A-Za-z0-9_]+\.sql)$/` and verify `hash_file('sha256', $file)` equals the manifest hash.

- [ ] Implement `V1BaselineSql::statements()` with this validation behavior:

```php
if (basename($file) !== $file || preg_match('/^[A-Za-z0-9_]+\.sql$/', $file) !== 1) {
    throw new RuntimeException('Invalid V1 baseline filename.');
}
```

Read only `$directory . DIRECTORY_SEPARATOR . $file`; missing/empty/no-statements throws a safe `RuntimeException` naming only the baseline filename. Split current baseline statements with the repository's existing `'/;\s*(?:\R|$)/'` rule.

- [ ] Implement `V1SqlMigration::runBaseline()` once:

```php
final protected function runBaseline(string $file): void
{
    $loader = new V1BaselineSql(dirname(__DIR__, 3) . '/database/schema/v1');
    foreach ($loader->statements($file) as $statement) {
        $this->execute($statement);
    }
}
```

If the relative root above is wrong in the real class location, use `dirname(__DIR__, 3)` only after verifying it resolves to repository root in a focused test; do not open another connection.

- [ ] Create the nine wrappers. Example:

```php
final class V001IamTenantAccount extends V1SqlMigration
{
    public function up(): void
    {
        $this->runBaseline('20260907_001_iam_tenant_account_up.sql');
    }

    public function down(): void
    {
        $this->runBaseline('20260907_001_iam_tenant_account_down.sql');
    }
}
```

Use corresponding V002–V009 names/files and versions exactly.

- [ ] Run:

```bash
php tests/Unit/Migration/V1BaselineSqlTest.php
php tests/run.php
php think migrate:status
```

Expected: unit/contract GREEN; `migrate:status` discovers exactly nine migration classes without duplicate/class-name errors.

- [ ] Commit:

```bash
git add composer.json composer.lock database app/common/migration tests
git commit -m "feat: add V1 think-migration wrappers"
```

---

## Task 3 — Real Fresh Migration Runtime and Bootstrap Smoke

**Files**
- Modify: `tests/Acceptance/bootstrap.php`, `tests/Acceptance/FreshDatabaseMigrationTest.php`, `tests/Acceptance/run.php`, `tests/Release/run.php`
- Create: `tests/Acceptance/DatabaseMigrationRuntimeTest.php`

**Produces**

```php
final readonly class AcceptanceCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}

AcceptanceRuntime::runThink(array $arguments, array $extraEnvironment = []): AcceptanceCommandResult
AcceptanceRuntime::reconnectDatabase(): PDO
acceptanceAssertV1Schema(PDO $db, AcceptanceConfig $config): void
```

- [ ] Add RED that resets the safe test DB, invokes `runThink(['migrate:run'])`, asserts exit 0, reconnects, verifies V1 schema/seeds, invokes `migrate:status`, then invokes `migrate:run` a second time and requires no-op success.

- [ ] Implement `runThink()` with `proc_open([PHP_BINARY, $root.'/think', ...$arguments], ...)`, repository root cwd, and `array_merge($config->childEnvironment(), $extraEnvironment)`. Capture stdout/stderr/exit code. Never pass secrets as CLI args.

- [ ] Add `reconnectDatabase()` that reconnects to the configured already-existing test database without dropping it.

- [ ] Rewrite `FreshDatabaseMigrationTest.php`: remove SQL discovery/splitting/execution; keep its schema/seed assertions as `acceptanceAssertV1Schema()`.

- [ ] After fresh migration, prove real bootstrap compatibility:

```php
$password = bin2hex(random_bytes(16));
$result = $runtime->runThink(
    ['admin:bootstrap', '--username=migration-test-admin'],
    ['WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD' => $password],
);
acceptanceAssert($result->exitCode === 0, 'admin:bootstrap failed after migrate:run.');
```

Query the row and require `status='active'` and `password_verify($password, $passwordHash) === true`. Never print `$password`.

- [ ] Extend `tests/Release/run.php` so `php think list` must expose all four migration commands.

- [ ] Run MySQL 8.4 acceptance using a safe DB such as `weplatform_migration_test`; expected fresh migrate/status/no-op/schema/bootstrap all PASS.

- [ ] Commit:

```bash
git add tests/Acceptance tests/Release/run.php
git commit -m "test: run fresh database through migration CLI"
```

---

## Task 4 — Full Rollback and Re-run

**Files**
- Extend: `tests/Acceptance/DatabaseMigrationRuntimeTest.php`, `tests/Acceptance/run.php`

**Consumes**
- `AcceptanceRuntime::runThink()` and `acceptanceAssertV1Schema()`.

- [ ] After fresh migration, run:

```php
$rollback = $runtime->runThink(['migrate:rollback', '--target=0']);
acceptanceAssert($rollback->exitCode === 0, 'Full V1 rollback failed: ' . $rollback->stderr);
```

- [ ] Assert V1 business tables are absent and migration history contains zero applied V1 versions. The framework-owned history table may remain.

- [ ] Run `migrate:run` again and require the same V1 schema/seed assertions and exact ordered `openplatform.*` permission set.

- [ ] If rollback fails because an immutable historical `_down.sql` is defective, STOP. Do not edit baseline SQL in this Task. Record the precise MySQL failure and amend the design before continuing.

- [ ] Run the real MySQL acceptance gate to GREEN and commit only test/runtime changes:

```bash
git add tests/Acceptance
git commit -m "test: verify V1 rollback and rebuild"
```

---

## Task 5 — V1 State Classifier and Strict Fingerprint Verifier

**Files**
- Create: `app/common/migration/V1DatabaseState.php`
- Create: `app/common/migration/V1SchemaInspector.php`
- Create: `app/common/migration/PdoV1SchemaInspector.php`
- Create: `app/common/migration/V1SchemaVerifier.php`
- Create: `tests/Component/Migration/V1SchemaVerifierTest.php`
- Modify: `tests/run.php`

**Produces**

```php
enum V1DatabaseState: string
{
    case EMPTY = 'empty';
    case MANAGED = 'managed';
    case LEGACY_V1_COMPLETE = 'legacy_v1_complete';
    case DRIFTED_OR_PARTIAL = 'drifted_or_partial';
}

interface V1SchemaInspector
{
    /** @return list<int> */
    public function appliedMigrationVersions(): array;
    /** @return list<string> */
    public function tables(): array;
    public function columnSignature(string $table, string $column): ?string;
    public function hasIndex(string $table, string $index): bool;
    public function hasForeignKey(string $table, string $constraint): bool;
    /** @return list<string> */
    public function openPlatformPermissions(): array;
}

final class V1SchemaVerifier
{
    public function __construct(V1SchemaInspector $inspector);
    public function classify(): V1DatabaseState;
    public function assertLegacyV1Complete(): void;
}
```

- [ ] Unit/component RED must cover exactly:

```text
no applied versions + no V1 business tables -> EMPTY
exact nine applied versions + valid fingerprint -> MANAGED
no applied versions + valid complete fingerprint -> LEGACY_V1_COMPLETE
some business schema but incomplete/mismatched -> DRIFTED_OR_PARTIAL
unexpected/partial migration versions -> DRIFTED_OR_PARTIAL
```

- [ ] Implement `PdoV1SchemaInspector` using read-only `information_schema` queries plus permission seed query. `columnSignature()` returns a deterministic string such as `varchar(64)|NO||` containing type/nullability/default/extra needed by the verifier.

- [ ] The verifier must at minimum require critical tables and these invariants:

```text
admin_users.id varchar(64) NOT NULL primary key
admin_users.username varchar(64) NOT NULL
uk_admin_users_username exists
fk_admin_sessions_user exists
fk_accounts_tenant exists
critical component/authorizer/provisioning tables exist
exact six openplatform.* permission seeds exist
```

Reuse stronger existing schema-contract expectations where available; do not weaken them.

- [ ] `assertLegacyV1Complete()` succeeds only for `LEGACY_V1_COMPLETE`; all other states throw a safe `RuntimeException` without environment/credential dumps.

- [ ] Run focused + offline GREEN:

```bash
php tests/Component/Migration/V1SchemaVerifierTest.php
php tests/run.php
```

- [ ] Commit:

```bash
git add app/common/migration tests/Component/Migration tests/run.php
git commit -m "feat: verify V1 migration state"
```

---

## Task 6 — Atomic Explicit Legacy Adoption

**Files**
- Create: `app/worker/command/MigrationAdoptV1Command.php`
- Modify: `config/console.php`, `tests/Acceptance/run.php`, `tests/Release/run.php`
- Create: `tests/Acceptance/V1AdoptionRuntimeTest.php`

**Produces**
- CLI: `php think migration:adopt-v1`

- [ ] Add RED requiring the command in `php think list` and requiring complete legacy V1 adoption without DDL replay.

- [ ] Implement `MigrationAdoptV1Command` by extending `think\migration\command\Migrate`. In `execute()`:

```php
$adapter = $this->getAdapter();
if (!$adapter instanceof \Phinx\Db\Adapter\PdoAdapter) {
    throw new RuntimeException('V1 adoption requires a PDO migration adapter.');
}

$pdo = $adapter->getConnection();
$verifier = new V1SchemaVerifier(new PdoV1SchemaInspector($pdo));
$verifier->assertLegacyV1Complete();

$migrations = $this->getMigrations();
$expected = [
    20260907000100, 20260907000200, 20260907000300, 20260907000400,
    20260908000500, 20260908000600, 20260908000700, 20260908000800,
    20260909000900,
];
if (array_keys($migrations) !== $expected) {
    throw new RuntimeException('Installed V1 migration set does not match adoption contract.');
}

$now = date('Y-m-d H:i:s');
$pdo->beginTransaction();
try {
    foreach ($migrations as $migration) {
        $adapter->migrated($migration, \Phinx\Migration\MigrationInterface::UP, $now, $now);
    }
    $actual = $adapter->getVersions();
    sort($actual);
    if ($actual !== $expected) {
        throw new RuntimeException('V1 adoption history verification failed.');
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
```

Configure name `migration:adopt-v1` and register it in `config/console.php`. Output only a non-secret success summary after commit.

- [ ] In `V1AdoptionRuntimeTest.php`, simulate a legacy installation by applying the immutable 001–009 baseline directly in test setup only, without migration history. Snapshot business-table counts and permission seeds, run `migration:adopt-v1`, and assert: exit 0, exact nine versions recorded, business data unchanged, later `migrate:run` no-op.

- [ ] Add negative real-MySQL cases. Recreate the fixture before each case and require non-zero exit plus zero adopted versions:

```text
one required table missing
admin_users.username signature changed
uk_admin_users_username missing
critical foreign key missing
one required openplatform permission missing
partial/unexpected migration history
history claims V1 but admin_users missing
```

- [ ] Extend release command assertions for `migration:adopt-v1`.

- [ ] Run real MySQL adoption matrix GREEN.

- [ ] Commit:

```bash
git add app/worker/command/MigrationAdoptV1Command.php config/console.php tests/Acceptance tests/Release/run.php
git commit -m "feat: adopt verified legacy V1 databases"
```

---

## Task 7 — Admin Browser E2E and Permanent CI Use Real Migration

**Files**
- Rewrite: `tests/E2E/PrepareAdminBrowserDatabase.php`
- Extend: `tests/Contract/DatabaseMigrationStandardizationContractTest.php`
- Modify: `.github/workflows/ci.yml`

**Produces**
- Permanent MySQL 8.4 job named `Database migration V1 gate`.
- Admin browser DB chain: safe reset → `migrate:run` → schema assertion → existing masked `admin:bootstrap` → Playwright.

- [ ] Add contract RED requiring workflow strings:

```text
Database migration V1 gate
Run fresh migrate rollback adoption gate
Prepare Admin browser database with migrate:run
```

and forbidding Admin browser setup from calling the old custom fresh-SQL executor.

- [ ] Rewrite Admin browser preparation:

```php
$runtime->resetDatabase();
$result = $runtime->runThink(['migrate:run']);
acceptanceAssert($result->exitCode === 0, 'Admin browser migrate:run failed: ' . $result->stderr);
acceptanceAssertV1Schema($runtime->reconnectDatabase(), $config);
fwrite(STDOUT, "[PASS] Admin browser database prepared by migrate:run\n");
```

- [ ] Add CI job `migration-v1` using MySQL `8.4`, DB `weplatform_migration_test`, Composer locked install, and:

```yaml
- name: Run fresh migrate rollback adoption gate
  run: php tests/Acceptance/run.php
```

Keep acceptance safety env vars explicit.

- [ ] Rename existing Admin browser preparation step to `Prepare Admin browser database with migrate:run`. Preserve the existing runtime-generated random password, `::add-mask::`, and `GITHUB_ENV`; do not reintroduce a fixed password.

- [ ] Push and require exact-head GREEN for all four jobs:

```text
main test
Database migration V1 gate
Admin production browser E2E
R8D MySQL release gate
```

Inspect Admin browser logs to confirm migration → bootstrap → login/dashboard/reload/logout and masked password.

- [ ] Commit:

```bash
git add tests/E2E/PrepareAdminBrowserDatabase.php tests/Contract/DatabaseMigrationStandardizationContractTest.php .github/workflows/ci.yml
git commit -m "ci: gate real database migration lifecycle"
```

---

## Task 8 — Documentation, Diff Review, and Final Exact-Head Gate

**Files**
- Modify: `README.md`
- Update spec only if implementation evidence requires an approved factual correction.
- Open stacked Draft PR: head `refactor/database-migration-standardization-v1`, base `refactor/admin-foundation-completion-v1`.

- [ ] Document the supported fresh install flow exactly:

```bash
composer install
php think migrate:status
php think migrate:run
php think admin:bootstrap --username=admin
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
php think run -p 18080
```

Document explicit legacy adoption:

```bash
php think migration:adopt-v1
```

Document destructive dev/test rollback:

```bash
php think migrate:rollback --target=0
```

State that production rollback is not a backup/restore or forward-fix substitute.

- [ ] Run fresh local/static verification:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php think list
php tests/run.php
php vendor/bin/phpunit
find app modules config tests database/migrations -name '*.php' -print0 | xargs -0 -n1 php -l
```

- [ ] Run the full real-MySQL release gate on a safe MySQL 8.4 test DB. Expected: fresh migrate, no-op migrate, rollback/re-run, bootstrap, legacy adoption matrix, and existing backend acceptance all PASS.

- [ ] Push and require a new exact-head CI SUCCESS. Run #508 is not reusable because this Feature changes dependencies and DB lifecycle.

- [ ] Review the diff against the spec. Confirm no unrelated business schema/API/UI changes and confirm PR #10 branch/head remains unchanged.

- [ ] Open/update the stacked Draft PR. Body must say:

```text
Automated Quality Gate = GREEN only after exact-head evidence
Human Gate = PENDING
```

Do not mark Ready or merge.

---

## Final Acceptance Checklist

- [ ] `topthink/think-migration:^3.1` is committed and exact-version locked.
- [ ] `migrate:create/run/rollback/status` are supported real commands.
- [ ] Empty MySQL 8.4 reaches complete V1 solely through `migrate:run`.
- [ ] 001–009 SQL is byte-preserved under `database/schema/v1` and SHA-256 pinned.
- [ ] Nine PHP wrappers preserve version ordering and use the active migration adapter.
- [ ] Repeated `migrate:run` is a no-op success.
- [ ] Full rollback succeeds without modifying immutable baseline SQL; re-run recreates equivalent schema/seeds.
- [ ] `migration:adopt-v1` adopts only a strictly verified complete legacy V1 database and writes all nine history rows atomically.
- [ ] Partial/drifted/history-inconsistent DBs fail closed with no adoption history mutation.
- [ ] Fresh migration is immediately compatible with real `admin:bootstrap`.
- [ ] Admin browser E2E proves `migrate:run → admin:bootstrap → login → dashboard → reload → logout`.
- [ ] main, migration, Admin browser, and R8D release jobs are GREEN at the same exact head.
- [ ] Independent Human Acceptance remains required before Ready/merge.
