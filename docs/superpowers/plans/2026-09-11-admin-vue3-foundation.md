# Admin Vue 3 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-ready Vue 3 Admin foundation with real IAM username/password login, HttpOnly cookie sessions, CSRF protection, authenticated shell, dashboard, logout, and CI/browser smoke gates.

**Architecture:** The Admin browser UI is a Vue 3 SPA under `frontend/admin`; all Admin business requests use `/admin-api/v1/*` through `app/admin`, which delegates authentication/session behavior to `modules/iam`. The raw session token is transported only in an HttpOnly cookie and is never stored in Web Storage or the database; the database stores only the SHA-256 token hash. Existing `/api/v1/*` provider/public routes and R8D behavior remain unchanged.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, MySQL 8.4 acceptance, Vue 3.5.42, TypeScript 7.0.2, Vite 8.2.2, Vue Router 5.3.1, Pinia 4.0.3, Axios 1.20.0, Element Plus 2.14.5, Vitest 5.0.0, Vue Test Utils 2.5.0, jsdom 30.0.1, vue-tsc 3.3.11, Node.js 24.

**Spec:** `docs/superpowers/specs/2026-09-11-frontend-architecture-foundation-design.md`

## Global Constraints

- Admin is a Vue 3 SPA; public Web/H5 remains server-template-first.
- Admin browser routes live under `/admin`; Admin backend endpoints live under `/admin-api/v1/*`.
- `/api/v1/*` remains reserved for public/provider/API use and must not become the default Admin API surface.
- Reuse `admin_users`, `admin_sessions`, `tenant_memberships`; do not create a second authentication database.
- Add `AuthenticateAdmin`, `CreateAdminSession`, and `LogoutAdminSession` as separate IAM application use cases.
- Session cookie name: `weplatform_admin_session`; HttpOnly; SameSite=Lax; Secure in production; path `/`.
- CSRF cookie name: `weplatform_admin_csrf`; readable by JavaScript; SameSite=Lax; Secure in production; path `/`.
- Mutating `/admin-api/v1/*` requests send header `X-CSRF-Token` equal to the CSRF cookie value.
- The raw admin session token must never be persisted in database, logs, localStorage, or sessionStorage.
- Password verification uses PHP `password_verify()`; no custom password cryptography.
- Existing `RestoreAdminSession` remains the restoration authority.
- Existing R8D PHP/offline/HTTP/MySQL gates must remain green.
- No tenant/account/module/OpenPlatform CRUD UI in this foundation phase.

---

## File Structure Map

### Backend IAM

- Create `modules/iam/domain/AdminCredential.php` — couples an `AdminUser` with its password hash for authentication only.
- Create `modules/iam/domain/IssuedAdminSession.php` — application result carrying the new `AdminSession` plus one-time raw token.
- Create `modules/iam/contract/AdminCredentialRepository.php` — load credential by username.
- Create `modules/iam/contract/AdminSessionStore.php` — persist/revoke sessions without changing the existing read repository contract.
- Create `modules/iam/contract/SessionTokenGenerator.php` — deterministic seam for tests.
- Create `modules/iam/infrastructure/ThinkPhpAdminCredentialRepository.php`.
- Create `modules/iam/infrastructure/ThinkPhpAdminSessionStore.php`.
- Create `modules/iam/security/SecureSessionTokenGenerator.php`.
- Create `modules/iam/application/AuthenticateAdmin.php`.
- Create `modules/iam/application/CreateAdminSession.php`.
- Create `modules/iam/application/LogoutAdminSession.php`.

### Admin HTTP

- Create `app/admin/controller/V1/AdminAuthController.php` — csrf/login/me/logout endpoints.
- Create `app/admin/controller/V1/DashboardController.php` — minimal authenticated dashboard payload.
- Create `app/admin/middleware/AdminSessionCookieMiddleware.php` — restore session from cookie and attach authenticated admin metadata to the request.
- Create `app/admin/middleware/AdminCsrfMiddleware.php` — enforce double-submit CSRF for mutating Admin API requests.
- Create `app/admin/support/AdminCookiePolicy.php` — central cookie names/options.
- Modify `app/admin/route/app.php` — register `/v1/auth/*` and `/v1/dashboard` relative to the admin app.
- Modify `app/AppService.php` — bind new IAM contracts/implementations.
- Modify `config/weplatform.php` and `.env.example` — Admin cookie/session lifetime and production secure-cookie configuration.

