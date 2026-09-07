# R6 Site + Domain + Theme Runtime Design

## Goal

Implement the runtime foundation for EPIC-06 without prematurely migrating CMS content. R6 establishes explicit Site identity, deterministic host binding, immutable theme publication, R20 style compatibility snapshots, and a non-server-executable rendering boundary.

## Source behavior verified from R20

- `uniacid`, `multiid`, and `styleid` are different identifiers and must remain different in the new model.
- Root `index.php` checks `site_multi.bindhost` before account-level `uni_settings.bind_domain` handling.
- `uni_settings.default_site` identifies the default site; R20 admin code forces that site back to `status=1` and prevents normal disable/delete.
- `site_styles` binds a style instance to a template; `site_styles_vars` stores variable values; `site_templates.version` carries the template version.
- R20 template compilation turns template syntax into PHP and supports server-executable directives such as `{php}` and `{hook}`.

## Domain model

### Site

`Site` owns the new site identity and keeps `tenantId`, `accountId`, optional `legacyMultiId`, status, default flag, and the active theme-release pointer. A default site cannot be disabled.

### DomainBinding

`DomainBinding` maps one normalized host to one Site and records its source:

- `NATIVE`
- `R20_SITE_MULTI`
- `R20_ACCOUNT_BIND_DOMAIN`

The database makes `host` globally unique. This intentionally removes the R20 ambiguity where `site_multi.bindhost` is only indexed and the first matching row wins. During migration, `site_multi.bindhost` must be imported before account-level `bind_domain`; a collision is resolved in favor of the former to preserve R20 routing precedence.

### Theme

`ThemeDefinition` is the package identity. `ThemeVersion` is immutable package-version metadata. `StyleInstance` is the editable tenant-scoped state and increments a revision on change. `StyleSnapshot` canonicalizes and hashes one immutable revision.

### SiteThemeRelease

A publish creates an append-only `SiteThemeRelease` containing ThemeVersion + StyleSnapshot and a predecessor pointer. Rollback never mutates/re-activates an old row: it creates a new release that references the old snapshot and records `rollbackOfReleaseId`.

`sites.active_theme_release_id` is only advanced inside the same database transaction that writes the release. The site row is locked to prevent concurrent lost updates.

## Idempotency

`(tenant_id, site_id, idempotency_key)` is unique. Application-level replay compares ThemeVersion plus snapshot content hash. The infrastructure performs the same semantic comparison after locking the Site, so two concurrent equivalent requests remain idempotent even if they independently generated different release/snapshot IDs.

## Request context and audit

Theme publish/rollback requires the existing immutable `RequestContext`. Tenant/account/site context must match the Site and an authenticated principal is required. Successful publish/rollback records the existing common `AuditEvent` contract with actor, tenant, account, action, result, request ID, trace ID, site/release metadata, and idempotency key.

## Safe rendering boundary

`SafeThemeRenderer` is deliberately narrower than R20's PHP compiler:

- only `{{ field }}` scalar substitution;
- all fields declared by `ViewContract` are required;
- all output values are HTML-escaped;
- undeclared, missing, or non-scalar values fail closed;
- PHP/template/hook/data/control directives (`<?`, `{php}`, `{hook}`, `{template}`, `{data}`, `{if}`, `{loop}`, etc.) are rejected;
- no `eval()` or PHP include execution is part of the renderer.

This is an `INTENTIONAL_FIX`, not a compatibility regression. Rich control/components can be added later only through explicit safe contracts.

## Persistence

R6 adds:

- `sites`
- `domain_bindings`
- `themes`
- `theme_versions`
- `style_instances`
- `style_snapshots`
- `site_theme_releases`

All tables use MySQL 8 / InnoDB / utf8mb4. Tenant-scoped tables carry explicit `tenant_id` and indexes. A generated nullable key enforces one default Site per account.

## Compatibility classification

### MUST_COMPAT

- separate `uniacid` / `multiid` / `styleid` meaning;
- default Site cannot be effectively disabled;
- `site_multi.bindhost` routing precedence;
- legacy template/style/version/style-variable data remains recoverable in snapshots;
- account `bind_domain` preserves `default_module` metadata.

### INTENTIONAL_FIX

- one unambiguous host binding instead of first-row wins;
- server-side arbitrary PHP/hook template execution is rejected;
- publish/rollback is immutable and transactional;
- concurrent idempotent requests compare semantic content, not generated IDs.

### DEFERRED

- `site_nav`, `site_category`, `site_article`, `site_page` CMS/content model;
- R20 shallow site-copy workflow;
- full legacy template loop/condition/component syntax;
- profile-specific serialized bind-domain variant;
- admin CRUD HTTP endpoints for Site/Domain/Theme catalogs.
