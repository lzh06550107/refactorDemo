# App / Modules Architecture Alignment Design

Date: 2026-09-11
Status: Proposed for implementation after user review
Branch: `refactor/openplatform-authorizer-provisioning-r8d`
Baseline before this design commit: `1bfa3ded63ff886483f01ac99bf6efedfa549aba`

## 1. Purpose

Restore the physical project structure to the approved V4 architecture before V1 release, without changing R8D business behavior.

The V4 source of truth defines two distinct concerns:

- `app/*` is the delivery/entry layer: HTTP applications, CLI/worker adapters, and the shared kernel.
- `modules/*` is the business capability layer: application services, domain types/contracts, and infrastructure adapters.

The current R8D implementation mixes both concerns under `app/`. This design closes that gap before the real WeChat Provider E2E release gate.

## 2. Source-of-truth architecture

The approved V4 design defines:

```text
app/
├── admin/
├── web/
├── api/
├── worker/
└── common/

modules/
├── iam/
├── tenant/
├── account/
├── entitlement/
├── module/
├── marketplace/
├── site/
├── theme/
├── member/
├── integration/
├── payment/
├── media/
└── notification/
```

The dependency direction is:

```text
HTTP / Worker -> Application -> Domain <- Infrastructure
```

R8A-R8D introduced additional bounded contexts (`miniapp`, `oauth`, `webhook`, `openplatform`, `quota`) that were not enumerated in the earlier V4 directory sample. They remain explicit business modules rather than being forced into an artificial combined module during this release-alignment change.

## 3. Current problem

Current `app/` contains true entry applications and business modules at the same physical level:

```text
app/
├── admin/          # entry application
├── api/            # entry application
├── web/            # entry application
├── common/         # shared kernel
├── command/        # CLI adapter, currently misplaced
│
├── account/        # business module
├── entitlement/    # business module
├── iam/            # business module
├── legacy/         # legacy integration runtime
├── member/         # business module
├── miniapp/        # business module
├── module/         # business module
├── oauth/          # business module
├── openplatform/   # business module
├── quota/          # business module
├── site/           # business module
├── tenant/         # business module
├── theme/          # business module
└── webhook/        # business module
```

This creates four concrete issues:

1. It contradicts the approved V4 physical architecture.
2. `think-multi-app` can interpret first-level `app/*` directories as applications unless explicitly denied.
3. Namespace semantics no longer reveal whether code is an entry adapter or a business capability.
4. Future development can continue adding domain code to `app/`, increasing coupling and making architecture drift harder to detect.

## 4. Goals

This change SHALL:

1. Restore `app/` as the entry/composition/shared-kernel layer.
2. Move business modules into a top-level `modules/` directory.
3. Move the existing CLI provisioning command into `app/worker/command/`.
4. Introduce an explicit `modules\\` PSR-4 namespace.
5. Rename moved PHP namespaces and imports to match their physical location.
6. Preserve all public HTTP routes, request/response contracts, CLI command names, database schema, migration SQL, provisioning state transitions, quota semantics, and provider behavior.
7. Add an automated architecture contract so the `app/`/`modules/` boundary cannot silently regress.
8. Re-run every automated release gate on the new exact HEAD before the real Provider E2E.

## 5. Non-goals

This change SHALL NOT:

- redesign any R1-R8D business behavior;
- change database tables, columns, indexes, migrations 001-009, or data semantics;
- change any API URL, method, JSON field, response envelope, or error code;
- change WeChat protocol behavior or cryptography;
- change `openplatform:provisioning-worker` as the external CLI command name;
- split the large root `AppService.php` into per-module service providers in this change;
- introduce Marketplace, Payment, Media, Notification, or other deferred V4 modules;
- merge existing bounded contexts merely to make the directory list match the early V4 sample exactly;
- perform the real WeChat Provider E2E until the post-migration exact HEAD is fully green.

## 6. Target physical structure

