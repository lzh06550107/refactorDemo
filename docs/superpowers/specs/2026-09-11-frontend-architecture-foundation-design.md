# Frontend Architecture Foundation Design

- Date: 2026-09-11
- Status: Proposed for implementation after user review
- Branch: `refactor/frontend-foundation-v1`
- Base: `8c0d565f4bbe97952949af43c79e4c826f748cb6`

## 1. Goal

Establish the first production frontend architecture for the ThinkPHP 8 WePlatform refactor without changing the already-validated R8D release candidate branch.

This design covers two browser-facing surfaces with intentionally different rendering models:

1. **Admin**: a Vue 3 single-page application for platform and tenant administration.
2. **Web/H5**: ThinkPHP server-rendered templates using HTML5, CSS and modern JavaScript, enhanced by Vite-managed assets and optional local Vue 3 islands only where interaction complexity justifies them.

The goal is not to build every business screen in one step. The first implementation increment establishes the frontend foundations, authentication entry path, shell/layout conventions, asset pipeline and test gates that later business screens will build on.

## 2. Source-of-truth boundaries

The existing backend architecture remains authoritative:

```text
HTTP / Worker -> Application -> Domain <- Infrastructure
```

Frontend code is a delivery layer. It must not contain domain rules that belong in `modules/*`.

### 2.1 Backend entry applications

```text
app/admin   = administrative HTTP endpoints
app/api     = public/provider/API endpoints, including WeChat OpenPlatform callbacks
app/web     = server-rendered public Web/H5 delivery
app/worker  = CLI/background entry points
app/common  = shared kernel/framework-neutral cross-cutting primitives
```

### 2.2 Business logic

All business capabilities remain under `modules/*`.

The frontend may call or render results from backend application services, but it must not bypass those services to duplicate authorization, quota, account, module, OpenPlatform or tenant business rules.

## 3. Repository layout

Target layout:

```text
weEngine/
├─ app/
│  ├─ admin/
│  ├─ api/
│  ├─ web/
│  ├─ worker/
│  └─ common/
├─ modules/
│  └─ ...
├─ frontend/
│  ├─ admin/
│  │  ├─ src/
│  │  │  ├─ api/
│  │  │  ├─ assets/
│  │  │  ├─ components/
│  │  │  ├─ layouts/
│  │  │  ├─ router/
│  │  │  ├─ stores/
│  │  │  ├─ utils/
│  │  │  ├─ views/
│  │  │  ├─ App.vue
│  │  │  └─ main.ts
│  │  ├─ index.html
│  │  ├─ package.json
│  │  ├─ tsconfig.json
│  │  └─ vite.config.ts
│  └─ web/
│     ├─ src/
│     │  ├─ css/
│     │  ├─ js/
│     │  │  ├─ core/
│     │  │  ├─ components/
│     │  │  ├─ pages/
│     │  │  └─ modules/
│     │  └─ islands/
│     ├─ package.json
│     └─ vite.config.ts
├─ themes/
│  └─ <theme-key>/
│     ├─ theme.json
│     ├─ layouts/
│     ├─ pages/
│     ├─ components/
│     └─ assets/
└─ public/
   └─ build/
      ├─ admin/
      └─ web/
```

`frontend/*` contains source/build tooling. Runtime HTML templates for public sites live in `themes/*` and are rendered by `app/web`.

## 4. Admin technology and architecture

### 4.1 Technology stack

Admin uses:

- Vue 3
- TypeScript
- Vite
- Vue Router
- Pinia
- Axios
- Element Plus

The Admin frontend is a SPA because it is an authenticated, high-interaction management surface and does not need search-engine indexing.

### 4.2 Admin route ownership

Browser routes are owned by Vue Router under `/admin`.

Administrative backend endpoints use a separate namespace:

```text
/admin-api/v1/*
```

Examples:

```text
POST /admin-api/v1/auth/login
POST /admin-api/v1/auth/logout
GET  /admin-api/v1/auth/me
GET  /admin-api/v1/dashboard
```

`/api/v1/*` remains reserved for public/provider/API use and must not become the default internal Admin API surface.

### 4.3 Phase 1 Admin UI scope

Phase 1 includes:

- login page
- authenticated Admin shell
- sidebar navigation
- header/current-user area
- breadcrumb region
- route guards
- logout
- dashboard landing page
- global loading/error handling
- 401 handling that clears browser auth state and returns to login

Phase 1 intentionally excludes full CRUD screens for tenants, accounts, modules, OpenPlatform, permissions and system settings.

## 5. Admin authentication design

The existing IAM schema and session model are reused. There will not be a second frontend-only identity system.

Existing tables remain the source of truth:

```text
admin_users
admin_sessions
tenant_memberships
```

Existing `RestoreAdminSession` remains the session restoration authority.

### 5.1 New application use cases

IAM will gain the minimum missing use cases required for browser login:

```text
AuthenticateAdmin
CreateAdminSession
LogoutAdminSession
```

