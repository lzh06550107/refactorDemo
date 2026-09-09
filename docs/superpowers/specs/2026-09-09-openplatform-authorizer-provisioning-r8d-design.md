# OpenPlatform Authorizer Provisioning R8D Design

Date: 2026-09-09

## 1. Scope

R8D extends the released R8C OpenPlatform authorizer lifecycle with automatic SaaS Account provisioning for WeChat Official Accounts and WeChat Mini Programs.

R8D does not replace R8C authorization, token refresh, event ordering, completion claims, or canonical authorizer identity. It starts after R8C has established a trusted `AuthorizerAuthorization` fact.

In scope:

- explicit authorization-intent modes for binding an existing Account or automatically provisioning a new Account;
- trusted authorizer metadata synchronization and versioned metadata history;
- reliable classification into `AccountType::OFFICIAL_ACCOUNT` or `AccountType::WECHAT_MINI_PROGRAM`;
- tenant-scoped automatic Account provisioning using existing quota;
- single-owner enforcement for each OpenPlatform authorizer;
- separate Mini Program and Official Account provider-binding tables;
- durable database-backed provisioning jobs with lease/CAS recovery;
- reconnect of previously bound authorizers without creating a second Account or consuming quota again;
- `unauthorized` connection teardown without deleting the SaaS Account;
- IAM, HTTP query/retry/metadata-refresh contracts, audit, secret-redaction rules, and release gates.

Out of scope:

- automatically creating a Tenant;
- authorizer sharing across multiple Tenants;
- automatic authorizer transfer between Tenants or Accounts;
- code release/version management;
- payment APIs;
- broad unrelated Official Account business APIs;
- automatically cancelling WeChat authorization when a local Account is deleted;
- a provisioning cancellation API/state;
- Redis, RabbitMQ, Kafka, or another external queue for provisioning;
- a provider-wide identity refactor replacing the existing R7 `Account.id` provider-account boundary.

## 2. Design Goals and Hard Invariants

R8D separates four different facts:

1. **Provider authorization fact** — `AuthorizerAuthorization`: whether WeChat currently authorizes the Component Platform.
2. **Provider metadata fact** — `AuthorizerMetadata`: trusted current information about the authorizer and its history.
3. **Provisioning fact** — `AuthorizerProvisioning`: whether and how an authorized provider asset is becoming a Tenant Account.
4. **Tenant ownership fact** — `Account + ProviderBinding + AuthorizerAccountOwnership`: which Tenant/Account owns the external asset.

Hard invariants:

- Canonical provider identity remains `(component_platform_id, authorizer_app_id)`.
- `AuthorizerAuthorization` is platform-level and does not own a Tenant.
- Tenant ownership is derived only from an explicit local intent or an existing ownership record; it is never guessed from callback input, nickname, AppId, or the most recent Tenant.
- An authorizer is globally exclusive by default: one canonical authorizer maps to at most one Tenant and one Account.
- A disabled provider binding still retains ownership and cannot be claimed by another Tenant.
- WeChat authorization success cannot be rolled back merely because metadata, quota, or Account provisioning failed.
- `unauthorized` means the provider connection is inactive; it does not delete the SaaS Account or release Account-creation quota.
- Reauthorization of the same owned authorizer reconnects the existing Account and does not create another Account or consume quota again.
- Retry/crash recovery must never create more than one Account, one ownership row, or one semantic quota consumption.
- `Account.name` is Tenant-owned master data. Provider nickname may initialize the name during first provisioning but later metadata refreshes never overwrite the local Account name.
- Existing R8C completion/event arbitration and authorizer token rules remain authoritative.
- For an auto-provisioning attempt, `account_type` is nullable only before trusted metadata classification. Once the attempt reaches `METADATA_READY`, its selected AccountType is immutable; any later trusted type change becomes `METADATA_TYPE_CONFLICT` and must never cause a second quota-resource consumption or automatic Account-type mutation.

## 3. Selected Architecture

R8D adds an independent provisioning subsystem after R8C rather than expanding the R8C authorization callback into a long synchronous workflow.

