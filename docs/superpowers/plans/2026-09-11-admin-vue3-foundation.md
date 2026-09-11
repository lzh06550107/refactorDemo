# Admin Vue 3 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-ready Vue 3 Admin foundation with real IAM username/password login, HttpOnly cookie sessions, CSRF protection, authenticated shell, dashboard, logout, and CI/browser smoke gates.

**Architecture:** The Admin browser UI is a Vue 3 SPA under `frontend/admin`. ThinkPHP maps the external first path segment `admin-api` to the existing `app/admin` application, so `/admin-api/v1/*` is the canonical browser Admin API while `/admin/*` remains available to the SPA/static web server. Authentication/session behavior stays in `modules/iam`; the raw session token exists only in the browser HttpOnly cookie and in memory during issuance, while the database stores only its SHA-256 hash. Existing `/api/v1/*` provider/public routes and R8D semantics remain unchanged.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, MySQL 8.4 acceptance, Vue 3.5.42, TypeScript 7.0.2, Vite 8.2.2, Vue Router 5.3.1, Pinia 4.0.3, Axios 1.20.0, Element Plus 2.14.5, Vitest 5.0.0, Vue Test Utils 2.5.0, jsdom 30.0.1, vue-tsc 3.3.11, Node.js 24.

**Spec:** `docs/superpowers/specs/2026-09-11-frontend-architecture-foundation-design.md`

## Global Constraints

- Admin is a Vue 3 SPA; public Web/H5 remains server-template-first.
- Browser Admin routes live under `/admin`; browser Admin backend endpoints live under `/admin-api/v1/*`.
- `config/app.php` must map `'admin-api' => 'admin'`; `/api/v1/*` remains the provider/public API surface.
- Reuse `admin_users`, `admin_sessions`, `tenant_memberships`; no second authentication database and no schema migration is required by this plan.
- Add separate IAM use cases `AuthenticateAdmin`, `CreateAdminSession`, `LogoutAdminSession`.
- Session cookie: `weplatform_admin_session`; HttpOnly=true; SameSite=Lax; path `/`; Secure=true in production and configurable false only for local HTTP development.
- CSRF cookie: `weplatform_admin_csrf`; HttpOnly=false; SameSite=Lax; path `/`; Secure follows the same environment policy.
- Mutating `/admin-api/v1/*` requests must carry `X-CSRF-Token` exactly matching `weplatform_admin_csrf` using constant-time comparison.
- Raw admin session tokens must not be stored in database, logs, `localStorage`, or `sessionStorage`.
- Password verification uses PHP `password_verify()` only.
- Existing `RestoreAdminSession` remains the authority for restoring an issued session.
- Admin authentication middleware must enrich `RequestContext` with `Principal(userId, 'admin')` instead of creating a second identity carrier.
- Existing R8D offline/PHPUnit/lint/HTTP/MySQL release gates must remain green.
- Phase 1 excludes tenant/account/module/OpenPlatform CRUD screens.

---

## File Structure Map

### IAM domain/application

- Create `modules/iam/domain/AdminCredential.php` — immutable `AdminUser + passwordHash` authentication record.
- Create `modules/iam/domain/IssuedAdminSession.php` — immutable one-time result containing `AdminSession + rawToken`.
- Create `modules/iam/contract/AdminCredentialRepository.php` — credential lookup by username.
- Create `modules/iam/contract/AdminUserRepository.php` — safe administrator profile lookup by id.
- Create `modules/iam/contract/AdminSessionStore.php` — session persistence/revocation write port.
- Create `modules/iam/contract/SessionTokenGenerator.php` — raw session-token generation seam.
- Create `modules/iam/contract/SessionIdGenerator.php` — deterministic session-id generation seam.
- Create `modules/iam/application/AuthenticateAdmin.php`.
- Create `modules/iam/application/CreateAdminSession.php`.
- Create `modules/iam/application/LogoutAdminSession.php`.
- Create infrastructure implementations in `modules/iam/infrastructure/` and secure generators in `modules/iam/security/`.

### Admin HTTP delivery