The concrete names may be adjusted during planning only if existing naming conventions require it; responsibilities may not be merged into controllers.

### 5.2 Login flow

```text
username + password
        |
        v
POST /admin-api/v1/auth/login
        |
        v
IAM authentication application service
        |
        +-- load active admin user
        +-- reject expired/banned user
        +-- password_verify()
        +-- generate cryptographically random session token
        +-- persist only SHA-256 token hash
        v
browser session established
```

The raw session token must never be stored in the database or logs.

### 5.3 Browser token transport

For the browser Admin SPA, the default design is an **HttpOnly, Secure, SameSite=Lax cookie** for the raw admin session token.

Reasons:

- JavaScript cannot read the token, reducing XSS token exfiltration risk.
- Refresh/navigation naturally restores the session.
- The backend remains the session authority.

For local HTTP development, `Secure` may be disabled by environment-specific configuration only. Production must require HTTPS and Secure cookies.

The Admin SPA must not persist the raw token in `localStorage` or `sessionStorage`.

### 5.4 CSRF

Because browser Admin authentication uses a cookie, state-changing Admin API requests must be protected against CSRF.

Phase 1 will use same-site deployment plus an explicit CSRF token/header mechanism for mutating `/admin-api/v1/*` requests. Login/logout behavior and CSRF bootstrap details will be specified in the implementation plan and tests, but CSRF protection itself is mandatory and may not be omitted.

## 6. Admin deployment model

Development:

```text
Vite dev server        ThinkPHP dev server
localhost:5173   --->  localhost:8000/admin-api/v1/*
```

Vite proxies Admin API requests to ThinkPHP so normal development does not require permissive CORS.

Production:

```text
Nginx
├─ /admin/*         -> built Admin SPA static assets/fallback
├─ /admin-api/*     -> ThinkPHP/PHP-FPM
├─ /api/*           -> ThinkPHP/PHP-FPM
└─ public assets    -> static files
```

The production deployment must not require a Node.js runtime for Admin. Node is a build-time dependency only.

## 7. Web/H5 rendering architecture

Public Web/H5 does **not** use a site-wide SPA or Nuxt runtime in this design.

The primary rendering model is:

```text
Browser request
      |
      v
app/web controller
      |
      v
modules/* application service
      |
      v
view model / render data
      |
      v
Theme Resolver
      |
      v
ThinkPHP template
      |
      v
complete HTML response
```

This keeps public sites server-rendered, SEO-friendly and compatible with a module/theme-oriented platform model.

## 8. Web/H5 technology

Public Web/H5 uses:

- ThinkPHP template rendering
- HTML5
- CSS
- modern ES modules / JavaScript
- Vite for asset bundling, hashing and development tooling

Vite is a build tool here; it does not imply Vue SPA architecture.

### 8.1 Progressive enhancement

Simple behavior should remain plain JavaScript:

- navigation
- tabs
- accordions
- modal/dialog behavior
- forms/AJAX
- lazy loading
- sliders
- page animations

Complex isolated interactions may use Vue 3 islands, for example:

- complex SKU selector
- advanced cart widget
- highly stateful account widget

A local Vue island must mount into a defined server-rendered DOM island and may not take over the entire site unless a later approved design changes this architecture.

## 9. Theme system

Public site templates live in `themes/<theme-key>/`.

Each theme has an explicit manifest and predictable template structure:

```text
themes/corporate/
├─ theme.json
├─ layouts/
│  └─ default.html
├─ pages/
│  ├─ index.html
│  ├─ article-list.html
│  ├─ article-detail.html
│  └─ product-detail.html
├─ components/
│  ├─ header.html
│  ├─ footer.html
│  └─ ...
└─ assets/
```

Themes consume prepared render data. They must not query the database directly or instantiate repositories.

Theme resolution will integrate with the existing `modules/theme` and `modules/site` boundaries rather than introducing a parallel theme database model.

## 10. Design tokens and customization

Theme styling should be exposed through CSS variables/design tokens rather than arbitrary code generation.

Representative tokens:

```css
--theme-primary
--theme-secondary
--theme-text
--theme-background
--theme-container-width
--theme-radius
--theme-font-family
```

Persisted theme/style configuration is translated into validated render variables before reaching templates.

User-controlled values must never be interpolated into executable JavaScript/PHP source.

## 11. Web route ownership

`app/web` owns public server-rendered routes such as:

```text
/
/news
/news/:slug
/products
/products/:slug
/about
/contact
```

Exact route sets belong to later site/module features and are not all implemented by this foundation phase.

Admin SPA browser routes do not belong in `app/web`.

## 12. Shared frontend contracts

Admin and Web/H5 may share design tokens, API type descriptions or generated metadata only through explicit shared packages/files introduced later.

They must not share runtime component assumptions:

- Admin is Element Plus/Vue SPA.
- Public Web/H5 is server-template-first.

This avoids turning public themes into Admin-style component trees.

