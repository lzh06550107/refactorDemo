# Web/H5 Template Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first production Web/H5 delivery foundation using ThinkPHP server-side delivery, HTML theme templates, modern JavaScript ES modules, Vite-built assets, safe theme resolution, and at least one complete SSR page.

**Architecture:** `app/web` owns public HTTP routes and obtains prepared view data from `modules/*`; `modules/theme` owns theme/template resolution and safe rendering boundaries; trusted theme files live under `themes/<theme-key>`. The browser receives complete HTML first, then plain JavaScript progressively enhances interaction. Vue is not a site-wide runtime; local Vue islands are allowed only in later approved complex components.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, existing `modules/theme` rendering primitives, HTML5, CSS, JavaScript ES modules, Vite 8.2.2, Vitest 5.0.0, Node.js 24.

**Spec:** `docs/superpowers/specs/2026-09-11-frontend-architecture-foundation-design.md`

## Global Constraints

- Public Web/H5 remains server-template-first; no Nuxt/Next/site-wide SPA runtime.
- `app/web` is the public delivery entry; business rules stay in `modules/*`.
- Themes live under `themes/<theme-key>` and cannot query infrastructure repositories directly.
- Theme keys follow the existing `ThemeDefinition` rule `^[A-Za-z0-9_-]+$`.
- Complete HTML must be returned without waiting for browser JavaScript.
- JavaScript uses ES modules and progressive enhancement.
- Vite is build-time tooling only; production does not require a Node.js runtime.
- User-controlled values must not be interpolated into PHP or JavaScript source.
- Untrusted text is escaped by default; rich HTML requires an explicit sanitization policy and is outside this foundation slice.
- Existing `SafeThemeRenderer` and `ViewContract` security behavior must not be weakened.
- Existing R8D and Admin PHP gates must remain green.

---

## File Structure Map

- Create `frontend/web/package.json`, `package-lock.json`, `vite.config.js` — Vanilla JS/CSS asset pipeline.
- Create `frontend/web/src/js/main.js`, `src/js/components/navigation.js`, `src/css/main.css`.
- Create `frontend/web/src/js/__tests__/navigation.test.js`.
- Create `themes/corporate/theme.json` — first trusted theme manifest.
- Create `themes/corporate/layouts/default.html` — HTML document shell.
- Create `themes/corporate/pages/index.html` — first page body template.
- Create `themes/corporate/components/header.html`, `footer.html`.
- Create `modules/theme/domain/ThemeManifest.php` — validated manifest metadata.
- Create `modules/theme/domain/ResolvedThemePage.php` — immutable resolved template bundle.
- Create `modules/theme/contract/ThemePackageRepository.php` — theme package lookup boundary.
- Create `modules/theme/infrastructure/FilesystemThemePackageRepository.php` — safe filesystem implementation rooted at `/themes`.
- Create `modules/theme/rendering/ThemePageRenderer.php` — compose layout/components/page then delegate escaping to `SafeThemeRenderer`.
- Create `modules/theme/application/RenderThemePage.php` — application use case.
- Create `app/web/controller/HomeController.php` — public root controller.
- Modify `app/web/route/app.php` — map `/` while keeping `/health`.
- Create `app/web/support/WebAssetManifest.php` — resolve Vite hashed assets with dev fallback rules.
- Create `tests/Contract/WebFrontendArchitectureContractTest.php` and focused unit/component tests.
- Modify `.github/workflows/ci.yml` — Node 24 web asset install/test/build and HTML smoke.

---

### Task 1: RED Web Frontend Architecture Contract