- Create `app/admin/support/AdminCookiePolicy.php`.
- Create `app/admin/middleware/AdminSessionCookieMiddleware.php`.
- Create `app/admin/middleware/AdminCsrfMiddleware.php`.
- Create `app/admin/controller/V1/AdminAuthController.php`.
- Create `app/admin/controller/V1/DashboardController.php`.
- Modify `app/admin/route/app.php`, `config/app.php`, `config/weplatform.php`, `.env.example`, and `app/AppService.php`.

### Vue SPA

- Create `frontend/admin/package.json`, `package-lock.json`, Vite/TypeScript config, `index.html`.
- Create `frontend/admin/src/main.ts`, `App.vue`, `router/`, `stores/`, `api/`, `layouts/`, `views/`, `styles/`.
- Generated `public/build/admin/` is build output and must not be committed; add it and `frontend/admin/node_modules/` to `.gitignore`.

### Tests/CI

- Create `tests/Contract/AdminFrontendArchitectureContractTest.php`.
- Add focused IAM/Admin unit/component tests and a MySQL acceptance runtime test.
- Extend `.github/workflows/ci.yml` with Node 24 Admin gates without weakening existing PHP gates.

---

### Task 1: RED Admin Frontend Architecture Contract

**Files:**
- Create: `tests/Contract/AdminFrontendArchitectureContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: repository root and existing require-time contract convention.
- Produces: permanent Admin architecture/security gate.

- [ ] **Step 1: Write the failing contract**

Create a require-time static closure that first requires `frontend/admin/package.json`, then asserts dependencies, source directories, canonical `/admin-api` mapping, and absence of browser Web Storage token persistence:

```php
<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);
    $packageFile = $root . '/frontend/admin/package.json';
    if (!is_file($packageFile)) {
        throw new RuntimeException('frontend/admin/package.json is missing');
    }

    $package = json_decode((string) file_get_contents($packageFile), true, 512, JSON_THROW_ON_ERROR);
    foreach (['vue', 'vue-router', 'pinia', 'axios', 'element-plus'] as $dependency) {
        if (!isset($package['dependencies'][$dependency])) {
            throw new RuntimeException("Admin dependency missing: {$dependency}");
        }
    }
    foreach (['vite', 'typescript', 'vitest', 'vue-tsc', '@vitejs/plugin-vue'] as $dependency) {
        if (!isset($package['devDependencies'][$dependency])) {
            throw new RuntimeException("Admin dev dependency missing: {$dependency}");
        }
    }

    foreach (['src', 'src/router', 'src/stores', 'src/api', 'src/views'] as $relative) {
        if (!is_dir($root . '/frontend/admin/' . $relative)) {
            throw new RuntimeException("Admin source directory missing: {$relative}");
        }
    }

    $appConfig = (string) file_get_contents($root . '/config/app.php');
    if (!str_contains($appConfig, "'admin-api' => 'admin'")) {
        throw new RuntimeException('config/app.php must map admin-api to admin');
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/frontend/admin/src'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || preg_match('/\.(ts|vue)$/', $file->getFilename()) !== 1) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach (['localStorage.setItem', 'sessionStorage.setItem'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                throw new RuntimeException('Raw browser storage is forbidden: ' . $file->getPathname());
            }
        }
    }
})();
```

Register it immediately after `AppModulesArchitectureContractTest.php`.

- [ ] **Step 2: Run and prove RED**

```bash
php tests/Contract/AdminFrontendArchitectureContractTest.php
php tests/run.php
```

Expected: first failure is `frontend/admin/package.json is missing`; unrelated tests still execute.

- [ ] **Step 3: Commit only the RED gate**

```bash
git add tests/Contract/AdminFrontendArchitectureContractTest.php tests/run.php
git commit -m "test: define admin frontend architecture contract"
```

---

### Task 2: Vue Toolchain, Canonical Admin API Mapping, and Minimal Router

**Files:**
- Create: `frontend/admin/package.json`
- Create: `frontend/admin/package-lock.json`
- Create: `frontend/admin/vite.config.ts`
- Create: `frontend/admin/tsconfig.json`
- Create: `frontend/admin/tsconfig.app.json`
- Create: `frontend/admin/index.html`
- Create: `frontend/admin/src/main.ts`
- Create: `frontend/admin/src/App.vue`
- Create: `frontend/admin/src/router/index.ts`
- Create: `frontend/admin/src/views/BootstrapView.vue`
- Create: `frontend/admin/src/styles/index.css`
- Modify: `config/app.php`
- Modify: `.gitignore`

**Interfaces:**
- External Admin API prefix: `/admin-api` mapped by ThinkPHP `app_map` to `app/admin`.
- Browser SPA base: `/admin/`.
- Initial Vue route: `/admin` -> `BootstrapView` until auth routes replace it.

- [ ] **Step 1: Create exact `package.json`**

```json
{
  "name": "@weplatform/admin",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "engines": { "node": ">=24 <25" },
  "scripts": {
    "dev": "vite --host 127.0.0.1 --port 5173",
    "typecheck": "vue-tsc --noEmit",
    "test": "vitest run",
    "build": "vue-tsc --noEmit && vite build"
  },
  "dependencies": {
    "axios": "1.20.0",
    "element-plus": "2.14.5",
    "pinia": "4.0.3",
    "vue": "3.5.42",
    "vue-router": "5.3.1"
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "6.0.8",
    "@vue/test-utils": "2.5.0",
    "jsdom": "30.0.1",
    "typescript": "7.0.2",
    "vite": "8.2.2",
    "vitest": "5.0.0",
    "vue-tsc": "3.3.11"
  }
}
```

- [ ] **Step 2: Configure Vite**

Use Vue plugin, `base: '/admin/'`, `build.outDir: '../../public/build/admin'`, `build.emptyOutDir: true`, and Vitest `environment: 'jsdom'`. Proxy `/admin-api` to `http://127.0.0.1:8000` **without rewrite**, because ThinkPHP itself recognizes the `admin-api` first segment through `app_map`.

