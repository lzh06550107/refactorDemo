# App / Modules Architecture Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the approved V4 physical boundary so `app/*` contains delivery/composition/shared-kernel code and `modules/*` contains business capabilities, with zero R1-R8D business-semantic change.

**Architecture:** Keep `app/admin`, `app/api`, `app/web`, `app/worker`, and `app/common` as ThinkPHP delivery/shared-kernel roots. Move every current business bounded context to a top-level `modules/` PSR-4 root, rename namespaces mechanically, move the provisioning CLI adapter to `app/worker/command`, and enforce the boundary with an offline architecture contract that executes on `require`.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, think-multi-app 1.1.1, Composer PSR-4, PHPUnit 11.5, MySQL 8.4 release acceptance, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-11-app-modules-architecture-alignment-design.md`

## Global Constraints

- Preserve all API URLs, methods, JSON fields, response envelopes, and stable error codes.
- Preserve database migrations `001` through `009` byte-for-byte; this architecture change requires no schema change.
- Preserve the external CLI command name `openplatform:provisioning-worker` and its options/exit semantics.
- Preserve R8D provisioning states, ownership uniqueness, reconnect behavior, quota consume/release/idempotency semantics, and provider cryptography.
- `app/*` first-level runtime directories are limited to `admin`, `api`, `web`, `worker`, and `common`.
- Business capabilities live under `modules/*` and use the `modules\` namespace.
- Business-module code MUST NOT depend on `app\admin`, `app\api`, `app\web`, or `app\worker`.
- Domain code MUST NOT depend on ThinkPHP framework APIs or `app\common\infrastructure`.
- No permanent `class_alias()` bridge for old business namespaces.
- `app/AppService.php` remains the composition root for this slice; do not split it into per-module providers.
- PR #7 stays Draft until the new exact HEAD passes automated gates and real WeChat Provider E2E.

---

## File/Responsibility Map

| Responsibility | Current | Target |
| --- | --- | --- |
| Admin HTTP adapter | `app/admin/**` | unchanged |
| Public API adapter | `app/api/**` | unchanged |
| Web adapter | `app/web/**` | unchanged |
| Shared kernel | `app/common/**` | unchanged |
| CLI provisioning adapter | `app/command/OpenPlatformProvisioningWorkerCommand.php` | `app/worker/command/OpenPlatformProvisioningWorkerCommand.php` |
| Account | `app/account/**` | `modules/account/**` |
| Entitlement | `app/entitlement/**` | `modules/entitlement/**` |
| IAM | `app/iam/**` | `modules/iam/**` |
| Member | `app/member/**` | `modules/member/**` |
| MiniApp | `app/miniapp/**` | `modules/miniapp/**` |
| Module runtime | `app/module/**` | `modules/module/**` |
| OAuth | `app/oauth/**` | `modules/oauth/**` |
| OpenPlatform | `app/openplatform/**` | `modules/openplatform/**` |
| Quota | `app/quota/**` | `modules/quota/**` |
| Site | `app/site/**` | `modules/site/**` |
| Tenant | `app/tenant/**` | `modules/tenant/**` |
| Theme | `app/theme/**` | `modules/theme/**` |
| Webhook | `app/webhook/**` | `modules/webhook/**` |
| Legacy runtime integration | `app/legacy/**` | `modules/integration/legacy/**` |
| Composition root | `app/AppService.php` | unchanged path; imports updated |
| CLI registration | `config/console.php` | unchanged path; command class updated |
| Autoload roots | `composer.json` | add `modules\` -> `modules/` |
| Architecture gate | absent | `tests/Contract/AppModulesArchitectureContractTest.php` |
| Offline suite | `tests/run.php` | register new contract after `StructureContractTest.php` |

---

### Task 1: Add the RED Architecture Contract

**Files:**
- Create: `tests/Contract/AppModulesArchitectureContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `expectTrue(bool $condition, string $message): void` from `tests/Support/bootstrap.php`.
- Produces: a require-time contract; it MUST NOT hide assertions inside an uncalled function.

- [ ] **Step 1: Create the failing contract**

Create `tests/Contract/AppModulesArchitectureContractTest.php`:

```php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$businessModules = [
    'account', 'entitlement', 'iam', 'member', 'miniapp', 'module', 'oauth',
    'openplatform', 'quota', 'site', 'tenant', 'theme', 'webhook',
];
$allowedAppDirectories = ['admin', 'api', 'web', 'worker', 'common'];

foreach (new DirectoryIterator($root . '/app') as $entry) {
    if ($entry->isDot() || !$entry->isDir()) {
        continue;
    }
    expectTrue(
        in_array($entry->getFilename(), $allowedAppDirectories, true),
        'unexpected first-level app directory: ' . $entry->getFilename(),
    );
}

foreach ($businessModules as $module) {
    expectTrue(!is_dir($root . '/app/' . $module), 'business module must not live under app/: ' . $module);
    expectTrue(is_dir($root . '/modules/' . $module), 'business module must live under modules/: ' . $module);
}
expectTrue(!is_dir($root . '/app/legacy'), 'legacy runtime must not live under app/');
expectTrue(is_dir($root . '/modules/integration/legacy'), 'legacy runtime must live under modules/integration/legacy');
expectTrue(!is_dir($root . '/app/command'), 'CLI adapter must not live in app/command');
expectTrue(
    is_file($root . '/app/worker/command/OpenPlatformProvisioningWorkerCommand.php'),
    'provisioning command must live under app/worker/command',
);

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$psr4 = $composer['autoload']['psr-4'] ?? [];
expectTrue(($psr4['app\\'] ?? null) === 'app/', 'Composer must map app\\ to app/');
expectTrue(($psr4['modules\\'] ?? null) === 'modules/', 'Composer must map modules\\ to modules/');

$forbiddenDeliveryPrefixes = [
    'app\\admin\\', 'app\\api\\', 'app\\web\\', 'app\\worker\\',
];
$moduleRoot = $root . '/modules';
if (is_dir($moduleRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        foreach ($forbiddenDeliveryPrefixes as $prefix) {
            expectTrue(!str_contains($source, $prefix), 'business module depends on delivery app: ' . $relative . ' -> ' . $prefix);
        }

        if (preg_match('/^modules\/(.+)\/[^\/]+\.php$/', $relative, $matches) === 1) {
            $expectedNamespace = 'modules\\' . str_replace('/', '\\', $matches[1]);
            expectTrue(
                preg_match('/\bnamespace\s+' . preg_quote($expectedNamespace, '/') . '\s*;/', $source) === 1,
                'module namespace does not match physical path: ' . $relative . ' expected=' . $expectedNamespace,
            );
        }

        if (str_contains('/' . $relative, '/domain/')) {
            expectTrue(!str_contains($source, 'think\\'), 'domain must not depend on ThinkPHP: ' . $relative);
            expectTrue(
                !str_contains($source, 'app\\common\\infrastructure\\'),
                'domain must not depend on app\\common\\infrastructure: ' . $relative,
            );
        }
    }
}

$staleModules = array_merge($businessModules, ['legacy', 'command']);
$activeRoots = [$root . '/app', $root . '/modules', $root . '/config', $root . '/tests'];
foreach ($activeRoots as $activeRoot) {
    if (!is_dir($activeRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($activeRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        if ($file->getFilename() === 'AppModulesArchitectureContractTest.php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach ($staleModules as $module) {
            $prefix = 'app\\' . $module . '\\';
            expectTrue(!str_contains($source, $prefix), 'stale business namespace remains: ' . $file->getPathname() . ' -> ' . $prefix);
        }
    }
}

$readme = (string) file_get_contents($root . '/README.md');
foreach (['app/admin', 'app/api', 'app/web', 'app/worker', 'app/common', 'modules/*'] as $needle) {
    expectTrue(str_contains($readme, $needle), 'README must document current architecture token: ' . $needle);
}
```

- [ ] **Step 2: Register it in the offline suite**

Immediately after `StructureContractTest.php` in `tests/run.php`, add:

```php
__DIR__ . '/Contract/AppModulesArchitectureContractTest.php',
```

- [ ] **Step 3: Prove RED directly**

Run:

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: assertion failure caused by the current tree, for example `unexpected first-level app directory: account`.

- [ ] **Step 4: Prove the offline suite also sees the RED contract**

Run `php tests/run.php`.

Expected: `AppModulesArchitectureContractTest.php` reports FAIL and subsequent tests continue. This proves the contract executes on `require`.

- [ ] **Step 5: Commit RED evidence**

```bash
git add tests/Contract/AppModulesArchitectureContractTest.php tests/run.php
git commit -m "test: define app-modules architecture boundary"
```

---

### Task 2: Add the Modules PSR-4 Root

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`

**Interfaces:**
- Consumes: existing `app\` -> `app/` mapping.
- Produces: `modules\` -> `modules/` autoload mapping.

- [ ] **Step 1: Add the exact Composer mapping**

Use:

```json
"autoload": {
  "psr-4": {
    "app\\": "app/",
    "modules\\": "modules/"
  }
}
```

- [ ] **Step 2: Refresh only lock metadata required by the root package change**

Run:

```bash
composer validate --strict
composer update --lock --no-install
composer install --no-interaction
```

Expected: no dependency package version changes caused by this PSR-4 edit; lock content-hash is consistent.

- [ ] **Step 3: Prove the contract is still RED for physical layout, not Composer**

Run the direct architecture-contract command from Task 1.

Expected: no Composer mapping failure; still fails on an unmigrated `app/*` business directory.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add modules psr-4 root"
```

---

### Task 3: Migrate Core Modules

**Files:**
- Move: `app/tenant/**` -> `modules/tenant/**`
- Move: `app/account/**` -> `modules/account/**`
- Move: `app/iam/**` -> `modules/iam/**`
- Move: `app/entitlement/**` -> `modules/entitlement/**`
- Move: `app/quota/**` -> `modules/quota/**`
- Modify references in: `app/**/*.php`, `modules/**/*.php`, `tests/**/*.php`, `config/**/*.php`
- Modify: `app/AppService.php`

**Interfaces:**
- Produces unchanged classes under new prefixes `modules\tenant`, `modules\account`, `modules\iam`, `modules\entitlement`, `modules\quota`.

- [ ] **Step 1: Move directories**

```bash
mkdir -p modules
git mv app/tenant modules/tenant
git mv app/account modules/account
git mv app/iam modules/iam
git mv app/entitlement modules/entitlement
git mv app/quota modules/quota
```

- [ ] **Step 2: Rewrite exact namespace prefixes**

Run this one-off script outside the repository or delete it immediately after use:

```python
from pathlib import Path

