# WePlatform ThinkPHP Refactor

Strangler-style refactor of WeEngine 2.7.4/R20 onto ThinkPHP 8, implemented incrementally from the supplied V4 design.

## Current implementation: R7

### R1 — Foundation Runtime
- ThinkPHP multi-app skeleton (`admin`, `web`, `api`, `common`).
- Immutable `RequestContext`, stable error envelope, audit/security foundations.
- R20 entrypoint Golden Master mapping.

### R2 — IAM + Tenant + Account
- `AdminUser`, server-side `AdminSession`, Tenant/Account boundaries.
- Explicit `LegacyAccountMapping` for `uniacid`/`acid`.
- R20 account-type and role compatibility rules.

### R3 — IAM Authorization + Module Platform
- Permission/ACL domain and R20 permission naming compatibility.
- Module definition/support/account overlay runtime.
- Binding router and legacy module adapter.
- Module-platform schema foundations.

### R4 — R20 Read Runtime + Plugin Dependency
- Directional `ModulePluginRelation`: a plugin is not runnable without its main module.
- Read-only `LegacyDatabase`/`ThinkPhpLegacyDatabase`; R20 tables are never written by this slice.
- Safe R20 serialized-value decoder with `allowed_classes=false`, including repair path.
- R20 module runtime/permission repositories preserve recycle, binding, plugin/main, and pipe-delimited permission semantics.
- Runtime availability is enforced before user authorization.

### R5 — Entitlement + Quota
- Commercial module entitlement is separate from IAM authorization and runtime compatibility.
- Account-creation quota is isolated per account type and preserves R20 outcome snapshots as Golden-Master evidence.
- Purchase quota is consumed before non-purchase quota; reseller/parent pools cap non-purchase capacity only.
- Grant/consume/release/expire ledger operations are idempotent and transactionally rechecked at the write boundary.
- The R20 unconditional account-create bypass is classified as an intentional correctness fix.

### R6 — Site + Domain + Theme Runtime
- Tenant/account-scoped Site and normalized Domain resolution with cross-tenant conflict protection.
- R20 `uniacid`, `multiid`, and `styleid` remain distinct compatibility fields.
- Immutable ThemeVersion/StyleSnapshot publication and rollback model with deterministic snapshots.
- Safe renderer rejects legacy server-execution/control directives instead of evaluating arbitrary template PHP.
- Publish/rollback uses row locking, semantic idempotency, RequestContext isolation, and audit events.

### R7 — Member + OAuth + Webhook
- Tenant-scoped canonical `Member` plus provider-account-scoped `ExternalIdentity` keyed by `(provider_type, provider_account_id, external_subject)`; there is no global `openid` identity key.
- Read-only R20 `mc_members` / `mc_mapping_fans` snapshots preserve `uniacid`, `acid`, `uid`, `openid`, and `unionid` independently.
- Borrowed OAuth keeps business Account and OAuth provider Account as separate context; state is a 256-bit opaque token with only its SHA-256 hash persisted and a 10-minute TTL.
- OAuth provider code exchange occurs outside DB locks; identity resolution and state finalization commit atomically under a locked state row.
- WeChat webhook verification uses a 300-second freshness window and constant-time signature comparison before XML parsing.
- Webhook Inbox deduplicates on `(provider_type, provider_account_id, provider_event_key)` and persists only the raw-body SHA-256; same-key/different-body replay is rejected.
- R7 does not write legacy R20 tables and deliberately defers full MiniApp token chains, AES message decryption, reply processors, and payment flows.

## Runtime rule order

```text
Account/Tenant context
  -> commercial entitlement / quota when applicable
  -> module runtime availability
  -> plugin dependency
  -> user authorization

Member OAuth:
business Account + provider binding
  -> opaque OAuth state
  -> provider code exchange (outside DB lock)
  -> lock/revalidate state
  -> provider-account-scoped ExternalIdentity
  -> atomic Member identity + state finalization

Webhook:
raw request metadata
  -> signature + freshness verification
  -> minimal XML parsing
  -> provider-account-scoped Inbox dedup
  -> dispatch once
```

Entitlement, runtime availability, and authorization are distinct gates. A permission row cannot resurrect an unavailable module, and an `openid` from one provider Account cannot identify a user under another provider Account.

## Development verification

Offline gate:

```bash
php tests/run.php
```

Networked/CI gate:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

GitHub Actions also boots ThinkPHP and smoke-tests `/health`, `/admin/health`, and `/api/v1/health`.

See `docs/migration/` for R20 compatibility boundaries and `docs/verification/` for phase-specific evidence.