- [ ] **Step 3: Add canonical ThinkPHP app mapping**

Change:

```php
'app_map' => [],
```

to:

```php
'app_map' => [
    'admin-api' => 'admin',
],
```

This is supported by `think-multi-app`: the first path segment is looked up in `app.app_map`, then removed from request pathinfo before the mapped app route is resolved.

- [ ] **Step 4: Add minimal compiling SPA**

`main.ts` installs Pinia, Router, Element Plus and imports CSS. `router/index.ts` uses `createWebHistory('/admin/')` and an initial route `{ path: '/', name: 'bootstrap', component: BootstrapView }`. `App.vue` renders `<RouterView />`.

- [ ] **Step 5: Ignore generated frontend artifacts**

Append exactly:

```gitignore
frontend/admin/node_modules/
public/build/admin/
```

- [ ] **Step 6: Generate lockfile and verify**

```bash
cd frontend/admin
npm install
npm run typecheck
npm test
npm run build
cd ../..
php tests/Contract/AdminFrontendArchitectureContractTest.php
php tests/run.php
```

Expected: Admin architecture contract GREEN and existing PHP suite GREEN.

- [ ] **Step 7: Commit**

```bash
git add frontend/admin config/app.php .gitignore tests
git commit -m "build: scaffold vue3 admin application"
```

---

### Task 3: IAM Administrator Credential and Profile Read Ports

**Files:**
- Create: `modules/iam/domain/AdminCredential.php`
- Create: `modules/iam/contract/AdminCredentialRepository.php`
- Create: `modules/iam/contract/AdminUserRepository.php`
- Create: `modules/iam/infrastructure/ThinkPhpAdminCredentialRepository.php`
- Create: `modules/iam/infrastructure/ThinkPhpAdminUserRepository.php`
- Create: `modules/iam/application/AuthenticateAdmin.php`
- Create: `tests/Component/Iam/AuthenticateAdminTest.php`
- Create: `tests/Component/Iam/ThinkPhpAdminUserRepositoryTest.php`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**

```php
interface AdminCredentialRepository
{
    public function findByUsername(string $username): ?AdminCredential;
}

interface AdminUserRepository
{
    public function findById(string $id): ?AdminUser;
}

final readonly class AuthenticateAdmin
{
    public function execute(string $username, string $password, DateTimeImmutable $now): AdminUser;
}
```