```text
ComponentPlatform
      |
      v
AuthorizerAuthorization         (R8C)
      |
      +-------------------------------+
      |                               |
      v                               v
BIND_EXISTING_ACCOUNT       AUTO_PROVISION_ACCOUNT
      |                               |
      v                               v
Existing Account Binding      AuthorizerProvisioning
                                      |
                                      v
                              Metadata Synchronizer
                                      |
                                      v
                              AccountType Detection
                                      |
                                      v
                              Ownership Resolution
                         +------------+------------+
                         |            |            |
                         v            v            v
                    same owner      unowned    other owner
                         |            |            |
                         v            v            v
                    reconnect       quota     BINDING_CONFLICT
                                      |
                                      v
                              Account Provisioner
                               /              \
                              v                v
                 Mini Program Binding   Official Account Binding
                              \                /
                               +------Account-+
```

Rejected alternatives:

- performing metadata, quota, Account creation, and binding inside `AuthorizationCompletionService`; this couples provider authorization to internal provisioning failures and makes callback latency/failure unsafe;
- introducing a new generic `wechat_provider_accounts` identity layer and rewriting R7 OAuth/Webhook/MiniApp identity architecture; this is unnecessary for R8D and duplicates the existing `Account.id` provider-account boundary.

## 4. AuthorizationIntent Modes

R8D adds:

```php
enum AuthorizationIntentMode: string
{
    case BIND_EXISTING_ACCOUNT = 'bind_existing_account';
    case AUTO_PROVISION_ACCOUNT = 'auto_provision_account';
}
```

Domain invariant:

- `BIND_EXISTING_ACCOUNT` requires non-null `targetAccountId`.
- `AUTO_PROVISION_ACCOUNT` requires null `targetAccountId`.

The mode is explicit; nullability must never be used as an implicit business-mode discriminator.

Migration `_009` will:

- add `intent_mode`;
- backfill all R8C rows as `bind_existing_account`;
- make `target_account_id` nullable;
- retain the Account FK for non-null values;
- add a database constraint enforcing the mode/target invariant.

### 4.1 Trusted Tenant Source

For admin-initiated authorization, Tenant identity comes from authenticated `RequestContext.tenantId`, not from caller-supplied JSON. If a compatibility `tenantId` field is temporarily accepted, it is only an equality assertion against `RequestContext.tenantId`; mismatch is rejected.

### 4.2 Authorization-start Eligibility

Introduce a narrow `AuthorizerAccountEligibility` port rather than changing the existing `AuthorizerAccountBinding` contract.

Before `createPreAuthCode()`:

- `BIND_EXISTING_ACCOUNT`: validate Tenant active, Account belongs to Tenant, Account is eligible/active, AccountType is supported, Component Platform enabled.
- `AUTO_PROVISION_ACCOUNT`: validate Tenant active and Component Platform enabled.

Any invalid local Tenant/Account condition must produce zero provider pre-auth calls.

For auto-created Accounts, AccountType is determined only by trusted provider metadata. `requestedAuthType` is a provider authorization-page/request parameter and never the internal AccountType source.

For an existing Account, its local `Account.type` is already established internal master data and is not derived from `requestedAuthType`. Later trusted metadata must never silently mutate that Account type; an observed mismatch is a manual-review/type-conflict condition.

## 5. Trusted Authorizer Metadata

R8D adds a metadata client operation over WeChat `api_get_authorizer_info`. The provider call uses trusted Component Platform credentials and the trusted `authorizer_app_id` established by R8C.

Classification rule:

- `MiniProgramInfo` present -> `AccountType::WECHAT_MINI_PROGRAM`;
- `MiniProgramInfo` absent -> `AccountType::OFFICIAL_ACCOUNT`.

`service_type_info` and `verify_type_info` are metadata only and are not used as the primary AccountType discriminator.

If classification is not trustworthy, auto provisioning does not create an Account and does not consume quota.

### 5.1 Current Projection

`authorizer_metadata_current`:

- `component_platform_id`
- `authorizer_app_id`
- `account_type`
- `nickname`
- `original_id`
- `principal_name`
- `alias`
- `head_image_url`
- `qrcode_url`
- `service_type`
- `verify_type`
- `business_info_json`
- `mini_program_info_json`
- `normalized_metadata_json`
- `metadata_hash`
- `provider_fetched_at`
- `version`
- timestamps

