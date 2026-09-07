# R20 Site / Domain / Theme Compatibility

## Legacy identifiers

R20 uses three separate identifiers in the site runtime:

- `uniacid`: account context;
- `multiid`: site instance (`site_multi.id`);
- `styleid`: selected style (`site_styles.id`).

R6 keeps these meanings separate. A migrated Site may retain `legacy_multi_id`; style/template IDs are carried by R20 compatibility snapshots rather than being renamed to the account ID.

## Default Site

`uni_settings.default_site` is the legacy default-site pointer. R20 admin logic forces this Site to status `1` and blocks normal disable/delete. Therefore an R20 default-site row with stale `status=0` is normalized to enabled during migration. This is `MUST_COMPAT`.

## Domain routing

R20 root routing checks `site_multi.bindhost` first. Account-level `uni_settings.bind_domain` + `default_module` is considered later. `site_multi.bindhost` has only a non-unique index, so duplicate hosts are ambiguous.

R6 stores normalized host-only values and enforces one globally unique binding. Migration must import `site_multi.bindhost` before account `bind_domain`. If both claim the same host, keep the `site_multi` binding and surface the discarded account binding as a migration conflict/report so R20 precedence is preserved. The uniqueness hardening is `INTENTIONAL_FIX`.

## Themes and styles

R20 uses:

- `site_templates`: template package metadata, including version;
- `site_styles`: one style instance pointing at a template;
- `site_styles_vars`: editable key/value settings.

`R20ThemeStyleSnapshotMapper` preserves this state. Duplicate keyed style variables are resolved by the last matching row, matching the effective keyed-result behavior used by R20 callers.

R6 converts editable state to immutable StyleSnapshot values before publishing. A SiteThemeRelease always references a fixed ThemeVersion + StyleSnapshot.

## Rendering safety

R20 `template_compile()` compiles template syntax into executable PHP. Directives such as `{php}` and `{hook}` can execute server-side code.

R6 does not preserve this execution model. `SafeThemeRenderer` supports only explicitly declared escaped scalar placeholders and rejects server-execution/control directives. This is an `INTENTIONAL_FIX` required by the V4 Theme architecture: a Theme is a presentation package, not an arbitrary server-code package.

## Publication and rollback

R6 publication is append-only:

1. lock Site row;
2. verify current release pointer;
3. enforce idempotency;
4. persist immutable StyleSnapshot if needed;
5. append SiteThemeRelease;
6. update `sites.active_theme_release_id`;
7. commit.

Rollback creates another release pointing to a prior snapshot instead of mutating historical releases.

## Deferred R20 behaviors

R6 intentionally does not migrate `site_nav`, `site_category`, `site_article`, `site_page` or the shallow site-copy workflow. Those belong to a later content/CMS slice. The profile-specific serialized bind-domain representation is also deferred until its independent entry path is migrated.