- [ ] **Step 1: RED credential tests**

Test success, unknown username, wrong password, banned user, expired user, and blank username/password. Every invalid case must produce HTTP-semantic 401 with the same public message `Administrator credentials are invalid.`.

- [ ] **Step 2: Implement `AdminCredential` and repositories**

`AdminCredential` exposes only `user(): AdminUser` and `passwordHash(): string`. Credential repository selects `id, username, password_hash, status, expires_at` by exact username. User repository selects `id, username, status, expires_at` by id and never exposes `password_hash`.

- [ ] **Step 3: Implement `AuthenticateAdmin`**

Normalize username with `trim`, reject blanks, call `password_verify`, then require `AdminUserStatus::ACTIVE` and `!$user->isExpired($now)`. Use existing `AppException`/`ErrorCode::UNAUTHORIZED`.

- [ ] **Step 4: Bind both read ports**

Add bindings in `AppService::register()`:

```php
AdminCredentialRepository::class => ThinkPhpAdminCredentialRepository::class,
AdminUserRepository::class => ThinkPhpAdminUserRepository::class,
```

- [ ] **Step 5: Verify and commit**

```bash
php tests/Component/Iam/AuthenticateAdminTest.php
php tests/Component/Iam/ThinkPhpAdminUserRepositoryTest.php
php tests/run.php
git add modules/iam app/AppService.php tests
git commit -m "feat: authenticate and load admin users"
```

---

### Task 4: Admin Session Issuance and Revocation

**Files:**
- Create: `modules/iam/domain/IssuedAdminSession.php`
- Create: `modules/iam/contract/AdminSessionStore.php`
- Create: `modules/iam/contract/SessionTokenGenerator.php`
- Create: `modules/iam/contract/SessionIdGenerator.php`
- Create: `modules/iam/security/SecureSessionTokenGenerator.php`
- Create: `modules/iam/security/SecureSessionIdGenerator.php`
- Create: `modules/iam/infrastructure/ThinkPhpAdminSessionStore.php`
- Create: `modules/iam/application/CreateAdminSession.php`
- Create: `modules/iam/application/LogoutAdminSession.php`
- Create: `tests/Component/Iam/CreateAdminSessionTest.php`
- Create: `tests/Component/Iam/LogoutAdminSessionTest.php`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**

```php
interface SessionTokenGenerator { public function generate(): string; }
interface SessionIdGenerator { public function generate(): string; }
interface AdminSessionStore
{
    public function save(AdminSession $session): void;
    public function deleteByTokenHash(string $tokenHash): void;
}
```

`CreateAdminSession::execute(AdminUser $user, DateTimeImmutable $now, int $ttlSeconds): IssuedAdminSession` and `LogoutAdminSession::execute(string $rawToken): void`.

- [ ] **Step 1: RED issuance test**

Inject token generator returning `raw-test-token` and id generator returning `session-1`. Assert returned `IssuedAdminSession::rawToken()` equals the one-time raw token, while persisted `AdminSession::tokenHash()` equals `SessionTokenHasher::hash('raw-test-token')` and never equals the raw token. Assert expiry is exactly `$now->modify('+' . $ttlSeconds . ' seconds')`.

- [ ] **Step 2: RED revocation test**

Assert non-empty raw token is hashed once and passed to `deleteByTokenHash`; blank token is an idempotent no-op.

- [ ] **Step 3: Implement secure generators**

Token generator: Base64URL of `random_bytes(32)` with padding removed. Session id generator: `bin2hex(random_bytes(16))`.

- [ ] **Step 4: Implement DB write store**

Insert only `id, admin_user_id, token_hash, issued_at, expires_at, created_at`; never write raw token. Delete only by `token_hash`.

- [ ] **Step 5: Bind ports and verify**

Bind `AdminSessionStore`, `SessionTokenGenerator`, `SessionIdGenerator` in `AppService`. Run:

```bash
php tests/Component/Iam/CreateAdminSessionTest.php
php tests/Component/Iam/LogoutAdminSessionTest.php
php tests/run.php
```

- [ ] **Step 6: Commit**

