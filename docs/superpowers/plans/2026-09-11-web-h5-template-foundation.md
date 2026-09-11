# Web/H5 Template Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first production Web/H5 delivery foundation using ThinkPHP server-side delivery, trusted HTML theme templates, modern JavaScript ES modules, Vite-built assets, safe filesystem theme resolution, and at least one complete server-rendered page.

**Architecture:** `app/web` owns public HTTP routes and prepares delivery inputs; business data continues to come from `modules/*`. `modules/theme` owns theme-package resolution and safe rendering, reusing the existing `SafeThemeRenderer` and `ViewContract` instead of introducing a second unsafe template engine. Trusted theme files live under `themes/<theme-key>`. The browser receives complete HTML before JavaScript; plain JavaScript progressively enhances interaction. No Nuxt, no site-wide Vue SPA, and no Node.js production runtime.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, existing `modules/theme` rendering primitives, HTML5, CSS, JavaScript ES modules, Vite 8.2.2, Vitest 5.0.0, jsdom 30.0.1, Node.js 24.

**Spec:** `docs/superpowers/specs/2026-09-11-frontend-architecture-foundation-design.md`

**Execution dependency:** Execute `docs/superpowers/plans/2026-09-11-admin-vue3-foundation.md` first. This plan assumes the frontend branch already has Node 24 CI setup and `.gitignore` entries for Admin artifacts; it adds Web-specific entries and gates without changing Admin behavior.

## Global Constraints

- Public Web/H5 is server-template-first; no Nuxt/Next/site-wide SPA runtime.
- `app/web` is the public delivery entry; business rules remain in `modules/*`.
- Themes live under `themes/<theme-key>` and cannot query database/repository infrastructure directly.
- Theme keys use the existing `ThemeDefinition` rule `^[A-Za-z0-9_-]+$`.
- Complete HTML must render with JavaScript disabled.
- JavaScript uses ES modules and progressive enhancement only.
- Vite is build-time/development tooling; production requires only static built assets plus ThinkPHP/PHP-FPM.
- Generated `public/build/web/` and `frontend/web/node_modules/` are not committed.
- Vite manifest path is exactly `public/build/web/manifest.json`.
- Untrusted scalar text is escaped through existing `SafeThemeRenderer`; rich HTML input is not accepted in this foundation slice.
- Existing `SafeThemeRenderer` forbidden-directive behavior must not be weakened.
- User-controlled values must never become PHP/JavaScript source or filesystem paths.
- Existing R8D and Admin gates remain green.

---

## File Structure Map

### Web assets

- Create `frontend/web/package.json`, `package-lock.json`, `vite.config.js`.
- Create `frontend/web/src/js/main.js`, `src/js/components/navigation.js`, `src/css/main.css`.
- Create `frontend/web/src/js/__tests__/navigation.test.js`.
- Modify `.gitignore` for Web node_modules/build output.

### Theme package

- Create `themes/corporate/theme.json`.
- Create `themes/corporate/layouts/default.html`.
- Create `themes/corporate/pages/index.html`.
- Create `themes/corporate/components/header.html`, `footer.html`.

### Theme runtime

- Create `modules/theme/domain/ThemeManifest.php`.
- Create `modules/theme/domain/ResolvedThemePage.php`.
- Create `modules/theme/domain/ThemePageNotFound.php`.
- Create `modules/theme/contract/ThemePackageRepository.php`.
- Create `modules/theme/infrastructure/FilesystemThemePackageRepository.php`.
- Create `modules/theme/rendering/ThemePageRenderer.php`.
- Create `modules/theme/application/RenderThemePage.php`.
- Modify `app/AppService.php` for theme package binding.

### Web delivery

- Create `app/web/support/WebAssetManifest.php`.
- Create `app/web/controller/HomeController.php`.
- Modify `app/web/route/app.php`, `config/weplatform.php`, `.env.example`, and `app/AppService.php` for asset configuration/binding.

### Tests/CI

- Create `tests/Contract/WebFrontendArchitectureContractTest.php` and focused Theme/Web tests.
- Extend `.github/workflows/ci.yml` with Web npm/test/build and HTML smoke.