Primary key: `(component_platform_id, authorizer_app_id)`.

Persist only an explicit whitelist of normalized metadata. Do not persist an unfiltered raw provider response.

### 5.2 Immutable Snapshots

`authorizer_metadata_snapshots`:

- `id`
- `component_platform_id`
- `authorizer_app_id`
- `account_type`
- `metadata_hash`
- `normalized_metadata_json`
- `observed_at`
- `source`
- `version`
- `created_at`

Unique: `(component_platform_id, authorizer_app_id, version)`.

Synchronization semantics:

- normalize provider metadata and compute SHA-256 semantic hash;
- same hash as current: update fetch timestamp only, no new snapshot/version;
- changed hash: increment version, insert immutable snapshot, update current projection;
- A -> B -> A creates three versions; metadata hash itself is not unique.

Metadata sync is independent of provisioning and can be triggered by:

- first successful authorization;
- `updateauthorized`;
- reauthorization/reconnect;
- admin manual refresh;
- periodic reconciliation.

Provider metadata failure never marks an active authorization as unauthorized.

## 6. Account Name Ownership

During first Account provisioning:

- initialize `Account.name` from trusted metadata nickname when valid;
- if nickname is absent/invalid but AccountType is reliably known, use a deterministic safe fallback such as `微信小程序 · <appid suffix>` or `微信公众号 · <appid suffix>`.

After Account creation, provider metadata refresh must never automatically overwrite `Account.name`. The current provider nickname remains available from `AuthorizerMetadata` for UI display alongside the Tenant-owned Account name.

No `name_source` state machine is introduced in R8D.

## 7. Authorizer Ownership

R8D adds `authorizer_account_ownerships` as the single source of truth for OpenPlatform authorizer ownership.

Fields:

- `component_platform_id`
- `authorizer_app_id`
- `tenant_id`
- `account_id`
- `account_type`
- `first_bound_at`
- `last_connected_at`
- timestamps

Constraints:

- primary key `(component_platform_id, authorizer_app_id)`;
- `account_id` unique;
- FKs to Component Platform, Tenant, and Account as appropriate.

The table does not replace R7 provider identity. R7 continues to use `Account.id` as the provider-account identity for OAuth, ExternalIdentity, and Webhook flows. Ownership only answers: “which Tenant/Account owns this OpenPlatform authorizer?”

Ownership remains present when a provider binding is disabled after `unauthorized`.

## 8. Provider Bindings

Mini Programs continue using `miniapp_provider_accounts`.

R8D adds `official_account_provider_accounts` with the same connection configuration shape:

- `account_id`
- `tenant_id`
- `provider_app_id`
- `connection_mode`
- `credential_ref`
- `component_platform_id`
- `enabled`
- timestamps

Connection invariants:

- `manual`: `credential_ref != null`, `component_platform_id == null`;
- `component`: `credential_ref == null`, `component_platform_id != null`.

OpenPlatform auto provisioning always creates `connection_mode = component`.

Official Account bindings must never be stored in `miniapp_provider_accounts`.

The existing Mini Program table remains intact; R8D may strengthen its Component Platform FK/contract but does not replace it.

## 9. AuthorizerProvisioning Aggregate

`authorizer_provisionings` records the tenant-specific, retryable provisioning workflow.

Fields:

- `id`
- `source_intent_id`
- `tenant_id`
- `component_platform_id`
- `authorizer_app_id`
- `account_type` nullable until metadata ready, then immutable for this provisioning attempt
- `status`
- `metadata_version`
- `quota_resource_key`
- `quota_consume_entry_id`
- `quota_release_entry_id`
- `account_id`
- `last_error_code`
- `last_error_stage`
- `created_at`
- `updated_at`
- `completed_at`
- `version`

Constraints/indexes:

- `source_intent_id` unique;
- canonical lookup index `(component_platform_id, authorizer_app_id, tenant_id)`;
- multiple historical provisioning attempts/reconnect records are permitted for the same authorizer/Tenant.

### 9.1 Provisioning Status

Approved business states:

- `PENDING_METADATA`
- `METADATA_READY`
- `QUOTA_CONSUMED`
- `PROVISIONED`
- `RECONNECTED`
- `QUOTA_BLOCKED`
- `BINDING_CONFLICT`
- `METADATA_FAILED`
- `METADATA_TYPE_CONFLICT`
- `PROVISION_FAILED`
- `AUTHORIZATION_INACTIVE`

