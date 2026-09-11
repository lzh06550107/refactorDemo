# App / Modules Architecture Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the approved V4 physical boundary so `app/*` contains delivery/composition/shared-kernel code and `modules/*` contains business capabilities, with zero R1-R8D business-semantic change.

**Architecture:** Keep `app/admin`, `app/api`, `app/web`, `app/worker`, and `app/common` as ThinkPHP delivery/shared-kernel roots. Move every current business bounded context to a top-level `modules/` PSR-4 root, rename namespaces mechanically, move the provisioning CLI adapter to `app/worker/command`, and enforce the boundary with an offline architecture contract that executes on `require`.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, think-multi-app 1.1.1, Composer PSR-4, PHPUnit 11.5, MySQL 8.4 release acceptance, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-11-app-modules-architecture-alignment-design.md`

## Global Constraints

- Preserve all API URLs, methods, JSON fields, response envelopes, and stable error codes.
- Preserve database migrations `001` through `009` byte-for-byte unless a test proves an unrelated pre-existing defect; this architecture task itself requires no schema change.
- Preserve the external CLI command name `openplatform:provisioning-worker` and its options/exit semantics.
- Preserve R8D provisioning states, ownership uniqueness, reconnect behavior, quota consume/release/idempotency semantics, and provider cryptography.
- `app/*` delivery roots are `admin`, `api`, `web`, `worker`; `common` is the shared kernel and is denied direct HTTP dispatch.
- Business capabilities live under `modules/*` and use the `modules\\` namespace.
- Business-module code MUST NOT depend on `app\\admin`, `app\\api`, `app\\web`, or `app\\worker`.
- Domain code MUST NOT depend on ThinkPHP delivery/framework APIs or `app\\common\\infrastructure`.
- No permanent `class_alias()` compatibility bridge for old `app\\<business-module>\\...` names.
- `AppService.php` remains the composition root for this slice; do not split it into per-module providers in this change.
- PR #7 stays Draft until the new exact HEAD passes automated gates and real WeChat Provider E2E.

---

## File/Responsibility Map

The implementation intentionally changes physical ownership but not behavior.

| Responsibility | Current | Target |
| --- | --- | --- |
| Admin HTTP adapter | `app/admin/**` | unchanged |
| Public API adapter | `app/api/**` | unchanged |
| Web adapter | `app/web/**` | unchanged |
| Shared kernel | `app/common/**` | unchanged |
| CLI provisioning adapter | `app/command/OpenPlatformProvisioningWorkerCommand.php` | `app/worker/command/OpenPlatformProvisioningWorkerCommand.php` |
| Account business module | `app/account/**` | `modules/account/**` |
| Entitlement business module | `app/entitlement/**` | `modules/entitlement/**` |
| IAM business module | `app/iam/**` | `modules/iam/**` |
| Member business module | `app/member/**` | `modules/member/**` |
| MiniApp business module | `app/miniapp/**` | `modules/miniapp/**` |
| Module runtime business module | `app/module/**` | `modules/module/**` |
| OAuth business module | `app/oauth/**` | `modules/oauth/**` |
| OpenPlatform business module | `app/openplatform/**` | `modules/openplatform/**` |
| Quota business module | `app/quota/**` | `modules/quota/**` |
| Site business module | `app/site/**` | `modules/site/**` |
| Tenant business module | `app/tenant/**` | `modules/tenant/**` |
| Theme business module | `app/theme/**` | `modules/theme/**` |
| Webhook business module | `app/webhook/**` | `modules/webhook/**` |
| Legacy runtime integration | `app/legacy/**` | `modules/integration/legacy/**` |
| Composition root | `app/AppService.php` | unchanged path; imports updated |
| CLI registration | `config/console.php` | unchanged path; class updated |
| Autoload roots | `composer.json` | add `modules\\` -> `modules/` |
| Permanent architecture gate | absent | `tests/Contract/AppModulesArchitectureContractTest.php` |
| Offline suite registration | `tests/run.php` | add architecture contract immediately after structure contract |

---

### Task 1: Add a Failing App/Modules Architecture Contract

**Files:**
- Create: `tests/Contract/AppModulesArchitectureContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `expectTrue(bool $condition, string $message): void` from `tests/Support/bootstrap.php`.
- Produces: a require-time architecture gate; no function wrapper and no direct-run conditional.

- [ ] **Step 1: Create the contract with current-tree RED assertions**

Create `tests/Contract/AppModulesArchitectureContractTest.php` with this structure:

```php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$businessModules = [
    'account',
    'entitlement',
    'iam',
    'member',
    'miniapp',
    'module',
    'oauth',
    'openplatform',
    'quota',
    'site',
    'tenant',
    'theme',
    'webhook',
];

foreach ($businessModules as $module) {
    expectTrue(
        !is_dir($root . '/app/' . $module),
        'business module must not live under app/: ' . $module,
    );
    expectTrue(
        is_dir($root . '/modules/' . $module),
        'business module must live under modules/: ' . $module,
    );
}

expectTrue(!is_dir($root . '/app/legacy'), 'legacy runtime must not live under app/');
expectTrue(
    is_dir($root . '/modules/integration/legacy'),
    'legacy runtime must live under modules/integration/legacy',
);
expectTrue(!is_dir($root . '/app/command'), 'CLI adapter must not live in app/command');
expectTrue(
    is_file($root . '/app/worker/command/OpenPlatformProvisioningWorkerCommand.php'),
    'provisioning worker command must live under app/worker/command',
);

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$psr4 = $composer['autoload']['psr-4'] ?? [];
expectTrue(($psr4['app\\\\'] ?? null) === 'app/', 'Composer must map app\\ to app/');
expectTrue(($psr4['modules\\\\'] ?? null) === 'modules/', 'Composer must map modules\\ to modules/');

$forbiddenAppDependencies = [
    'app' . '\\\\' . 'admin' . '\\\\',
    'app' . '\\\\' . 'api' . '\\\\',
    'app' . '\\\\' . 'web' . '\\\\',
    'app' . '\\\\' . 'worker' . '\\\\',
];

$moduleRoot = $root . '/modules';
if (is_dir($moduleRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $moduleRoot,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace('\\\\', '/', substr($file->getPathname(), strlen($root) + 1));

        foreach ($forbiddenAppDependencies as $prefix) {
            expectTrue(
                !str_contains($source, $prefix),
                'business module must not depend on delivery app namespace: ' . $relative . ' -> ' . $prefix,
            );
        }

        $normalized = str_replace('\\\\', '/', $file->getPathname());
        if (str_contains($normalized, '/domain/')) {
            expectTrue(!preg_match('/\\buse\\s+think\\\\/i', $source), 'domain must not import ThinkPHP: ' . $relative);
            expectTrue(
                !str_contains($source, 'app' . '\\\\' . 'common' . '\\\\' . 'infrastructure' . '\\\\'),
                'domain must not import app\\common\\infrastructure: ' . $relative,
            );
        }
    }
}

$activeRoots = [
    $root . '/app',
    $root . '/modules',
    $root . '/config',
    $root . '/tests',
];
$stalePrefixes = array_map(
    static fn (string $module): string => 'app' . '\\\\' . $module . '\\\\',
    array_merge($businessModules, ['legacy', 'command']),
);

foreach ($activeRoots as $activeRoot) {
    if (!is_dir($activeRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $activeRoot,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        if ($file->getFilename() === 'AppModulesArchitectureContractTest.php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach ($stalePrefixes as $prefix) {
            expectTrue(
                !str_contains($source, $prefix),
                'stale business namespace remains: ' . $file->getPathname() . ' -> ' . $prefix,
            );
        }
    }
}
```

- [ ] **Step 2: Register the contract in `tests/run.php`**

Insert immediately after `StructureContractTest.php`:

```php
__DIR__ . '/Contract/AppModulesArchitectureContractTest.php',
```

- [ ] **Step 3: Run only the new contract and verify RED**

Run:

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: non-zero/fatal assertion with a message such as:

```text
business module must not live under app/: account
```

The failure must be caused by the current architecture, not syntax or bootstrap failure.

- [ ] **Step 4: Verify the normal offline suite is RED for the same reason**

Run:

```bash
php tests/run.php
```

Expected: `AppModulesArchitectureContractTest.php` reports FAIL while pre-existing contracts continue running after it.

- [ ] **Step 5: Commit the RED gate**

```bash
git add tests/Contract/AppModulesArchitectureContractTest.php tests/run.php
git commit -m "test: define app-modules architecture boundary"
```

---

### Task 2: Add the Modules PSR-4 Root

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` only if `composer validate --strict` / `composer install` shows the lock content-hash must be refreshed because `composer.json` changed.

**Interfaces:**
- Consumes: existing `app\\` -> `app/` Composer mapping.
- Produces: `modules\\` -> `modules/` autoload mapping used by every subsequent migration task.

- [ ] **Step 1: Add the exact PSR-4 mapping**

Change:

```json
"autoload": {
  "psr-4": {
    "app\\\\": "app/"
  }
}
```

to:

```json
"autoload": {
  "psr-4": {
    "app\\\\": "app/",
    "modules\\\\": "modules/"
  }
}
```

- [ ] **Step 2: Refresh Composer metadata reproducibly**

Run:

```bash
composer validate --strict
composer update --lock --no-install
composer install --no-interaction
```

Expected: `composer.json` validates, the lock is internally consistent, and no dependency version changes occur solely because of this PSR-4 edit.

- [ ] **Step 3: Verify the architecture contract is still RED for missing/misplaced modules, not Composer**

Run:

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: failure such as `business module must not live under app/: account`; there must be no Composer JSON/autoload assertion failure.

- [ ] **Step 4: Commit the autoload root**

```bash
git add composer.json composer.lock
git commit -m "build: add modules psr-4 root"
```

---

### Task 3: Migrate Core Identity, Ownership, Entitlement, and Quota Modules

**Files:**
- Move: `app/tenant/**` -> `modules/tenant/**`
- Move: `app/account/**` -> `modules/account/**`
- Move: `app/iam/**` -> `modules/iam/**`
- Move: `app/entitlement/**` -> `modules/entitlement/**`
- Move: `app/quota/**` -> `modules/quota/**`
- Modify references in: `app/**`, `modules/**`, `tests/**`, `config/**`
- Modify: `app/AppService.php`

**Interfaces:**
- Consumes: `modules\\` PSR-4 root from Task 2.
- Produces namespaces `modules\\tenant`, `modules\\account`, `modules\\iam`, `modules\\entitlement`, `modules\\quota` with unchanged public class names/method signatures.

- [ ] **Step 1: Move the five bounded contexts without renaming files**

```bash
mkdir -p modules
git mv app/tenant modules/tenant
git mv app/account modules/account
git mv app/iam modules/iam
git mv app/entitlement modules/entitlement
git mv app/quota modules/quota
```

- [ ] **Step 2: Rewrite the exact namespace prefixes in active PHP code/tests/config**

Use this one-off migration script from the repository root; do not commit the script itself:

```python
from pathlib import Path

mapping = {
    r'app\\tenant\\': r'modules\\tenant\\',
    r'app\\account\\': r'modules\\account\\',
    r'app\\iam\\': r'modules\\iam\\',
    r'app\\entitlement\\': r'modules\\entitlement\\',
    r'app\\quota\\': r'modules\\quota\\',
}

roots = [Path('app'), Path('modules'), Path('tests'), Path('config')]
for root in roots:
    if not root.exists():
        continue
    for path in root.rglob('*.php'):
        text = path.read_text(encoding='utf-8')
        changed = text
        for old, new in mapping.items():
            changed = changed.replace(old, new)
        if changed != text:
            path.write_text(changed, encoding='utf-8')
```

Run it with:

```bash
python /tmp/migrate_core_namespaces.py
```

If `/tmp` is not available, save the exact script above outside the repository and run it from there.

- [ ] **Step 3: Regenerate autoload metadata and lint moved PHP**

```bash
composer dump-autoload
find modules/tenant modules/account modules/iam modules/entitlement modules/quota -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every moved PHP file reports `No syntax errors detected`.

- [ ] **Step 4: Run the affected unit/component/golden-master tests directly**

Run:

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Tenant/TenantTest.php'; require 'tests/Unit/Account/AccountTest.php'; require 'tests/Unit/Account/LegacyAccountMappingTest.php'; require 'tests/Unit/Iam/AdminUserTest.php'; require 'tests/Component/Iam/RestoreAdminSessionTest.php'; require 'tests/Unit/Entitlement/TenantModuleEntitlementTest.php'; require 'tests/Component/Entitlement/TenantModuleEntitlementServiceTest.php'; require 'tests/Unit/Quota/QuotaLedgerTest.php'; require 'tests/Component/Quota/QuotaServiceIdempotencyTest.php'; require 'tests/GoldenMaster/R20AccountQuotaSnapshotTest.php';"
```

Expected: process exits `0` with no uncaught exception.

- [ ] **Step 5: Confirm the architecture gate progressed but remains intentionally RED**

Run:

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: it no longer fails on `tenant/account/iam/entitlement/quota`; it fails on the next unmigrated module, such as `member`.

- [ ] **Step 6: Commit the core module migration**

```bash
git add app modules tests config
git commit -m "refactor: move core business modules out of app"
```

---

### Task 4: Migrate Module Runtime, Site, and Theme Capabilities

**Files:**
- Move: `app/module/**` -> `modules/module/**`
- Move: `app/site/**` -> `modules/site/**`
- Move: `app/theme/**` -> `modules/theme/**`
- Modify references in: `app/**`, `modules/**`, `tests/**`, `config/**`

**Interfaces:**
- Consumes: the core business namespaces from Task 3.
- Produces: `modules\\module`, `modules\\site`, and `modules\\theme`; runtime availability, R20 compatibility, site/domain resolution, and theme publication APIs remain unchanged.

- [ ] **Step 1: Move the three bounded contexts**

```bash
git mv app/module modules/module
git mv app/site modules/site
git mv app/theme modules/theme
```

- [ ] **Step 2: Rewrite the three namespace prefixes everywhere active**

Use this exact mapping in the same one-off replacement pattern as Task 3:

```python
mapping = {
    r'app\\module\\': r'modules\\module\\',
    r'app\\site\\': r'modules\\site\\',
    r'app\\theme\\': r'modules\\theme\\',
}
```

Apply only to `app/**/*.php`, `modules/**/*.php`, `tests/**/*.php`, and `config/**/*.php`.

- [ ] **Step 3: Lint and autoload**

```bash
composer dump-autoload
find modules/module modules/site modules/theme -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all pass.