```bash
git add modules/iam app/AppService.php tests
git commit -m "feat: issue and revoke admin sessions"
```

---

### Task 5: Cookie Policy and Double-Submit CSRF

**Files:**
- Create: `app/admin/support/AdminCookiePolicy.php`
- Create: `app/admin/middleware/AdminCsrfMiddleware.php`
- Create: `tests/Unit/Admin/AdminCookiePolicyTest.php`
- Create: `tests/Component/Admin/AdminCsrfMiddlewareTest.php`
- Modify: `config/weplatform.php`
- Modify: `.env.example`
- Modify: `tests/run.php`

**Interfaces:**

```php
AdminCookiePolicy::sessionName(): string // weplatform_admin_session
AdminCookiePolicy::csrfName(): string    // weplatform_admin_csrf
AdminCookiePolicy::sessionOptions(int $ttlSeconds): array
AdminCookiePolicy::csrfOptions(int $ttlSeconds): array
AdminCsrfMiddleware::handle(Request $request, Closure $next): mixed
```

- [ ] **Step 1: RED cookie tests**

Assert session cookie is HttpOnly=true, CSRF cookie HttpOnly=false, both SameSite=`lax`, path=`/`, expiry follows TTL, Secure flag follows `weplatform.admin_cookie_secure`.

- [ ] **Step 2: RED CSRF tests**

GET/HEAD/OPTIONS pass without token. POST/PUT/PATCH/DELETE reject missing cookie, missing header, blank token, and mismatch with `AppException(ErrorCode::FORBIDDEN, 'CSRF validation failed.', 403)`. Exact match passes.

- [ ] **Step 3: Implement middleware**

Read cookie via `$request->cookie('weplatform_admin_csrf', '')`, header via `$request->header('x-csrf-token', '')`, then use `hash_equals` after non-empty checks.

- [ ] **Step 4: Add exact config**

`config/weplatform.php`:

```php
'admin_cookie_secure' => (bool) env('WEPLATFORM_ADMIN_COOKIE_SECURE', false),
'admin_session_ttl_seconds' => (int) env('WEPLATFORM_ADMIN_SESSION_TTL_SECONDS', 28800),
```

`.env.example`:

```env
WEPLATFORM_ADMIN_COOKIE_SECURE=false
WEPLATFORM_ADMIN_SESSION_TTL_SECONDS=28800
```

Production deployment documentation must set Secure=true under HTTPS.

- [ ] **Step 5: Verify and commit**

```bash
php tests/Unit/Admin/AdminCookiePolicyTest.php
php tests/Component/Admin/AdminCsrfMiddlewareTest.php
php tests/run.php
git add app/admin config/weplatform.php .env.example tests
git commit -m "feat: define admin cookie csrf policy"
```

---

### Task 6: Cookie Session Middleware and RequestContext Principal

