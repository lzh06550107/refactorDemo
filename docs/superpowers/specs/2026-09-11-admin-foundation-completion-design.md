# Admin Foundation Completion — Design

Date: 2026-09-11
Status: Proposed for implementation after human review
Branch: `refactor/admin-foundation-completion-v1`
Base: `refactor/web-h5-template-foundation-v1` @ `c00ff32dcc2c05ff4490295ba445bd4dba474ed7`

## 1. Goal

Complete the Admin foundation so a fresh installation can be operated without a Vite development server and without manually inserting administrator rows.

After this feature:

1. The production Admin SPA is available from the same application origin at `/admin/` and supports direct navigation/reload of nested Vue Router history routes such as `/admin/login`.
2. Admin API remains isolated under `/admin-api/*` and continues to use the existing ThinkPHP `app/admin` application.
3. A fresh database can securely create exactly one initial administrator through a ThinkPHP console bootstrap command.
4. A real Chromium + MySQL E2E proves bootstrap → login → dashboard → refresh/session restore → logout → old-session rejection.
5. Existing Web/H5, Admin auth API, R8D and release gates remain green.

## 2. Scope

### In scope

- Production `/admin/` SPA delivery.
- Vue Router history fallback for `/admin/*` non-asset routes.
- Direct static serving of built Admin assets under `/admin/assets/*`.
- First-administrator bootstrap application service and console command.
- Fail-closed bootstrap semantics when any administrator already exists.
- Password hashing using PHP's password API; no plaintext password persistence.
- Automated contract/component/acceptance/browser tests and permanent CI gates.
- Production build output and generated-file boundaries.

### Out of scope

- General administrator CRUD UI.
- Creating second/subsequent administrators.
- Password reset/recovery.
- Roles/tenant membership management UI.
- MFA.
- SSO/OAuth for Admin.
- Replacing the current Admin Vue/Element Plus stack.
- Changing `/admin-api/v1` API semantics.
- Merging or marking existing stacked PRs Ready.

## 3. Existing constraints

The design preserves these existing contracts:

- Admin SPA uses Vue Router history base `/admin/`.
- Admin HTTP client uses same-origin `/admin-api/v1` with credentials.
- ThinkPHP maps `/admin-api` to the `admin` application.
- `admin_users.username` is unique and credentials are stored as `password_hash`.
- Existing CSRF/session-cookie behavior remains authoritative.
- Admin production output is generated and must not be committed.
- Human acceptance remains independent from automated gates.

## 4. Options considered

### Option A — Dedicated `adminui` application + static Admin build — selected

Use a dedicated ThinkPHP application for Admin SPA shell/history fallback while keeping `/admin-api/*` isolated in `app/admin`.

Production layout:

```text
/admin/*       -> app/adminui for SPA shell/history fallback
/admin/assets/* -> public/admin/assets/* static files
/admin-api/*   -> app/admin API
```

Advantages:

- Separates UI delivery from API behavior.
- Works consistently under `php think run` and production reverse proxies.
- Keeps same-origin cookies and CSRF semantics.
- Avoids PHP proxying normal JS/CSS assets.
- Gives `/admin/*` a code-owned fallback contract instead of deployment-specific magic.

Trade-off: adds a small application boundary dedicated to UI delivery.

### Option B — Reverse-proxy `try_files` only

Let Nginx/Caddy map `/admin/*` directly to a built SPA and fallback to `index.html`.

Rejected because local `php think run` and production would behave differently, and every deployment recipe would need to reproduce the fallback correctly.

### Option C — Serve all Admin files through PHP

Have ThinkPHP read and return index/assets.

Rejected because it unnecessarily routes cacheable static assets through PHP and couples SPA delivery to application execution.

## 5. Architecture

### 5.1 URL ownership

```text
/                         app/web
/admin/*                  app/adminui
/admin-api/*              app/admin
```

`config/app.php` receives an explicit `admin` URL mapping to `adminui`, while the existing `admin-api` mapping remains `admin`.

The `adminui` application owns only SPA page delivery. It has no domain/application business logic and no database access.

### 5.2 Production Admin build

Vite production output becomes:

```text
public/admin/
  index.html
  assets/
    *.js
    *.css
```

The Vue Router base remains `/admin/`.

Generated Admin output stays ignored by Git. CI always rebuilds it from the locked frontend dependency graph before browser tests.

