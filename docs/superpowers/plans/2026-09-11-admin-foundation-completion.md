# Admin Foundation Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a production-mode Admin SPA at `/admin/`, a safe one-time first-administrator bootstrap command, and real MySQL + Chromium proof of bootstrap → login → dashboard → session restore → logout.

**Architecture:** Keep `/admin-api/*` owned by the existing `app/admin` API while adding a dedicated `app/adminui` application for SPA history fallback. Build the Vue Admin directly into generated `public/admin/`, create the first administrator through an IAM application service backed by a MySQL advisory-lock repository, and verify the complete path with real MySQL and Playwright Chromium.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, think-orm 4.x, MySQL 8.4, Vue 3.5, Vue Router 5.3, Element Plus 2.14, Vite 8.2, Vitest 5, Playwright 1.63, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-11-admin-foundation-completion-design.md`

## Global Constraints

- Work only on `refactor/admin-foundation-completion-v1`, stacked on `refactor/web-h5-template-foundation-v1` at `c00ff32dcc2c05ff4490295ba445bd4dba474ed7`.
- Do not modify PR #7, PR #8, or completed PR #9 content.
- `/admin/*` belongs to `app/adminui`; `/admin-api/*` must remain `app/admin`.
- Admin SPA production base remains `/admin/`; Admin API base remains same-origin `/admin-api/v1`.
- Production Admin assets are generated under `public/admin/` and must not be committed.
- No default username/password in source, migrations, fixtures, docs, or deployment files.
- Username: trim Unicode whitespace; 3–64 Unicode code points; reject ASCII controls and DEL; preserve other Unicode.
- Password: minimum 12 Unicode code points; maximum 1024 UTF-8 bytes; do not trim or normalize; no composition rule.
- Password hashing uses `password_hash(..., PASSWORD_DEFAULT)`; plaintext password is never logged, returned, or persisted.
- First-admin persistence uses the fixed MySQL advisory lock `weplatform:admin-bootstrap` with 5-second acquisition timeout and fail-closed behavior.
- Real MySQL concurrency acceptance must prove two simultaneous bootstrap attempts leave exactly one administrator.
- Automated gates never replace the independent Human Gate.

---

## File Map

### New Admin UI delivery files

- `app/adminui/controller/SpaController.php` — production SPA shell response only; no business logic/database access.
- `app/adminui/route/app.php` — `/admin/` application-local SPA routes and catch-all history fallback.
- `tests/Component/AdminUi/SpaControllerTest.php` — component coverage for shell delivery and missing-build 503 behavior.

### First-admin bootstrap files

- `modules/iam/contract/AdminIdGenerator.php` — secure administrator ID abstraction.
- `modules/iam/contract/BootstrapAdminRepository.php` — one atomic semantic first-admin creation operation.
- `modules/iam/domain/BootstrapAdminCreateResult.php` — `CREATED` / `ALREADY_EXISTS` persistence result.
- `modules/iam/security/SecureAdminIdGenerator.php` — `bin2hex(random_bytes(16))` implementation.
- `modules/iam/application/BootstrapFirstAdmin.php` — validation, hashing, ID generation, fail-closed application flow.
- `modules/iam/application/InitialAdminAlreadyExists.php` — stable application error for one-time bootstrap refusal.
- `modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php` — MySQL advisory-lock + transaction implementation.
- `app/worker/command/AdminBootstrapCommand.php` — console adapter only.
- `tests/Component/Iam/BootstrapFirstAdminTest.php` — pure application/component behavior.
- `tests/Component/Iam/ThinkPhpBootstrapAdminRepositoryTest.php` — repository contract behavior where practical without the release database.
- `tests/Acceptance/AdminBootstrapRuntimeTest.php` — real MySQL creation, second-attempt refusal, login compatibility and concurrency.

### Browser gate files

- `frontend/admin/e2e/package.json` — isolated Playwright package pinned to 1.63.0.
- `frontend/admin/e2e/package-lock.json` — locked browser dependencies.
- `frontend/admin/e2e/playwright.config.js` — production Admin server/browser setup.
- `frontend/admin/e2e/admin-login.e2e.js` — bootstrap/login/dashboard/reload/logout browser flow.

### Existing files to modify

- `config/app.php` — add explicit `admin => adminui` mapping while preserving `admin-api => admin`.
- `config/console.php` — register `AdminBootstrapCommand`.
- `app/AppService.php` — bind bootstrap repository and admin ID generator.
- `frontend/admin/vite.config.ts` — production output `../../public/admin` while retaining base `/admin/`.
- `.gitignore` — ignore `public/admin/` and Admin E2E generated output.
- `tests/Contract/AdminFoundationCompletionContractTest.php` — permanent architecture/security/build/browser contract.
- `tests/run.php` — include new contract/component tests.
- `tests/Acceptance/run.php` — invoke real MySQL bootstrap acceptance.
- `tests/Release/run.php` — require the console command in release gate output.
- `.github/workflows/ci.yml` — Admin E2E dependency/browser install, production browser gate and release wiring.

---

### Task 1: Add the permanent Admin foundation contract and establish RED

**Files:**
- Create: `tests/Contract/AdminFoundationCompletionContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: current repository paths/configuration.
- Produces: a fail-closed architecture test that all later tasks must satisfy.

- [ ] **Step 1: Write the failing architecture contract**

Create `tests/Contract/AdminFoundationCompletionContractTest.php` with assertions equivalent to:

```php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$appConfig = file_get_contents($root . '/config/app.php');
$consoleConfig = file_get_contents($root . '/config/console.php');
$viteConfig = file_get_contents($root . '/frontend/admin/vite.config.ts');
$gitignore = file_get_contents($root . '/.gitignore');
$ci = file_get_contents($root . '/.github/workflows/ci.yml');

expectTrue(is_file($root . '/app/adminui/controller/SpaController.php'), 'adminui SPA controller required');
expectTrue(is_file($root . '/app/adminui/route/app.php'), 'adminui routes required');
expectTrue(is_file($root . '/app/worker/command/AdminBootstrapCommand.php'), 'admin bootstrap command required');
expectTrue(is_file($root . '/modules/iam/application/BootstrapFirstAdmin.php'), 'bootstrap application service required');
expectTrue(is_file($root . '/modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php'), 'bootstrap MySQL repository required');
expectTrue(is_file($root . '/frontend/admin/e2e/playwright.config.js'), 'Admin Playwright config required');
expectTrue(is_file($root . '/frontend/admin/e2e/admin-login.e2e.js'), 'Admin browser E2E spec required');

expectTrue(str_contains((string) $appConfig, "'admin'"), 'admin URL mapping required');
expectTrue(str_contains((string) $appConfig, "'admin-api'"), 'admin-api mapping must remain');
expectTrue(str_contains((string) $consoleConfig, 'AdminBootstrapCommand::class'), 'bootstrap command must be registered');
expectTrue(str_contains((string) $viteConfig, "base: '/admin/'"), 'Admin Vite base must remain /admin/');
expectTrue(str_contains((string) $viteConfig, "outDir: '../../public/admin'"), 'Admin build must target public/admin');
expectTrue(str_contains((string) $gitignore, 'public/admin/'), 'generated Admin build must be ignored');
expectTrue(str_contains((string) $ci, 'Test Admin production browser E2E'), 'CI must contain permanent Admin browser gate');
expectTrue(str_contains((string) $ci, 'frontend/admin/e2e/package-lock.json'), 'CI cache must include Admin E2E lock');
```

Add the file to `tests/run.php` directly after `AdminFrontendArchitectureContractTest.php`.

- [ ] **Step 2: Run the offline suite and prove RED**

Run:

```bash
php tests/run.php
```

Expected: FAIL specifically in `AdminFoundationCompletionContractTest.php` because `app/adminui`, bootstrap command/repository and Admin Playwright gate do not exist yet. Existing earlier tests should remain green.

- [ ] **Step 3: Commit the test-only RED**

```bash
git add tests/Contract/AdminFoundationCompletionContractTest.php tests/run.php
git commit -m "test: define admin foundation completion contract"
```

---

### Task 2: Deliver the production Admin SPA at `/admin/`

**Files:**
- Create: `app/adminui/controller/SpaController.php`
- Create: `app/adminui/route/app.php`
- Create: `tests/Component/AdminUi/SpaControllerTest.php`
- Modify: `config/app.php`
- Modify: `frontend/admin/vite.config.ts`
- Modify: `.gitignore`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: existing Vue Router base `/admin/`, existing `public/router.php` static-file short circuit.
- Produces: `SpaController::index(): Response`, `/admin/* => adminui`, generated `public/admin/index.html` and `public/admin/assets/*`.

- [ ] **Step 1: Write controller tests before the controller exists**

Create `tests/Component/AdminUi/SpaControllerTest.php` to exercise an injected/temporary build path. Required assertions:

```php
$controller = new app\adminui\controller\SpaController($existingIndexPath);
$response = $controller->index();
expectSame(200, $response->getCode(), 'Admin SPA returns 200');
expectTrue(str_contains((string) $response->getContent(), '<div id="app"></div>'), 'Admin SPA returns built shell');
expectTrue(str_contains((string) $response->getHeader('Content-Type'), 'text/html'), 'Admin SPA content type');

$missing = new app\adminui\controller\SpaController($missingIndexPath);
$missingResponse = $missing->index();
expectSame(503, $missingResponse->getCode(), 'Missing Admin build returns 503');
expectTrue(!str_contains((string) $missingResponse->getContent(), 'Stack trace'), '503 response is production-safe');
```

Register it in `tests/run.php`.

- [ ] **Step 2: Run the focused/offline suite and verify RED**

```bash
php tests/run.php
```

Expected: FAIL because `SpaController` does not exist.

- [ ] **Step 3: Implement minimal SPA shell delivery**

Create `SpaController` with one responsibility:

```php
final readonly class SpaController
{
    public function __construct(private ?string $indexPath = null)
    {
    }

    public function index(): Response
    {
        $path = $this->indexPath ?? app()->getRootPath() . 'public/admin/index.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            return response(
                '<!doctype html><html><body><h1>Admin UI unavailable</h1></body></html>',
                503,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
```

Define Admin UI routes:

```php
Route::get('', 'SpaController/index');
Route::get('<path>', 'SpaController/index')->pattern(['path' => '.*']);
```

Modify `config/app.php` mapping to preserve API isolation:

```php
'app_map' => [
    'admin' => static fn ($app): string => 'adminui',
    'admin-api' => static fn ($app): string => 'admin',
],
```

Change Admin Vite output:

```ts
build: {
  outDir: '../../public/admin',
  emptyOutDir: true,
},
```

Replace the old generated Admin ignore entry with:

```text
public/admin/
```

- [ ] **Step 4: Build and verify production paths**

```bash
npm ci --prefix frontend/admin
npm run typecheck --prefix frontend/admin
npm test --prefix frontend/admin
npm run build --prefix frontend/admin
test -f public/admin/index.html
find public/admin/assets -type f | head
php tests/run.php
```

Expected: Admin frontend checks PASS; generated shell/assets exist; SPA component test PASS. The global architecture contract may still remain RED for bootstrap/E2E files.

- [ ] **Step 5: Commit the production SPA slice**

```bash
git add app/adminui config/app.php frontend/admin/vite.config.ts .gitignore tests/Component/AdminUi/SpaControllerTest.php tests/run.php
git commit -m "feat: serve production admin SPA"
```

---

### Task 3: Implement first-admin application semantics with pure tests

**Files:**
- Create: `modules/iam/contract/AdminIdGenerator.php`
- Create: `modules/iam/contract/BootstrapAdminRepository.php`
- Create: `modules/iam/domain/BootstrapAdminCreateResult.php`
- Create: `modules/iam/security/SecureAdminIdGenerator.php`
- Create: `modules/iam/application/InitialAdminAlreadyExists.php`
- Create: `modules/iam/application/BootstrapFirstAdmin.php`
- Create: `tests/Component/Iam/BootstrapFirstAdminTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces:
  - `AdminIdGenerator::generate(): string`
  - `BootstrapAdminRepository::createFirst(string $id, string $username, string $passwordHash): BootstrapAdminCreateResult`
  - `BootstrapAdminCreateResult::{CREATED, ALREADY_EXISTS}`
  - `BootstrapFirstAdmin::execute(string $username, string $password): AdminUser`

- [ ] **Step 1: Write application tests with fakes**

The test must include a fake repository and deterministic ID generator and verify all policy edges. Core shape:

```php
final class FakeBootstrapAdminRepository implements BootstrapAdminRepository
{
    public BootstrapAdminCreateResult $result = BootstrapAdminCreateResult::CREATED;
    public array $calls = [];

    public function createFirst(string $id, string $username, string $passwordHash): BootstrapAdminCreateResult
    {
        $this->calls[] = [$id, $username, $passwordHash];
        return $this->result;
    }
}

final class FixedAdminIdGenerator implements AdminIdGenerator
{
    public function generate(): string
    {
        return '0123456789abcdef0123456789abcdef';
    }
}
```

Required cases:

```text
"  管理员  " + 12-char password -> username stored as "管理员"
2-code-point username -> rejected
65-code-point username -> rejected
username containing \x1F or \x7F -> rejected
11-code-point password -> rejected
password >1024 UTF-8 bytes -> rejected
leading/trailing password whitespace -> preserved and hashed as supplied
successful hash verifies with password_verify and is not plaintext
repository ALREADY_EXISTS -> InitialAdminAlreadyExists
returned AdminUser is ACTIVE with null expiry
```

- [ ] **Step 2: Run tests to prove RED**

```bash
php tests/run.php
```

Expected: FAIL because bootstrap contracts/service do not exist.

- [ ] **Step 3: Implement the contracts and minimal application service**

Use these exact contracts:

```php
interface AdminIdGenerator
{
    public function generate(): string;
}

enum BootstrapAdminCreateResult
{
    case CREATED;
    case ALREADY_EXISTS;
}

interface BootstrapAdminRepository
{
    public function createFirst(
        string $id,
        string $username,
        string $passwordHash,
    ): BootstrapAdminCreateResult;
}
```

`SecureAdminIdGenerator`:

```php
public function generate(): string
{
    return bin2hex(random_bytes(16));
}
```

`BootstrapFirstAdmin::execute()` must:

```php
$username = preg_replace('/^\s+|\s+$/u', '', $username) ?? '';
if (preg_match('//u', $username) !== 1) {
    throw new InvalidArgumentException('Administrator username must be valid UTF-8.');
}
$length = mb_strlen($username, 'UTF-8');
if ($length < 3 || $length > 64 || preg_match('/[\x00-\x1F\x7F]/u', $username) === 1) {
    throw new InvalidArgumentException('Administrator username is invalid.');
}
if (preg_match('//u', $password) !== 1 || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 1024) {
    throw new InvalidArgumentException('Administrator password does not satisfy bootstrap policy.');
}

$id = $this->ids->generate();
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash) || $passwordHash === '') {
    throw new RuntimeException('Administrator password could not be hashed.');
}
$result = $this->repository->createFirst($id, $username, $passwordHash);
if ($result === BootstrapAdminCreateResult::ALREADY_EXISTS) {
    throw new InitialAdminAlreadyExists('Initial administrator already exists.');
}
return new AdminUser($id, $username, AdminUserStatus::ACTIVE, null);
```

- [ ] **Step 4: Run the offline suite**

```bash
php tests/run.php
```

Expected: bootstrap application tests PASS; architecture contract still RED only for missing persistence/command/E2E/CI items.

- [ ] **Step 5: Commit the application layer**

```bash
git add modules/iam tests/Component/Iam/BootstrapFirstAdminTest.php tests/run.php
git commit -m "feat: add first admin bootstrap service"
```

---

### Task 4: Add MySQL serialized bootstrap repository and console adapter

**Files:**
- Create: `modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php`
- Create: `app/worker/command/AdminBootstrapCommand.php`
- Modify: `app/AppService.php`
- Modify: `config/console.php`
- Modify: `tests/Release/run.php`
- Create: `tests/Component/Iam/ThinkPhpBootstrapAdminRepositoryTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: Task 3 contracts.
- Produces: concrete repository binding and `php think admin:bootstrap --username=<username>`.

- [ ] **Step 1: Add component/registration tests before implementation**

The test must assert:

```text
SecureAdminIdGenerator returns exactly 32 lowercase hex chars.
ThinkPhpBootstrapAdminRepository implements BootstrapAdminRepository.
config/console.php exposes AdminBootstrapCommand.
AppService binds BootstrapAdminRepository -> ThinkPhpBootstrapAdminRepository.
AppService binds AdminIdGenerator -> SecureAdminIdGenerator.
```

Also extend the release console gate to require:

```php
releaseGateAssert(
    str_contains($thinkList, 'admin:bootstrap'),
    'ThinkPHP console must expose admin:bootstrap.',
);
```

- [ ] **Step 2: Run tests and verify RED**

```bash
php tests/run.php
php think list
```

Expected: bootstrap repository/command assertions fail and `admin:bootstrap` is absent.

- [ ] **Step 3: Implement MySQL advisory-lock repository**

Use one connection handle for lock, transaction, count, insert and release:

```php
final class ThinkPhpBootstrapAdminRepository implements BootstrapAdminRepository
{
    private const LOCK_NAME = 'weplatform:admin-bootstrap';

    public function createFirst(string $id, string $username, string $passwordHash): BootstrapAdminCreateResult
    {
        $connection = Db::connect();
        $lockRows = $connection->query(
            'SELECT GET_LOCK(:name, 5) AS acquired',
            ['name' => self::LOCK_NAME],
        );
        $acquired = (int) ($lockRows[0]['acquired'] ?? 0);
        if ($acquired !== 1) {
            throw new RuntimeException('Administrator bootstrap lock could not be acquired.');
        }

        try {
            return $connection->transaction(function () use ($connection, $id, $username, $passwordHash): BootstrapAdminCreateResult {
                $count = (int) $connection->table('admin_users')->count();
                if ($count > 0) {
                    return BootstrapAdminCreateResult::ALREADY_EXISTS;
                }

                $connection->table('admin_users')->insert([
                    'id' => $id,
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'status' => 'active',
                    'expires_at' => null,
                    'session_version' => 0,
                ]);
                return BootstrapAdminCreateResult::CREATED;
            });
        } finally {
            $connection->query(
                'SELECT RELEASE_LOCK(:name) AS released',
                ['name' => self::LOCK_NAME],
            );
        }
    }
}
```

If the installed think-orm parameter binding syntax differs, preserve this semantic contract: a single connection instance must own `GET_LOCK`, transaction work and `RELEASE_LOCK`; never split lock and insert over different connections.

- [ ] **Step 4: Implement the console adapter without password arguments**

Register:

```php
AdminBootstrapCommand::class,
```

Command contract:

```php
$this->setName('admin:bootstrap')
    ->setDescription('Create the initial administrator on an empty installation')
    ->addOption('username', null, Option::VALUE_REQUIRED, 'Initial administrator username');
```

Password source precedence:

```text
1. If WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD is set, use it (CI/E2E only).
2. Otherwise request password interactively with hidden input supported by the console runtime.
3. Never support --password.
```

The adapter calls only:

```php
$admin = $this->app->make(BootstrapFirstAdmin::class)->execute($username, $password);
```

Success output contains only ID/username. `InitialAdminAlreadyExists`, validation failures and lock/database failures return a non-zero exit code and never print the password/hash.

- [ ] **Step 5: Bind implementations in `AppService`**

Add:

```php
BootstrapAdminRepository::class => ThinkPhpBootstrapAdminRepository::class,
AdminIdGenerator::class => SecureAdminIdGenerator::class,
```

- [ ] **Step 6: Run local non-DB verification**

```bash
php tests/run.php
php think list | grep 'admin:bootstrap'
php -l modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php
php -l app/worker/command/AdminBootstrapCommand.php
```

Expected: all non-MySQL checks PASS; global architecture contract may remain RED only for browser/CI work.

- [ ] **Step 7: Commit persistence and command slice**

```bash
git add modules/iam/infrastructure app/worker/command/AdminBootstrapCommand.php app/AppService.php config/console.php tests/Component/Iam/ThinkPhpBootstrapAdminRepositoryTest.php tests/Release/run.php tests/run.php
git commit -m "feat: add serialized admin bootstrap command"
```

---

### Task 5: Prove first-admin behavior against real MySQL, including concurrency

**Files:**
- Create: `tests/Acceptance/AdminBootstrapRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`

**Interfaces:**
- Consumes: real migrated `admin_users`, Task 4 command/repository, existing `AuthenticateAdmin`.
- Produces: release-grade evidence for AC4/AC5/AC6 and bootstrap/login compatibility.

- [ ] **Step 1: Write real-MySQL acceptance before changing implementation**

Add an acceptance test that resets only the acceptance database it owns, migrates it using the existing acceptance harness, and exercises:

```text
A. admin_users empty.
B. Run: WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD='acceptance-password-123' php think admin:bootstrap --username=accept-admin
C. Assert exit 0.
D. Assert exactly one admin_users row, status active, expires_at NULL.
E. Assert stored password_hash != plaintext and password_verify(...) is true.
F. Resolve the row through ThinkPhpAdminCredentialRepository and AuthenticateAdmin.
G. Run bootstrap a second time with another username; assert non-zero and row count still 1.
```

Concurrency section must launch two independent PHP processes against the same empty acceptance DB:

```php
$commands = [
    ['concurrent-admin-a', 'concurrent-password-123'],
    ['concurrent-admin-b', 'concurrent-password-456'],
];
```

Start both with `proc_open()` before waiting for either. Both use `WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD`. Final assertions:

```text
exactly one process exits 0
exactly one process exits non-zero
admin_users count === 1
winning row password verifies against the corresponding winner password
no plaintext password appears in stdout/stderr
```

- [ ] **Step 2: Run acceptance and verify failures expose persistence/CLI defects**

Run with the repository's standard acceptance environment:

```bash
WEPLATFORM_ACCEPTANCE=1 php tests/Acceptance/run.php
```

Expected before fixes: any advisory-lock/console integration defects fail here rather than being hidden by mocks.

- [ ] **Step 3: Make only minimal persistence/CLI corrections required by the real test**

Do not weaken the acceptance assertions. Preserve:

```text
single connection for GET_LOCK → transaction → RELEASE_LOCK
5-second timeout
exactly one row after concurrent attempts
no password argument/log output
```

- [ ] **Step 4: Run the real MySQL gate again**

```bash
WEPLATFORM_ACCEPTANCE=1 php tests/Acceptance/run.php
```

Expected: PASS including concurrent first-admin creation.

- [ ] **Step 5: Commit real-MySQL proof**

```bash
git add tests/Acceptance modules/iam/infrastructure app/worker/command/AdminBootstrapCommand.php
git commit -m "test: prove admin bootstrap on mysql"
```

---

### Task 6: Add production-mode Admin Chromium E2E

**Files:**
- Create: `frontend/admin/e2e/package.json`
- Create: `frontend/admin/e2e/package-lock.json`
- Create: `frontend/admin/e2e/playwright.config.js`
- Create: `frontend/admin/e2e/admin-login.e2e.js`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: production `public/admin/`, real ThinkPHP `/admin/` + `/admin-api/`, bootstrapped MySQL administrator.
- Produces: isolated `npm test --prefix frontend/admin/e2e` Chromium gate.

- [ ] **Step 1: Define isolated Playwright package**

`frontend/admin/e2e/package.json`:

```json
{
  "name": "@weplatform/admin-e2e",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "engines": { "node": ">=24 <25" },
  "scripts": { "test": "playwright test" },
  "devDependencies": { "@playwright/test": "1.63.0" }
}
```

Generate the lock with:

```bash
npm install --prefix frontend/admin/e2e --package-lock-only --ignore-scripts
```

- [ ] **Step 2: Write browser test first**

`admin-login.e2e.js` must use stable accessible selectors from the current UI:

```js
import { expect, test } from '@playwright/test'

test('production admin bootstrap login session restore and logout', async ({ page }) => {
  await page.goto('/admin/login')
  await expect(page.getByRole('heading', { name: 'WePlatform Admin' })).toBeVisible()

  await page.locator('input[name="username"]').fill('e2e-admin')
  await page.locator('input[name="password"]').fill('e2e-password-123')
  await page.getByRole('button', { name: '登录' }).click()

  await expect(page).toHaveURL(/\/admin\/?$/)
  await expect(page.getByText('后台运行正常')).toBeVisible()
  await expect(page.getByText('e2e-admin')).toBeVisible()

  await page.reload()
  await expect(page.getByText('后台运行正常')).toBeVisible()

  await page.getByRole('button', { name: '退出登录' }).click()
  await expect(page).toHaveURL(/\/admin\/login$/)

  await page.goto('/admin/')
  await expect(page).toHaveURL(/\/admin\/login\?redirect=/)

  await page.goto('/admin/login')
  await expect(page.getByRole('heading', { name: 'WePlatform Admin' })).toBeVisible()
})
```

- [ ] **Step 3: Configure production server/browser fixture**

`playwright.config.js` must:

```text
baseURL = http://127.0.0.1:18080
workers = 1
trace = retain-on-failure
screenshot = only-on-failure
webServer command = php ../../../think run -p 18080
webServer readiness URL = /admin/login
```

The test setup must run the real bootstrap command before browser navigation, using only:

```text
WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD=e2e-password-123
username=e2e-admin
```

against the real acceptance MySQL database. No mocked `/admin-api` responses are allowed.

- [ ] **Step 4: Ignore browser generated output**

Add:

```text
frontend/admin/e2e/node_modules/
frontend/admin/e2e/playwright-report/
frontend/admin/e2e/test-results/
```

- [ ] **Step 5: Build and execute locally against MySQL**

```bash
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
npm ci --prefix frontend/admin/e2e
cd frontend/admin/e2e
npx playwright install chromium
npm test
```

Expected: direct `/admin/login` production shell loads; real login succeeds; dashboard renders; reload restores session; logout invalidates session; direct history URLs remain usable.

- [ ] **Step 6: Commit the browser gate**

```bash
git add frontend/admin/e2e .gitignore
git commit -m "test: add admin production browser gate"
```

---

### Task 7: Wire permanent CI gates and complete exact-head verification

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Contract/AdminFoundationCompletionContractTest.php` only if needed to enforce final ordering without weakening behavior.
- Modify: `tests/Release/run.php` only if needed to ensure release evidence includes Admin bootstrap acceptance.
- Modify: PR #10 body after exact-head evidence is available.

**Interfaces:**
- Consumes: all previous tasks.
- Produces: exact-head CI evidence while leaving Human Gate pending.

- [ ] **Step 1: Extend Node cache and locked installs**

Add `frontend/admin/e2e/package-lock.json` to `cache-dependency-path`.

After Admin production build, add:

```yaml
- name: Install locked Admin browser E2E dependencies
  run: npm ci --prefix frontend/admin/e2e

- name: Install Chromium for Admin browser E2E
  working-directory: frontend/admin/e2e
  run: npx playwright install --with-deps chromium
```

- [ ] **Step 2: Run Admin browser E2E in the real-MySQL job**

Because the browser gate requires the migrated MySQL database, keep database-dependent setup in the MySQL release job. Extend that job with Node 24, locked Admin frontend/E2E installs, production build, Chromium install, then run:

```yaml
- name: Test Admin production browser E2E
  env:
    WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD: e2e-password-123
  run: npm test --prefix frontend/admin/e2e
```

The release job must still execute `php tests/Release/run.php`; do not replace the existing R8D release gate.

- [ ] **Step 3: Enforce CI ordering in the architecture contract**

Contract checks must prove:

```text
Admin npm ci/typecheck/test/build occurs before Admin production browser E2E.
Admin E2E uses its committed lockfile.
Chromium install exists.
Admin production browser E2E occurs before the release job can be considered successful.
/admin-api mapping remains unchanged.
```

- [ ] **Step 4: Run all local non-MySQL gates from a clean generated-output state**

```bash
rm -rf public/admin public/build/web frontend/admin/node_modules frontend/web/node_modules frontend/admin/e2e/node_modules frontend/web/e2e/node_modules
composer install --no-interaction --prefer-dist --no-progress
npm ci --prefix frontend/admin
npm run typecheck --prefix frontend/admin
npm test --prefix frontend/admin
npm run build --prefix frontend/admin
npm ci --prefix frontend/web
npm test --prefix frontend/web
npm run build --prefix frontend/web
npm ci --prefix frontend/admin/e2e
npm ci --prefix frontend/web/e2e
php tests/run.php
php vendor/bin/phpunit
find app modules config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all non-MySQL gates PASS and `public/admin/index.html` is regenerated rather than committed.

- [ ] **Step 5: Run full real-MySQL release gate**

With the standard acceptance DB environment:

```bash
WEPLATFORM_ACCEPTANCE=1 php tests/Release/run.php
```

Expected: existing R8D gates plus Admin bootstrap acceptance PASS.

- [ ] **Step 6: Push and require fresh exact-head GitHub Actions evidence**

```bash
git status --short
git log -1 --oneline
git push origin refactor/admin-foundation-completion-v1
```

Do not claim completion from an earlier SHA. Record the exact branch HEAD and the fresh workflow run where both the main test job and real-MySQL release job are `success`.

- [ ] **Step 7: Update PR #10 without marking Ready**

PR body must record:

```text
implementation tasks completed
exact HEAD SHA
TDD RED commit/run evidence
Admin bootstrap MySQL concurrency evidence
Admin Chromium production E2E evidence
full regression/release gate evidence
Human visual/browser acceptance: PENDING
```

PR #10 remains Draft. Do not merge or mark Ready before explicit Human Gate PASS.

- [ ] **Step 8: Human acceptance handoff**

Provide the exact-head local recipe:

```bash
git checkout refactor/admin-foundation-completion-v1
git pull --ff-only
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD='<local-secret>' php think admin:bootstrap --username='<local-admin>'
php think run -p 18080
```

Human checks:

```text
/admin/login loads without Vite dev server
login succeeds with bootstrapped administrator
Dashboard is visibly acceptable
browser reload remains authenticated
logout returns to login
/admin/login direct reload succeeds
/admin/ direct reload resolves correctly
no blocking console/runtime errors
```

Only explicit Human PASS closes the feature gate.