### Admin Vue SPA

- Create `frontend/admin/package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `tsconfig.app.json`, `index.html`.
- Create `frontend/admin/src/main.ts`, `App.vue`.
- Create `frontend/admin/src/api/http.ts`, `auth.ts`, `dashboard.ts`.
- Create `frontend/admin/src/stores/auth.ts`.
- Create `frontend/admin/src/router/index.ts`, `guards.ts`.
- Create `frontend/admin/src/layouts/AdminLayout.vue`.
- Create `frontend/admin/src/views/LoginView.vue`, `DashboardView.vue`, `NotFoundView.vue`.
- Create `frontend/admin/src/styles/index.css`.
- Create focused Vitest test files under `frontend/admin/src/**/__tests__/`.

### Tests / CI

- Create backend unit/component tests under `tests/Unit/Iam`, `tests/Component/Iam`, `tests/Component/Admin`.
- Create `tests/Contract/AdminFrontendArchitectureContractTest.php` and register it in `tests/run.php`.
- Modify `.github/workflows/ci.yml` to add Node 24 + `npm ci`, typecheck, Vitest, and Admin build while preserving all existing PHP gates.

---

### Task 1: RED Admin Frontend Architecture Contract

**Files:**
- Create: `tests/Contract/AdminFrontendArchitectureContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: repository root and existing contract-runner convention.
- Produces: a permanent architecture gate that later tasks must satisfy.

- [ ] **Step 1: Write the failing contract**

Create a require-time static closure that asserts:

```php
<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);
    $package = $root . '/frontend/admin/package.json';
    if (!is_file($package)) {
        throw new RuntimeException('frontend/admin/package.json is missing');
    }

    $json = json_decode((string) file_get_contents($package), true, 512, JSON_THROW_ON_ERROR);
    foreach (['vue', 'vue-router', 'pinia', 'axios', 'element-plus'] as $dependency) {
        if (!isset($json['dependencies'][$dependency])) {
            throw new RuntimeException("Admin dependency missing: {$dependency}");
        }
    }
    foreach (['vite', 'typescript', 'vitest', 'vue-tsc', '@vitejs/plugin-vue'] as $dependency) {
        if (!isset($json['devDependencies'][$dependency])) {
            throw new RuntimeException("Admin dev dependency missing: {$dependency}");
        }
    }

    foreach (['frontend/admin/src', 'frontend/admin/src/router', 'frontend/admin/src/stores'] as $path) {
        if (!is_dir($root . '/' . $path)) {
            throw new RuntimeException("Admin source directory missing: {$path}");
        }
    }

    $forbidden = ['localStorage.setItem', 'sessionStorage.setItem'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/frontend/admin/src'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !preg_match('/\.(ts|vue)$/', $file->getFilename())) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach ($forbidden as $needle) {
            if (str_contains($source, $needle)) {
                throw new RuntimeException('Raw browser storage is forbidden: ' . $file->getPathname());
            }
        }
    }
})();
```

Register it immediately after `AppModulesArchitectureContractTest.php` in `tests/run.php`.

- [ ] **Step 2: Run to verify RED**

Run:

```bash
php tests/Contract/AdminFrontendArchitectureContractTest.php
php tests/run.php
```

Expected: the new contract fails with `frontend/admin/package.json is missing`; existing unrelated tests continue to execute.

- [ ] **Step 3: Commit only the RED gate**

```bash
git add tests/Contract/AdminFrontendArchitectureContractTest.php tests/run.php
git commit -m "test: define admin frontend architecture contract"
```

---