```text
weplatform/
├── app/
│   ├── admin/
│   │   ├── controller/
│   │   ├── middleware/
│   │   ├── route/
│   │   └── validate/            # when needed
│   ├── api/
│   │   ├── controller/
│   │   ├── middleware/
│   │   └── route/
│   ├── web/
│   │   ├── controller/
│   │   ├── middleware/
│   │   └── route/
│   ├── worker/
│   │   └── command/
│   │       └── OpenPlatformProvisioningWorkerCommand.php
│   ├── common/
│   │   ├── audit/
│   │   ├── context/
│   │   ├── contract/
│   │   ├── error/
│   │   ├── http/
│   │   ├── infrastructure/
│   │   ├── middleware/
│   │   └── security/
│   ├── AppService.php
│   ├── ExceptionHandle.php
│   ├── provider.php
│   └── service.php
│
├── modules/
│   ├── account/
│   ├── entitlement/
│   ├── iam/
│   ├── member/
│   ├── miniapp/
│   ├── module/
│   ├── oauth/
│   ├── openplatform/
│   ├── quota/
│   ├── site/
│   ├── tenant/
│   ├── theme/
│   ├── webhook/
│   └── integration/
│       └── legacy/
│
├── config/
├── database/
├── docs/
├── legacy/                       # existing documentation-only compatibility scaffold; unchanged in this slice
├── public/
├── tests/
├── composer.json
└── think
```

The existing root `legacy/README.md` is documentation-only and is not a ThinkPHP application. It remains unchanged in this slice. Runtime compatibility classes currently under `app/legacy/` move to `modules/integration/legacy/`.

## 7. Exact namespace and path mapping

The migration SHALL use these mappings:

| Current path | Target path | Namespace mapping |
| --- | --- | --- |
| `app/account/` | `modules/account/` | `app\\account\\*` -> `modules\\account\\*` |
| `app/entitlement/` | `modules/entitlement/` | `app\\entitlement\\*` -> `modules\\entitlement\\*` |
| `app/iam/` | `modules/iam/` | `app\\iam\\*` -> `modules\\iam\\*` |
| `app/legacy/` | `modules/integration/legacy/` | `app\\legacy\\*` -> `modules\\integration\\legacy\\*` |
| `app/member/` | `modules/member/` | `app\\member\\*` -> `modules\\member\\*` |
| `app/miniapp/` | `modules/miniapp/` | `app\\miniapp\\*` -> `modules\\miniapp\\*` |
| `app/module/` | `modules/module/` | `app\\module\\*` -> `modules\\module\\*` |
| `app/oauth/` | `modules/oauth/` | `app\\oauth\\*` -> `modules\\oauth\\*` |
| `app/openplatform/` | `modules/openplatform/` | `app\\openplatform\\*` -> `modules\\openplatform\\*` |
| `app/quota/` | `modules/quota/` | `app\\quota\\*` -> `modules\\quota\\*` |
| `app/site/` | `modules/site/` | `app\\site\\*` -> `modules\\site\\*` |
| `app/tenant/` | `modules/tenant/` | `app\\tenant\\*` -> `modules\\tenant\\*` |
| `app/theme/` | `modules/theme/` | `app\\theme\\*` -> `modules\\theme\\*` |
| `app/webhook/` | `modules/webhook/` | `app\\webhook\\*` -> `modules\\webhook\\*` |
| `app/command/OpenPlatformProvisioningWorkerCommand.php` | `app/worker/command/OpenPlatformProvisioningWorkerCommand.php` | `app\\command\\OpenPlatformProvisioningWorkerCommand` -> `app\\worker\\command\\OpenPlatformProvisioningWorkerCommand` |

`app/admin`, `app/api`, `app/web`, `app/common`, `app/AppService.php`, `app/ExceptionHandle.php`, `app/provider.php`, and `app/service.php` remain under `app/`.

## 8. Composer/autoload contract

`composer.json` SHALL expose both roots:

```json
"autoload": {
  "psr-4": {
    "app\\\\": "app/",
    "modules\\\\": "modules/"
  }
}
```

After the migration, a clean `composer dump-autoload` / `composer install` must resolve all moved classes without classmap workarounds or compatibility aliases.

No permanent `class_alias()` bridge is allowed. The release should fail if stale `app\\<business-module>\\...` references remain.

## 9. Entry-layer and dependency contract

The application layer owns delivery adapters only:

```text
app/admin   -> modules/* application services
app/api     -> modules/* application services
app/web     -> modules/* application services
app/worker  -> modules/* application services
```

