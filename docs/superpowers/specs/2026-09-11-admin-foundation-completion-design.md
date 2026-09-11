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
/admin/*        -> app/adminui for SPA shell/history fallback
/admin/assets/* -> public/admin/assets/* static files
/admin-api/*    -> app/admin API
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
- Missing generated `public/admin/index.html` returns a controlled 503 response and never exposes an application stack trace in production.

## 6. First administrator bootstrap

### 6.1 Command

Add a ThinkPHP console command with the external contract:

```text
php think admin:bootstrap --username=<username>
```

Production/human use reads the password interactively without echo where the console runtime supports hidden input.

CI/E2E receives the password from a dedicated environment variable so automation does not place secrets in the repository or command arguments.

The command prints only non-secret outcome information.

### 6.2 Input policy

Username policy:

- trim leading/trailing Unicode whitespace before validation;
- length after trimming: 3–64 Unicode code points;
- reject ASCII control characters and DEL;
- otherwise preserve Unicode usernames rather than limiting administrators to ASCII.

Password policy for the initial administrator:

- minimum 12 Unicode code points;
- maximum 1024 bytes after UTF-8 encoding to bound hashing input;
- no composition rule such as mandatory uppercase/digit/symbol;
- password must never be normalized or trimmed because all supplied characters are intentional secret material.

These rules are part of the bootstrap application service contract and are tested independently of the console adapter.

### 6.3 Application service

Add an IAM application service `BootstrapFirstAdmin`, independent of the console adapter.

Responsibilities:

1. Validate/normalize the username and validate the password according to section 6.2.
2. Invoke the repository's serialized first-admin creation operation.
3. Hash the password using `password_hash(..., PASSWORD_DEFAULT)` only inside the application flow before persistence.
4. Generate the administrator ID through a dedicated `AdminIdGenerator` contract.
5. Return the created administrator identity without returning the password or password hash.

Use a `SecureAdminIdGenerator` implementation that follows the repository's existing secure-ID convention: `bin2hex(random_bytes(16))`, producing a 32-character identifier that fits the existing `varchar(64)` primary key.

The console command is an adapter only; it must not contain persistence SQL or duplicate bootstrap rules.

### 6.4 Persistence boundary

Use a dedicated repository contract for bootstrap, rather than expanding the existing read-only `AdminUserRepository` / `AdminCredentialRepository` interfaces with unrelated creation semantics.

The repository exposes one atomic semantic operation rather than separate externally callable `hasAny()` and `createFirst()` methods:

```text
createFirst(id, username, passwordHash): Created | AlreadyExists
```

This prevents callers from accidentally reintroducing a check-then-insert race.

The infrastructure implementation uses the existing `admin_users` schema and unique username constraint.

### 6.5 Concurrency / fail-closed semantics

The MySQL implementation uses a named advisory lock with the fixed name:

```text
weplatform:admin-bootstrap
```

Algorithm on one database connection:

1. Acquire `GET_LOCK('weplatform:admin-bootstrap', 5)`; failure/timeout is an error and creates nothing.
2. Begin a database transaction.
3. Count `admin_users` while holding the advisory lock.
4. If count > 0, roll back/finish without mutation and return `AlreadyExists`.
5. Insert exactly one active administrator.
6. Commit.
7. Release with `RELEASE_LOCK('weplatform:admin-bootstrap')` in `finally` semantics.

The advisory lock must be released on all normal/error paths; connection termination also causes MySQL to release it.

The service must not rely only on an application-level `COUNT(*)` check. A real-MySQL concurrent acceptance test must launch two bootstrap attempts and prove exactly one administrator exists afterward.

## 7. Security requirements

- No default username/password shipped in source, SQL migrations, fixtures, or deployment files.
- Plaintext passwords are never logged or returned.
- Command-line password arguments are not supported for normal production use.
- Automated password injection is environment-only and scoped to tests/CI.
- Password hash is produced with PHP's current `PASSWORD_DEFAULT`.
- Bootstrap is one-time/fail-closed once any administrator exists.
- Existing login CSRF/session protections remain unchanged.
- Admin UI and API stay same-origin in production.
- Advisory-lock timeout/failure fails closed and never falls back to an unlocked insert.

## 8. Data flow

### 8.1 Fresh installation bootstrap

```text
operator
  -> php think admin:bootstrap --username=admin
  -> Console adapter
  -> BootstrapFirstAdmin
  -> AdminIdGenerator
  -> BootstrapAdminRepository::createFirst(...)
  -> MySQL advisory lock + transaction
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

- Admin production build absent: `/admin/*` returns controlled HTTP 503 with a production-safe response.
- Bootstrap username invalid: command exits non-zero with a safe validation message.
- Bootstrap password invalid: command exits non-zero without echoing the password.
- Existing administrator: command exits non-zero with an explicit `initial administrator already exists` result.
- Advisory lock timeout/failure: command exits non-zero and creates no row.
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

- bootstrap succeeds on an empty repository.
- username trimming/length/control-character policy.
- password length/byte-bound policy and preservation of intentional whitespace.
- password is hashed, not persisted/returned plaintext.
- administrator ID uses the injectable generator rather than being hard-coded.
- existing administrator blocks bootstrap.
- advisory-lock/persistence failures propagate safely.
- `adminui` serves SPA shell for `/admin/` and `/admin/login`.
- missing build returns controlled 503 behavior.

### 10.3 MySQL acceptance tests

Against a real migrated MySQL database:

- empty DB bootstrap creates exactly one active administrator.
- created password can authenticate through existing credential repository/auth flow.
- second bootstrap is rejected.
- concurrent bootstrap attempts leave exactly one administrator.
- advisory lock is released after success and failure.

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

AC5. Bootstrap username/password validation follows the explicit policy in section 6.2.

AC6. The bootstrap command refuses to create an administrator when any administrator already exists.

AC7. Two concurrent bootstrap attempts against an empty database result in exactly one created administrator, using the fixed MySQL advisory-lock protocol in section 6.5.

AC8. The bootstrapped administrator can log in through the production-built Admin SPA, reach Dashboard, restore the authenticated session after reload, and log out.

AC9. After logout, the old authenticated session is rejected.

AC10. All existing Web/Admin/backend/release quality gates remain GREEN at the same exact head.

AC11. Human acceptance verifies the production `/admin/` UI and login/Dashboard experience before the feature PR can be marked ready.

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