### Task 2: Vue 3 Admin Toolchain Skeleton

**Files:**
- Create: `frontend/admin/package.json`
- Create: `frontend/admin/package-lock.json`
- Create: `frontend/admin/vite.config.ts`
- Create: `frontend/admin/tsconfig.json`
- Create: `frontend/admin/tsconfig.app.json`
- Create: `frontend/admin/index.html`
- Create: `frontend/admin/src/main.ts`
- Create: `frontend/admin/src/App.vue`
- Create: `frontend/admin/src/styles/index.css`

**Interfaces:**
- Consumes: `/admin-api` backend base path.
- Produces: reproducible SPA dev/build/test foundation.

- [ ] **Step 1: Create exact package manifest**

Use:

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

`vite.config.ts` must set `base: '/admin/'`, output to `../../public/build/admin`, empty the target directory, enable Vue plugin, configure Vitest `environment: 'jsdom'`, and proxy `/admin-api` to `http://127.0.0.1:8000` with `changeOrigin: true`.

- [ ] **Step 3: Add minimal app bootstrap**

`main.ts` installs Pinia, router placeholder, Element Plus, and imports `styles/index.css`; `App.vue` renders `<router-view />`.

- [ ] **Step 4: Install lockfile and run gates**

```bash
cd frontend/admin
npm install
npm run typecheck
npm test
npm run build
cd ../..
php tests/Contract/AdminFrontendArchitectureContractTest.php
```

Expected: typecheck/build pass; architecture contract now progresses past package/source checks.

- [ ] **Step 5: Commit**

```bash
git add frontend/admin tests/Contract/AdminFrontendArchitectureContractTest.php
git commit -m "build: scaffold vue3 admin application"
```

---

### Task 3: IAM Credential Authentication

**Files:**
- Create: `modules/iam/domain/AdminCredential.php`
- Create: `modules/iam/contract/AdminCredentialRepository.php`
- Create: `modules/iam/infrastructure/ThinkPhpAdminCredentialRepository.php`
- Create: `modules/iam/application/AuthenticateAdmin.php`
- Create: `tests/Component/Iam/AuthenticateAdminTest.php`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces: `AuthenticateAdmin::execute(string $username, string $password, DateTimeImmutable $now): AdminUser`.
- Produces: `AdminCredentialRepository::findByUsername(string $username): ?AdminCredential`.

- [ ] **Step 1: Write failing tests**

Cover valid credentials, missing user, wrong password, banned user, and expired user. Tests must assert all invalid credential cases return the same 401 message so username existence is not disclosed.

Representative test seam:

```php
$credential = new AdminCredential(
    new AdminUser('admin-1', 'root', AdminUserStatus::ACTIVE, null),
    password_hash('correct-password', PASSWORD_DEFAULT),
);
$service = new AuthenticateAdmin(new InMemoryAdminCredentialRepository($credential));
$user = $service->execute('root', 'correct-password', new DateTimeImmutable('2026-09-11T00:00:00Z'));
assert($user->id() === 'admin-1');
```

- [ ] **Step 2: Verify RED**

```bash
php tests/Component/Iam/AuthenticateAdminTest.php
```

Expected: FAIL because `AuthenticateAdmin`/contracts do not exist.

- [ ] **Step 3: Implement minimal domain/repository/service**

`AdminCredential` exposes `user(): AdminUser` and `passwordHash(): string`; repository selects only one `admin_users` row by exact username. `AuthenticateAdmin` trims username, calls `password_verify`, rejects non-active or expired users using `AppException(ErrorCode::UNAUTHORIZED, 'Administrator credentials are invalid.', 401)`.

- [ ] **Step 4: Bind repository and verify GREEN**

Add:

```php
AdminCredentialRepository::class => ThinkPhpAdminCredentialRepository::class,
```

Run:

```bash
php tests/Component/Iam/AuthenticateAdminTest.php
php tests/run.php
```

Expected: both GREEN.

- [ ] **Step 5: Commit**