Entry applications MAY depend on `app/common` and `modules/*`.

Business modules MUST NOT depend on:

- `app\\admin\\...`
- `app\\api\\...`
- `app\\web\\...`
- `app\\worker\\...`

Within each business module, dependency direction SHALL remain compatible with the V4 rule:

```text
Application -> Domain <- Infrastructure
```

In particular:

- `modules/*/domain` MUST NOT import ThinkPHP/framework classes;
- `modules/*/domain` MUST NOT import HTTP/CLI entry namespaces;
- infrastructure adapters MAY depend on framework classes and SHALL implement/serve contracts needed by Application/Domain;
- business code MAY use true shared-kernel contracts/value objects from `app/common`, but Domain MUST NOT reach into `app/common/infrastructure` or delivery middleware.

This keeps HTTP/CLI/framework details outside the business domain.

## 10. Shared-kernel contract

`app/common` remains the shared kernel because the current classes are cross-cutting runtime concerns, including RequestContext, Principal, audit interfaces/implementation, transaction abstraction, error envelope, API response helpers, and secret wrappers.

`app/common` MUST NOT acquire business-specific services such as Account, Tenant, OpenPlatform, OAuth, Quota, Member, Site, or Theme application services.

This migration does not move current shared-kernel classes unless a specific architecture test proves one is business-specific.

## 11. Worker/composition-root contract

The existing provisioning worker command is a CLI adapter and SHALL move from `app/command/` to `app/worker/command/`.

Its external command remains exactly:

```bash
php think openplatform:provisioning-worker
```

The command continues delegating to the OpenPlatform application layer. Its business collaborators move to `modules\\openplatform\\...`.

`config/console.php` currently registers `app\\command\\OpenPlatformProvisioningWorkerCommand`; it SHALL be updated to register `app\\worker\\command\\OpenPlatformProvisioningWorkerCommand` while preserving the external command name and options.

`app/AppService.php` remains the release composition root for this slice. It SHALL be updated only as needed for moved namespaces/bindings. Splitting it into module-specific service providers is intentionally deferred.

## 12. ThinkPHP multi-app safety

The architecture contract SHALL prevent business modules from reappearing under `app/`.

After migration, first-level runtime application directories under `app/` are limited to:

```text
admin
api
web
worker
common
```

Top-level PHP bootstrap/service files are allowed separately.

`config/app.php` SHALL continue to deny non-HTTP application directories from direct multi-app dispatch. At minimum `common` and `worker` remain in `deny_app_list`.

The important invariant is not merely the deny list: business modules no longer live beneath the ThinkPHP multi-app root at all.

## 13. Architecture contract tests

A new contract test SHALL run in the normal offline suite before implementation is considered complete.

It SHALL verify at least:

1. Business directories no longer exist under `app/`:
   - `account`
   - `entitlement`
   - `iam`
   - `legacy`
   - `member`
   - `miniapp`
   - `module`
   - `oauth`
   - `openplatform`
   - `quota`
   - `site`
   - `tenant`
   - `theme`
   - `webhook`
   - old `command`
2. Required target module directories exist under `modules/`.
3. `app/worker/command/OpenPlatformProvisioningWorkerCommand.php` exists.
4. `composer.json` maps `app\\` to `app/` and `modules\\` to `modules/`.
5. Moved source files declare `modules\\...` namespaces consistent with their paths.
6. No active source/test/config references stale business namespaces under `app\\account`, `app\\iam`, `app\\openplatform`, etc.
7. `config/console.php` registers the worker command through `app\\worker\\command` and contains no stale `app\\command` registration.
8. Business-module code does not import `app\\admin`, `app\\api`, `app\\web`, or `app\\worker`.
9. `modules/*/domain` source does not import `think\\...`, `app\\common\\infrastructure`, or delivery middleware/entry namespaces.
10. The offline suite actually executes the architecture contract on `require`, avoiding the earlier Provider-E2E false-green pattern.

The contract may scan source text, but it must avoid false positives from archived design-source documents that intentionally describe historical paths. Runtime code, active tests, active config, and active README/release docs are the enforcement scope.

## 14. TDD migration strategy

Implementation SHALL use a RED -> GREEN -> REFACTOR sequence:

### RED

Add the architecture contract first. On the current R8D tree it must fail because business modules still exist under `app/` and `modules/` does not yet contain the required runtime modules.

A test that is green before migration is not sufficient evidence.

### GREEN

Perform the minimum structural migration needed to satisfy the contract:

1. Add `modules\\` PSR-4 mapping.
2. Move one bounded context at a time.
3. Update each moved file's namespace.
4. Update all imports/references after each bounded-context move.
5. Move the CLI command to `app/worker/command/`.
6. Update `config/console.php`, `AppService`, tests, and active docs.
7. Regenerate Composer autoload metadata.

### REFACTOR

Only cleanup directly caused by the migration is allowed. No unrelated domain/API/provider redesign is permitted.

## 15. Migration ordering

To reduce blast radius, implementation SHOULD proceed in dependency-aware batches rather than moving all directories blindly:

```text
A. architecture contract + Composer mapping
B. low-level/shared business modules
   tenant / account / iam / entitlement / quota
C. content/runtime modules
   module / site / theme
D. identity/integration modules
   member / oauth / webhook / miniapp
E. OpenPlatform + legacy integration runtime
   openplatform / integration/legacy
F. CLI adapter + command registration
   app/command -> app/worker/command + config/console.php
G. composition root + registration cleanup
H. stale namespace/path scan
I. full release verification
```

The exact batch order may be adjusted if dependency inspection proves another order is safer, but a partially migrated batch must not be committed as a claimed release candidate unless its tests are green.

## 16. Behavior-preservation acceptance criteria

After migration, all of the following external behavior must remain identical:

- `/admin/*`, `/`, `/api/v1/*` routing semantics;
- OpenPlatform ticket/events/authorization/provisioning endpoints;
- admin Bearer/Tenant request context behavior;
- R8D authorization intent modes;
- authorizer metadata classification;
- provisioning statuses and transition semantics;
- ownership uniqueness and reconnect behavior;
- quota consume/release/idempotency semantics;
- encrypted credential/ticket/token storage behavior;
- provisioning worker command name/options/exit behavior;
- database migrations 001-009 and resulting schema.

Any failure in these areas is treated as a regression, not an expected consequence of the directory move.

## 17. Verification gate

The migrated exact HEAD must pass, in order:

1. `composer validate --strict`
2. committed `composer.lock` consistency
3. `composer install` from the lock and/or clean `composer dump-autoload`
4. architecture contract RED evidence before migration, then GREEN after migration
5. `php tests/run.php`
6. PHPUnit bridge
7. project PHP lint
8. ThinkPHP HTTP smoke
9. `php think list` and provisioning-worker command visibility
10. MySQL 8.4 R8D Release Gate / fresh-database acceptance
11. fresh comparison with `main` showing no unexpected behind/race condition
12. exact-HEAD GitHub Actions GREEN
13. only then: real WeChat Provider E2E first authorization -> `provisioned`
14. same authorizer + same Tenant second authorization -> `reconnected` with no second quota consume

The previous exact HEAD `1bfa3ded63ff886483f01ac99bf6efedfa549aba` remains historical automated evidence only. It is not sufficient release evidence after this architecture migration.

## 18. Release policy

PR #7 remains Draft during this migration.

Do not mark the PR Ready, merge to `main`, or tag V1 until:

- architecture migration is complete;
- all automated gates pass on the new exact feature HEAD;
- the real Provider E2E passes on that same exact HEAD;
- final PR review is complete;
- post-merge `main` CI passes on the exact merge SHA.

## 19. Success criteria

This design is complete when a developer can inspect the repository and answer these questions from the physical structure alone:

- Where does an HTTP/CLI request enter? -> `app/*`
- Where does business behavior live? -> `modules/*`
- Where are cross-cutting runtime primitives? -> `app/common/*`
- Where does the provisioning daemon enter? -> `app/worker/command/*`
- Can a business module accidentally become a ThinkPHP multi-app endpoint? -> No, because it is outside `app/`.
- Can architecture drift silently return? -> No, the offline Architecture Contract Gate fails.

The intended outcome is an architecture-only release correction: clearer boundaries and safer framework behavior with zero R8D business-semantic change.