- [ ] **Step 4: Run the affected tests**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Unit/Module/RuntimeModuleResolverTest.php'; require 'tests/Unit/Module/ModulePluginRelationTest.php'; require 'tests/Component/Module/R20ModuleRuntimeRepositoryTest.php'; require 'tests/Component/Module/ModuleAuthorizationServiceTest.php'; require 'tests/GoldenMaster/LegacyModuleAdapterTest.php'; require 'tests/Unit/Site/SiteTest.php'; require 'tests/Component/Site/SiteDomainResolverTest.php'; require 'tests/GoldenMaster/R20SiteSnapshotTest.php'; require 'tests/Unit/Theme/ThemeVersionTest.php'; require 'tests/Component/Theme/ThemeReleaseServiceTest.php'; require 'tests/Unit/Theme/SafeThemeRendererTest.php'; require 'tests/GoldenMaster/R20ThemeStyleSnapshotTest.php';"
```

Expected: exit `0`.

- [ ] **Step 5: Confirm Architecture Contract moves to the next unmigrated module**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: no failure naming `module`, `site`, or `theme`; still RED on identity/OpenPlatform modules.

- [ ] **Step 6: Commit**

```bash
git add app modules tests config
git commit -m "refactor: move runtime site and theme modules"
```

---

### Task 5: Migrate Member, OAuth, Webhook, and MiniApp Capabilities

**Files:**
- Move: `app/member/**` -> `modules/member/**`
- Move: `app/oauth/**` -> `modules/oauth/**`
- Move: `app/webhook/**` -> `modules/webhook/**`
- Move: `app/miniapp/**` -> `modules/miniapp/**`
- Modify references in: `app/**`, `modules/**`, `tests/**`, `config/**`

**Interfaces:**
- Consumes: `modules\\iam`, `modules\\account`, shared `app\\common` primitives.
- Produces: `modules\\member`, `modules\\oauth`, `modules\\webhook`, `modules\\miniapp` with unchanged OAuth state, identity, webhook replay, MiniApp session, and encrypted-data semantics.

- [ ] **Step 1: Move the four bounded contexts**

```bash
git mv app/member modules/member
git mv app/oauth modules/oauth
git mv app/webhook modules/webhook
git mv app/miniapp modules/miniapp
```

- [ ] **Step 2: Rewrite exact namespaces**

```python
mapping = {
    r'app\\member\\': r'modules\\member\\',
    r'app\\oauth\\': r'modules\\oauth\\',
    r'app\\webhook\\': r'modules\\webhook\\',
    r'app\\miniapp\\': r'modules\\miniapp\\',
}
```

Apply only to active PHP roots `app`, `modules`, `tests`, and `config`.

- [ ] **Step 3: Lint/autoload**

```bash
composer dump-autoload
find modules/member modules/oauth modules/webhook modules/miniapp -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all pass.

- [ ] **Step 4: Run identity/integration tests**

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
- Modify references in: `app/**`, `modules/**`, `tests/**`, `config/**`
- Modify: `app/AppService.php`

**Interfaces:**
- Consumes: all migrated core/identity modules and `app/common` cross-cutting primitives.
- Produces: `modules\\openplatform\\...` and `modules\\integration\\legacy\\...`; all R8B-R8D public service signatures and provider behaviors remain identical.

- [ ] **Step 1: Move both runtime areas**

```bash
git mv app/openplatform modules/openplatform
mkdir -p modules/integration
git mv app/legacy modules/integration/legacy
```

- [ ] **Step 2: Rewrite the exact namespaces**

```python
mapping = {
    r'app\\openplatform\\': r'modules\\openplatform\\',
    r'app\\legacy\\': r'modules\\integration\\legacy\\',
}
```

Apply to active PHP roots `app`, `modules`, `tests`, and `config`.

- [ ] **Step 3: Verify `app/AppService.php` imports only new business namespaces**

Run:

```bash
php -r '$s=file_get_contents("app/AppService.php"); foreach (["app\\\\openplatform\\\\","app\\\\iam\\\\","app\\\\miniapp\\\\","app\\\\quota\\\\"] as $x) { if (str_contains($s,$x)) { fwrite(STDERR,"stale AppService import: $x\n"); exit(1); } }'
```

Expected: exit `0` with no output.

- [ ] **Step 4: Lint and autoload**

```bash
composer dump-autoload
find modules/openplatform modules/integration/legacy -name '*.php' -print0 | xargs -0 -n1 php -l
php -l app/AppService.php
```

Expected: all pass.

- [ ] **Step 5: Run OpenPlatform/legacy contract and component coverage**

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

### Task 7: Move the CLI Adapter and Turn the Architecture Contract GREEN

**Files:**
- Move: `app/command/OpenPlatformProvisioningWorkerCommand.php` -> `app/worker/command/OpenPlatformProvisioningWorkerCommand.php`
- Modify: `config/console.php`
- Modify active references in: `tests/**`, `README.md`
- Test: `tests/Contract/AppModulesArchitectureContractTest.php`

**Interfaces:**
- Consumes: `modules\\openplatform\\application\\AuthorizerProvisioningWorker`, `modules\\openplatform\\application\\ProvisioningBatchRunner`, `modules\\openplatform\\infrastructure\\ThinkPhpProvisioningJobSource`.
- Produces: `app\\worker\\command\\OpenPlatformProvisioningWorkerCommand`; external command remains `openplatform:provisioning-worker`.

- [ ] **Step 1: Move the command and rename its namespace/imports**

```bash
mkdir -p app/worker/command
git mv app/command/OpenPlatformProvisioningWorkerCommand.php app/worker/command/OpenPlatformProvisioningWorkerCommand.php
```

The file header must become:

```php
namespace app\worker\command;

use modules\openplatform\application\AuthorizerProvisioningWorker;
use modules\openplatform\application\ProvisioningBatchRunner;
use modules\openplatform\infrastructure\ThinkPhpProvisioningJobSource;
```

Do not change:

```php
$this->setName('openplatform:provisioning-worker')
```

- [ ] **Step 2: Update `config/console.php`**

Replace:

```php
use app\command\OpenPlatformProvisioningWorkerCommand;
```

with:

```php
use app\worker\command\OpenPlatformProvisioningWorkerCommand;
```

Keep the `commands` array shape unchanged.

- [ ] **Step 3: Update active tests/docs that refer to `app/command` or `app\\command`**

Run searches:

```bash
grep -RFn --exclude='2026-09-11-app-modules-architecture-alignment-design.md' --exclude='2026-09-11-app-modules-architecture-alignment.md' 'app/command' app config tests README.md || true
grep -RFn 'app\\command\\' app config tests README.md || true
```

Expected after edits: no output.

- [ ] **Step 4: Verify ThinkPHP discovers the command under the new namespace**

```bash
composer dump-autoload
php think list
```

Expected output contains:

```text
openplatform:provisioning-worker
```

- [ ] **Step 5: Run the architecture contract and require GREEN**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/AppModulesArchitectureContractTest.php';"
```

Expected: exit `0`, no assertion failure.

- [ ] **Step 6: Run the existing worker runtime contract**

```bash
php -r "require 'tests/Support/bootstrap.php'; require 'tests/Contract/R8DProvisioningWorkerRuntimeContractTest.php';"
```

Expected: exit `0`.

- [ ] **Step 7: Commit the CLI alignment**

```bash
git add app/worker config/console.php tests README.md
git add -u app/command
git commit -m "refactor: align provisioning worker with app worker entry"
```

---

### Task 8: Repair Architecture-Sensitive Contracts and Active Documentation

**Files:**
- Modify as required by stale path/namespace assertions: `tests/Contract/*.php`
- Modify as required by moved imports: `tests/Unit/**/*.php`, `tests/Component/**/*.php`, `tests/GoldenMaster/**/*.php`, `tests/Acceptance/**/*.php`, `tests/ProviderE2E/run.php`
- Modify: `README.md`
- Do not rewrite historical source: `docs/design-source/WeEngine-ThinkPHP-Refactor-V4/**`
- Do not rewrite the approved mapping history inside the spec/plan documents merely to eliminate old-path text.

**Interfaces:**
- Consumes: completed target tree from Tasks 3-7.
- Produces: active tests/docs that describe the target architecture and contain no stale runtime namespace/path assumptions.

- [ ] **Step 1: Scan runtime/test/config source for stale namespace prefixes**

Run:

```bash
php -r '$roots=["app","modules","config","tests"]; $mods=["account","entitlement","iam","legacy","member","miniapp","module","oauth","openplatform","quota","site","tenant","theme","webhook","command"]; foreach($roots as $r){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS));foreach($it as $f){if(!$f->isFile()||strtolower($f->getExtension())!=="php"||$f->getFilename()==="AppModulesArchitectureContractTest.php")continue;$s=file_get_contents($f->getPathname());foreach($mods as $m){$p="app\\\\$m\\\\";if(str_contains($s,$p)){fwrite(STDERR,$f->getPathname()." -> ".$p.PHP_EOL);}}}}'
```

Expected: no output and exit `0` after all required edits.

- [ ] **Step 2: Scan active path assertions/documentation**

```bash
grep -RFn --include='*.php' 'app/openplatform' tests app config || true
grep -RFn --include='*.php' 'app/iam' tests app config || true
grep -RFn --include='*.php' 'app/account' tests app config || true
grep -RFn --include='*.php' 'app/legacy' tests app config || true
grep -RFn --include='*.php' 'app/command' tests app config || true
```

For each result that is an active runtime-path assertion, replace it with the corresponding `modules/...` or `app/worker/...` target. Do not weaken security/secret-scan assertions; only change the root they scan.

- [ ] **Step 3: Update README architecture wording**

README must explicitly state:

```text
app/admin, app/api, app/web = HTTP delivery applications
app/worker = CLI/worker delivery adapter
app/common = shared kernel
modules/* = business bounded contexts
```

Keep R1-R8D functional history intact; update paths/namespaces where README describes current runtime code.

- [ ] **Step 4: Run the complete offline suite**

```bash
php tests/run.php
```

Expected: every listed contract/unit/component/golden-master test prints `[PASS]`; process exits `0`.

- [ ] **Step 5: Run PHPUnit bridge and full PHP lint**

```bash
php vendor/bin/phpunit
find app modules config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: PHPUnit exits `0`; every lint passes.

- [ ] **Step 6: Verify multi-app HTTP routes still boot**

Run the same smoke command/workflow used by current CI for:

```text
/health
/admin/health
/api/v1/health
```

Expected: each route returns the same successful health response/status as before the migration.

- [ ] **Step 7: Commit contract/doc cleanup**

```bash
git add tests README.md app modules config
git commit -m "test: enforce aligned app-modules runtime paths"
```

---

### Task 9: Run the Local/MySQL Release Gate on the New Exact HEAD

**Files:**
- No product-source changes expected.
- Evidence-producing commands only; if a failure reveals a regression, fix it in the owning prior task and rerun from the failing gate.

**Interfaces:**
- Consumes: migrated exact feature HEAD.
- Produces: local release evidence proving structural refactor preserved R8D behavior.

- [ ] **Step 1: Verify clean source and exact HEAD**

```bash
git status --short
git rev-parse HEAD
```

Expected: clean worktree; record the new SHA as the release-candidate feature HEAD.

- [ ] **Step 2: Validate/install locked Composer dependencies**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
```

Expected: both exit `0`; no uncommitted `composer.lock` mutation.

- [ ] **Step 3: Run the full local release runner against a disposable local MySQL database**

Set only a local acceptance DB, for example:

```bash
export WEPLATFORM_ACCEPTANCE=1
export DATABASE_HOSTNAME=127.0.0.1
export DATABASE_HOSTPORT=3306
export DATABASE_DATABASE=weplatform_acceptance
export DATABASE_USERNAME=root
export DATABASE_PASSWORD='<local-test-password>'
php tests/Release/run.php
```

Expected final lines include:

```text
[PASS] Local acceptance runtime gate
[PASS] Local release gate
```

Never point this runner at a production/non-local database.

- [ ] **Step 4: Verify migrations 001-009 are unchanged relative to the pre-alignment baseline**

```bash
git diff 1bfa3ded63ff886483f01ac99bf6efedfa549aba -- database/migrations
```

Expected: no output.

- [ ] **Step 5: Compare with current `main` before pushing release evidence**

```bash
git fetch origin main
git rev-list --left-right --count origin/main...HEAD
```

Expected: feature must not unexpectedly be behind `main`. If the left count is non-zero, stop release progression, rebase/merge according to repository policy, and rerun all gates on the resulting exact HEAD.

---

### Task 10: Exact-HEAD CI, PR Evidence, and Real Provider Gate

**Files:**
- PR #7 metadata/body may be updated with evidence.
- No product-source change unless a gate exposes a real defect.

**Interfaces:**
- Consumes: exact HEAD that passed Task 9.
- Produces: final pre-merge release evidence; PR remains Draft until real Provider E2E is GREEN.

- [ ] **Step 1: Push the exact feature HEAD and require GitHub Actions GREEN**

```bash
git push origin refactor/openplatform-authorizer-provisioning-r8d
```

Expected on that exact SHA:

```text
test: SUCCESS
R8D MySQL release gate: SUCCESS
```

The architecture contract must be visible in the offline suite execution; a workflow that skips it is not valid evidence.

- [ ] **Step 2: Record architecture-release evidence in PR #7**

Update the PR body with:

```text
Architecture alignment: GREEN
app/* delivery boundary: GREEN
modules/* business boundary: GREEN
Composer exact-head gate: GREEN
Offline/Unit/Component/GoldenMaster: GREEN
MySQL 8.4 R8D Release Gate: GREEN
Real WeChat Provider E2E: PENDING
```

Also replace the old historical feature HEAD with the new exact SHA while retaining `1bfa3ded...` only as historical pre-alignment evidence.

- [ ] **Step 3: Deploy that same exact SHA to the public HTTPS Provider-E2E environment**

On the test server:

```bash
git rev-parse HEAD
git status --short
```

Expected: HEAD exactly equals the CI-green feature SHA; worktree clean.

- [ ] **Step 4: Run first real authorization**

With test-runner-only `WEPLATFORM_PROVIDER_E2E_*` variables set in the temporary shell:

```bash
php tests/ProviderE2E/run.php start
```

Complete the real WeChat authorization in a browser, copy only the callback `data.provisioning_id`, then run:

```bash
php tests/ProviderE2E/run.php verify '<first-provisioning-id>'
```

Expected safe result:

```text
status=provisioned
```

Do not persist/share authorization URL, state, auth code, provider tokens, AppSecret, EncodingAESKey, verify token, or raw decrypted callback content.

- [ ] **Step 5: Run same-authorizer reconnect authorization**

Run `start` again using the same Tenant, component platform, and authorizer; then:

```bash
php tests/ProviderE2E/run.php verify '<second-provisioning-id>'
```

Expected safe result:

```text
status=reconnected
```

The runner must also confirm no second semantic quota consume.

- [ ] **Step 6: Stop test-only environment leakage**

```bash
unset WEPLATFORM_PROVIDER_E2E
unset WEPLATFORM_PROVIDER_E2E_BASE_URL
unset WEPLATFORM_PROVIDER_E2E_ADMIN_BEARER_TOKEN
unset WEPLATFORM_PROVIDER_E2E_TENANT_ID
unset WEPLATFORM_PROVIDER_E2E_COMPONENT_PLATFORM_ID
```

- [ ] **Step 7: Only after all gates, transition PR #7 toward release**

Required sequence:

```text
fresh main comparison
-> final PR diff/review
-> Draft -> Ready
-> merge to main
-> independent main CI on exact merge SHA
-> tag/release v1.0.0
```

Do not mark V1 released before the independent post-merge `main` CI is GREEN.

---

## Final Verification Checklist

Before claiming the architecture alignment complete, all of these must be true:

- [ ] `app/` has no business directories `account`, `entitlement`, `iam`, `legacy`, `member`, `miniapp`, `module`, `oauth`, `openplatform`, `quota`, `site`, `tenant`, `theme`, `webhook`, or old `command`.
- [ ] `app/worker/command/OpenPlatformProvisioningWorkerCommand.php` exists and `php think list` exposes `openplatform:provisioning-worker`.
- [ ] `modules/` contains every migrated bounded context and `modules/integration/legacy`.
- [ ] Composer maps both `app\\` and `modules\\` roots and the committed lock is valid.
- [ ] No active runtime/test/config PHP source contains stale `app\\<business-module>\\...` namespaces.
- [ ] Business modules do not depend on `app\\admin`, `app\\api`, `app\\web`, or `app\\worker`.
- [ ] Domain source does not import ThinkPHP or `app\\common\\infrastructure`.
- [ ] Architecture contract executes through `tests/run.php` and is GREEN.
- [ ] Offline suite, PHPUnit bridge, lint, HTTP smoke, worker command gate, and MySQL 8.4 release gate are GREEN on the same exact feature SHA.
- [ ] `database/migrations` has no architecture-induced diff from `1bfa3ded63ff886483f01ac99bf6efedfa549aba`.
- [ ] Real Provider E2E first authorization is `provisioned` and second same-owner authorization is `reconnected` with no second quota consume.
- [ ] PR #7 remains Draft until those exact-head gates are satisfied.