```bash
git add modules/iam app/AppService.php tests
git commit -m "feat: authenticate admin credentials"
```

---

### Task 4: Create and Revoke Admin Sessions

**Files:**
- Create: `modules/iam/domain/IssuedAdminSession.php`
- Create: `modules/iam/contract/AdminSessionStore.php`
- Create: `modules/iam/contract/SessionTokenGenerator.php`
- Create: `modules/iam/security/SecureSessionTokenGenerator.php`
- Create: `modules/iam/infrastructure/ThinkPhpAdminSessionStore.php`
- Create: `modules/iam/application/CreateAdminSession.php`
- Create: `modules/iam/application/LogoutAdminSession.php`
- Create: `tests/Component/Iam/CreateAdminSessionTest.php`
- Create: `tests/Component/Iam/LogoutAdminSessionTest.php`
- Modify: `app/AppService.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces: `SessionTokenGenerator::generate(): string`.
- Produces: `AdminSessionStore::save(AdminSession $session): void`.
- Produces: `AdminSessionStore::deleteByTokenHash(string $tokenHash): void`.
- Produces: `CreateAdminSession::execute(AdminUser $user, DateTimeImmutable $now, int $ttlSeconds): IssuedAdminSession`.
- Produces: `LogoutAdminSession::execute(string $rawToken): void`.

- [ ] **Step 1: RED tests for token secrecy and expiry**

Use deterministic token generator returning `raw-test-token`. Assert created session stores `SessionTokenHasher::hash('raw-test-token')`, never the raw token, and `expiresAt = now + ttlSeconds`.

- [ ] **Step 2: RED logout test**

Assert logout hashes the provided raw token and calls `deleteByTokenHash()` exactly once; empty token is idempotent and does not throw.

- [ ] **Step 3: Implement secure generator**

Generate 32 random bytes and Base64URL encode without padding:

```php
$r = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
```

- [ ] **Step 4: Implement persistence**

`ThinkPhpAdminSessionStore::save()` inserts into `admin_sessions`; `deleteByTokenHash()` deletes only the matching hash. Generate a session id with `bin2hex(random_bytes(16))` inside `CreateAdminSession` or an injected ID generator if tests require deterministic IDs.

- [ ] **Step 5: Bind and verify**

```bash
php tests/Component/Iam/CreateAdminSessionTest.php
php tests/Component/Iam/LogoutAdminSessionTest.php
php tests/run.php
```

Expected: GREEN.

- [ ] **Step 6: Commit**

```bash
git add modules/iam app/AppService.php tests
git commit -m "feat: manage admin browser sessions"
```

---

### Task 5: Admin Cookie Policy and CSRF

**Files:**
- Create: `app/admin/support/AdminCookiePolicy.php`
- Create: `app/admin/middleware/AdminCsrfMiddleware.php`
- Create: `tests/Unit/Admin/AdminCookiePolicyTest.php`
- Create: `tests/Component/Admin/AdminCsrfMiddlewareTest.php`
- Modify: `config/weplatform.php`
- Modify: `.env.example`
- Modify: `tests/run.php`

**Interfaces:**
- Produces: `AdminCookiePolicy::sessionName(): string` => `weplatform_admin_session`.
- Produces: `AdminCookiePolicy::csrfName(): string` => `weplatform_admin_csrf`.
- Produces: `AdminCookiePolicy::sessionOptions(int $ttlSeconds): array`.
- Produces: `AdminCsrfMiddleware::handle(Request $request, Closure $next): mixed`.

- [ ] **Step 1: RED cookie-policy test**

Assert local config can set Secure=false, production config requires Secure=true, HttpOnly is true for session cookie and false for CSRF cookie, SameSite is `lax`, path is `/`.

- [ ] **Step 2: RED CSRF tests**

For `POST/PUT/PATCH/DELETE`, missing/mismatched cookie/header must return/throw 403. For GET/HEAD, middleware passes through. Matching `weplatform_admin_csrf` cookie and `X-CSRF-Token` header passes.

- [ ] **Step 3: Implement policy and middleware**

Use `hash_equals($cookieToken, $headerToken)` after rejecting empty values. Do not use session token as the CSRF token.

- [ ] **Step 4: Add config**

Add config keys sourced from environment:

```php
'admin_cookie_secure' => (bool) env('WEPLATFORM_ADMIN_COOKIE_SECURE', false),
'admin_session_ttl_seconds' => (int) env('WEPLATFORM_ADMIN_SESSION_TTL_SECONDS', 28800),
```

Document matching values in `.env.example`.

- [ ] **Step 5: Verify and commit**

```bash
php tests/Unit/Admin/AdminCookiePolicyTest.php
php tests/Component/Admin/AdminCsrfMiddlewareTest.php
php tests/run.php
git add app/admin config/weplatform.php .env.example tests
git commit -m "feat: protect admin cookies with csrf"
```

---

### Task 6: Admin Auth HTTP Endpoints

**Files:**
- Create: `app/admin/controller/V1/AdminAuthController.php`
- Create: `app/admin/middleware/AdminSessionCookieMiddleware.php`
- Create: `tests/Component/Admin/AdminAuthControllerTest.php`
- Create: `tests/Component/Admin/AdminSessionCookieMiddlewareTest.php`
- Modify: `app/admin/route/app.php`
- Modify: `tests/run.php`

**Interfaces:**
- `GET /admin/v1/auth/csrf` when using ThinkPHP multi-app direct path; production proxy exposes it as `/admin-api/v1/auth/csrf`.
- `POST /admin-api/v1/auth/login` body `{ "username": string, "password": string }`.
- `GET /admin-api/v1/auth/me` returns `{ id, username }` for valid session.
- `POST /admin-api/v1/auth/logout` revokes the session and expires cookies.

- [ ] **Step 1: RED endpoint tests**

Assert csrf endpoint sets a random readable CSRF cookie; login requires matching CSRF token, returns 200 for valid credentials, sets HttpOnly session cookie, and never returns the raw token in JSON. `me` returns 401 without session cookie. Logout clears/revokes session.

- [ ] **Step 2: Implement session middleware**

Read only `weplatform_admin_session`, call `RestoreAdminSession::execute(rawToken, now)`, and store authenticated session/user id in request attributes. Do not parse Authorization bearer token for the browser Admin path.

- [ ] **Step 3: Implement controller**

Login sequence must be:

```text
AuthenticateAdmin -> CreateAdminSession -> set session cookie -> success JSON
```

Logout sequence:

```text
read cookie -> LogoutAdminSession -> expire session cookie -> rotate/expire csrf cookie -> success JSON
```

- [ ] **Step 4: Register routes with explicit middleware**

`csrf` is public; `login` uses CSRF only; `me` uses session middleware; `logout` uses session + CSRF. Keep existing `health` route unchanged.

- [ ] **Step 5: Run focused/full tests**

```bash
php tests/Component/Admin/AdminAuthControllerTest.php
php tests/Component/Admin/AdminSessionCookieMiddlewareTest.php
php tests/run.php
```

Expected: GREEN.

- [ ] **Step 6: Commit**

```bash
git add app/admin tests
git commit -m "feat: expose admin browser auth api"
```

---

### Task 7: Vue HTTP Client and Auth Store

**Files:**
- Create: `frontend/admin/src/api/http.ts`
- Create: `frontend/admin/src/api/auth.ts`
- Create: `frontend/admin/src/stores/auth.ts`
- Create: `frontend/admin/src/stores/__tests__/auth.test.ts`

**Interfaces:**
- `bootstrapCsrf(): Promise<void>`.
- `login(username: string, password: string): Promise<AdminUser>`.
- `me(): Promise<AdminUser>`.
- `logout(): Promise<void>`.
- `useAuthStore()` state: `user: AdminUser | null`, `status: 'unknown'|'authenticated'|'anonymous'`.

- [ ] **Step 1: RED auth-store tests**

Test successful restore, 401 restore -> anonymous, login -> authenticated, logout -> anonymous. Assert no test or source uses `localStorage.setItem`/`sessionStorage.setItem`.

- [ ] **Step 2: Implement Axios client**

Set:

```ts
axios.create({ baseURL: '/admin-api/v1', withCredentials: true })
```

Request interceptor reads `weplatform_admin_csrf` cookie and adds `X-CSRF-Token` only for mutating methods. Response interceptor maps 401 into a typed auth error but must not redirect directly; the store/router owns navigation.

- [ ] **Step 3: Implement store**

`restore()` calls `/auth/me`; `signIn()` first ensures CSRF bootstrap then posts login; `signOut()` posts logout and always clears in-memory user state after the request completes or returns 401.

- [ ] **Step 4: Verify**

```bash
cd frontend/admin
npm test -- src/stores/__tests__/auth.test.ts
npm run typecheck
cd ../..
```

Expected: GREEN.

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/src/api frontend/admin/src/stores
git commit -m "feat: add admin auth client state"
```