`retry_wait` is not a business status; retry timing belongs to the durable job.

Normal path:

```text
PENDING_METADATA
      -> METADATA_READY
          -> same owner -> RECONNECTED
          -> other owner -> BINDING_CONFLICT
          -> unowned -> quota consume
                       -> QUOTA_BLOCKED
                       -> QUOTA_CONSUMED
                            -> Account + binding + ownership
                            -> PROVISIONED
```

Once `account_type` is set at `METADATA_READY`, later metadata may update the platform-level metadata projection but cannot change the provisioning attempt's type. A type mismatch before Account finalization becomes `METADATA_TYPE_CONFLICT`; after Account creation it likewise never mutates `Account.type` or causes a second Account/quota-resource charge.

## 10. Durable Provisioning Job

R8D uses a database-backed worker and does not introduce an external MQ.

`authorizer_provisioning_jobs` is one durable job per provisioning:

- `provisioning_id` PK/FK
- `status`
- `next_attempt_at`
- `claim_holder_id`
- `claim_expires_at`
- `attempt_count`
- `last_error_code`
- timestamps

Job states:

- `READY`
- `CLAIMED`
- `COMPLETED`
- `DEAD`

Claim TTL is 60 seconds. Expired claims are recoverable. Every state-changing write uses version/CAS or equivalent ownership checks so a late expired holder cannot commit.

Default automatic retry policy:

- maximum 10 attempts;
- exponential backoff: approximately 1m, 2m, 4m, 8m, 16m, then capped around 30m.

Retryable examples: provider 5xx/timeout, DB deadlock/transient failure.

Non-looping business outcomes: quota blocked, ownership conflict, authorization inactive, trusted type conflict.

## 11. Authorization Completion Integration

R8D preserves R8C callback/event arbitration and the 30-second AuthorizationIntent completion claim.

Both browser callback and `authorized` event continue to converge on the same intent/completion service when they refer to the same pre-auth flow. The claim winner alone may exchange the authorization code; a busy loser makes zero provider query-auth calls.

After trusted `AuthorizerAuthorization` is written, completion branches by intent mode.

### 11.1 Existing Account Mode

Within the R8C completion transaction:

- save/advance `AuthorizerAuthorization`;
- lock/check ownership;
- if unowned, establish ownership to the explicit target Account;
- if already owned by that Account, treat as idempotent/reconnect;
- if owned elsewhere, return conflict;
- bind/enable the provider configuration appropriate to the existing Account's local `Account.type`;
- complete AuthorizationIntent.

This remains synchronous and does not use the provisioning worker. The flow never derives the existing Account's type from `requestedAuthType`. Independent metadata synchronization subsequently verifies/records the trusted provider type; a mismatch is surfaced for manual review and must never silently mutate the Account type or move ownership.

### 11.2 Auto Provision Mode

Within one short transaction:

- save/advance `AuthorizerAuthorization`;
- insert exactly one `AuthorizerProvisioning` as `PENDING_METADATA`;
- insert exactly one durable job as `READY`;
- complete AuthorizationIntent.

Then return immediately. Metadata API calls, quota, Account creation, and provider binding are not executed in the authorization callback.

This transaction prevents the fatal state “Intent completed but no durable provisioning trigger exists.”

## 12. Provisioning Worker Flow

For a claimed job:

1. Reload current provisioning and current `AuthorizerAuthorization` from storage.
2. If authorization is inactive, transition to `AUTHORIZATION_INACTIVE` without consuming quota.
3. Synchronize trusted metadata if required.
4. Detect AccountType. If provisioning has no AccountType yet, persist it and `metadata_version` while moving to `METADATA_READY`; if a previously frozen AccountType conflicts with newer trusted metadata, move to `METADATA_TYPE_CONFLICT` and stop before any new quota operation.
5. Resolve canonical ownership.
6. Same owner: reconnect existing provider binding, set `RECONNECTED`, no quota.
7. Other owner: set `BINDING_CONFLICT`, no Account creation, no quota consumption.
8. Unowned: consume `QuotaResource::accountCreate(frozen AccountType)` with deterministic idempotency key.
9. Record the quota consume entry id and move to `QUOTA_CONSUMED`.
10. Atomically create Account + provider binding + ownership and finalize `PROVISIONED`.

