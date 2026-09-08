# R7 Member / External Identity / OAuth / Webhook Design

> Date: 2026-09-08
> Baseline: `main@13da0f72fdbe4b7fa2062179d1ce1b359922be1b` (R6)
> Source baseline: WeEngine 2.7.4 R20 deep-source archive
> Approved approach: layered Member identity + OAuth state machine + Webhook Inbox

## 1. Goal

Build the first public-identity and provider-callback foundation on top of R1-R6 without entering payment or content scope.

R7 must provide four independent but composable boundaries:

1. Tenant-scoped `Member` identity.
2. Provider-account-scoped `ExternalIdentity` mapping for `openid` / `unionid`-style subjects.
3. Explicit OAuth borrowing with single-use, short-lived state that preserves the business account while identifying the OAuth provider account.
4. WeChat webhook verification and inbox idempotency that verifies the raw request before parsing or dispatch.

## 2. Scope

### Included

- Member domain foundation.
- ExternalIdentity unique identity boundary.
- R20 `mc_members` / `mc_mapping_fans` read-only migration snapshots.
- OAuthBinding between a business Account and a provider Account.
- OAuthState issue / consume lifecycle.
- Redirect allowlist policy.
- Borrowed OAuth semantics.
- WeChat plaintext signature verification contract.
- Timestamp freshness / replay protection policy.
- Webhook Inbox idempotency contract.
- Provider event normalization boundary.
- MySQL 8 migration and rollback.
- Unit / security / Golden Master / schema contract tests.

### Deferred

- ST-06-02 Credit Ledger.
- Member password / OTP login and MemberSession cookie implementation.
- Full MiniApp login / token chain.
- WeChat AES decrypt implementation beyond the verifier interface boundary.
- Module message processors / reply rules.
- Payment / refund / fulfillment.
- Outbox dispatcher and worker retry runtime.

## 3. R20 Evidence

The executable R20 source is authoritative over old comments or design notes.

### 3.1 Member and fan identity

Relevant source:

- `framework/table/Mc/MappingFans.php`
- `framework/model/mc.mod.php`
- `app/source/auth/oauth.ctrl.php`
- `data/db.php`

Confirmed behavior:

- `mc_mapping_fans` carries `acid`, `uniacid`, `uid`, `openid`, `unionid`, follow state and profile metadata.
- Runtime lookups commonly combine `openid + uniacid` or `unionid + uniacid`.
- R20 schema nevertheless declares a global unique index on `mc_mapping_fans.openid` (`openid_2`).
- Borrowed OAuth may obtain an OAuth openid from another provider account while business member/fan data remains under the current business `uniacid`.

The global unique `openid` index cannot correctly express provider-account identity boundaries in the target model.

### 3.2 OAuth borrowing and state restore

Relevant source:

- `framework/model/mc.mod.php`
- `app/common/bootstrap.app.inc.php`
- `app/source/auth/oauth.ctrl.php`
- `framework/model/user.mod.php`

Confirmed behavior:

- R20 supports borrowing another capable official account for OAuth/JSSDK.
- Business `uniacid` is not automatically replaced by the borrowed OAuth account.
- R20 generates OAuth state as `we7sid-<session_id>`.
- `app/common/bootstrap.app.inc.php` extracts the suffix and installs it directly as the PHP session id.
- No HMAC-bound business context, short TTL, nonce uniqueness or single-use state consumption is enforced by that legacy path.

### 3.3 WeChat webhook verification

Relevant source:

- `framework/class/account/weixin.account.class.php`
- `api.php`

Confirmed behavior:

- `WeixinAccount::checkSign()` computes SHA1 over sorted token/timestamp/nonce.
- Signature verification itself does not enforce timestamp freshness.
- No nonce / request fingerprint replay store exists at this boundary.
- `api.php` reads the raw request body and only enters message parsing after signature checking; this ordering must be preserved and strengthened.

## 4. Compatibility Classification

### MUST_COMPAT

1. `Member` belongs to the business Tenant, not the OAuth provider Tenant/account.
2. Borrowed OAuth must preserve both `businessAccountId` and `oauthProviderAccountId`.
3. OAuth-returned openid belongs to the provider account that issued it.
4. Same Member may bind multiple external identities.
5. Repeated successful OAuth callback for the same external identity must not create duplicate Members.
6. R20 legacy `uniacid`, `acid`, `uid`, `openid`, `unionid` source values must be retained in migration snapshots.
7. Webhook provider signature must be checked before provider message parsing / module dispatch.

### INTENTIONAL_FIX

