# Foundation Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the first runnable ThinkPHP 8 refactor increment for WeEngine 2.7.4: multi-app WebRoot/routing, immutable RequestContext, stable error contract, and configuration/audit foundations, while keeping the legacy system untouched for Strangler/Golden-Master comparison.

**Architecture:** Build a new `weplatform` codebase beside the legacy WeEngine installation. HTTP requests enter only through `public/index.php`, then ThinkPHP multi-app routes `/admin`, public web, and `/api`; worker/common remain non-HTTP. Framework concerns adapt into `app/*`, while reusable request/error/audit primitives live in `app/common` without business services; later domains will live under `modules/*`.

**Tech Stack:** PHP 8.2+, ThinkPHP 8 (`topthink/think` 8.1.3 / framework 8.1.x locked by Composer), `topthink/think-multi-app` 1.1.1, PHPUnit, Composer PSR-4.

**Spec:** `docs/design-source/WeEngine-ThinkPHP-Refactor-V4/` (supplied V4 design), especially `01-architecture/*`, `10-epics/EPIC-01*`, `90-migration-testing/*`, and `99-implementation/*`.

## Global Constraints

- Preserve WeEngine 2.7.4 business semantics unless explicitly classified `INTENTIONAL_FIX`.
- Use ThinkPHP 8 series and `topthink/think-multi-app`.
- `public/` is the only WebRoot; `worker/common` expose no HTTP routes.
- Dependency direction is `HTTP/Worker -> Application -> Domain <- Infrastructure`; Domain must not depend on ThinkPHP Facades.
- Replace global `$_W` with immutable typed `RequestContext`; replace `$_GPC` with validated request DTOs as each route migrates.
- Controllers contain no DB/cache/queue/HTTP business logic.
- Errors use stable application codes rather than PHP warnings/notices.
- Structured audit/log context includes `request_id`, `trace_id`, `tenant_id`, `account_id`, `user_id` when present.
- Legacy WeEngine stays unchanged and addressable for Golden-Master comparison; no Big-Bang replacement.

---

### Task 1: Bootstrap ThinkPHP multi-app runtime and route contract

**Files:**
- Create/lock: `composer.json`, `composer.lock`
- Create/retain from ThinkPHP skeleton: `think`, `public/index.php`, `config/*`, `app/AppService.php`
- Create: `app/admin/controller/HealthController.php`, `app/admin/route/app.php`
- Create: `app/web/controller/HealthController.php`, `app/web/route/app.php`
- Create: `app/api/controller/V1/HealthController.php`, `app/api/route/app.php`
- Create: `tests/Contract/HttpEntryContractTest.php`
- Create: `phpunit.xml`

**Interfaces:**
- Produces routes: `GET /admin/health`, `GET /health`, `GET /api/v1/health`.
- Each health endpoint returns JSON `{code: "OK", message: "ok", data: {application: string}, request_id: string}` after Task 2 middleware is added; Task 1 may initially assert application/status only.

- [ ] **Step 1: Scaffold failing HTTP contract tests**

Create tests that boot each multi-app URL and assert a `200` response with the correct application marker; also assert `/worker/*` and `/common/*` are not routable.

- [ ] **Step 2: Run the contract test and verify failure**

Run: `php vendor/bin/phpunit tests/Contract/HttpEntryContractTest.php`
Expected: FAIL because routes/applications do not exist yet.

- [ ] **Step 3: Add ThinkPHP skeleton and multi-app dependency**

Create the project from `topthink/think:8.1.3`, require `topthink/think-multi-app:1.1.1`, add PHPUnit as a dev dependency, and commit the resulting lockfile. Keep `public/index.php` as the only PHP Web entrypoint.

- [ ] **Step 4: Implement minimal admin/web/api health controllers and routes**

Use thin controllers only; no domain logic. Route mapping must make `/admin/*`, `/api/v1/*`, and public web distinct, while worker/common have no route files registered for HTTP.

- [ ] **Step 5: Run route/contract tests**

Run: `php vendor/bin/phpunit tests/Contract/HttpEntryContractTest.php`
Expected: PASS.

### Task 2: Immutable RequestContext and stable error envelope

**Files:**
- Create: `app/common/context/RuntimeType.php`
- Create: `app/common/context/Principal.php`
- Create: `app/common/context/RequestContext.php`
- Create: `app/common/context/RequestContextFactory.php`
- Create: `app/common/error/ErrorCode.php`
- Create: `app/common/error/AppException.php`
- Create: `app/common/http/ApiResponse.php`
- Create: `app/common/middleware/RequestContextMiddleware.php`
- Create: `tests/Unit/Common/Context/RequestContextTest.php`
- Create: `tests/Unit/Common/Context/RequestContextFactoryTest.php`
- Create: `tests/Contract/ErrorContractTest.php`
- Modify: app middleware registration for `admin`, `web`, `api`.

**Interfaces:**
- Produces immutable `RequestContext` getters for `requestId`, `traceId`, `runtimeType`, nullable `tenantId/accountId/siteId/principal`, `locale`, `clientIp`.
- `RequestContextFactory::fromRequest(think\Request $request, RuntimeType $runtimeType): RequestContext`.
- `ApiResponse::success(mixed $data = null, string $message = 'ok'): Json` and `ApiResponse::error(string $code, string $message, mixed $data = null, int $httpStatus = 400): Json` read the current context request id through DI/request attribute rather than globals.

- [ ] **Step 1: Write unit tests proving RequestContext immutability and ID behavior**