**Files:**
- Create: `app/admin/middleware/AdminSessionCookieMiddleware.php`
- Create: `tests/Component/Admin/AdminSessionCookieMiddlewareTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `RestoreAdminSession`, `App`, current `RequestContext`.
- Produces: request-scoped `RequestContext` with `Principal($session->userId(), 'admin')`.

- [ ] **Step 1: RED middleware tests**

Without cookie -> 401 through `RestoreAdminSession`; valid cookie -> downstream closure sees `RequestContext::principal()->id()` equal admin user id and role/type `admin`. Preserve requestId, traceId, locale, clientIp and RuntimeType from the base context.

- [ ] **Step 2: Implement by following the existing OpenPlatform context pattern**

Read only `weplatform_admin_session`; call `RestoreAdminSession` with UTC now; replace the request-scoped `RequestContext` through `$app->instance(RequestContext::class, new RequestContext(...))`. Do not parse Authorization bearer tokens on this browser Admin path.

- [ ] **Step 3: Verify and commit**

```bash
php tests/Component/Admin/AdminSessionCookieMiddlewareTest.php
php tests/run.php
git add app/admin/middleware tests
git commit -m "feat: restore admin session from cookie"
```

---

### Task 7: Admin Auth HTTP API

**Files:**
- Create: `app/admin/controller/V1/AdminAuthController.php`
- Create: `tests/Component/Admin/AdminAuthControllerTest.php`
- Modify: `app/admin/route/app.php`
- Modify: `tests/run.php`

**Interfaces:**

```text
GET  /admin-api/v1/auth/csrf
POST /admin-api/v1/auth/login
GET  /admin-api/v1/auth/me
POST /admin-api/v1/auth/logout
```

Login body: `{ "username": string, "password": string }`. `me` response data: `{ "id": string, "username": string }`.

- [ ] **Step 1: RED endpoint tests**

CSRF endpoint returns 200 and sets a cryptographically random readable `weplatform_admin_csrf` cookie. Login with valid CSRF and credentials returns 200, sets HttpOnly session cookie, never returns raw session token in JSON. `me` without valid session is 401. Logout requires valid session + CSRF, revokes DB session and expires both cookies.

- [ ] **Step 2: Implement CSRF token issuance**

Generate Base64URL `random_bytes(32)` and set only the CSRF cookie; response body may return `{ "ready": true }` but not the token.

- [ ] **Step 3: Implement login**

Sequence:

```text
AuthenticateAdmin
-> CreateAdminSession(ttl from config)
-> cookie(session name, raw token, session options)
-> ApiResponse::success(... user id + username ...)
```

- [ ] **Step 4: Implement `me`**

Read authenticated user id from `RequestContext::principal()`, call `AdminUserRepository::findById`, require active/non-expired user, and return only id + username.

- [ ] **Step 5: Implement logout**

Read raw session cookie, call `LogoutAdminSession`, delete session cookie and CSRF cookie using the same path/security policy, return success.

- [ ] **Step 6: Register explicit route middleware**

In `app/admin/route/app.php` keep `health`. Add group `v1/auth` routes: csrf public; login with `AdminCsrfMiddleware`; me with `AdminSessionCookieMiddleware`; logout with both session and CSRF middleware. Route `v1/dashboard` is added in Task 10.

- [ ] **Step 7: Verify and commit**

```bash
php tests/Component/Admin/AdminAuthControllerTest.php
php tests/run.php
php think route:list
git add app/admin tests
git commit -m "feat: expose admin browser authentication api"
```

---

### Task 8: Vue Axios Client and Pinia Auth Store

**Files:**
- Create: `frontend/admin/src/api/http.ts`
- Create: `frontend/admin/src/api/auth.ts`
- Create: `frontend/admin/src/stores/auth.ts`
- Create: `frontend/admin/src/stores/__tests__/auth.test.ts`

**Interfaces:**

```ts
export type AdminUser = { id: string; username: string }
export async function bootstrapCsrf(): Promise<void>
export async function login(username: string, password: string): Promise<AdminUser>
export async function me(): Promise<AdminUser>
export async function logout(): Promise<void>
```

Auth store state: `user: AdminUser | null`, `status: 'unknown' | 'authenticated' | 'anonymous'`.

- [ ] **Step 1: RED store tests**

Test restore success, restore 401, login success, login failure, logout success. Assert no token value exists in store state and no production source invokes Web Storage setters.

- [ ] **Step 2: Implement Axios client**

```ts
axios.create({ baseURL: '/admin-api/v1', withCredentials: true })
```

For POST/PUT/PATCH/DELETE, parse `document.cookie` for `weplatform_admin_csrf` and set `X-CSRF-Token`. Never read the HttpOnly session cookie.

- [ ] **Step 3: Implement API/store**

`signIn()` calls `bootstrapCsrf()` then login; `restore()` calls me; `signOut()` calls logout and sets memory state anonymous. A 401 from restore means anonymous, not an application crash.

- [ ] **Step 4: Verify and commit**

```bash
cd frontend/admin
npm test -- src/stores/__tests__/auth.test.ts
npm run typecheck
cd ../..
git add frontend/admin/src/api frontend/admin/src/stores
git commit -m "feat: add admin browser auth state"
```

---

### Task 9: Router Guard and Login Page

**Files:**
- Create: `frontend/admin/src/router/guards.ts`
- Create: `frontend/admin/src/views/LoginView.vue`
- Create: `frontend/admin/src/views/NotFoundView.vue`
- Create: `frontend/admin/src/router/__tests__/guards.test.ts`
- Create: `frontend/admin/src/views/__tests__/LoginView.test.ts`
- Modify: `frontend/admin/src/router/index.ts`
- Delete: `frontend/admin/src/views/BootstrapView.vue`

**Interfaces:**
- `/admin/login` public.
- `/admin` protected.
- catch-all Admin route -> `NotFoundView`.
- protected routes use meta `requiresAuth: true`.

- [ ] **Step 1: RED guard tests**

Anonymous protected navigation -> `/admin/login?redirect=/admin...`; authenticated login navigation -> `/admin`; unknown state calls `auth.restore()` exactly once before deciding.

- [ ] **Step 2: RED login-view tests**

Require username/password; submit calls store `signIn`; invalid credentials show generic `用户名或密码错误`; success redirects to validated internal Admin path only. Reject `https://...`, `//host/...`, and paths not beginning `/admin`.

