# Admin Foundation Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a production-mode Admin SPA at `/admin/`, a safe one-time first-administrator bootstrap command, and real MySQL + Chromium proof of bootstrap → login → dashboard → session restore → logout.

**Architecture:** Keep `/admin-api/*` owned by the existing `app/admin` API. Add `app/adminui` only for SPA history fallback, build Vue Admin into generated `public/admin/`, create the first administrator through an IAM application service backed by a serialized MySQL repository, and prove the full path with MySQL 8.4 and Playwright Chromium.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, think-orm 4.x, MySQL 8.4, Vue 3.5, Vue Router 5.3, Element Plus 2.14, Vite 8.2, Vitest 5, Playwright 1.63, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-11-admin-foundation-completion-design.md`

## Global Constraints

- Work only on `refactor/admin-foundation-completion-v1`, stacked on `refactor/web-h5-template-foundation-v1` at `c00ff32dcc2c05ff4490295ba445bd4dba474ed7`.
- Do not modify PR #7, PR #8, or completed PR #9 content.
- `/admin/*` belongs to `app/adminui`; `/admin-api/*` remains `app/admin`.
- Admin SPA base remains `/admin/`; Admin API base remains same-origin `/admin-api/v1`.
- Admin production assets are generated under `public/admin/` and never committed.
- No default administrator credentials in source, migrations, fixtures, docs, or deployment files.
- Username: trim Unicode whitespace; 3–64 Unicode code points; reject ASCII controls and DEL; preserve other Unicode.
- Password: 12+ Unicode code points; at most 1024 UTF-8 bytes; never trim/normalize; no composition rule.
- Hash with `password_hash(..., PASSWORD_DEFAULT)`; never persist/log/return plaintext.
- First-admin persistence uses `GET_LOCK('weplatform:admin-bootstrap', 5)` and fails closed on timeout/failure.
- Two simultaneous bootstrap processes against an empty database must leave exactly one administrator.
- Automated gates never replace the independent Human Gate.

## Dependency APIs verified before implementation

ThinkPHP 8.1.3 `think\console\Output` exposes:

```php
public function askHidden(Input $input, $question, $validator = null)
```

think-orm 4.x connection exposes:

```php
public function query(string $sql, array $bind = [], bool $master = false): array;
public function execute(string $sql, array $bind = []): int;
public function transaction(callable $callback);
```

`think\db\Connection::__call()` forwards query-builder methods such as `table()` to a new query bound to that same connection. Therefore the serialized repository must keep one `$connection = Db::connect()` instance for lock acquisition, transaction work, and lock release.

---

## File Map

**Admin UI delivery**
- Create `app/adminui/controller/SpaController.php`
- Create `app/adminui/route/app.php`
- Create `tests/Component/AdminUi/SpaControllerTest.php`
- Modify `config/app.php`, `frontend/admin/vite.config.ts`, `.gitignore`, `tests/run.php`

**First-admin bootstrap**
- Create `modules/iam/contract/AdminIdGenerator.php`
- Create `modules/iam/contract/BootstrapAdminRepository.php`
- Create `modules/iam/domain/BootstrapAdminCreateResult.php`
- Create `modules/iam/security/SecureAdminIdGenerator.php`
- Create `modules/iam/application/InitialAdminAlreadyExists.php`
- Create `modules/iam/application/BootstrapFirstAdmin.php`
- Create `modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php`
- Create `app/worker/command/AdminBootstrapCommand.php`
- Create `tests/Component/Iam/BootstrapFirstAdminTest.php`
- Create `tests/Component/Iam/ThinkPhpBootstrapAdminRepositoryTest.php`
- Create `tests/Acceptance/AdminBootstrapRuntimeTest.php`
- Modify `app/AppService.php`, `config/console.php`, `tests/Acceptance/run.php`, `tests/Release/run.php`, `tests/run.php`

**Browser gate**
- Create `frontend/admin/e2e/package.json`
- Create `frontend/admin/e2e/package-lock.json`
- Create `frontend/admin/e2e/playwright.config.js`
- Create `frontend/admin/e2e/admin-login.e2e.js`
- Modify `.github/workflows/ci.yml`, `.gitignore`

**Permanent contract**
- Create `tests/Contract/AdminFoundationCompletionContractTest.php`
- Modify `tests/run.php`

---

### Task 1: Add the permanent architecture contract and establish RED

**Files:**
- Create: `tests/Contract/AdminFoundationCompletionContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces a fail-closed contract that later tasks must satisfy.

- [ ] **Step 1: Write the failing contract**

Use this core:

```php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$appConfig = (string) file_get_contents($root . '/config/app.php');
$consoleConfig = (string) file_get_contents($root . '/config/console.php');
$viteConfig = (string) file_get_contents($root . '/frontend/admin/vite.config.ts');
$gitignore = (string) file_get_contents($root . '/.gitignore');
$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');

expectTrue(is_file($root . '/app/adminui/controller/SpaController.php'), 'adminui SPA controller required');
expectTrue(is_file($root . '/app/adminui/route/app.php'), 'adminui routes required');
expectTrue(is_file($root . '/app/worker/command/AdminBootstrapCommand.php'), 'bootstrap command required');
expectTrue(is_file($root . '/modules/iam/application/BootstrapFirstAdmin.php'), 'bootstrap service required');
expectTrue(is_file($root . '/modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php'), 'bootstrap repository required');
expectTrue(is_file($root . '/frontend/admin/e2e/playwright.config.js'), 'Admin Playwright config required');
expectTrue(is_file($root . '/frontend/admin/e2e/admin-login.e2e.js'), 'Admin Playwright spec required');

expectTrue(str_contains($appConfig, "'admin'"), 'admin mapping required');
expectTrue(str_contains($appConfig, "'admin-api'"), 'admin-api mapping must remain');
expectTrue(str_contains($consoleConfig, 'AdminBootstrapCommand::class'), 'bootstrap command must be registered');
expectTrue(str_contains($viteConfig, "base: '/admin/'"), 'Admin base must remain /admin/');
expectTrue(str_contains($viteConfig, "outDir: '../../public/admin'"), 'Admin build must target public/admin');
expectTrue(str_contains($gitignore, 'public/admin/'), 'generated Admin build must be ignored');
expectTrue(str_contains($ci, 'Test Admin production browser E2E'), 'CI Admin browser gate required');
expectTrue(str_contains($ci, 'frontend/admin/e2e/package-lock.json'), 'CI Admin E2E lock required');
```

Insert this file in `tests/run.php` immediately after `AdminFrontendArchitectureContractTest.php`.

- [ ] **Step 2: Prove RED**

```bash
php tests/run.php
```

Expected: existing earlier tests remain green; `AdminFoundationCompletionContractTest.php` fails because required production/bootstrap/E2E files do not exist.

- [ ] **Step 3: Commit RED only**

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
- Produces `SpaController::index(): think\Response` and `/admin/* -> adminui`.

- [ ] **Step 1: Write component tests first**

The test creates one temporary existing `index.html` and one missing path, then asserts:

```php
$ok = new app\adminui\controller\SpaController($existingIndexPath);
$okResponse = $ok->index();
expectSame(200, $okResponse->getCode(), 'Admin SPA 200');
expectTrue(str_contains((string) $okResponse->getContent(), '<div id="app"></div>'), 'Admin SPA shell');
expectTrue(str_contains((string) $okResponse->getHeader('Content-Type'), 'text/html'), 'Admin SPA content type');

$missing = new app\adminui\controller\SpaController($missingIndexPath);
$missingResponse = $missing->index();
expectSame(503, $missingResponse->getCode(), 'Missing Admin build 503');
expectTrue(!str_contains((string) $missingResponse->getContent(), 'Stack trace'), '503 is production-safe');
```

Register the test in `tests/run.php`.

- [ ] **Step 2: Prove RED**

```bash
php tests/run.php
```

Expected: component test fails because `SpaController` does not exist.

- [ ] **Step 3: Implement shell delivery**

```php
<?php

declare(strict_types=1);

namespace app\adminui\controller;

use think\Response;

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

Routes:

```php
<?php

declare(strict_types=1);

use think\facade\Route;

Route::get('', 'SpaController/index');
Route::get('<path>', 'SpaController/index')->pattern(['path' => '.*']);
```

`config/app.php`:

```php
'app_map' => [
    'admin' => static fn ($app): string => 'adminui',
    'admin-api' => static fn ($app): string => 'admin',
],
```

`frontend/admin/vite.config.ts`:

```ts
build: {
  outDir: '../../public/admin',
  emptyOutDir: true,
},
```

`.gitignore` must ignore `public/admin/` instead of the old Admin build directory.

- [ ] **Step 4: Verify GREEN for this slice**

```bash
npm ci --prefix frontend/admin
npm run typecheck --prefix frontend/admin
npm test --prefix frontend/admin
npm run build --prefix frontend/admin
test -f public/admin/index.html
find public/admin/assets -type f | head
php tests/run.php
```

Expected: SPA component test passes; global Task 1 contract still fails only on bootstrap/E2E requirements.

- [ ] **Step 5: Commit**

```bash
git add app/adminui config/app.php frontend/admin/vite.config.ts .gitignore tests/Component/AdminUi/SpaControllerTest.php tests/run.php
git commit -m "feat: serve production admin SPA"
```

---

### Task 3: Implement first-admin application semantics

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
    public function createFirst(string $id, string $username, string $passwordHash): BootstrapAdminCreateResult;
}

final readonly class BootstrapFirstAdmin
{
    public function execute(string $username, string $password): AdminUser;
}
```

- [ ] **Step 1: Write application tests with fakes**

Use deterministic ID `0123456789abcdef0123456789abcdef`. Required cases:

```text
"  管理员  " -> stored as "管理员"
2-code-point username -> InvalidArgumentException
65-code-point username -> InvalidArgumentException
username containing \x1F or \x7F -> InvalidArgumentException
11-code-point password -> InvalidArgumentException
password >1024 UTF-8 bytes -> InvalidArgumentException
leading/trailing password whitespace -> preserved exactly
successful hash != plaintext and password_verify() is true
ALREADY_EXISTS -> InitialAdminAlreadyExists
returned AdminUser -> ACTIVE, null expiry
```

- [ ] **Step 2: Prove RED**

```bash
php tests/run.php
```

Expected: bootstrap classes absent.

- [ ] **Step 3: Implement minimal application code**

`SecureAdminIdGenerator`:

```php
public function generate(): string
{
    return bin2hex(random_bytes(16));
}
```

`BootstrapFirstAdmin::execute()` validation and flow:

```php
$username = preg_replace('/^\s+|\s+$/u', '', $username) ?? '';
if (preg_match('//u', $username) !== 1) {
    throw new InvalidArgumentException('Administrator username must be valid UTF-8.');
}
$usernameLength = mb_strlen($username, 'UTF-8');
if ($usernameLength < 3 || $usernameLength > 64 || preg_match('/[\x00-\x1F\x7F]/u', $username) === 1) {
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

- [ ] **Step 4: Verify GREEN**

```bash
php tests/run.php
```

Expected: bootstrap application tests pass; Task 1 contract remains red only for persistence/command/E2E/CI.

- [ ] **Step 5: Commit**

```bash
git add modules/iam tests/Component/Iam/BootstrapFirstAdminTest.php tests/run.php
git commit -m "feat: add first admin bootstrap service"
```

---

### Task 4: Add serialized MySQL persistence and console command

**Files:**
- Create: `modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php`
- Create: `app/worker/command/AdminBootstrapCommand.php`
- Create: `tests/Component/Iam/ThinkPhpBootstrapAdminRepositoryTest.php`
- Modify: `app/AppService.php`
- Modify: `config/console.php`
- Modify: `tests/Release/run.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes Task 3 contracts.
- Produces `php think admin:bootstrap --username=<username>`.

- [ ] **Step 1: Write registration/security tests first**

Assert:

```text
SecureAdminIdGenerator output matches ^[0-9a-f]{32}$
ThinkPhpBootstrapAdminRepository implements BootstrapAdminRepository
config/console.php contains AdminBootstrapCommand::class
AppService binds BootstrapAdminRepository -> ThinkPhpBootstrapAdminRepository
AppService binds AdminIdGenerator -> SecureAdminIdGenerator
AdminBootstrapCommand defines --username and does not define --password
```

Extend `tests/Release/run.php`:

```php
releaseGateAssert(
    str_contains($thinkList, 'admin:bootstrap'),
    'ThinkPHP console must expose admin:bootstrap.',
);
```

- [ ] **Step 2: Prove RED**

```bash
php tests/run.php
php think list
```

Expected: repository/command missing; `admin:bootstrap` absent.

- [ ] **Step 3: Implement MySQL advisory-lock repository using one connection**

```php
<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use modules\iam\contract\BootstrapAdminRepository;
use modules\iam\domain\BootstrapAdminCreateResult;
use RuntimeException;
use think\facade\Db;

final class ThinkPhpBootstrapAdminRepository implements BootstrapAdminRepository
{
    public function createFirst(string $id, string $username, string $passwordHash): BootstrapAdminCreateResult
    {
        $connection = Db::connect();
        $lockRows = $connection->query("SELECT GET_LOCK('weplatform:admin-bootstrap', 5) AS acquired", [], true);
        if ((int) ($lockRows[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Administrator bootstrap lock could not be acquired.');
        }

        try {
            return $connection->transaction(
                static function ($tx) use ($id, $username, $passwordHash): BootstrapAdminCreateResult {
                    if ((int) $tx->table('admin_users')->count() > 0) {
                        return BootstrapAdminCreateResult::ALREADY_EXISTS;
                    }

                    $tx->table('admin_users')->insert([
                        'id' => $id,
                        'username' => $username,
                        'password_hash' => $passwordHash,
                        'status' => 'active',
                        'expires_at' => null,
                        'session_version' => 0,
                    ]);

                    return BootstrapAdminCreateResult::CREATED;
                },
            );
        } finally {
            $connection->query("SELECT RELEASE_LOCK('weplatform:admin-bootstrap') AS released", [], true);
        }
    }
}
```

Do not replace this with `Db::transaction()` plus separate `Db::query()` calls; the advisory lock is connection-scoped.

- [ ] **Step 4: Implement exact console password flow**

Command configuration:

```php
protected function configure(): void
{
    $this->setName('admin:bootstrap')
        ->setDescription('Create the initial administrator on an empty installation')
        ->addOption('username', null, Option::VALUE_REQUIRED, 'Initial administrator username');
}
```

Password acquisition:

```php
$password = getenv('WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD');
if ($password === false) {
    if (!$input->isInteractive()) {
        $output->error('WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD is required in non-interactive mode.');
        return 2;
    }
    $password = (string) $output->askHidden($input, 'Initial administrator password: ');
}
```

Then only call the application service:

```php
$admin = $this->app->make(BootstrapFirstAdmin::class)->execute(
    (string) $input->getOption('username'),
    $password,
);
$output->writeln(sprintf('initial administrator created id=%s username=%s', $admin->id(), $admin->username()));
return 0;
```

Catch `InitialAdminAlreadyExists`, `InvalidArgumentException`, and runtime/database exceptions; write only safe exception messages to stderr/output error and return `1`. Never add a `--password` option and never print password/hash.

- [ ] **Step 5: Register bindings**

In `AppService` bindings:

```php
BootstrapAdminRepository::class => ThinkPhpBootstrapAdminRepository::class,
AdminIdGenerator::class => SecureAdminIdGenerator::class,
```

In `config/console.php`:

```php
use app\worker\command\AdminBootstrapCommand;

'commands' => [
    AdminBootstrapCommand::class,
    OpenPlatformProvisioningWorkerCommand::class,
],
```

- [ ] **Step 6: Verify GREEN for command registration**

```bash
php tests/run.php
php think list | grep 'admin:bootstrap'
php -l modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php
php -l app/worker/command/AdminBootstrapCommand.php
```

Expected: non-MySQL tests pass; Task 1 contract remains red only for E2E/CI.

- [ ] **Step 7: Commit**

```bash
git add modules/iam/infrastructure app/worker/command/AdminBootstrapCommand.php app/AppService.php config/console.php tests/Component/Iam/ThinkPhpBootstrapAdminRepositoryTest.php tests/Release/run.php tests/run.php
git commit -m "feat: add serialized admin bootstrap command"
```

---

### Task 5: Prove bootstrap semantics on real MySQL, including concurrency

**Files:**
- Create: `tests/Acceptance/AdminBootstrapRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`

**Interfaces:**
- Consumes Task 4 command/repository and existing `AuthenticateAdmin`.
- Produces AC4/AC5/AC6 release evidence.

- [ ] **Step 1: Write real-MySQL acceptance first**

Single-process section:

```text
1. Start with migrated acceptance DB and empty admin_users.
2. Run env WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD=acceptance-password-123 php think admin:bootstrap --username=accept-admin.
3. Assert exit code 0.
4. Assert exactly one row; status=active; expires_at=NULL.
5. Assert password_hash != plaintext and password_verify() true.
6. Load through ThinkPhpAdminCredentialRepository and authenticate through AuthenticateAdmin.
7. Run second bootstrap as another username; assert non-zero and row count still 1.
8. Assert no plaintext password appears in stdout/stderr.
```

Concurrency section starts both `proc_open()` processes before waiting:

```php
$attempts = [
    ['concurrent-admin-a', 'concurrent-password-123'],
    ['concurrent-admin-b', 'concurrent-password-456'],
];
```

Assertions:

```text
exactly one exit code == 0
exactly one exit code != 0
admin_users count == 1
winner password verifies against stored hash
neither plaintext password appears in either stdout/stderr
```

- [ ] **Step 2: Run real MySQL acceptance and observe RED defects**

```bash
WEPLATFORM_ACCEPTANCE=1 php tests/Acceptance/run.php
```

Expected: fail only on concrete persistence/CLI defects discovered by the real database.

- [ ] **Step 3: Fix only observed persistence/CLI defects; do not weaken assertions**

Preserve the exact lock name, 5-second timeout, one-connection lock/transaction/release sequence, and one-row concurrency invariant.

- [ ] **Step 4: Verify GREEN**

```bash
WEPLATFORM_ACCEPTANCE=1 php tests/Acceptance/run.php
```

- [ ] **Step 5: Commit**

```bash
git add tests/Acceptance modules/iam/infrastructure app/worker/command/AdminBootstrapCommand.php
git commit -m "test: prove admin bootstrap on mysql"
```

---

### Task 6: Add real production-mode Admin Chromium E2E

**Files:**
- Create: `frontend/admin/e2e/package.json`
- Create: `frontend/admin/e2e/package-lock.json`
- Create: `frontend/admin/e2e/playwright.config.js`
- Create: `frontend/admin/e2e/admin-login.e2e.js`
- Modify: `.gitignore`

**Interfaces:**
- Consumes production `public/admin/`, real `/admin-api/v1`, acceptance MySQL.
- Produces `npm test --prefix frontend/admin/e2e`.

- [ ] **Step 1: Create isolated Playwright package**

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

Generate lock only:

```bash
npm install --prefix frontend/admin/e2e --package-lock-only --ignore-scripts
```

- [ ] **Step 2: Write E2E first**

`admin-login.e2e.js`:

```js
import { expect, test } from '@playwright/test'

test('production admin login restores session and logout invalidates it', async ({ page }) => {
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

- [ ] **Step 3: Configure Playwright**

`playwright.config.js` must set:

```js
import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: '.',
  testMatch: 'admin-login.e2e.js',
  workers: 1,
  reporter: process.env.CI ? 'line' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:18080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php ../../../think run -p 18080',
    url: 'http://127.0.0.1:18080/admin/login',
    reuseExistingServer: !process.env.CI,
    timeout: 120000,
    stdout: 'pipe',
    stderr: 'pipe',
    env: { ...process.env },
  },
})
```

The CI/release job, not the browser spec, performs this bootstrap immediately before the browser test:

```bash
WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD=e2e-password-123 php think admin:bootstrap --username=e2e-admin
```

No mocked `/admin-api` requests are permitted.

- [ ] **Step 4: Ignore generated E2E output**

```text
frontend/admin/e2e/node_modules/
frontend/admin/e2e/playwright-report/
frontend/admin/e2e/test-results/
```

- [ ] **Step 5: Verify locally against real MySQL**

```bash
npm ci --prefix frontend/admin
npm run build --prefix frontend/admin
npm ci --prefix frontend/admin/e2e
cd frontend/admin/e2e
npx playwright install chromium
cd ../../..
WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD=e2e-password-123 php think admin:bootstrap --username=e2e-admin
npm test --prefix frontend/admin/e2e
```

Expected: no Vite dev server; direct `/admin/login`; real login/dashboard/reload/logout; direct history reload works.

- [ ] **Step 6: Commit**

```bash
git add frontend/admin/e2e .gitignore
git commit -m "test: add admin production browser gate"
```

---

### Task 7: Wire permanent CI and exact-head release evidence

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Contract/AdminFoundationCompletionContractTest.php` only to enforce final ordering/paths.
- Modify: PR #10 body after exact-head evidence exists.

**Interfaces:**
- Consumes all previous tasks.
- Produces exact-head CI evidence while Human Gate remains pending.

- [ ] **Step 1: Extend Node cache/install steps**

Add to `cache-dependency-path`:

```yaml
frontend/admin/e2e/package-lock.json
```

Add locked E2E install and Chromium install to the real-MySQL release job after Node 24 setup and Admin production build:

```yaml
- name: Install locked Admin browser E2E dependencies
  run: npm ci --prefix frontend/admin/e2e

- name: Install Chromium for Admin browser E2E
  working-directory: frontend/admin/e2e
  run: npx playwright install --with-deps chromium
```

- [ ] **Step 2: Keep all DB-dependent Admin browser work in the MySQL release job**

Before browser execution, reset/re-migrate the release acceptance DB using the existing acceptance harness, then:

```yaml
- name: Bootstrap Admin browser administrator
  env:
    WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD: e2e-password-123
  run: php think admin:bootstrap --username=e2e-admin

- name: Test Admin production browser E2E
  run: npm test --prefix frontend/admin/e2e
```

The job must still execute:

```bash
php tests/Release/run.php
```

Do not replace the existing R8D release gate.

- [ ] **Step 3: Make Task 1 contract GREEN and enforce ordering**

Require all of these strings/order relations:

```text
frontend/admin/e2e/package-lock.json
npm ci --prefix frontend/admin/e2e
npx playwright install --with-deps chromium
Test Admin production browser E2E
Admin build before Admin browser E2E
/admin-api remains mapped to app/admin
/admin remains mapped to app/adminui
```

- [ ] **Step 4: Run clean local non-MySQL regression**

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

Expected: all non-MySQL gates pass and `public/admin/index.html` is regenerated, not tracked.

- [ ] **Step 5: Run full real-MySQL release gate**

```bash
WEPLATFORM_ACCEPTANCE=1 php tests/Release/run.php
```

Expected: existing R8D release gate plus Admin bootstrap acceptance pass.

- [ ] **Step 6: Push and require fresh exact-head GitHub Actions evidence**

```bash
git status --short
git log -1 --oneline
git push origin refactor/admin-foundation-completion-v1
```

Record only the workflow run whose SHA equals the final branch HEAD and where both the main test job and real-MySQL release job are `success`.

- [ ] **Step 7: Update PR #10 but keep Draft**

Record:

```text
implementation tasks completed
exact HEAD SHA
TDD RED evidence
MySQL concurrent bootstrap evidence
Admin Chromium production E2E evidence
full regression/release gate evidence
Human visual/browser acceptance: PENDING
```

Do not mark Ready or merge.

- [ ] **Step 8: Human acceptance handoff**

Local exact-head recipe:

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
Dashboard is visually acceptable
reload remains authenticated
logout returns to login
/admin/login direct reload succeeds
/admin/ direct reload resolves correctly
no blocking console/runtime errors
```

Only explicit Human PASS closes the feature gate.