mapping = {
    'app\\tenant\\': 'modules\\tenant\\',
    'app\\account\\': 'modules\\account\\',
    'app\\iam\\': 'modules\\iam\\',
    'app\\entitlement\\': 'modules\\entitlement\\',
    'app\\quota\\': 'modules\\quota\\',
}
for root_name in ('app', 'modules', 'tests', 'config'):
    root = Path(root_name)
    if not root.exists():
        continue
    for path in root.rglob('*.php'):
        original = path.read_text(encoding='utf-8')
        changed = original
        for old, new in mapping.items():
            changed = changed.replace(old, new)
        if changed != original:
            path.write_text(changed, encoding='utf-8')
```

- [ ] **Step 3: Regenerate autoload and lint moved files**

```bash
composer dump-autoload
find modules/tenant modules/account modules/iam modules/entitlement modules/quota -name '*.php' -print0 | xargs -0 -n1 php -l
php -l app/AppService.php
```

Expected: all pass.

- [ ] **Step 4: Run focused regression tests**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Tenant/TenantTest.php'; require 'tests/Unit/Account/AccountTest.php'; require 'tests/Unit/Account/LegacyAccountMappingTest.php'; require 'tests/Unit/Iam/AdminUserTest.php'; require 'tests/Component/Iam/RestoreAdminSessionTest.php'; require 'tests/Unit/Entitlement/TenantModuleEntitlementTest.php'; require 'tests/Component/Entitlement/TenantModuleEntitlementServiceTest.php'; require 'tests/Unit/Quota/QuotaLedgerTest.php'; require 'tests/Component/Quota/QuotaServiceIdempotencyTest.php'; require 'tests/GoldenMaster/R20AccountQuotaSnapshotTest.php';"
```