- [ ] **Step 3: Implement router and login UI**

Use Element Plus form/input/button/card. Do not add CAPTCHA in this foundation slice. Preserve a loading state to prevent duplicate submissions.

- [ ] **Step 4: Verify and commit**

```bash
cd frontend/admin
npm test -- src/router/__tests__/guards.test.ts src/views/__tests__/LoginView.test.ts
npm run typecheck
npm run build
cd ../..
git add frontend/admin
git commit -m "feat: add admin login route guard"
```

---

### Task 10: Authenticated Layout and Minimal Dashboard

**Files:**
- Create: `app/admin/controller/V1/DashboardController.php`
- Create: `tests/Component/Admin/DashboardControllerTest.php`
- Create: `frontend/admin/src/api/dashboard.ts`
- Create: `frontend/admin/src/layouts/AdminLayout.vue`
- Create: `frontend/admin/src/views/DashboardView.vue`
- Create: `frontend/admin/src/views/__tests__/DashboardView.test.ts`
- Modify: `app/admin/route/app.php`
- Modify: `frontend/admin/src/router/index.ts`
- Modify: `tests/run.php`

**Interfaces:**

`GET /admin-api/v1/dashboard` requires `AdminSessionCookieMiddleware` and returns:

```json
{
  "application": "admin",
  "status": "ready",
  "admin_user_id": "<authenticated-id>"
}
```

No cross-domain counts are introduced in Phase 1.

- [ ] **Step 1: RED backend dashboard test**

Unauthenticated request fails 401; authenticated controller reads principal from `RequestContext` and returns exactly application/status/admin_user_id.

- [ ] **Step 2: Implement route/controller**

No direct DB access. Controller is a delivery health/landing summary only.

- [ ] **Step 3: RED Vue layout/dashboard tests**

Layout renders platform name, Dashboard menu item, current username, logout button and `<RouterView>`. Dashboard fetches summary and renders `后台运行正常` for `status=ready`; loading/error states are deterministic.

- [ ] **Step 4: Implement layout/dashboard**

Use `ElContainer`, `ElAside`, `ElHeader`, `ElMain`, `ElMenu`, `ElCard`. Only Dashboard is an active menu route. Future feature labels may be rendered as non-clickable text, not fake routes.

- [ ] **Step 5: Verify and commit**

```bash
php tests/Component/Admin/DashboardControllerTest.php
php tests/run.php
cd frontend/admin
npm test
npm run typecheck
npm run build
cd ../..
git add app/admin frontend/admin tests
git commit -m "feat: add admin shell dashboard"
```

---