---

### Task 1: RED Web/H5 Architecture Contract

**Files:**
- Create: `tests/Contract/WebFrontendArchitectureContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: repository root.
- Produces: permanent server-template-first architecture gate.

- [ ] **Step 1: Write failing contract**

```php
<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);
    foreach ([
        'frontend/web/package.json',
        'themes/corporate/theme.json',
        'themes/corporate/layouts/default.html',
        'themes/corporate/pages/index.html',
        'themes/corporate/components/header.html',
        'themes/corporate/components/footer.html',
    ] as $relative) {
        if (!is_file($root . '/' . $relative)) {
            throw new RuntimeException("Web frontend foundation missing: {$relative}");
        }
    }

    $package = json_decode(
        (string) file_get_contents($root . '/frontend/web/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    foreach (['vue', 'nuxt', 'react', 'next'] as $forbiddenDependency) {
        if (isset($package['dependencies'][$forbiddenDependency])) {
            throw new RuntimeException("Web foundation runtime dependency is forbidden: {$forbiddenDependency}");
        }
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes'));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach (['<?', 'think\\facade\\Db', 'Db::', 'Repository::class'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                throw new RuntimeException('Theme contains forbidden executable/persistence access: ' . $file->getPathname());
            }
        }
    }
})();
```

Register immediately after `AdminFrontendArchitectureContractTest.php` in `tests/run.php`.

- [ ] **Step 2: Prove RED**

```bash
php tests/Contract/WebFrontendArchitectureContractTest.php
php tests/run.php
```

Expected: first failure says `frontend/web/package.json` is missing; unrelated tests continue.

- [ ] **Step 3: Commit RED gate only**

```bash
git add tests/Contract/WebFrontendArchitectureContractTest.php tests/run.php
git commit -m "test: define web template architecture contract"
```

---

### Task 2: Vanilla JavaScript/CSS Vite Pipeline

**Files:**
- Create: `frontend/web/package.json`
- Create: `frontend/web/package-lock.json`
- Create: `frontend/web/vite.config.js`
- Create: `frontend/web/src/js/main.js`
- Create: `frontend/web/src/js/components/navigation.js`
- Create: `frontend/web/src/css/main.css`
- Create: `frontend/web/src/js/__tests__/navigation.test.js`
- Modify: `.gitignore`

**Interfaces:**
- Vite entry: `src/js/main.js`.
- Build output: `public/build/web/`.
- Manifest: `public/build/web/manifest.json`.
- JS API: `initNavigation(root = document): void`.

- [ ] **Step 1: Create exact package manifest**

```json
{
  "name": "@weplatform/web-assets",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "engines": { "node": ">=24 <25" },
  "scripts": {
    "dev": "vite --host 127.0.0.1 --port 5174",
    "test": "vitest run",
    "build": "vite build"
  },
  "devDependencies": {
    "jsdom": "30.0.1",
    "vite": "8.2.2",
    "vitest": "5.0.0"
  }
}
```

- [ ] **Step 2: Configure Vite deterministically**

`vite.config.js`:

```js
import { defineConfig } from 'vite'
import { resolve } from 'node:path'