## 13. Error handling

### Admin

- 400/422: render field/global validation errors.
- 401: clear browser session state and redirect to login.
- 403: show permission-denied view without pretending the session expired.
- 404: Admin not-found view.
- 5xx/network errors: common recoverable error notification; no raw stack traces.

### Web/H5

- missing site/theme/page resolves to controlled 404.
- template/render exceptions produce controlled 5xx handling and structured server logs.
- public production pages must never expose stack traces or secrets.

## 14. Security boundaries

Mandatory constraints:

- no raw admin session token in localStorage/sessionStorage
- no raw session token in DB or logs
- password verification uses PHP password APIs; no custom crypto
- state-changing cookie-auth Admin APIs require CSRF protection
- Vue output and server templates must escape untrusted content by default
- explicit sanitization policy is required before rendering trusted-rich HTML content
- frontend code must not embed provider secrets, AppSecret, EncodingAESKey, access tokens or refresh tokens
- public theme templates cannot call infrastructure repositories directly

## 15. Testing strategy

### 15.1 Architecture contracts

Add permanent contract coverage for:

- `frontend/admin` exists and uses Vue 3 + TypeScript + Vite
- public Web/H5 remains server-template-first
- `/admin-api/v1/*` and `/api/v1/*` responsibilities stay separated
- public themes do not import/use infrastructure repositories
- no frontend source persists admin raw tokens through localStorage/sessionStorage
- build outputs are not treated as editable source

### 15.2 Admin frontend tests

Use a layered approach:

- unit tests for auth store/router guards/API error mapping
- component tests for login and shell behaviors
- build/typecheck gate
- browser smoke for login -> dashboard -> logout

### 15.3 Backend tests

TDD is required for:

- username/password authentication
- banned/expired/wrong-password rejection
- session creation/token hashing
- session restoration through cookie transport
- logout invalidation
- CSRF rejection/acceptance
- Admin `/auth/me`

Existing release tests must remain green.

### 15.4 Web/H5 tests

Foundation tests cover:

- theme resolution
- complete server-rendered HTML response
- template escaping
- asset-manifest lookup
- controlled missing-theme behavior

Later feature-specific page tests are added with their respective modules.

## 16. CI gates

The frontend foundation extends CI without weakening existing PHP gates.

Expected gates:

```text
PHP composer validate/install
PHP offline contracts
PHPUnit
PHP lint
ThinkPHP HTTP smoke
Admin npm clean install
Admin TypeScript typecheck
Admin unit/component tests
Admin production build
Web asset npm clean install
Web asset tests/lint when configured
Web production asset build
frontend architecture contract
```

MySQL acceptance/release gates remain required for backend changes that touch runtime behavior.

## 17. Implementation sequence

Implementation will be planned as independent TDD increments:

```text
A. frontend architecture contracts and directories
B. Vue 3 Admin toolchain/shell skeleton
C. IAM login/session/logout backend use cases
D. /admin-api/v1/auth endpoints + cookie/CSRF transport
E. Admin auth store/router guard/login page
F. Admin authenticated layout + dashboard foundation
G. Web/H5 Vite asset pipeline
H. Theme resolver + first server-rendered theme skeleton
I. CI integration and browser/runtime smoke
J. final regression against existing R8D gates
```

Each increment must be independently testable and should be committed only after its focused gates pass.

## 18. Non-goals for this foundation phase

This phase does not implement:

- full tenant CRUD UI
- full account/official-account/miniapp management UI
- application marketplace UI
- complete module-management UI
- full OpenPlatform operations UI
- payment UI
- visual page builder
- arbitrary drag-and-drop theme editor
- Nuxt/Next SSR runtime
- a second authentication database

Those become later vertical slices on top of the foundation.

## 19. Acceptance criteria

The frontend foundation is complete when all of the following are true:

1. Admin Vue 3 application can run locally and build reproducibly.
2. A valid administrator can authenticate through the real IAM/database path.
3. The browser does not store the raw admin token in Web Storage.
4. Refresh restores the authenticated Admin session.
5. Invalid/expired sessions return to login.
6. Logout invalidates the server-side session.
7. Authenticated user can enter the Admin shell and Dashboard route.
8. Public Web/H5 can render at least one complete HTML page through `app/web` and a theme template.
9. Web/H5 JavaScript assets build through Vite without requiring a Node production runtime.
10. Architecture/security tests enforce Admin SPA vs Web server-template boundaries.
11. Existing R8D PHP/HTTP/MySQL release gates remain green.

## 20. Branch and release isolation

The validated R8D release-candidate branch remains frozen at:

```text
refactor/openplatform-authorizer-provisioning-r8d
8c0d565f4bbe97952949af43c79e4c826f748cb6
```

Frontend work proceeds on:

```text
refactor/frontend-foundation-v1
```

This prevents frontend development from invalidating the exact-SHA automated evidence already collected for R8D. The frontend branch can later be integrated only after its own review and gates are green.