### 5.3 SPA fallback

`app/adminui` defines a catch-all GET route for Admin page paths and returns `public/admin/index.html` with `text/html; charset=UTF-8`.

Rules:

- `/admin/` returns the SPA shell.
- `/admin/login` returns the same SPA shell.
- Future `/admin/...` client-side routes return the SPA shell.
- `/admin/assets/...` must be served as static files by the web server / PHP development router and must never be swallowed by the SPA fallback when the file exists.
- Missing generated `public/admin/index.html` fails clearly rather than silently returning an unrelated page.

## 6. First administrator bootstrap

### 6.1 Command

Add a ThinkPHP console command with the external contract:

```text
php think admin:bootstrap --username=<username>
```

Production/human use reads the password interactively without echo where the console runtime supports hidden input.

CI/E2E receives the password from a dedicated environment variable so automation does not place secrets in the repository or command arguments.

The command prints only non-secret outcome information.

### 6.2 Application service

Add an IAM application service, conceptually `BootstrapFirstAdmin`, independent of the console adapter.

Responsibilities:

1. Normalize/validate username and password policy inputs.
2. Ask a dedicated bootstrap repository whether any administrator exists.
3. Refuse if the administrator table is non-empty.
4. Hash the password using `password_hash(..., PASSWORD_DEFAULT)`.
5. Generate a non-secret administrator ID through an injectable ID generator or an existing project-safe ID mechanism.
6. Insert one active administrator transactionally.
7. Return the created administrator identity without returning the password or password hash.

The console command is an adapter only; it must not contain persistence SQL or duplicate bootstrap rules.

### 6.3 Persistence boundary

Use a dedicated repository contract for bootstrap, rather than expanding the existing read-only `AdminUserRepository` / `AdminCredentialRepository` interfaces with unrelated creation semantics.

Required operations are intentionally narrow:

```text
hasAny(): bool
createFirst(id, username, passwordHash): void
```

The infrastructure implementation uses the existing `admin_users` schema and unique username constraint.

### 6.4 Concurrency / fail-closed semantics

The bootstrap path must prevent two concurrent empty-database bootstrap requests from creating two initial administrators.

The implementation must make the empty-check and insertion atomic under MySQL, using a transaction plus a deterministic database serialization mechanism. The exact mechanism may be an advisory lock or another MySQL-safe serialization primitive, but it must be verified by an automated concurrency test and must not rely only on an application-level `COUNT(*)` check.

If an administrator already exists, the service returns a stable domain/application error and no row is inserted or modified.

## 7. Security requirements

- No default username/password shipped in source, SQL migrations, fixtures, or deployment files.
- Plaintext passwords are never logged or returned.
- Command-line password arguments are not supported for normal production use.
- Automated password injection is environment-only and scoped to tests/CI.
- Password hash is produced with PHP's current `PASSWORD_DEFAULT`.
- Bootstrap is one-time/fail-closed once any administrator exists.
- Existing login CSRF/session protections remain unchanged.
- Admin UI and API stay same-origin in production.

## 8. Data flow

### 8.1 Fresh installation bootstrap

```text
operator
  -> php think admin:bootstrap --username=admin
  -> Console adapter
  -> BootstrapFirstAdmin
  -> serialized bootstrap repository transaction
  -> admin_users
  -> success identity (no secret)
```

### 8.2 Production Admin request

```text
browser GET /admin/login
  -> adminui SPA fallback
  -> public/admin/index.html
  -> /admin/assets/*.js + *.css
  -> Vue boots with history base /admin/
```

### 8.3 Login

```text
Vue LoginView
  -> GET /admin-api/v1/auth/csrf
  -> POST /admin-api/v1/auth/login
  -> existing IAM credential/session flow
  -> session cookie
  -> router /admin/
  -> GET /admin-api/v1/dashboard
```

## 9. Error behavior

- Admin production build absent: `/admin/*` returns a controlled 5xx/error response identifying missing Admin build rather than a PHP stack trace in production.
- Bootstrap username invalid: command exits non-zero with a safe validation message.
- Bootstrap password invalid: command exits non-zero without echoing the password.
- Existing administrator: command exits non-zero with an explicit "initial administrator already exists" result.
- Concurrent bootstrap loser: exits non-zero and leaves exactly one administrator row.
- Database failure: transaction rolls back; no partial administrator is created.
- Existing login errors keep their current API response contract.