export default defineConfig({
  base: '/build/web/',
  server: { host: '127.0.0.1', port: 5174, cors: true },
  test: { environment: 'jsdom' },
  build: {
    outDir: resolve(process.cwd(), '../../public/build/web'),
    emptyOutDir: true,
    manifest: 'manifest.json',
    rolldownOptions: {
      input: resolve(process.cwd(), 'src/js/main.js'),
    },
  },
})
```

- [ ] **Step 3: RED progressive-navigation test**

Build DOM with `[data-nav-toggle]` and `[data-nav-panel]`. Assert `initNavigation(document)` sets initial `aria-expanded=false`, click toggles to true and unhides panel, second click reverses it.

- [ ] **Step 4: Implement minimal progressive enhancement**

`main.js` imports `../css/main.css` and `initNavigation`; on DOMContentLoaded call `initNavigation(document)`. No Vue/jQuery dependency.

- [ ] **Step 5: Ignore generated artifacts**

Append:

```gitignore
frontend/web/node_modules/
public/build/web/
```

- [ ] **Step 6: Install/test/build**

```bash
cd frontend/web
npm install
npm test
npm run build
cd ../..
test -f public/build/web/manifest.json
```

Expected: GREEN; manifest exists only as generated output.

- [ ] **Step 7: Commit source and lockfile, not build output**

```bash
git add frontend/web .gitignore
git commit -m "build: add web vite asset pipeline"
```

---

### Task 3: First Trusted Corporate Theme

**Files:**
- Create: `themes/corporate/theme.json`
- Create: `themes/corporate/layouts/default.html`
- Create: `themes/corporate/pages/index.html`
- Create: `themes/corporate/components/header.html`
- Create: `themes/corporate/components/footer.html`

**Interfaces:**
- Theme key: `corporate`.
- Page key: `index`.
- Trusted fragment tokens: `@@HEADER_HTML@@`, `@@CONTENT_HTML@@`, `@@FOOTER_HTML@@`.

- [ ] **Step 1: Create exact manifest**

```json
{
  "key": "corporate",
  "name": "Corporate",
  "version": "1.0.0",
  "layout": "layouts/default.html",
  "pages": {
    "index": "pages/index.html"
  },
  "components": {
    "header": "components/header.html",
    "footer": "components/footer.html"
  }
}
```

- [ ] **Step 2: Create layout**

`default.html` contains complete HTML document and only these scalar placeholders:

```text
{{ page_title }}
{{ page_description }}
{{ asset_css_url }}
{{ asset_js_url }}
```

It contains exact trusted fragment tokens `@@HEADER_HTML@@`, `@@CONTENT_HTML@@`, `@@FOOTER_HTML@@`. CSS link is `<link rel="stylesheet" href="{{ asset_css_url }}">`; JS is `<script type="module" src="{{ asset_js_url }}"></script>`.

- [ ] **Step 3: Create header/page/footer templates**

Header uses `{{ site_title }}` and semantic navigation with `data-nav-toggle`/`data-nav-panel`. Index page uses `{{ site_title }}`, `{{ page_title }}`, `{{ page_description }}`. Footer uses `{{ site_title }}`. No PHP tags or ThinkPHP directives.

- [ ] **Step 4: Verify architecture contract progresses**

```bash
php tests/Contract/WebFrontendArchitectureContractTest.php
```

Expected: file/dependency/theme-source checks pass; later runtime checks may still fail until subsequent tasks.

- [ ] **Step 5: Commit**

```bash
git add themes
git commit -m "feat: add corporate web theme skeleton"
```

---

### Task 4: Theme Manifest and Safe Filesystem Resolution

**Files:**
- Create: `modules/theme/domain/ThemeManifest.php`
- Create: `modules/theme/domain/ResolvedThemePage.php`
- Create: `modules/theme/domain/ThemePageNotFound.php`
- Create: `modules/theme/contract/ThemePackageRepository.php`
- Create: `modules/theme/infrastructure/FilesystemThemePackageRepository.php`
- Create: `tests/Unit/Theme/ThemeManifestTest.php`
- Create: `tests/Component/Theme/FilesystemThemePackageRepositoryTest.php`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**

```php
interface ThemePackageRepository
{
    public function resolve(string $themeKey, string $pageKey): ResolvedThemePage;
}
```

`ResolvedThemePage` exposes `manifest(): ThemeManifest`, `layoutTemplate(): string`, `pageTemplate(): string`, `headerTemplate(): string`, `footerTemplate(): string`.

- [ ] **Step 1: RED manifest tests**

Reject malformed key, manifest key mismatch, missing `name/version/layout/pages/components`, missing `index`, missing header/footer, absolute paths, `..` traversal, NUL bytes, and non-string path values.

- [ ] **Step 2: Implement manifest value object**

Use the same key regex as existing `ThemeDefinition`. Page/component keys use `^[A-Za-z0-9_-]+$`. Template paths are normalized forward-slash relative paths and may not start `/` or contain `..` segments.

- [ ] **Step 3: RED filesystem tests**

Cover valid `corporate/index`, unknown theme -> `ThemePageNotFound`, unknown page -> `ThemePageNotFound`, symlink/path escape -> rejection, missing declared file -> invalid package error.

- [ ] **Step 4: Implement filesystem repository**

Constructor receives absolute themes root. Resolve root with `realpath`; theme directory must exist directly below root. Every declared template gets `realpath`, and the resolved file path must begin with `$themeRoot . DIRECTORY_SEPARATOR` before reading.

- [ ] **Step 5: Bind exact root in AppService**

Factory:

```php
$this->app->bind(ThemePackageRepository::class, function (): ThemePackageRepository {
    return new FilesystemThemePackageRepository($this->app->getRootPath() . 'themes');
});
```

- [ ] **Step 6: Verify and commit**

```bash
php tests/Unit/Theme/ThemeManifestTest.php
php tests/Component/Theme/FilesystemThemePackageRepositoryTest.php
php tests/run.php
git add modules/theme app/AppService.php tests
git commit -m "feat: resolve trusted theme packages"
```

---

### Task 5: Safe Theme Page Composition

**Files:**
- Create: `modules/theme/rendering/ThemePageRenderer.php`
- Create: `modules/theme/application/RenderThemePage.php`
- Create: `tests/Component/Theme/ThemePageRendererTest.php`
- Create: `tests/Component/Theme/RenderThemePageTest.php`
- Modify: `tests/run.php`

**Interfaces:**

```php
final class ThemePageRenderer
{
    public function render(ResolvedThemePage $page, array $viewModel): string;
}