The provisioning worker never trusts process memory from a previous attempt; each retry re-reads persistent facts.

## 13. Quota and Transaction Boundary

R8D reuses the existing quota service/ledger. It does not duplicate quota allocation logic.

Deterministic consume key:

```text
openplatform-provision:<componentPlatformId>:<authorizerAppId>:<tenantId>
```

The frozen provisioning AccountType selects the resource `account_create:<type>`. Once quota processing starts, a later metadata type change never switches the provisioning attempt to a different quota resource.

`availability()` may be used as an advisory UI/pre-check only. `QuotaService.consume()` is authoritative.

Because the quota repository already owns its own transaction semantics, R8D does not depend on nested ThinkPHP transactions to atomically combine quota and Account creation.

Internal Saga:

1. atomically consume quota through the existing quota service;
2. persist/recover the resulting consume entry id;
3. in a separate short DB transaction create Account + provider binding + ownership + provisioning finalization.

Retry behavior:

- if `quota_consume_entry_id` already exists, never consume again;
- if a crash occurs after quota consume but before the entry id is persisted, recover the same ledger entry by deterministic idempotency key and the frozen resource key;
- transient finalization failure does not release quota; the workflow keeps retrying;
- a terminal failure may release quota only after it is proven that no Account was committed for this provisioning.

Deterministic release key:

```text
openplatform-provision-release:<provisioningId>
```

Unknown commit outcome is not treated as failure. Reconcile Account/binding/ownership before deciding whether a release is legal.

## 14. Atomic Account Finalization

For an unowned authorizer after quota consumption:

```text
BEGIN
  lock provisioning
  lock/check canonical ownership
  re-check authorization ACTIVE
  re-check provisioning version and frozen AccountType
  INSERT Account
  INSERT subtype provider binding
  INSERT authorizer_account_ownerships
  UPDATE provisioning account_id/status = PROVISIONED
COMMIT
```

Account is created directly in its normal business state (typically ACTIVE). R8D does not add an `AccountStatus::PROVISIONING`; provisioning state belongs to `AuthorizerProvisioning`.

A commit can never expose an Account without its provider binding and ownership row.

## 15. Reconnect and Unauthorized Semantics

### 15.1 Unauthorized

On authenticated, ordered WeChat `unauthorized`:

- `AuthorizerAuthorization` becomes unauthorized using existing R8C ordering/CAS semantics;
- retain `AuthorizerAccountOwnership`;
- retain Account and its existing ACTIVE/SUSPENDED/DELETED business state;
- retain last trusted AuthorizerMetadata and snapshots;
- retain consumed Account-creation quota;
- set provider binding `enabled = 0` as a connection projection;
- authorizer token access remains blocked by the authorization state even if the binding projection update must later be reconciled.

### 15.2 Reauthorization

When the same canonical authorizer becomes ACTIVE again:

- if ownership points to an ACTIVE Account, re-enable the existing binding;
- if the Account is SUSPENDED, reconnect the provider binding but keep Account SUSPENDED;
- if the Account is DELETED, do not automatically resurrect it; produce a conflict/manual-review outcome;
- never create a second Account and never consume quota again.

A provisioning record created for the new local authorization ends `RECONNECTED` when it restores an existing owned Account.

### 15.3 Local Account Deletion/Disconnect

Local Account deletion, local provider disconnect, and remote WeChat `unauthorized` are three distinct actions. R8D does not automatically call WeChat to revoke authorization when a local Account is deleted or disabled.

## 16. Event-only Authorized and updateauthorized

R8D preserves R8C event ordering and completion routing.

### Event-only `authorized` with no local intent

- always may establish/update the platform-level `AuthorizerAuthorization` fact;
- may synchronize platform-level metadata;
- if no historical ownership exists: no Tenant inference, no provisioning, no Account, no quota;
- if historical ownership exists: reconnect that existing Account/binding without quota or Account creation.

### `updateauthorized`