1. Replace global `openid` uniqueness with provider-account-scoped external identity uniqueness.
2. Never identify a user by openid alone.
3. Replace `we7sid-<session_id>` state restore with opaque nonce state stored server-side.
4. OAuth state is short-lived and single-use.
5. Return URLs are allowlisted; arbitrary open redirects are rejected.
6. Webhook timestamp freshness is enforced.
7. Replay requests are deduplicated before business dispatch.
8. Provider credential / token material does not enter Member domain objects.

### UNSUPPORTED_LEGACY

- Treating OAuth `state` as a raw PHP session identifier.
- Global cross-provider lookup by `openid` only.

## 5. Target Architecture

```text
HTTP web callback / api webhook
          |
          v
Application services
  +----------------------+-----------------------+
  |                      |                       |
MemberIdentityService  OAuthOrchestrator  WechatWebhookService
  |                      |                       |
  v                      v                       v
Member/ExternalIdentity OAuthBinding/State  Raw Verify/Replay/Inbox
  |                      |                       |
  +----------+-----------+-----------+-----------+
             |                       |
             v                       v
      Repository ports        Provider adapter ports
             |
             v
   ThinkPHP Db infrastructure
```

Dependency rule remains:

```text
HTTP -> Application -> Domain <- Infrastructure
```

Domain classes must not import ThinkPHP Facades, Db, Request, Session, XML parsers or provider SDKs.

## 6. Member and ExternalIdentity Domain

### 6.1 Member

`Member` is the business-facing public principal inside one Tenant.

Minimum fields:

- `id`
- `tenantId`
- `status` (`ACTIVE`, `DISABLED`)
- `displayName?`
- `avatarUrl?`

R7 does not migrate the entire historical `mc_members` profile surface into the domain object. Large profile/detail fields remain migration metadata until a later profile story requires them.

### 6.2 ExternalIdentity

Fields:

- `id`
- `tenantId`
- `memberId`
- `providerType`
- `providerAccountId`
- `externalSubject` (for WeChat official OAuth this is openid)
- `unionId?`
- `legacyUniacid?`
- `legacyAcid?`
- `legacyUid?`

Canonical unique key:

```text
(provider_type, provider_account_id, external_subject)
```

`tenant_id` is persisted and checked as an ownership invariant, but it is not a substitute for the provider-account key.

UnionId is an attribute / secondary correlation hint. R7 does not globally merge Members merely because two records share a unionId; any later merge must be an explicit audited operation with provider-specific policy.

## 7. MemberIdentityService

Primary operation:

```php
resolveOrCreate(
    string $tenantId,
    ProviderIdentity $providerIdentity,
    LegacyIdentitySnapshot|null $legacy = null,
): MemberIdentityResult
```

Rules:

1. Lookup external identity by provider key.
2. If found, require `tenantId` to match and return the existing Member.
3. If absent, create Member + ExternalIdentity in one transaction.
4. Concurrent duplicate callbacks must converge on the database unique key and return one semantic identity result.
5. Cross-tenant collision is `CONFLICT`, never silent reassignment.

Unbind / member merge are intentionally outside R7.

## 8. OAuth Borrowing Model

### 8.1 OAuthBinding

Fields:

- `id`
- `tenantId`
- `businessAccountId`
- `providerType`
- `oauthProviderAccountId`
- `enabled`

Rules:

- business and provider account may be equal.
- if they differ, both accounts must belong to the same target Tenant policy allowed by Account service; R7 will not infer a tenant switch from provider credentials.
- provider capability validation belongs to an Account/OAuth provider port, not Member domain.

### 8.2 OAuthState

Fields:

- `id`
- `nonceHash`
- `tenantId`
- `businessAccountId`
- `oauthProviderAccountId`
- `providerType`
- `returnUrl`
- `issuedAt`
- `expiresAt`
- `consumedAt?`
- `resultMemberId?`
- `resultExternalIdentityId?`

The browser receives only an opaque random state token. Persistence stores a hash of the token, not the plaintext token.

Default TTL for R7: **10 minutes**.

### 8.3 Return URL policy

Accepted return targets:

- relative application paths beginning with `/` but not `//`.
- optionally configured absolute HTTPS origins from an explicit allowlist.

Rejected:

- `javascript:` or other non-HTTP schemes.
- scheme-relative `//host` values.
- absolute origins not configured for the Tenant/site.

## 9. OAuth State Lifecycle

### Start

1. Resolve business Account.
2. Resolve configured OAuthBinding / provider Account.
3. Validate return URL.
4. Create 256-bit random state token.
5. Store `sha256(token)` with business/provider context and `expiresAt`.
6. Build provider authorization URL with opaque state.

### Callback