---

### Task 8: Vue Router Guard and Login View

**Files:**
- Create: `frontend/admin/src/router/index.ts`
- Create: `frontend/admin/src/router/guards.ts`
- Create: `frontend/admin/src/views/LoginView.vue`
- Create: `frontend/admin/src/views/NotFoundView.vue`
- Create: `frontend/admin/src/router/__tests__/guards.test.ts`
- Create: `frontend/admin/src/views/__tests__/LoginView.test.ts`
- Modify: `frontend/admin/src/main.ts`

**Interfaces:**
- Browser routes: `/admin/login`, `/admin`, `/admin/:pathMatch(.*)*`.
- Route meta: `requiresAuth: boolean`.

- [ ] **Step 1: RED route-guard tests**

Assert anonymous access to protected route redirects to `/admin/login`; authenticated user visiting login redirects to `/admin`; unknown state calls `auth.restore()` once before deciding.

- [ ] **Step 2: RED login component tests**

Assert username/password required; submit calls `signIn`; invalid credentials show a generic error; successful login navigates to `/admin` or validated internal `redirect` query.

- [ ] **Step 3: Implement router/guard/login**

Never accept an external absolute URL as post-login redirect. Only paths beginning `/admin` are eligible.

- [ ] **Step 4: Verify and commit**