- preserve R8C newer-wins / same-timestamp-idempotent-or-conflict / older-no-op rules;
- refresh trusted metadata;
- never move/create Tenant ownership merely because an update event arrived;
- existing ownership remains unchanged.

## 17. Application Service Boundaries

R8C services remain focused on provider authorization/token lifecycle. R8D adds focused services rather than a monolithic OpenPlatform manager.

Expected boundaries:

- `AuthorizationStartService` — extended for explicit mode and eligibility.
- `AuthorizationCompletionService` — extended only at the post-authorization mode branch.
- `AuthorizerMetadataSyncService` — provider metadata normalization/current/snapshot.
- `AuthorizerOwnershipResolver` — canonical owner lookup/classification.
- `AuthorizerProvisioningService` — state transitions and provisioning decisions.
- `AuthorizerConnectionService` — subtype binding enable/disable/reconnect.
- `AuthorizerProvisioningWorker` — lease acquisition and one retryable iteration.
- `AuthorizerProvisioningRetryService` — explicit admin retry after revalidation.
- `AuthorizerProvisioningQueryService` — tenant-scoped read model.

MetadataSync does not depend on Provisioning; Provisioning is only one metadata consumer.

## 18. HTTP API

### 18.1 Start Authorization

`POST /api/v1/openplatform/components/{componentPlatformId}/authorization-intents`

Auto provision request:

```json
{
  "mode": "auto_provision_account",
  "requestedAuthType": "3"
}
```

Existing Account request:

```json
{
  "mode": "bind_existing_account",
  "targetAccountId": "account-100",
  "requestedAuthType": "3"
}
```

Tenant comes from authenticated RequestContext.

Existing R8C authorization URL/state behavior remains compatible. Plaintext state/pre-auth code are not persisted.

### 18.2 Provider Callback

`GET /api/v1/openplatform/authorization/callback`

Provider callback is authenticated by opaque state/intent semantics, not admin IAM. Query/cookie/header Tenant input cannot override the Tenant captured in the intent.

Auto-provision completion response reports an asynchronous provisioning result, e.g.:

```json
{
  "status": "provisioning",
  "authorizerAppId": "wx...",
  "provisioningId": "prov-..."
}
```

### 18.3 Provisioning Query

`GET /api/v1/openplatform/provisionings/{id}`

Requires `openplatform.authorizer.read` and current Tenant ownership of the provisioning resource.

Cross-Tenant lookup returns 404 rather than 403 to avoid resource-existence disclosure.

A successfully queried provisioning resource returns HTTP 200 even when its business status is `QUOTA_BLOCKED`, `BINDING_CONFLICT`, etc.

### 18.4 Retry

`POST /api/v1/openplatform/provisionings/{id}/retry`

Requires `openplatform.authorizer.retry_provision`. Returns 202 when retry is accepted. Retry revalidates current authorization, ownership, quota/account/binding facts and resumes from the current durable stage; it does not blindly reset the workflow.

### 18.5 Metadata Refresh

`POST /api/v1/openplatform/components/{platform}/authorizers/{appid}/metadata/refresh`

Requires `openplatform.authorizer.refresh_metadata`.

Tenant-admin scoping rule: the current Tenant may refresh an authorizer only when either (a) `authorizer_account_ownerships` maps it to the current Tenant, or (b) the current Tenant has a non-terminal provisioning for that same canonical authorizer. Otherwise return tenant-scoped 404. This prevents AppId-based cross-Tenant enumeration/provider calls.

The endpoint refreshes metadata only; it never creates an Account, consumes quota, or claims ownership. Platform-level unbound metadata reconciliation may still occur from trusted provider ingress/internal reconciliation, not through an arbitrary Tenant admin request.

### 18.6 Provider Events

Existing provider event endpoint remains cryptographically authenticated provider ingress and does not use admin IAM.

## 19. IAM

Stable permissions:

- `openplatform.authorizer.read`
- `openplatform.authorizer.start`
- `openplatform.authorizer.bind`
- `openplatform.authorizer.provision`
- `openplatform.authorizer.refresh_metadata`
- `openplatform.authorizer.retry_provision`

Required combinations:

- start existing binding: `start + bind`;
- start auto provisioning: `start + provision`;
- query: `read`;
- manual metadata refresh: `refresh_metadata` plus the Tenant-resource scope in 18.5;
- manual retry: `retry_provision` plus ownership of the provisioning resource in the current Tenant.

Do not hard-code `Principal.type == admin`; `Principal` is identity, while authorization belongs to the existing IAM/ACL layer.

## 20. Error Contract

Reuse the existing application ErrorCode set.

Synchronous HTTP mapping:

- invalid mode/arguments -> `INVALID_ARGUMENT`, 400;
- missing admin authentication -> `UNAUTHORIZED`, 401;
- invalid/expired provider callback state -> `UNAUTHORIZED`, 401;
- IAM/eligibility denial -> `FORBIDDEN`, 403;
- resource absent in current Tenant scope -> `NOT_FOUND`, 404;
- ownership/claim/CAS/event semantic conflict -> `CONFLICT`, 409;
- provider API failure -> `BAD_GATEWAY`, 502;
- temporary token/provider lease/service unavailability -> `SERVICE_UNAVAILABLE`, 503;
- uncategorized internal fault -> `INTERNAL_ERROR`, 500.

Async worker failures are mapped to provisioning business states instead of leaking HTTP semantics; for example quota exhaustion becomes `QUOTA_BLOCKED`.

## 21. Audit and Security

Audit actor classes:

- admin: `admin:<principalId>`;
- provider: `external:wechat-openplatform`;
- worker: `system:openplatform-provisioning-worker`.

Minimum audit actions:

- authorization start/complete;
- metadata refresh/change;
- provisioning created/metadata-ready/quota-consumed/quota-released/provisioned/reconnected;
- binding conflict/failure/retry requested;
- provider connection disconnected.

Safe audit metadata may include component platform id, authorizer AppId, intent/provisioning/account ids, AccountType, versions, scope count, quota resource/ledger entry ids, sanitized error code/stage, request/trace ids.

Never persist or log plaintext:

- state;
- pre-auth code;
- authorization code;
- component access token;
- authorizer access token;
- authorizer refresh token;
- credential plaintext;
- AES key;
- session key;
- decrypted callback raw XML;
- complete encrypted callback payload.

Database hash usage remains allowed where operationally required (state, pre-auth code, refresh-token hash, metadata hash), but hashes are not copied into audit logs without a clear business need.

## 22. Testing Strategy

R8D follows strict RED -> GREEN TDD. Each implementation task begins with a test-only RED change whose failure is attributable to the missing target behavior, followed by minimal production GREEN and full regression.

Existing Unit/Component/Contract/GoldenMaster/PHPUnit layers are extended rather than replaced.

### 22.1 Domain Unit Tests

Cover:

- AuthorizationIntent mode/target invariants;
- metadata classification and semantic hash normalization;
- metadata A -> B -> A history;
- valid/invalid provisioning state transitions;
- AccountType freeze after `METADATA_READY` and metadata type conflict before/after quota;
- Account local-name preservation.

### 22.2 Application/Component Tests

Cover:

- eligibility failure causes zero pre-auth provider calls;
- callback vs authorized-event competition causes exactly one query-auth provider call;
- auto-provision completion creates exactly one Provisioning + Job atomically with intent completion;
- metadata provider failures do not change authorization to unauthorized;
- same-owner reconnect vs cross-owner conflict;
- quota consume exactly once; reconnect consumes zero;
- type change after `METADATA_READY` never switches quota resource or creates a second consume;
- terminal compensation releases exactly once only when no Account exists;
- `unauthorized` keeps Account/ownership/quota and disables provider binding;
- event-only authorized without ownership never provisions;
- event-only authorized with ownership reconnects without quota;
- Account SUSPENDED/DELETED reconnect behavior;
- Tenant-scoped HTTP read/retry/refresh and cross-Tenant 404;
- metadata refresh AppId for another Tenant results in 404 and zero provider metadata calls.

### 22.3 Worker Concurrency and Crash Recovery

Two concurrent workers:

- exactly one acquires the claim;
- loser makes zero provider/quota/Account writes;
- expired claim is recoverable;
- expired holder cannot late-save after CAS/version changes.

Crash points include:

- metadata returned before save;
- metadata saved before provisioning status update;
- before/after quota consume;
- quota consumed before consume-entry reference persisted;
- after consume-entry reference before Account creation;
- Account transaction before commit;
- Account transaction committed but worker missed result;
- binding/ownership committed before provisioning final state observed;
- reconnect binding enabled before final status observed.

For every crash point, eventual invariant:

```text
Account count <= 1
Ownership count <= 1
semantic quota consume count <= 1
```

### 22.4 SQL Contract Tests

Migration `_009` must assert:

- nullable `target_account_id` plus explicit intent-mode constraint;
- metadata current/snapshot keys and indexes;
- global ownership primary/unique constraints;
- Official Account provider-binding FKs/constraints;
- provisioning `source_intent_id` uniqueness;
- one durable job per provisioning;
- symmetric up/down migration contracts.

### 22.5 Golden Master

Use only for stable provider-normalization fixtures where compatibility is valuable, such as representative Official Account, Mini Program, and edge metadata payloads. Never include tokens/secrets in fixtures.

### 22.6 Secret Leakage Tests

Inject sentinel plaintext values for state, pre-auth code, authorization code, refresh token, and access token. Scan audit payloads, serialized jobs, persisted normalized metadata, exception output, and captured logs. Sentinel plaintext must never appear.

## 23. Failure Matrix

| Failure | Authorization | Metadata | Quota | Account | Result |
|---|---|---|---|---|---|
| metadata timeout | ACTIVE | incomplete | none | none | retry |
| malformed/unclassifiable metadata | ACTIVE | failed/retained | none | none | METADATA_FAILED |
| type changed after `METADATA_READY` | ACTIVE | updated/flagged | no new resource/consume | unchanged | METADATA_TYPE_CONFLICT |
| quota exhausted | ACTIVE | ready | not consumed | none | QUOTA_BLOCKED |
| other Tenant/Account owns | ACTIVE | ready | none | none | BINDING_CONFLICT |
| transient DB failure after quota | ACTIVE | ready | consumed | none | retry |
| Account transaction rollback | ACTIVE | ready | consumed | none | retry |
| Account commit + worker crash | ACTIVE | ready | consumed | exists | reconcile -> PROVISIONED |
| unauthorized before worker | UNAUTHORIZED | retained/optional | none | none | AUTHORIZATION_INACTIVE |
| unauthorized after provision | UNAUTHORIZED | retained | retained | retained | binding disabled |
| same-owner reauthorization | ACTIVE | refreshed | no new consume | original | RECONNECTED |

## 24. CI and Release Gates

R8D extends the existing repository CI and keeps these required checks:

- `composer validate --strict`;
- dependency install;
- offline contract suite (`php tests/run.php`);
- PHPUnit bridge;
- PHP lint;
- multi-app HTTP smoke.

New HTTP routes must at least be smoke-loaded in the API application. CI does not require live WeChat access; provider behavior is deterministic/faked in automated tests.

Release discipline:

1. feature branch exact HEAD passes all required CI;
2. compare against fresh `main`; feature must not be behind;
3. no force push/merge to main;
4. fast-forward main only after the exact feature HEAD is green;
5. independent push/main CI on the exact released SHA must also pass;
6. do not claim release until both feature/PR CI and main CI are verified green.

## 25. Definition of Done

R8D is complete only when all of the following are true:

- successful WeChat authorization remains compatible with R8C;
- trusted metadata classification works for Official Account and Mini Program;
- both account types can auto-provision under an existing Tenant;
- quota is enforced exactly once for first creation and AccountType changes cannot switch quota resource after `METADATA_READY`;
- canonical authorizer ownership is globally exclusive;
- reconnect uses the same Account with no second quota charge;
- `unauthorized` retains the Account and ownership while disabling connection;
- metadata sync is versioned and never overwrites Tenant-owned Account name;
- metadata type conflict is blocked from automatic Account-type mutation;
- durable worker concurrency and crash recovery preserve idempotency;
- IAM and Tenant isolation are enforced, including metadata-refresh anti-enumeration;
- secrets never leak into logs/audit/jobs/normalized metadata;
- R1-R8C regression remains green, including R7 OAuth/Webhook, R8A MiniApp login, R8B ComponentPlatform, and R8C authorizer lifecycle;
- all required CI and release gates pass.