**Files:**
- Create: `tests/Contract/WebFrontendArchitectureContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: repo root and require-time contract convention.
- Produces: permanent gate for server-template-first Web/H5 architecture.

- [ ] **Step 1: Write failing contract**

Use a static closure and assert:

```php
(static function (): void {
    $root = dirname(__DIR__, 2);
    foreach ([
        'frontend/web/package.json',
        'themes/corporate/theme.json',
        'themes/corporate/layouts/default.html',
        'themes/corporate/pages/index.html',
    ] as $path) {
        if (!is_file($root . '/' . $path)) {
            throw new RuntimeException("Web frontend foundation missing: {$path}");
        }
    }

    $package = json_decode(
        (string) file_get_contents($root . '/frontend/web/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    if (isset($package['dependencies']['vue']) || isset($package['dependencies']['nuxt'])) {
        throw new RuntimeException('Web/H5 foundation must not be a site-wide Vue/Nuxt runtime.');
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes'));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach (['think\\facade\\Db', 'Db::', 'Repository::class'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                throw new RuntimeException('Theme must not access persistence directly: ' . $file->getPathname());
            }
        }
    }
})();
```

Register immediately after `AdminFrontendArchitectureContractTest.php`.

- [ ] **Step 2: Verify RED**

```bash
php tests/Contract/WebFrontendArchitectureContractTest.php
php tests/run.php
```

Expected: fail because `frontend/web/package.json` is absent; unrelated tests continue.

- [ ] **Step 3: Commit RED gate**

```bash
git add tests/Contract/WebFrontendArchitectureContractTest.php tests/run.php
git commit -m "test: define web template architecture contract"
```

---

### Task 2: Vanilla Web Vite Asset Pipeline

**Files:**
- Create: `frontend/web/package.json`
- Create: `frontend/web/package-lock.json`
- Create: `frontend/web/vite.config.js`
- Create: `frontend/web/src/js/main.js`
- Create: `frontend/web/src/js/components/navigation.js`
- Create: `frontend/web/src/css/main.css`
- Create: `frontend/web/src/js/__tests__/navigation.test.js`

**Interfaces:**
- Produces `public/build/web/manifest.json` and hashed JS/CSS assets.
- Produces `initNavigation(root = document): void`.

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

- [ ] **Step 2: Configure Vite**

`vite.config.js` must use `frontend/web/src/js/main.js` as the build entry, output to `../../public/build/web`, enable `manifest: true`, and use `base: '/build/web/'`. Vitest uses jsdom.

- [ ] **Step 3: Write RED navigation test**

Create DOM with a button carrying `data-nav-toggle` and target with `data-nav-panel`; call `initNavigation(document)` and assert click toggles `aria-expanded` and panel hidden state.

- [ ] **Step 4: Implement minimal progressive enhancement**

`main.js` imports CSS and `initNavigation`; run it on `DOMContentLoaded`. No framework dependency.

- [ ] **Step 5: Install/build/test**

```bash
cd frontend/web
npm install
npm test
npm run build
cd ../..
test -f public/build/web/.vite/manifest.json || test -f public/build/web/manifest.json
```

Expected: GREEN with hashed assets.

- [ ] **Step 6: Commit**

```bash
git add frontend/web public/build/web
git commit -m "build: add web vite asset pipeline"
```

If repository policy excludes generated build output, keep `public/build/web` ignored and change the architecture contract/CI to require the build result at runtime rather than committing it; make this decision once and encode it in `.gitignore` and the contract.

---

### Task 3: First Trusted Theme Package

**Files:**
- Create: `themes/corporate/theme.json`
- Create: `themes/corporate/layouts/default.html`
- Create: `themes/corporate/pages/index.html`
- Create: `themes/corporate/components/header.html`
- Create: `themes/corporate/components/footer.html`

**Interfaces:**
- Theme key: `corporate`.
- Manifest fields: `key`, `name`, `version`, `layout`, `pages`.
- Foundation page contract fields: `site_title`, `page_title`, `page_description`, `asset_css`, `asset_js`, `header_html`, `content_html`, `footer_html`.

- [ ] **Step 1: Define manifest**

```json
{
  "key": "corporate",
  "name": "Corporate",
  "version": "1.0.0",
  "layout": "layouts/default.html",
  "pages": {
    "index": "pages/index.html"
  }
}
```

- [ ] **Step 2: Create complete HTML layout**

The layout contains `<!doctype html>`, `<html lang="zh-CN">`, UTF-8 charset, responsive viewport, escaped `{{ page_title }}`/`{{ page_description }}`, asset placeholders, and body placeholders for header/content/footer.

- [ ] **Step 3: Create first page/components**

Header contains site title and a responsive navigation toggle. Index body contains a Hero section with `{{ site_title }}` and `{{ page_description }}`. Footer contains a static platform-neutral copyright region; no database calls or PHP directives.

- [ ] **Step 4: Contract verification**

```bash
php tests/Contract/WebFrontendArchitectureContractTest.php
```

Expected: contract progresses beyond file existence and no persistence violations are found.

- [ ] **Step 5: Commit**

```bash
git add themes tests/Contract/WebFrontendArchitectureContractTest.php
git commit -m "feat: add corporate web theme skeleton"
```

---

### Task 4: Filesystem Theme Package Resolution

**Files:**
- Create: `modules/theme/domain/ThemeManifest.php`
- Create: `modules/theme/domain/ResolvedThemePage.php`
- Create: `modules/theme/contract/ThemePackageRepository.php`
- Create: `modules/theme/infrastructure/FilesystemThemePackageRepository.php`
- Create: `tests/Unit/Theme/ThemeManifestTest.php`
- Create: `tests/Component/Theme/FilesystemThemePackageRepositoryTest.php`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**
- `ThemePackageRepository::resolve(string $themeKey, string $pageKey): ResolvedThemePage`.
- `ThemeManifest::fromArray(array $data): ThemeManifest`.
- `ResolvedThemePage` returns `layoutTemplate()`, `pageTemplate()`, `componentTemplate(string $name)` and manifest metadata.

- [ ] **Step 1: RED validation tests**

Reject invalid theme keys (`../x`, `/absolute`, `x\\y`), manifest key mismatch, missing layout, missing page mapping, paths leaving the package root, and unreadable files.

- [ ] **Step 2: Implement manifest validation**

Apply the same theme-key regex already used by `ThemeDefinition`. Manifest template paths must be relative, contain no NUL bytes, and after `realpath` must start with the resolved theme root plus directory separator.

- [ ] **Step 3: Implement filesystem repository**

Constructor receives the absolute themes root. `resolve('corporate', 'index')` loads only `themes/corporate/theme.json` and paths declared in it. Never concatenate unvalidated page/template paths directly from request input.

- [ ] **Step 4: Bind in AppService**

Bind `ThemePackageRepository::class` to a factory creating `FilesystemThemePackageRepository(root_path() . 'themes')` or the repository's canonical project-root equivalent.

- [ ] **Step 5: Verify GREEN**

```bash
php tests/Unit/Theme/ThemeManifestTest.php
php tests/Component/Theme/FilesystemThemePackageRepositoryTest.php
php tests/run.php
```

- [ ] **Step 6: Commit**

```bash
git add modules/theme app/AppService.php tests
git commit -m "feat: resolve trusted theme packages"
```

---

### Task 5: Compose Theme Page with Existing Safe Renderer

**Files:**
- Create: `modules/theme/rendering/ThemePageRenderer.php`
- Create: `modules/theme/application/RenderThemePage.php`
- Create: `tests/Component/Theme/ThemePageRendererTest.php`
- Create: `tests/Component/Theme/RenderThemePageTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- `ThemePageRenderer::render(ResolvedThemePage $page, array $viewModel): string`.
- `RenderThemePage::execute(string $themeKey, string $pageKey, array $viewModel): string`.