```bash
cd frontend/admin
npm test -- src/router/__tests__/guards.test.ts src/views/__tests__/LoginView.test.ts
npm run typecheck
npm run build
cd ../..
git add frontend/admin
git commit -m "feat: add admin login routing"
```

---

### Task 9: Authenticated Layout and Dashboard

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
- `GET /admin-api/v1/dashboard` requires valid Admin session.
- Response fields: `tenant_count`, `account_count`, `module_count`, `official_account_count`, `miniapp_count` as non-negative integers; fields may initially be 0 when no records exist.

- [ ] **Step 1: RED backend dashboard test**

Assert unauthenticated request is 401 and authenticated response has the exact five integer fields. Keep aggregation read-only.

- [ ] **Step 2: Implement minimal dashboard query/controller**

Use application/repository boundaries where an existing module already exposes the required count; for missing count abstractions, add one focused read model instead of querying from Vue. Controller never embeds business rules.

- [ ] **Step 3: RED Vue layout/dashboard tests**

Layout must render sidebar, header username, logout action, router outlet. Dashboard renders five statistic cards and loading/error states.

- [ ] **Step 4: Implement UI**

Use Element Plus `ElContainer`, `ElAside`, `ElHeader`, `ElMain`, `ElMenu`, `ElCard`, `ElStatistic`. Phase-1 menu contains Dashboard plus disabled/placeholder labels for later vertical slices; placeholders must not point to nonexistent operational routes.

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
git commit -m "feat: add admin shell and dashboard"
```

---

### Task 10: CI and Runtime Smoke

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Contract/AdminFrontendArchitectureContractTest.php`

**Interfaces:**
- CI must prove PHP and Admin frontend gates independently.