## 10. Test strategy

The feature follows strict RED → GREEN with exact-head evidence.

### 10.1 Contract tests

Add fail-closed architecture contracts requiring:

- `app/adminui` production SPA entry/fallback.
- Admin Vite output under `public/admin` with base `/admin/`.
- `/admin-api` remains mapped to `app/admin`.
- CI builds Admin before Admin browser E2E.
- CI contains a permanent real-browser Admin E2E gate.
- Generated Admin outputs/reports remain ignored.

The first commit should make this contract RED before implementation is added.

### 10.2 Unit/component tests

Cover:

- bootstrap succeeds on empty repository.
- password is hashed, not persisted/returned plaintext.
- existing administrator blocks bootstrap.
- invalid username/password rejected.
- transaction/persistence failures propagate safely.
- `adminui` serves SPA shell for `/admin/` and `/admin/login`.
- missing build has controlled error behavior.

### 10.3 MySQL acceptance tests

Against a real migrated MySQL database:

- empty DB bootstrap creates exactly one active administrator.
- created password can authenticate through existing credential repository/auth flow.
- second bootstrap is rejected.
- concurrent bootstrap attempts leave exactly one administrator.

### 10.4 Chromium E2E

On a fresh migrated MySQL database and production-built Admin assets:

1. Bootstrap initial administrator through the real console command.
2. Start real ThinkPHP server.
3. Navigate directly to `/admin/login` (no Vite dev server).
4. Verify Admin SPA shell/assets load from the production origin.
5. Log in with the bootstrapped administrator.
6. Verify redirect/navigation to Dashboard.
7. Verify Dashboard API data renders.
8. Reload the browser and verify authenticated session restoration.
9. Log out.
10. Verify protected route/session is no longer usable.
11. Directly reload `/admin/login` and `/admin/` to prove history fallback.

Chromium only is sufficient for this foundation gate. Retain trace and screenshot on failure.

### 10.5 Full regression

Exact-head CI must still pass:

- Composer locked install/validation.
- Admin typecheck/unit/build.
- Web unit/build/browser E2E.
- Admin production Chromium E2E.
- offline contracts.
- PHPUnit bridge.
- PHP lint.
- multi-app HTTP smoke.
- R8D MySQL release gate.

## 11. CI ordering

Required order inside the primary test job:

```text
locked installs
  -> Admin typecheck/unit/build
  -> Web unit/build
  -> browser E2E dependencies/browser install
  -> start ThinkPHP with migrated test DB as needed
  -> Web browser E2E
  -> Admin production browser E2E
  -> contracts / PHPUnit / lint / HTTP smoke
```

A separate real-MySQL acceptance/release gate may remain independently serialized if that preserves current CI reliability, but the Admin bootstrap + production browser path must run against real MySQL before the feature is considered GREEN.

## 12. Acceptance criteria

AC1. A clean checkout can run Admin in production mode without `vite dev`; navigating to `/admin/login` loads the Admin SPA.

AC2. Direct navigation/reload of `/admin/` and `/admin/login` succeeds through server-side SPA fallback.

AC3. `/admin-api/v1/*` remains handled by the existing Admin API application and is not intercepted by SPA fallback.

AC4. On an empty migrated database, `php think admin:bootstrap --username=<name>` creates exactly one active administrator with a password hash and no plaintext password persistence.

AC5. The bootstrap command refuses to create an administrator when any administrator already exists.

AC6. Two concurrent bootstrap attempts against an empty database result in exactly one created administrator.

AC7. The bootstrapped administrator can log in through the production-built Admin SPA, reach Dashboard, restore the authenticated session after reload, and log out.

AC8. After logout, the old authenticated session is rejected.

AC9. All existing Web/Admin/backend/release quality gates remain GREEN at the same exact head.

AC10. Human acceptance verifies the production `/admin/` UI and login/Dashboard experience before the feature PR can be marked ready.

## 13. Delivery / stacking

Create and develop only on:

```text
refactor/admin-foundation-completion-v1
```

Base it on:

```text
refactor/web-h5-template-foundation-v1
```

Open a new stacked Draft PR. Do not modify PR #7, #8, or the completed contents of PR #9 for this feature. Do not mark the new PR Ready or merge it until automated exact-head gates and the independent Human Gate are both GREEN.