final readonly class RenderThemePage
{
    public function execute(string $themeKey, string $pageKey, array $viewModel): string;
}
```

Required view-model scalar fields: `site_title`, `page_title`, `page_description`, `asset_css_url`, `asset_js_url`.

- [ ] **Step 1: RED escaping tests**

Pass `<script>alert(1)</script>` in every user-derived scalar field and assert no executable script appears. Existing forbidden directives (`<?`, `{php`, `{hook`, `{template`, `{data`, `{if`, `{elseif`, `{else}`, `{loop`) must still fail through `SafeThemeRenderer`.

- [ ] **Step 2: Implement fragment rendering**

Render fragments separately with `SafeThemeRenderer`:

```text
header contract: site_title
page contract: site_title, page_title, page_description
footer contract: site_title
```

These outputs are trusted only because they are produced from trusted repository templates plus escaped scalar values.

- [ ] **Step 3: Render scalar layout safely**

Render layout with `SafeThemeRenderer` contract:

```text
page_title, page_description, asset_css_url, asset_js_url
```

The `@@...@@` fragment tokens remain untouched because they are not `{{...}}` placeholders.

- [ ] **Step 4: Insert only exact trusted tokens**

After scalar rendering, replace exactly three tokens with pre-rendered fragment strings. If any token is missing or remains after replacement, throw an invalid-theme exception. Do not add a generic `raw` placeholder feature to `SafeThemeRenderer`.

- [ ] **Step 5: Implement application use case**

`RenderThemePage` resolves the theme package through `ThemePackageRepository`, then delegates to `ThemePageRenderer`.

- [ ] **Step 6: Verify and commit**

```bash
php tests/Component/Theme/ThemePageRendererTest.php
php tests/Component/Theme/RenderThemePageTest.php
php tests/Unit/Theme/SafeThemeRendererTest.php
php tests/run.php
git add modules/theme tests
git commit -m "feat: compose safe server rendered theme pages"
```

---

### Task 6: Deterministic Vite Asset Manifest Resolver

**Files:**
- Create: `app/web/support/WebAssetManifest.php`
- Create: `tests/Unit/Web/WebAssetManifestTest.php`
- Modify: `config/weplatform.php`
- Modify: `.env.example`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**

```php
WebAssetManifest::forEntry(string $entry): array{js:string,css:string}
```

- Production entry: `src/js/main.js` from `public/build/web/manifest.json`.
- Development JS URL: `http://127.0.0.1:5174/src/js/main.js` by default.
- Development CSS URL: `http://127.0.0.1:5174/src/css/main.css` by default.

- [ ] **Step 1: RED production-manifest tests**

Fixture:

```json
{
  "src/js/main.js": {
    "file": "assets/main-abc123.js",
    "src": "src/js/main.js",
    "isEntry": true,
    "css": ["assets/main-def456.css"]
  }
}
```

Assert result is `{js: '/build/web/assets/main-abc123.js', css: '/build/web/assets/main-def456.css'}`. Reject missing entry, missing `file`, missing CSS, absolute asset path, and `..` traversal.

- [ ] **Step 2: RED development-mode test**

With dev mode true and origin `http://127.0.0.1:5174`, return direct JS/CSS source URLs and do not require manifest file.

- [ ] **Step 3: Implement resolver**

Constructor receives `manifestPath`, `devMode`, `devOrigin`. Production manifest is decoded once per instance. Asset paths must remain relative and are prefixed with `/build/web/`.

- [ ] **Step 4: Add exact configuration**

`config/weplatform.php`:

```php
'web_assets_dev' => (bool) env('WEPLATFORM_WEB_ASSETS_DEV', false),
'web_assets_dev_origin' => (string) env('WEPLATFORM_WEB_ASSETS_DEV_ORIGIN', 'http://127.0.0.1:5174'),
```

`.env.example`:

```env
WEPLATFORM_WEB_ASSETS_DEV=false
WEPLATFORM_WEB_ASSETS_DEV_ORIGIN=http://127.0.0.1:5174
```

- [ ] **Step 5: Bind resolver in AppService**

Use manifest path `$this->app->getRootPath() . 'public/build/web/manifest.json'` and the exact config values above.

- [ ] **Step 6: Verify and commit**

```bash
php tests/Unit/Web/WebAssetManifestTest.php
php tests/run.php
git add app/web/support config/weplatform.php .env.example app/AppService.php tests
git commit -m "feat: resolve web vite assets"
```

---

### Task 7: First Complete Server-Rendered Home Page

**Files:**
- Create: `app/web/controller/HomeController.php`
- Create: `tests/Component/Web/HomeControllerTest.php`
- Modify: `app/web/route/app.php`
- Modify: `tests/run.php`

**Interfaces:**
- `GET /` -> HTML 200.
- Existing `GET /health` remains JSON 200.

- [ ] **Step 1: RED controller test**

Assert controller output starts with `<!doctype html>`, contains escaped page/site text, includes CSS and module JS URLs from `WebAssetManifest`, and never requires browser JavaScript to reveal the Hero text.

- [ ] **Step 2: Implement foundation controller**

Use:

```php
$assets = $this->assets->forEntry('src/js/main.js');
$html = $this->renderThemePage->execute('corporate', 'index', [
    'site_title' => 'WePlatform',
    'page_title' => 'WePlatform',
    'page_description' => 'ThinkPHP 8 Web/H5 theme runtime',
    'asset_css_url' => $assets['css'],
    'asset_js_url' => $assets['js'],
]);
return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
```

This bootstrap content is deliberately static; later Site/Theme vertical slices replace it with domain-selected site/theme data.

- [ ] **Step 3: Register root route**

Keep:

```php
Route::get('health', 'HealthController/index');
```

and add:

```php
Route::get('/', 'HomeController/index');
```

- [ ] **Step 4: Build assets and verify runtime**

```bash
npm ci --prefix frontend/web
npm run build --prefix frontend/web
php tests/Component/Web/HomeControllerTest.php
php tests/run.php
```

Then start `php think run -p 18080` and verify `/` returns 200 HTML while `/health` remains 200 JSON.

- [ ] **Step 5: Commit**

```bash
git add app/web tests
git commit -m "feat: serve first web theme page"
```

---

### Task 8: Controlled Theme/Page Failure Mapping

**Files:**
- Create: `tests/Component/Web/WebThemeNotFoundTest.php`
- Modify: `modules/theme/domain/ThemePageNotFound.php`
- Modify: `modules/theme/infrastructure/FilesystemThemePackageRepository.php`
- Modify: `app/web/controller/HomeController.php` only if controller-level mapping is the established project convention; otherwise modify the central exception mapping used by `ExceptionHandle`.

**Interfaces:**
- Unknown theme/page -> controlled 404.
- Corrupt trusted theme package/rendering error -> controlled 500.
- Public responses contain no absolute filesystem paths or stack traces.

- [ ] **Step 1: RED not-found/error tests**

Test unknown theme, unknown page, missing declared template, and invalid template directive. Assert only unknown theme/page are 404; corrupt package/directive errors are 500.

- [ ] **Step 2: Implement explicit exception types/mapping**

`ThemePageNotFound` is the only not-found condition from theme lookup. Invalid manifest/path/template conditions remain invalid-package/render errors and map to 500 through the existing exception envelope/handler.

- [ ] **Step 3: Verify and commit**

```bash
php tests/Component/Web/WebThemeNotFoundTest.php
php tests/run.php
git add modules/theme app/web tests
git commit -m "feat: map web theme failures safely"
```

---

### Task 9: Web/H5 CI and HTML Smoke

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Contract/WebFrontendArchitectureContractTest.php`

**Interfaces:**
- Final CI `test` job runs both Admin and Web Node gates plus all existing PHP gates.

- [ ] **Step 1: Expand existing Node cache**

Keep Node version `24`; set `cache-dependency-path` to a multiline value containing both:

```text
frontend/admin/package-lock.json
frontend/web/package-lock.json
```

- [ ] **Step 2: Add exact Web commands**

```bash
npm ci --prefix frontend/web
npm test --prefix frontend/web
npm run build --prefix frontend/web
```

Run Web build before starting the PHP HTTP smoke so the production-mode manifest exists.

- [ ] **Step 3: Extend HTTP smoke**

Keep every existing R8D/Admin check. Add request `/` and assert:

```text
HTTP 200
Content-Type includes text/html
body includes <!doctype html>
body includes /build/web/assets/
body includes ThinkPHP 8 Web/H5 theme runtime
```

- [ ] **Step 4: Run full local gates**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app modules config tests -name '*.php' -print0 | xargs -0 -n1 php -l
npm ci --prefix frontend/admin
npm run typecheck --prefix frontend/admin
npm test --prefix frontend/admin
npm run build --prefix frontend/admin
npm ci --prefix frontend/web
npm test --prefix frontend/web
npm run build --prefix frontend/web
```

Expected: all GREEN.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml tests/Contract/WebFrontendArchitectureContractTest.php
git commit -m "ci: gate server rendered web frontend"
```

---

### Task 10: Exact-HEAD Web/H5 Verification

**Files:**
- No planned source changes.

**Interfaces:**
- Produces final combined frontend-foundation evidence.

- [ ] **Step 1: Verify repository boundaries**

Search `themes/` for PHP tags, `Db::`, repository class references and provider secrets. Search `frontend/web/package.json` for Vue/Nuxt/React/Next runtime dependencies. Expected: no violations.

- [ ] **Step 2: Verify generated output is untracked**

```bash
git status --short
```

After builds, `public/build/admin`, `public/build/web`, and both node_modules directories must not appear in Git status.

- [ ] **Step 3: Fresh complete regression**

Run Task 9 local gates plus `php tests/Release/run.php` against disposable MySQL acceptance configuration. Fresh results are required; do not reuse Admin-plan evidence.

- [ ] **Step 4: Manual no-JavaScript acceptance**

Build Web assets and run ThinkPHP on port 8000. Open `http://127.0.0.1:8000/` with browser JavaScript disabled and confirm title/Hero/content are visible. Re-enable JavaScript and confirm the responsive navigation toggle works.

- [ ] **Step 5: Exact-head CI**

Push `refactor/frontend-foundation-v1`; require PR-triggered CI for the exact final commit SHA to show PHP tests, Admin frontend gates, Web frontend gates, HTTP smoke, and MySQL release gate GREEN before declaring the combined frontend foundation complete.