Expected: exit `0`.

- [ ] **Step 5: Confirm architecture RED advances to the next unmigrated module**

Run the direct Architecture Contract.

Expected: it no longer reports the five moved modules; it remains RED on another business directory.

- [ ] **Step 6: Commit**

```bash
git add app modules tests config
git commit -m "refactor: move core business modules out of app"
```

---

### Task 4: Migrate Module Runtime, Site, and Theme

**Files:**
- Move: `app/module/**` -> `modules/module/**`
- Move: `app/site/**` -> `modules/site/**`
- Move: `app/theme/**` -> `modules/theme/**`
- Modify references in active PHP roots.

**Interfaces:**
- Produces unchanged APIs under `modules\module`, `modules\site`, `modules\theme`.

- [ ] **Step 1: Move directories**

```bash
git mv app/module modules/module
git mv app/site modules/site
git mv app/theme modules/theme
```

- [ ] **Step 2: Apply exact namespace mapping**

```python
mapping = {
    'app\\module\\': 'modules\\module\\',
    'app\\site\\': 'modules\\site\\',
    'app\\theme\\': 'modules\\theme\\',
}
```

Use the same active-root replacement loop from Task 3.

- [ ] **Step 3: Autoload/lint**

```bash
composer dump-autoload
find modules/module modules/site modules/theme -name '*.php' -print0 | xargs -0 -n1 php -l
```