The provider code exchange is an external network operation and must not be performed while holding a database row lock. The callback therefore has a validation phase followed by one mandatory finalization transaction.

1. Hash the presented state token and load the state context.
2. Reject missing, malformed or expired state with `UNAUTHORIZED` / 401.
3. If the state is already consumed and contains a complete semantic result, return that same result as an idempotent callback replay. If it is consumed without a complete result, reject with `UNAUTHORIZED` / 401.
4. Exchange the provider code through `OAuthProviderClient` outside the database transaction.
5. Require the provider Account used/returned by the client to match the state; mismatch is `FORBIDDEN` / 403.
6. Begin the finalization database transaction and lock the OAuthState row by `nonceHash`.
7. Revalidate state existence, expiry, provider/business context and consumed status under the lock. A concurrent completed callback returns its already-stored semantic result.
8. Within this same transaction, resolve or create the Member/ExternalIdentity, write `resultMemberId` and `resultExternalIdentityId`, mark the state consumed, and commit once.
9. Return business Account context + Member identity + the allowlisted return URL.

**Hard atomicity invariant:** a successful Member/ExternalIdentity binding performed for an OAuth callback must never commit independently from that OAuthState's consumption and semantic result. State consumption without the corresponding identity result is likewise invalid. The repository/application boundary must provide one transaction that covers identity persistence plus state finalization.

The provider openid is never written as a business Account openid when OAuth is borrowed.

## 10. WeChat Webhook Boundary

### 10.1 Request envelope

The controller must construct a `WechatWebhookRequest` containing:

- provider Account key from route.
- raw body bytes/string exactly as received.
- `signature` or `msg_signature`.
- `timestamp`.
- `nonce`.
- `encrypt_type?`.

No XML/JSON business parsing occurs before verifier success.

### 10.2 Signature verifier

R7 plaintext signature contract preserves WeChat semantics:

```text
sha1(sort(token, timestamp, nonce)) == signature
```

Requirements:

- constant-time comparison (`hash_equals`).
- numeric timestamp parsing.
- configured freshness window: **300 seconds** past/future skew.
- token supplied only by provider Account secret adapter.

AES verification/decrypt remains a provider adapter extension point and is deferred from production implementation in R7 unless required by an existing test fixture.

### 10.3 Replay key and Inbox

Verification freshness does not itself make webhooks idempotent.

After signature verification and account resolution, compute / extract a stable provider event key:

1. Use provider message `MsgId` when present.
2. For event callbacks without MsgId, derive a deterministic SHA-256 key from provider account id + FromUserName + ToUserName + CreateTime + MsgType + Event + EventKey + raw-body hash.

Persist to `webhook_inbox` under unique:

```text
(provider_type, provider_account_id, provider_event_key)
```

If the same key and same raw-body hash already exists, return duplicate ACK without dispatching side effects again.

If the same provider event key is reused with a different raw-body hash, return `CONFLICT` and emit security audit metadata.

## 11. Webhook Processing Order

Hard invariant:

```text
Raw Body
  -> Resolve provider account secret metadata
  -> Signature verify
  -> Timestamp freshness
  -> Parse minimal provider event identity
  -> Inbox insert/deduplicate
  -> Normalize IntegrationEvent
  -> Dispatch application port
  -> Provider ACK
```

Invalid signature, stale timestamp or unresolved account never reaches the dispatcher.

R7 dispatcher is an interface/recording port only; module message business execution is deferred.

## 12. R20 Compatibility Snapshots

### LegacyMemberSnapshot

Preserve at least:

- legacy uid
- uniacid
- group id
- nickname
- avatar
- status / blacklist-relevant flags when present

### LegacyFanIdentitySnapshot

Preserve:

- fanid
- uniacid
- acid
- uid
- openid
- unionid
- follow
- followtime
- unfollowtime
- user_from

Migration adapter must not use the R20 global openid unique constraint as the target identity key.

Rows whose provider account (`acid`) cannot be mapped to an R2 Account are marked unresolved for migration diagnostics rather than guessed.

## 13. Persistence Model

Migration `_005` creates:

1. `members`
2. `external_identities`
3. `oauth_bindings`
4. `oauth_states`
5. `webhook_inbox`

All tables:

- MySQL 8
- InnoDB
- utf8mb4 / utf8mb4_unicode_ci
- explicit foreign keys to existing `tenants` / `accounts` where applicable

Key constraints:

```text
external_identities:
  UNIQUE (provider_type, provider_account_id, external_subject)
  INDEX  (tenant_id, member_id)

oauth_bindings:
  UNIQUE (tenant_id, business_account_id, provider_type)

oauth_states:
  UNIQUE (nonce_hash)
  INDEX  (tenant_id, business_account_id, expires_at)

webhook_inbox:
  UNIQUE (provider_type, provider_account_id, provider_event_key)
  INDEX  (tenant_id, received_at)
```