- [ ] **Step 1: RED escaping/security tests**

Pass `site_title` as `<script>alert(1)</script>` and assert the output contains escaped text, not executable script. Assert templates containing forbidden `<?`, `{php`, `{hook}`, `{template}`, `{data}`, `{if}`, `{loop}` continue to fail through `SafeThemeRenderer`.

- [ ] **Step 2: Define composition order**

Render header/footer/page fragments first with their own explicit `ViewContract`s, then insert those trusted rendered HTML fragments into the layout without re-escaping them. Do not generalize `SafeThemeRenderer` to allow arbitrary raw fields. `ThemePageRenderer` must own the distinction between escaped scalar inputs and internally-produced trusted fragments.

- [ ] **Step 3: Implement renderer**

The foundation contracts are fixed:

```text
header:  site_title
page:    site_title, page_title, page_description
footer:  site_title
layout:  page_title, page_description, asset_css, asset_js + trusted header/content/footer fragments
```

Implement a small internal placeholder substitution for the three trusted fragments only after all user-derived scalar values have gone through `SafeThemeRenderer`.

- [ ] **Step 4: Implement application use case**

`RenderThemePage` resolves package through `ThemePackageRepository`, then delegates to `ThemePageRenderer`.

- [ ] **Step 5: Verify**

```bash
php tests/Component/Theme/ThemePageRendererTest.php
php tests/Component/Theme/RenderThemePageTest.php
php tests/run.php
```

Expected: GREEN and existing `SafeThemeRendererTest.php` still GREEN.

- [ ] **Step 6: Commit**

```bash
git add modules/theme tests
git commit -m "feat: compose safe server rendered theme pages"
```

---

### Task 6: Vite Asset Manifest Resolver