### Task 11: CI and HTTP Smoke

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Contract/AdminFrontendArchitectureContractTest.php`

**Interfaces:**
- Existing PHP `test` and MySQL `release` jobs remain mandatory.
- Admin frontend build/test becomes part of `test` job.

- [ ] **Step 1: Add Node setup**

After checkout, add `actions/setup-node@v4` with `node-version: '24'` and npm cache dependency path `frontend/admin/package-lock.json`.

- [ ] **Step 2: Add deterministic Admin commands**

```bash
npm ci --prefix frontend/admin
npm run typecheck --prefix frontend/admin
npm test --prefix frontend/admin
npm run build --prefix frontend/admin
```

- [ ] **Step 3: Extend ThinkPHP smoke**

Keep existing `/health`, `/admin/health`, `/api/v1/health`, R8D provider ingress checks. Add:

```text
GET /admin-api/v1/auth/csrf -> 200 and Set-Cookie contains weplatform_admin_csrf
GET /admin-api/v1/auth/me   -> 401 without session cookie
```

No hard-coded administrator password is added to the fast CI job.

- [ ] **Step 4: Run complete local non-DB gates**

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
php think list
```

Expected: all GREEN.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml tests/Contract/AdminFrontendArchitectureContractTest.php
git commit -m "ci: gate admin vue3 application"
```

---

### Task 12: MySQL Browser Authentication Runtime Acceptance

**Files:**
- Create: `tests/Acceptance/AdminBrowserAuthRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`

**Interfaces:**
- Disposable DB only: `weplatform_acceptance`.
- Uses real HTTP against local ThinkPHP acceptance server and real `admin_users/admin_sessions` tables.

- [ ] **Step 1: Create administrator fixture**

After migrations insert one active admin with a runtime-generated `password_hash('acceptance-password', PASSWORD_DEFAULT)`. The test must never print the password, raw session cookie, or CSRF value.

- [ ] **Step 2: Exercise exact lifecycle**

```text
GET /admin-api/v1/auth/csrf
-> capture CSRF cookie
POST /admin-api/v1/auth/login with CSRF header/cookie
-> capture HttpOnly session cookie
-> assert exactly one DB session and token_hash != raw cookie
GET /admin-api/v1/auth/me with session cookie -> 200
GET /admin-api/v1/dashboard with session cookie -> 200
POST /admin-api/v1/auth/logout with session + CSRF -> 200
GET /admin-api/v1/auth/me with old session -> 401
-> assert DB session row removed
```

- [ ] **Step 3: Run release gate**

```bash
WEPLATFORM_ACCEPTANCE=1 \
DATABASE_HOSTNAME=127.0.0.1 \
DATABASE_DATABASE=weplatform_acceptance \
DATABASE_USERNAME=root \
DATABASE_PASSWORD="$DATABASE_PASSWORD" \
DATABASE_HOSTPORT=3306 \
php tests/Release/run.php
```

Expected: existing R8D runtime gates plus `AdminBrowserAuthRuntimeTest` GREEN and final `[PASS] Local release gate`.

- [ ] **Step 4: Commit**

```bash
git add tests/Acceptance
git commit -m "test: verify admin browser authentication runtime"
```

---

### Task 13: Exact-HEAD Verification and Manual Browser Acceptance

**Files:**
- No planned source changes.

**Interfaces:**
- Produces final Admin foundation evidence before Web/H5 implementation starts.

- [ ] **Step 1: Verify clean source and no schema drift**

```bash
git status --short
git rev-parse HEAD
git diff 8c0d565f4bbe97952949af43c79e4c826f748cb6 -- database/migrations
```

Expected: clean worktree and empty migration diff.

- [ ] **Step 2: Fresh security scans**

Search production frontend source for `localStorage.setItem`, `sessionStorage.setItem`, `weplatform_admin_session` value logging, and password literals. Expected: no token persistence/logging and no production password fixtures.

- [ ] **Step 3: Re-run all gates fresh**

Run Task 11 non-DB gates and Task 12 MySQL Release Gate from the final exact HEAD. Do not reuse an earlier green run.

- [ ] **Step 4: Manual browser flow**

Terminal A:

```bash
php think run -p 8000
```

Terminal B:

```bash
npm run dev --prefix frontend/admin
```

Open `http://127.0.0.1:5173/admin/login`; verify valid login -> `/admin`, refresh stays authenticated, Dashboard loads, logout returns to login, and direct protected-route access while logged out redirects to login.

- [ ] **Step 5: Push exact HEAD and inspect CI**

Push `refactor/frontend-foundation-v1`. Require the PR-triggered CI associated with that exact commit to show the PHP test job and MySQL release job GREEN before declaring the Admin foundation complete.