Down migration drops in reverse FK order.

## 14. Error Semantics

Use the existing stable `AppException` / `ErrorCode` enum. R7 does not add a new global error taxonomy.

Required outcomes:

- explicit missing identity/member lookup: `NOT_FOUND` / 404.
- cross-tenant identity collision: `CONFLICT` / 409.
- OAuth state missing, malformed, expired, consumed without a complete semantic result, or otherwise not consumable: `UNAUTHORIZED` / 401.
- OAuth provider-account mismatch against an otherwise valid state: `FORBIDDEN` / 403.
- return URL rejected: `INVALID_ARGUMENT` / 400.
- webhook signature invalid or timestamp outside the freshness window: `UNAUTHORIZED` / 401.
- webhook event key reused with a different payload: `CONFLICT` / 409.

## 15. Audit Requirements

Audit at least:

- `member.identity.bound`
- `oauth.state.issued`
- `oauth.callback.succeeded`
- `oauth.callback.rejected`
- `webhook.replay.conflict`

Audit payload must include RequestContext identifiers when a user-facing HTTP request exists, and must never include access tokens, app secrets or raw OAuth codes.

## 16. Security Invariants

1. No global openid lookup.
2. No raw session id in OAuth state.
3. No OAuth access token in Member/ExternalIdentity persistence.
4. No arbitrary redirect target.
5. No webhook parse/dispatch before signature verification.
6. No signature-only replay assumption: freshness + Inbox idempotency are separate controls.
7. No cross-tenant identity rebinding.
8. Provider secrets remain infrastructure values.
9. OAuth identity persistence and state finalization are one atomic database transaction.

## 17. Test Matrix

R7 must add tests for at least:

### Unit

- ExternalIdentity key includes provider account.
- same openid under two provider accounts produces two legal identities.
- OAuthBinding preserves business/provider account distinction.
- ReturnUrlPolicy accepts relative/allowlisted HTTPS and rejects unsafe values.
- OAuthState expires after 10 minutes and supports semantic idempotent replay only after successful completion.
- WeChat SHA1 verifier accepts known sample and rejects wrong signature.
- stale/future timestamp outside 300 seconds rejected.

### Component / concurrency contract

- duplicate identity callback converges to one Member.
- cross-tenant identity binding rejected.
- OAuth finalization locks state and atomically persists identity + state result/consumption.
- concurrent callbacks converge on one completed OAuth semantic result.
- duplicate webhook same key+payload returns duplicate without second dispatch.
- same event key + different payload is conflict.

### Golden Master

- R20 fan snapshot keeps `uniacid`, `acid`, `uid`, `openid`, `unionid` distinct.
- borrowed OAuth provider openid is not relabeled as business Account identity.
- R20 `we7sid-*` is classified unsupported legacy and not emitted by the new state service.

### Schema contract

- five `_005` tables exist.
- external identity composite unique constraint exists.
- OAuth nonce unique constraint exists.
- webhook Inbox unique event constraint exists.
- InnoDB/utf8mb4/FKs and reverse rollback exist.

## 18. Quality Gate

Before merge:

1. New R7 focused tests pass locally where executable.
2. Full `tests/run.php` count increases from 51 by the exact number of R7 tests.
3. PHP lint passes.
4. Domain/Application layers contain no ThinkPHP `Db`/Facade imports.
5. `$_W`, `$_GPC`, `pdo_*`, `session_id()` appear only in explicit legacy adapters, never new R7 domain/application code.
6. Safe secret scan: no token/appsecret fixtures committed except obviously fake deterministic test values.
7. PR GitHub Actions passes Composer strict/install, full offline suite, PHPUnit bridge, lint and existing multi-app HTTP smoke.
8. Only after PR CI success may `main` be non-force fast-forwarded; `main` push CI must also pass.

## 19. Acceptance Criteria

R7 is complete only when all are true:

- Same openid string can coexist under different Provider Accounts.
- Repeated callback does not duplicate Member/ExternalIdentity.
- Borrowed OAuth never changes business Tenant/Account ownership.
- OAuth identity binding and state consumption/result are committed atomically.
- State tampering/expiry/replay cannot restore arbitrary session context.
- Unsafe return URL is rejected.
- Invalid/stale/replayed webhook cannot cause duplicate business dispatch.
- R20 fan/member source identifiers are preserved in migration snapshots.
- Full repository CI is green on the final `main` SHA.