**Files:**
- Create: `app/web/support/WebAssetManifest.php`
- Create: `tests/Unit/Web/WebAssetManifestTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- `WebAssetManifest::forEntry(string $entry): array{js:string,css:list<string>}`.
- Production manifest path points to Vite output under `public/build/web`.

- [ ] **Step 1: RED manifest tests**

Fixture manifest maps `src/js/main.js` to a hashed JS file and CSS list. Assert returned public paths begin `/build/web/`. Missing manifest/entry throws a controlled runtime exception without leaking filesystem paths to HTTP clients.

- [ ] **Step 2: Implement resolver**

Support Vite 8 manifest location discovered from the actual Task 2 build (`.vite/manifest.json` if present). Cache decoded manifest in-memory per request/process instance. Validate referenced file names are relative and remain beneath `/build/web/`.

- [ ] **Step 3: Verify and commit**

```bash
php tests/Unit/Web/WebAssetManifestTest.php
php tests/run.php
git add app/web/support tests
git commit -m "feat: resolve web vite assets"
```

---

### Task 7: Public Home Route Returns Complete HTML

**Files:**
- Create: `app/web/controller/HomeController.php`
- Create: `tests/Component/Web/HomeControllerTest.php`
- Modify: `app/web/route/app.php`
- Modify: `tests/run.php`

**Interfaces:**
- `GET /` -> HTML 200.
- Existing `GET /health` remains JSON 200.

- [ ] **Step 1: RED controller test**

Instantiate controller with fake `RenderThemePage`/asset resolver dependencies or their interfaces and assert content type is HTML, output starts with `<!doctype html>`, contains escaped title/content, and includes resolved CSS/JS asset paths.

- [ ] **Step 2: Implement controller**

Use foundation data only:

```php
[
    'site_title' => 'WePlatform',
    'page_title' => 'WePlatform',
    'page_description' => 'ThinkPHP 8 Web/H5 theme runtime',
]
```

Theme key is `corporate`, page key is `index`. This is a bootstrap page, not a replacement for later site-domain resolution.

- [ ] **Step 3: Register root route**

Add `Route::get('/', 'HomeController/index');` or the ThinkPHP multi-app syntax proven by runtime tests. Keep `health` unchanged.

- [ ] **Step 4: Verify**

```bash
php tests/Component/Web/HomeControllerTest.php
php tests/run.php
php think run -p 18080
```

In another shell:

```bash
curl -fsS http://127.0.0.1:18080/ | grep '<!doctype html>'
curl -fsS http://127.0.0.1:18080/health
```

Expected: root HTML and health JSON both work.

- [ ] **Step 5: Commit**

```bash
git add app/web themes tests
git commit -m "feat: serve first web theme page"
```

---

### Task 8: Controlled Missing Theme/Page Handling

**Files:**
- Create: `modules/theme/domain/ThemePageNotFound.php` or use the project-standard typed application exception if that is already the established boundary.
- Modify: `modules/theme/infrastructure/FilesystemThemePackageRepository.php`
- Modify: `app/web/controller/HomeController.php` only if HTTP mapping belongs there; otherwise use central exception mapping.
- Create: `tests/Component/Web/WebThemeNotFoundTest.php`

**Interfaces:**
- Missing theme or page maps to controlled HTTP 404.
- Internal template/render corruption maps to controlled HTTP 500 and server-side structured logging.

- [ ] **Step 1: RED missing-theme test**

Assert `resolve('missing-theme', 'index')` produces the typed not-found condition and public HTTP response does not include absolute paths or stack traces.

- [ ] **Step 2: Implement typed failure mapping**

Differentiate not-found from invalid/corrupt trusted package. Do not turn every rendering failure into 404.

- [ ] **Step 3: Verify and commit**

```bash
php tests/Component/Web/WebThemeNotFoundTest.php
php tests/run.php
git add modules/theme app/web tests
git commit -m "feat: handle missing web themes safely"
```

---

### Task 9: Web/H5 CI Gates

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Contract/WebFrontendArchitectureContractTest.php`

**Interfaces:**
- CI independently proves Vanilla web assets and full HTML runtime.

- [ ] **Step 1: Reuse Node 24 setup**

If Admin plan already added Node setup, reuse the same setup-node step and add npm cache dependency path for both `frontend/admin/package-lock.json` and `frontend/web/package-lock.json`. Do not add a second competing Node version.

- [ ] **Step 2: Add Web asset gates**

```bash
npm ci --prefix frontend/web
npm test --prefix frontend/web
npm run build --prefix frontend/web
```

- [ ] **Step 3: Extend HTTP smoke**

After starting ThinkPHP, request `/` and assert status 200, `Content-Type` contains `text/html`, response contains `<!doctype html>` and `/build/web/`, while existing `/health`, `/admin/health`, `/api/v1/health` checks remain unchanged.

- [ ] **Step 4: Run complete local gates**

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

### Task 10: Final Web/H5 Foundation Verification

**Files:**
- Modify only if fresh verification exposes a real defect.

**Interfaces:**
- Produces exact-head evidence for the combined frontend foundation branch.

- [ ] **Step 1: Security/static scans**

Confirm themes contain no PHP opening tags, ThinkPHP Db access, repository instantiation, or provider secrets. Confirm `frontend/web/package.json` has no Vue/Nuxt runtime dependency.

- [ ] **Step 2: Fresh full regression**

Run the complete PHP + Admin + Web gates from Task 9 and the MySQL Release Gate. Do not reuse prior green logs as completion evidence.

- [ ] **Step 3: Manual browser acceptance**

Start ThinkPHP on port 8000. Build Web assets. Open `http://127.0.0.1:8000/` and confirm a styled Corporate home page renders with JavaScript disabled; re-enable JavaScript and confirm responsive navigation enhancement works.

- [ ] **Step 4: Exact-head CI**

Push the branch, inspect CI for the exact SHA, and require PHP tests, Admin frontend gates, Web asset gates, HTTP smoke, and MySQL Release Gate to be GREEN before calling the combined frontend foundation complete.