- [ ] **Step 1: Extend CI setup**

In `test` job, add `actions/setup-node@v4` with `node-version: '24'` and npm cache keyed to `frontend/admin/package-lock.json`.

- [ ] **Step 2: Add deterministic frontend gates**

Run:

```bash
npm ci --prefix frontend/admin
npm run typecheck --prefix frontend/admin
npm test --prefix frontend/admin
npm run build --prefix frontend/admin
```

Keep existing Composer/offline/PHPUnit/lint/HTTP smoke unchanged.

- [ ] **Step 3: Extend HTTP smoke**

With local ThinkPHP server running, verify:

```text
GET  /admin/health -> 200
GET  /admin/v1/auth/csrf -> 200 + CSRF Set-Cookie
GET  /admin/v1/auth/me -> 401 without session cookie
```

Do not add a hard-coded admin password to CI. Full login/database runtime belongs to MySQL acceptance.

- [ ] **Step 4: Run all local gates**

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

### Task 11: MySQL Acceptance for Real Login Lifecycle

**Files:**
- Create: `tests/Acceptance/AdminBrowserAuthRuntimeTest.php`
- Modify: `tests/Acceptance/run.php`

**Interfaces:**
- Uses disposable `weplatform_acceptance` database only.
- Proves actual password hash, DB session row, cookie restoration, CSRF, and logout invalidation.

- [ ] **Step 1: Write runtime test fixture**

Insert an active `admin_users` row with `password_hash('acceptance-password', PASSWORD_DEFAULT)` after migrations. Do not print the password/token to logs.

- [ ] **Step 2: Exercise browser-auth lifecycle**

The test must perform:

```text
GET csrf
POST login with matching CSRF
assert session cookie exists
assert DB has exactly one session row and token_hash != raw cookie
GET me with cookie -> 200
POST logout with session + matching CSRF -> 200
GET me with old session cookie -> 401
assert session row removed
```

- [ ] **Step 3: Run Release Gate**

```bash
WEPLATFORM_ACCEPTANCE=1 \
DATABASE_HOSTNAME=127.0.0.1 \
DATABASE_DATABASE=weplatform_acceptance \
DATABASE_USERNAME=root \
DATABASE_PASSWORD="$DATABASE_PASSWORD" \
DATABASE_HOSTPORT=3306 \
php tests/Release/run.php
```

Expected: existing R8D acceptance plus `AdminBrowserAuthRuntimeTest` GREEN and final `[PASS] Local release gate`.

- [ ] **Step 4: Commit**

```bash
git add tests/Acceptance
git commit -m "test: verify admin browser auth runtime"
```

---

### Task 12: Final Admin Foundation Verification

**Files:**
- Modify only if verification exposes a real defect.

**Interfaces:**
- Produces exact-head evidence for the Admin foundation branch.

- [ ] **Step 1: Verify source tree and secrets**

Run scans for `localStorage.setItem`, `sessionStorage.setItem`, raw password fixtures outside tests, and accidental session-cookie logging. Expected: no production violations.

- [ ] **Step 2: Verify exact HEAD**

```bash
git status --short
git rev-parse HEAD
git diff 8c0d565f4bbe97952949af43c79e4c826f748cb6 -- database/migrations
```

Expected: clean worktree; migration diff empty unless an explicitly approved migration was required (this plan does not require one).

- [ ] **Step 3: Run complete gates fresh**

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
```

Then run the MySQL Release Gate from Task 11.

- [ ] **Step 4: Push and inspect exact-head CI**

Push `refactor/frontend-foundation-v1`, then require the PR-triggered CI for that exact SHA to show PHP test job and MySQL release job GREEN before calling the Admin foundation complete.

- [ ] **Step 5: Manual browser acceptance**

Run ThinkPHP at `127.0.0.1:8000` and Vite at `127.0.0.1:5173`. Confirm `/admin/login` -> valid login -> `/admin` Dashboard -> browser refresh remains authenticated -> logout -> `/admin/login` -> protected route redirects back to login.