- [ ] **Step 4: Run focused tests**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Module/RuntimeModuleResolverTest.php'; require 'tests/Unit/Module/ModulePluginRelationTest.php'; require 'tests/Component/Module/R20ModuleRuntimeRepositoryTest.php'; require 'tests/Component/Module/ModuleAuthorizationServiceTest.php'; require 'tests/GoldenMaster/LegacyModuleAdapterTest.php'; require 'tests/Unit/Site/SiteTest.php'; require 'tests/Component/Site/SiteDomainResolverTest.php'; require 'tests/GoldenMaster/R20SiteSnapshotTest.php'; require 'tests/Unit/Theme/ThemeVersionTest.php'; require 'tests/Component/Theme/ThemeReleaseServiceTest.php'; require 'tests/Unit/Theme/SafeThemeRendererTest.php'; require 'tests/GoldenMaster/R20ThemeStyleSnapshotTest.php';"
```

Expected: exit `0`.

- [ ] **Step 5: Commit**

```bash
git add app modules tests config
git commit -m "refactor: move runtime site and theme modules"
```

---

### Task 5: Migrate Member, OAuth, Webhook, and MiniApp

**Files:**
- Move: `app/member/**` -> `modules/member/**`
- Move: `app/oauth/**` -> `modules/oauth/**`
- Move: `app/webhook/**` -> `modules/webhook/**`
- Move: `app/miniapp/**` -> `modules/miniapp/**`
- Modify references in active PHP roots.

**Interfaces:**
- Produces unchanged identity/OAuth/webhook/MiniApp APIs under `modules\member`, `modules\oauth`, `modules\webhook`, `modules\miniapp`.

- [ ] **Step 1: Move directories**

```bash
git mv app/member modules/member
git mv app/oauth modules/oauth
git mv app/webhook modules/webhook
git mv app/miniapp modules/miniapp
```

- [ ] **Step 2: Apply exact namespace mapping**

```python
mapping = {
    'app\\member\\': 'modules\\member\\',
    'app\\oauth\\': 'modules\\oauth\\',
    'app\\webhook\\': 'modules\\webhook\\',
    'app\\miniapp\\': 'modules\\miniapp\\',
}
```

Use the Task 3 replacement loop.

- [ ] **Step 3: Autoload/lint**

```bash
composer dump-autoload
find modules/member modules/oauth modules/webhook modules/miniapp -name '*.php' -print0 | xargs -0 -n1 php -l
```

- [ ] **Step 4: Run focused tests**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Member/ExternalIdentityTest.php'; require 'tests/Component/Member/MemberIdentityServiceTest.php'; require 'tests/Unit/OAuth/OAuthStateTest.php'; require 'tests/Component/OAuth/OAuthOrchestratorTest.php'; require 'tests/Unit/Webhook/WechatSignatureVerifierTest.php'; require 'tests/Component/Webhook/WechatWebhookServiceTest.php'; require 'tests/Unit/MiniApp/MiniAppProviderAccountTest.php'; require 'tests/Unit/MiniApp/MiniAppSessionTest.php'; require 'tests/Component/MiniApp/MiniAppLoginServiceTest.php'; require 'tests/Component/MiniApp/MiniAppSessionServiceTest.php'; require 'tests/GoldenMaster/R20MiniAppProviderSnapshotTest.php';"
```

Expected: exit `0`.

- [ ] **Step 5: Commit**

```bash
git add app modules tests config
git commit -m "refactor: move identity integration modules"
```

---

### Task 6: Migrate OpenPlatform and Legacy Runtime Integration

**Files:**
- Move: `app/openplatform/**` -> `modules/openplatform/**`
- Move: `app/legacy/**` -> `modules/integration/legacy/**`
- Modify references in active PHP roots.
- Modify: `app/AppService.php`

**Interfaces:**
- Produces all existing R8B-R8D OpenPlatform services/contracts/infrastructure under `modules\openplatform` and legacy runtime under `modules\integration\legacy`.

- [ ] **Step 1: Move directories**

```bash
git mv app/openplatform modules/openplatform
mkdir -p modules/integration
git mv app/legacy modules/integration/legacy
```

- [ ] **Step 2: Apply exact namespace mapping**

```python
mapping = {
    'app\\openplatform\\': 'modules\\openplatform\\',
    'app\\legacy\\': 'modules\\integration\\legacy\\',
}
```

Use the Task 3 replacement loop.

- [ ] **Step 3: Verify the composition root has no stale business imports**

```bash
php -r '$s=file_get_contents("app/AppService.php"); foreach (["app\\openplatform\\","app\\iam\\","app\\miniapp\\","app\\quota\\"] as $x) { if (str_contains($s,$x)) { fwrite(STDERR,"stale AppService import: $x\n"); exit(1); } }'
```

Expected: exit `0`.

- [ ] **Step 4: Autoload/lint**

```bash
composer dump-autoload
find modules/openplatform modules/integration/legacy -name '*.php' -print0 | xargs -0 -n1 php -l
php -l app/AppService.php
```

- [ ] **Step 5: Run focused OpenPlatform/legacy coverage**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Legacy/LegacySerializedValueDecoderTest.php'; require 'tests/Unit/OpenPlatform/ComponentPlatformTest.php'; require 'tests/Unit/OpenPlatform/AuthorizationIntentTest.php'; require 'tests/Unit/OpenPlatform/AuthorizerProvisioningTest.php'; require 'tests/Unit/OpenPlatform/WechatComponentCallbackAuthenticatorTest.php'; require 'tests/Component/OpenPlatform/AuthorizationCompletionServiceTest.php'; require 'tests/Component/OpenPlatform/AuthorizationAutoProvisionCompletionTest.php'; require 'tests/Component/OpenPlatform/AuthorizerMetadataSyncServiceTest.php'; require 'tests/Component/OpenPlatform/AuthorizerOwnershipResolverTest.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningWorkerTest.php'; require 'tests/Component/OpenPlatform/AuthorizerProvisioningQuotaTest.php'; require 'tests/Component/OpenPlatform/AuthorizerReconnectLifecycleTest.php'; require 'tests/Component/MiniApp/OpenPlatformComponentAccessTokenProviderTest.php'; require 'tests/Component/MiniApp/OpenPlatformAuthorizerAccountBindingTest.php';"
```

Expected: exit `0`.

- [ ] **Step 6: Commit**

```bash
git add app modules tests config
git commit -m "refactor: move openplatform and legacy runtime modules"
```

---

### Task 7: Move the CLI Adapter and Make the Architecture Contract GREEN

**Files:**
- Move: `app/command/OpenPlatformProvisioningWorkerCommand.php` -> `app/worker/command/OpenPlatformProvisioningWorkerCommand.php`
- Modify: `config/console.php`
- Modify active references in: `tests/**/*.php`, `README.md`

**Interfaces:**
- Consumes: `modules\openplatform\application\AuthorizerProvisioningWorker`, `modules\openplatform\application\ProvisioningBatchRunner`, `modules\openplatform\infrastructure\ThinkPhpProvisioningJobSource`.
- Produces: `app\worker\command\OpenPlatformProvisioningWorkerCommand`; external command remains `openplatform:provisioning-worker`.

- [ ] **Step 1: Move and rename the command namespace**

```bash
mkdir -p app/worker/command
git mv app/command/OpenPlatformProvisioningWorkerCommand.php app/worker/command/OpenPlatformProvisioningWorkerCommand.php
```

The imports/header must be:

```php
namespace app\worker\command;