Tests must verify non-empty request/trace IDs, pass-through of valid inbound IDs, nullable tenant/account/site/principal fields, and no public mutation API.

- [ ] **Step 2: Run unit tests and verify failure**

Run: `php vendor/bin/phpunit tests/Unit/Common/Context`
Expected: FAIL because context classes are missing.

- [ ] **Step 3: Implement minimal context value objects/factory**

Generate UUID-like request/trace identifiers when absent, normalize locale/client IP, and store the context on the ThinkPHP request as an attribute/middleware-provided service. Do not read `$_W` or `$_GPC`.

- [ ] **Step 4: Write failing error-contract tests**

Exercise an intentionally invalid API route/input and assert a stable JSON envelope containing application error `code`, human message, `data`, and `request_id`, with no PHP stack trace/warning text.

- [ ] **Step 5: Implement AppException/ErrorCode/ApiResponse and exception adaptation**

Map validation/not-found/application failures to stable status+code pairs; preserve framework exceptions internally while preventing raw exception details in production responses.

- [ ] **Step 6: Run context and error contract tests**

Run: `php vendor/bin/phpunit tests/Unit/Common/Context tests/Contract/ErrorContractTest.php tests/Contract/HttpEntryContractTest.php`
Expected: PASS.

### Task 3: Configuration boundary, secret handling contract, and audit foundation

**Files:**
- Create: `.env.example`
- Create: `config/weplatform.php`
- Create: `app/common/contract/AuditLogger.php`
- Create: `app/common/audit/AuditEvent.php`
- Create: `app/common/audit/StructuredAuditLogger.php`
- Create: `app/common/security/SecretValue.php`
- Create: `tests/Unit/Common/Security/SecretValueTest.php`
- Create: `tests/Unit/Common/Audit/AuditEventTest.php`
- Create: `tests/Integration/Common/Audit/StructuredAuditLoggerTest.php`

**Interfaces:**
- `AuditLogger::record(AuditEvent $event): void`.
- `AuditEvent` fields: `actorId`, nullable `tenantId/accountId`, `action`, `result`, `requestId`, `traceId`, timestamp, metadata.
- `SecretValue` can reveal its raw value only via an explicit method and must redact on string/debug serialization.

- [ ] **Step 1: Write failing tests for secret redaction and audit required fields**

Assert secrets render as redacted and audit events reject missing action/result/request ID.

- [ ] **Step 2: Run tests and verify failure**

Run: `php vendor/bin/phpunit tests/Unit/Common/Security tests/Unit/Common/Audit`
Expected: FAIL because classes do not exist.

- [ ] **Step 3: Implement configuration and audit primitives**

Environment config must contain deployment profile, legacy root/base URL, trusted proxy/host placeholders, logging channel, DB/Redis placeholders, with no real secrets committed. Structured logger emits JSON-compatible context and never serializes `SecretValue` raw values.

- [ ] **Step 4: Add integration test using the configured log channel**

Write one audit event and assert the persisted line contains request/trace/action/result and excludes raw secret material.

- [ ] **Step 5: Run audit/security tests**

Run: `php vendor/bin/phpunit tests/Unit/Common/Security tests/Unit/Common/Audit tests/Integration/Common/Audit`
Expected: PASS.

### Task 4: Legacy entrypoint mapping fixture and first Golden-Master boundary

**Files:**
- Create: `legacy/README.md`
- Create: `tests/GoldenMaster/fixtures/entrypoints.json`
- Create: `tests/GoldenMaster/LegacyEntrypointMappingTest.php`
- Create: `docs/migration/legacy-entrypoint-map.md`
- Create: `docs/verification/foundation-runtime-r1.md`

**Interfaces:**
- Fixture maps `/index.php`, `/web/index.php`, `/app/index.php`, `/api.php`, `payment/*` to target runtime categories without executing legacy code.
- `LEGACY_ROOT` points to the user-supplied/external WeEngine 2.7.4 tree; new runtime never mutates it.

- [ ] **Step 1: Write fixture-based Golden-Master mapping test**

Assert the known R20 entrypoints map to `router/domain-resolver`, `admin`, `web`, `api/webhook`, `api/payment-webhook` and that `install.php` is deployment-only.

- [ ] **Step 2: Run test and verify failure**

Run: `php vendor/bin/phpunit tests/GoldenMaster/LegacyEntrypointMappingTest.php`
Expected: FAIL until fixture/documented mapping exists.

- [ ] **Step 3: Add mapping fixture/docs without executing dynamic legacy includes**

Record source paths and target ownership; explicitly state that detailed `LegacyRouteAdapter` implementation belongs to M2 after Tenant/Account identity exists.

- [ ] **Step 4: Run the complete Foundation Runtime test suite**

Run: `php vendor/bin/phpunit`
Expected: PASS with zero failures/errors.

- [ ] **Step 5: Run static/safety checks and write verification report**

Verify no PHP files outside `public/` are intended as web roots, `app/worker` and `app/common` contain no route registration, no source file references `$_W`/`$_GPC`, and `composer.lock` is committed/present.

## Plan self-review

- Spec coverage for EPIC-01: ST-01-01 covered by Task 1, ST-01-02 by Task 2, ST-01-03 by Task 3; migration/Golden-Master foundation by Task 4.
- Deliberately deferred: IAM/Tenant/Account (M1 next plan), Module Registry/Legacy Adapter execution (M2), Entitlement/Quota, Site/Theme, Member, Payment, Marketplace.
- No business-domain classes are placed in `app/common`.
