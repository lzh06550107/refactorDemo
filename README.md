# WePlatform ThinkPHP Refactor

Strangler-style refactor of WeEngine 2.7.4/R20 onto ThinkPHP 8, implemented incrementally from the supplied V4 design.

## Current implementation: R8B

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

### R8A — MiniApp Identity + Secure Session
- WeChat Mini Program `code -> jscode2session -> openid/unionid/session_key` is preserved for both manual and component-authorized modes.
- MiniApp identities reuse R7 `ExternalIdentity` with `provider_account_id = internal Account.id`; the same openid cannot become a global identity across accounts.
- Client-supplied `openid` is never accepted as authentication evidence; the weak R20 direct-openid session-restore path is intentionally removed.
- Login exchange happens before the final DB transaction; Member/ExternalIdentity resolution, session insertion, and success audit commit atomically.
- The client receives a 256-bit opaque MiniApp token; only its SHA-256 hash is persisted and the session TTL is exactly 1800 seconds.
- WeChat `session_key` is protected at rest with AES-256-GCM using randomized 96-bit IVs, authentication tags, and explicit key versions.
- Legacy encrypted-profile compatibility keeps `sha1(rawData + session_key)`, AES-128-CBC payload decryption, and `watermark.appid` validation.
- R20 MiniApp provider snapshots are read-only and use account type 4/7 as the explicit manual/component mode fact; raw legacy secrets/tokens are never serialized.

### R8B — OpenPlatform Component Trust Chain
- `ComponentPlatform` is operator/platform-scoped shared infrastructure; it intentionally has no `tenant_id`, while R8A Account bindings reference an explicit `component_platform_id`.
- Encrypted WeChat component callbacks are accepted only after the 128 KiB body bound, hardened XML parsing, ±300-second freshness check, `msg_signature` verification, AES-256-CBC frame validation, and both framed/inner Component AppId checks.
- Replay identity is `(component_platform_id, SHA-256(platform + timestamp + nonce))`; exact replay is semantic success while same replay identity/different encrypted payload is a conflict.
- The current `component_verify_ticket` is versioned by signed source timestamp and stored only as AES-256-GCM ciphertext plus non-secret metadata.
- Component access tokens are fetched only from the authenticated current ticket chain, use provider `expires_in`, and are encrypted at rest with explicit key versions.
- Token refresh uses a platform-scoped 30-second database lease and holder/version/expiry compare-and-set; provider HTTP is never executed inside a database transaction.
- A still-unexpired token remains a degraded fallback on provider failure; expired/missing state fails closed with stable 502/503 errors.
- R8A component-mode MiniApp login consumes R8B only through `OpenPlatformComponentAccessTokenProvider`; verify tickets and Component AppSecrets never enter MiniApp code.
- Historical fixed/global component-token cache state is deliberately not imported because its Component Platform scope is ambiguous.
- R8C authorizer authorization, `pre_auth_code`, authorizer refresh/access tokens, and authorization lifecycle remain explicitly deferred.

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

MiniApp login:
Tenant + Account + MiniApp provider binding
  -> manual jscode2session OR R8B component access-token service
  -> provider-account-scoped ExternalIdentity
  -> opaque token + protected session_key
  -> atomic Member identity + MiniApp session + audit

OpenPlatform component trust:
explicit ComponentPlatform route id
  -> bounded outer XML
  -> freshness + msg_signature
  -> WeChat AES frame + Component AppId checks
  -> platform-scoped replay inbox + current verify ticket
  -> short refresh lease
  -> component token provider HTTP (outside DB transaction)
  -> holder/version/expiry CAS
  -> R8A ComponentAccessTokenProvider

Webhook:
raw request metadata
  -> signature + freshness verification
  -> minimal XML parsing
  -> provider-account-scoped Inbox dedup
  -> dispatch once
```

Entitlement, runtime availability, and authorization are distinct gates. Provider identities are account-scoped, while Component Platform credentials are operator-scoped shared infrastructure with explicit Account bindings.

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