use modules\openplatform\application\AuthorizerProvisioningWorker;
use modules\openplatform\application\ProvisioningBatchRunner;
use modules\openplatform\infrastructure\ThinkPhpProvisioningJobSource;
```

Keep:

```php
$this->setName('openplatform:provisioning-worker')
```

- [ ] **Step 2: Update `config/console.php`**

Use:

```php
use app\worker\command\OpenPlatformProvisioningWorkerCommand;
```

Do not change the `commands` array shape.

- [ ] **Step 3: Update README with current architecture tokens**

README must explicitly contain all of:

```text
app/admin
app/api
app/web
app/worker
app/common
modules/*
```

It must explain that the first four are delivery adapters, `common` is the shared kernel, and `modules/*` contains business bounded contexts.

- [ ] **Step 4: Verify command discovery**

```bash
composer dump-autoload
php think list
```

Expected: output contains `openplatform:provisioning-worker`.

- [ ] **Step 5: Require Architecture Contract GREEN**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: exit `0`.

- [ ] **Step 6: Run worker contract**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/R8DProvisioningWorkerRuntimeContractTest.php';"
```

Expected: exit `0`.

- [ ] **Step 7: Commit**

```bash
git add app/worker config/console.php tests README.md
git add -u app/command
git commit -m "refactor: align provisioning worker with app worker entry"
```

---

### Task 8: Repair Architecture-Sensitive Tests and Run the Full Automated Gate

**Files:**
- Modify path-sensitive: `tests/Contract/*.php`
- Modify stale imports as needed: `tests/Unit/**/*.php`, `tests/Component/**/*.php`, `tests/GoldenMaster/**/*.php`, `tests/Acceptance/**/*.php`, `tests/ProviderE2E/run.php`
- Modify: `README.md`
- Do not rewrite: `docs/design-source/WeEngine-ThinkPHP-Refactor-V4/**`

**Interfaces:**
- Produces active tests/docs consistent with the new physical layout without weakening existing behavioral/security assertions.

- [ ] **Step 1: Run stale namespace scan**

```bash
php -r '$roots=["app","modules","config","tests"]; $mods=["account","entitlement","iam","legacy","member","miniapp","module","oauth","openplatform","quota","site","tenant","theme","webhook","command"]; foreach($roots as $r){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS));foreach($it as $f){if(!$f->isFile()||strtolower($f->getExtension())!=="php"||$f->getFilename()==="AppModulesArchitectureContractTest.php")continue;$s=file_get_contents($f->getPathname());foreach($mods as $m){$p="app\\$m\\";if(str_contains($s,$p)){fwrite(STDERR,$f->getPathname()." -> ".$p.PHP_EOL);}}}}'
```

Expected: no output.

- [ ] **Step 2: Repair active path assertions without weakening them**

```bash
grep -RFn --include='*.php' 'app/openplatform' tests app config || true
grep -RFn --include='*.php' 'app/iam' tests app config || true
grep -RFn --include='*.php' 'app/account' tests app config || true
grep -RFn --include='*.php' 'app/legacy' tests app config || true
grep -RFn --include='*.php' 'app/command' tests app config || true
```

For runtime-path assertions, replace only the path root with `modules/openplatform`, `modules/iam`, `modules/account`, `modules/integration/legacy`, or `app/worker` respectively. Secret scans and security checks must continue scanning the same logical code.

- [ ] **Step 3: Run complete offline suite**

```bash
php tests/run.php
```

Expected: every listed test prints `[PASS]`; exit `0`.

- [ ] **Step 4: Run PHPUnit bridge and full lint**

```bash
php vendor/bin/phpunit
find app modules config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all pass.

- [ ] **Step 5: Run existing ThinkPHP HTTP smoke and command gates**

Run the repository's current CI smoke procedure for `/health`, `/admin/health`, and `/api/v1/health`, then run `php think list`.

Expected: unchanged successful health semantics and visible `openplatform:provisioning-worker` command.

- [ ] **Step 6: Commit any path-sensitive cleanup**

```bash
git add tests README.md app modules config
git commit -m "test: enforce aligned app-modules runtime paths"
```

If Steps 1-5 require no additional file changes, do not create an empty commit.

---

### Task 9: Run Local/MySQL Release Acceptance on the New Exact HEAD

**Files:**
- No source changes expected; this task produces verification evidence.

**Interfaces:**
- Produces the architecture-aligned release-candidate SHA and local MySQL evidence.

- [ ] **Step 1: Verify clean source and record exact HEAD**

```bash
git status --short
FEATURE_HEAD=$(git rev-parse HEAD)
printf '%s\n' "$FEATURE_HEAD"
```

Expected: clean worktree and one exact SHA printed.

- [ ] **Step 2: Validate/install locked dependencies**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
git status --short
```

Expected: all exit `0`; worktree stays clean.

- [ ] **Step 3: Run disposable local MySQL release gate**

Set `DATABASE_PASSWORD` in the shell to the local MySQL test account password before running:

```bash
export WEPLATFORM_ACCEPTANCE=1
export DATABASE_HOSTNAME=127.0.0.1
export DATABASE_HOSTPORT=3306
export DATABASE_DATABASE=weplatform_acceptance
export DATABASE_USERNAME=root
php tests/Release/run.php
```

Expected final output includes `[PASS] Local acceptance runtime gate` and `[PASS] Local release gate`. Never point this runner at a non-local/production database.

- [ ] **Step 4: Prove migrations 001-009 were untouched**

```bash
git diff 1bfa3ded63ff886483f01ac99bf6efedfa549aba -- database/migrations
```

Expected: no output.

- [ ] **Step 5: Fresh-main race check**

```bash
git fetch origin main
git rev-list --left-right --count origin/main...HEAD
```

Expected: left/behind count `0`. Otherwise integrate current `main` according to repository policy and rerun Tasks 8-9 on the resulting SHA.

---

### Task 10: Exact-HEAD CI and Real Provider Release Gate

**Files:**
- PR #7 metadata/body only unless a gate exposes a real defect.

**Interfaces:**
- Consumes: `FEATURE_HEAD` from Task 9.
- Produces final pre-merge evidence; PR stays Draft until real Provider E2E is GREEN.

- [ ] **Step 1: Push exact feature HEAD and require Actions GREEN**

```bash
git push origin refactor/openplatform-authorizer-provisioning-r8d
```

Require on the same SHA: `test: SUCCESS` and `R8D MySQL release gate: SUCCESS`. The log must show `AppModulesArchitectureContractTest.php` executed.

- [ ] **Step 2: Update PR #7 evidence**

Record:

```text
Architecture alignment: GREEN
app/* delivery boundary: GREEN
modules/* business boundary: GREEN
Composer exact-head gate: GREEN
Offline/Unit/Component/GoldenMaster: GREEN
MySQL 8.4 R8D Release Gate: GREEN
Real WeChat Provider E2E: PENDING
```

Use the new exact SHA as current feature HEAD; retain `1bfa3ded63ff886483f01ac99bf6efedfa549aba` only as historical pre-alignment evidence.

- [ ] **Step 3: Deploy the same exact SHA to the public HTTPS E2E environment**

```bash
git rev-parse HEAD
git status --short
```

Expected: HEAD equals the CI-green feature SHA and worktree is clean.

- [ ] **Step 4: First real authorization -> `provisioned`**

With temporary `WEPLATFORM_PROVIDER_E2E_*` runner variables set:

```bash
php tests/ProviderE2E/run.php start
```

After browser authorization, store only callback `data.provisioning_id` in `FIRST_PROVISIONING_ID`, then run:

```bash
php tests/ProviderE2E/run.php verify "$FIRST_PROVISIONING_ID"
```

Expected safe result: `status=provisioned`. Do not persist/share authorization URL, state, auth code, provider tokens, AppSecret, EncodingAESKey, verify token, or raw decrypted callback content.

- [ ] **Step 5: Same-authorizer reconnect -> `reconnected`**

Run `start` again with the same Tenant/component platform/authorizer. Store only the second callback provisioning id in `SECOND_PROVISIONING_ID`, then run:

```bash
php tests/ProviderE2E/run.php verify "$SECOND_PROVISIONING_ID"
```

Expected safe result: `status=reconnected`; the runner also confirms no second semantic quota consume.

- [ ] **Step 6: Remove test-only runner variables**

```bash
unset WEPLATFORM_PROVIDER_E2E
unset WEPLATFORM_PROVIDER_E2E_BASE_URL
unset WEPLATFORM_PROVIDER_E2E_ADMIN_BEARER_TOKEN
unset WEPLATFORM_PROVIDER_E2E_TENANT_ID
unset WEPLATFORM_PROVIDER_E2E_COMPONENT_PLATFORM_ID
```

- [ ] **Step 7: Release sequence only after all gates**

```text
fresh main comparison
-> final PR diff/review
-> Draft -> Ready
-> merge to main
-> independent main CI on exact merge SHA
-> tag/release v1.0.0
```

Do not claim V1 released before independent post-merge `main` CI is GREEN.

---

## Final Verification Checklist

- [ ] Only `admin`, `api`, `web`, `worker`, and `common` remain as first-level `app/` directories.
- [ ] `app/worker/command/OpenPlatformProvisioningWorkerCommand.php` exists and `php think list` exposes `openplatform:provisioning-worker`.
- [ ] `modules/` contains all migrated bounded contexts and `modules/integration/legacy`.
- [ ] Every module PHP namespace matches its physical `modules/...` path.
- [ ] Composer maps both `app\` and `modules\`; committed lock is valid.
- [ ] No active runtime/test/config PHP source contains stale old business namespaces.
- [ ] Business modules do not depend on `app\admin`, `app\api`, `app\web`, or `app\worker`.
- [ ] Domain source does not depend on ThinkPHP or `app\common\infrastructure`.
- [ ] Architecture contract executes through `tests/run.php` and is GREEN.
- [ ] README describes `app/*` delivery/shared-kernel and `modules/*` business boundaries.
- [ ] Offline suite, PHPUnit, lint, HTTP smoke, command gate, and MySQL 8.4 release gate are GREEN on one exact feature SHA.
- [ ] `database/migrations` has no diff from pre-alignment baseline `1bfa3ded63ff886483f01ac99bf6efedfa549aba`.
- [ ] Real Provider E2E first authorization is `provisioned`; second same-owner authorization is `reconnected` with no second quota consume.
- [ ] PR #7 remains Draft until all exact-head gates above are satisfied.
