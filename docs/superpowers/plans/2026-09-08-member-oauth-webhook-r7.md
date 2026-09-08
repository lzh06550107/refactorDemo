# R7 Member / OAuth / Webhook Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement tenant-scoped Member identity, provider-account-scoped ExternalIdentity, secure borrowed OAuth state/finalization, and verified/idempotent WeChat webhook ingestion.

**Architecture:** Keep `HTTP -> Application -> Domain <- Infrastructure`. Identity keys are `(provider_type, provider_account_id, external_subject)`. OAuth code exchange occurs outside DB locks; final Member/ExternalIdentity binding and OAuthState consumption commit atomically. Webhooks verify raw request metadata before minimal parsing and Inbox deduplication.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, Think ORM 4, MySQL 8, project offline runner + PHPUnit bridge, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-08-member-oauth-webhook-r7-design.md`

## Global Constraints

- Do not write R20 tables.
- No global openid lookup.
- OAuth state token is 256-bit random; only SHA-256 hash is persisted; TTL is 10 minutes.
- Relative return paths are allowed only when they begin `/` and not `//`; absolute URLs must be HTTPS and origin-allowlisted.
- OAuth state invalid/expired/not-consumable => `UNAUTHORIZED` 401; provider-account mismatch => `FORBIDDEN` 403.
- Webhook freshness window is 300 seconds; comparison uses `hash_equals`.
- No provider secret/access token/raw OAuth code in Member persistence or audit payloads.
- OAuth identity persistence + state result + consumed marker are one DB transaction.
- R7 defers Credit Ledger, full MiniApp token chain, AES decrypt runtime, module reply processors, payment/refund/fulfillment.

---

### Task 1: Member identity domain and `_005` schema

**Files:**
- Create: `app/member/domain/MemberStatus.php`
- Create: `app/member/domain/Member.php`
- Create: `app/member/domain/ProviderIdentity.php`
- Create: `app/member/domain/ExternalIdentity.php`
- Create: `app/member/domain/MemberIdentityResult.php`
- Create: `database/migrations/20260908_005_member_oauth_webhook_up.sql`
- Create: `database/migrations/20260908_005_member_oauth_webhook_down.sql`
- Test: `tests/Unit/Member/ExternalIdentityTest.php`
- Test: `tests/Contract/MemberOAuthWebhookSchemaContractTest.php`

**Interfaces:**
- Produces `ProviderIdentity(providerType, providerAccountId, externalSubject, unionId?)`.
- Produces canonical `ExternalIdentity::providerKey()` as `providerType|providerAccountId|externalSubject`.
- `_005` creates `members`, `external_identities`, `oauth_bindings`, `oauth_states`, `webhook_inbox`.

- [ ] Write RED tests proving same openid under two provider accounts yields different keys and schema has the required composite unique keys/FKs/engine/charset.
- [ ] Run focused tests and verify failure is due to missing R7 classes/migration.
- [ ] Implement minimal domain objects and SQL migration/down migration.
- [ ] Re-run focused tests to GREEN.

### Task 2: R20 member/fan compatibility snapshots

**Files:**
- Create: `app/member/compat/LegacyMemberSnapshot.php`
- Create: `app/member/compat/LegacyFanIdentitySnapshot.php`
- Create: `app/member/compat/R20MemberIdentitySnapshotRepository.php`
- Test: `tests/GoldenMaster/R20MemberIdentitySnapshotTest.php`

**Interfaces:**
- Consumes existing `app\legacy\contract\LegacyDatabase` read-only port.
- Produces member snapshot fields `uid, uniacid, groupid, nickname, avatar, status`.
- Produces fan snapshot fields `fanid, uniacid, acid, uid, openid, unionid, follow, followtime, unfollowtime, user_from`.

- [ ] Write RED Golden Master test with distinct `uniacid/acid/uid/openid/unionid` values.
- [ ] Verify RED because snapshot adapter does not exist.
- [ ] Implement read-only adapter; never infer missing provider Account mappings and never normalize openid globally.
- [ ] Re-run Golden Master to GREEN.

### Task 3: OAuth domain, redirect policy, and state repository contracts

**Files:**
- Create: `app/oauth/domain/OAuthBinding.php`
- Create: `app/oauth/domain/OAuthState.php`
- Create: `app/oauth/domain/ReturnUrlPolicy.php`
- Create: `app/oauth/domain/OAuthStartResult.php`
- Create: `app/oauth/domain/OAuthCallbackResult.php`
- Create: `app/oauth/contract/OAuthBindingRepository.php`
- Create: `app/oauth/contract/OAuthStateRepository.php`
- Create: `app/oauth/contract/OAuthProviderClient.php`
- Create: `app/common/contract/TransactionManager.php`
- Create: `app/common/infrastructure/ThinkPhpTransactionManager.php`
- Test: `tests/Unit/OAuth/ReturnUrlPolicyTest.php`
- Test: `tests/Unit/OAuth/OAuthStateTest.php`

**Interfaces:**
- `OAuthState::issue(...)` stores nonce hash/context/timestamps; TTL fixed by application to 600 seconds.
- `OAuthStateRepository::findByNonceHash`, `lockByNonceHash`, `insert`, `saveFinalResult`.
- `TransactionManager::run(callable $callback): mixed`.

- [ ] Write RED tests for allowed relative/allowlisted HTTPS redirects, unsafe redirects, expiry, and consumed semantic replay.
- [ ] Verify RED.
- [ ] Implement minimal immutable/value-style domain behavior and transaction abstraction.
- [ ] Re-run focused tests to GREEN.

### Task 4: Member resolution and OAuth atomic finalization

**Files:**
- Create: `app/member/contract/MemberIdentityRepository.php`
- Create: `app/member/infrastructure/ThinkPhpMemberIdentityRepository.php`
- Create: `app/member/application/MemberIdentityService.php`
- Create: `app/oauth/infrastructure/ThinkPhpOAuthBindingRepository.php`
- Create: `app/oauth/infrastructure/ThinkPhpOAuthStateRepository.php`
- Create: `app/oauth/application/OAuthOrchestrator.php`
- Test: `tests/Component/Member/MemberIdentityServiceTest.php`
- Test: `tests/Component/OAuth/OAuthOrchestratorTest.php`

**Interfaces:**
- `MemberIdentityService::resolveOrCreate(string $tenantId, ProviderIdentity $identity): MemberIdentityResult`.
- `OAuthOrchestrator::start(...)` returns opaque state token + provider authorization URL.
- `OAuthOrchestrator::callback(string $stateToken, string $code, DateTimeImmutable $now): OAuthCallbackResult`.

- [ ] Write RED component tests for duplicate callback convergence, cross-tenant conflict, provider-account mismatch, expired state, semantic replay, and borrowed OAuth preserving business Account context.
- [ ] Verify RED.
- [ ] Implement repository methods using DB composite unique constraints; duplicate-key race must reload canonical identity.
- [ ] Implement callback: pre-read state -> provider code exchange outside lock -> `TransactionManager::run` -> lock/revalidate state -> resolve identity -> write result+consume -> one commit.
- [ ] Re-run focused tests to GREEN.

### Task 5: WeChat raw verification and Webhook Inbox

**Files:**
- Create: `app/webhook/domain/WechatWebhookRequest.php`
- Create: `app/webhook/domain/WechatWebhookEvent.php`
- Create: `app/webhook/domain/WebhookInboxResult.php`
- Create: `app/webhook/security/WechatSignatureVerifier.php`
- Create: `app/webhook/contract/WebhookInboxRepository.php`
- Create: `app/webhook/contract/ProviderEventDispatcher.php`
- Create: `app/webhook/infrastructure/ThinkPhpWebhookInboxRepository.php`
- Create: `app/webhook/application/WechatWebhookService.php`
- Test: `tests/Unit/Webhook/WechatSignatureVerifierTest.php`
- Test: `tests/Component/Webhook/WechatWebhookServiceTest.php`

**Interfaces:**
- Verifier consumes token/signature/timestamp/nonce/now and enforces 300-second window.
- Inbox unique key `(provider_type, provider_account_id, provider_event_key)` with raw body SHA-256.
- Same key+hash => duplicate ACK/no second dispatch; same key+different hash => `CONFLICT` 409.

- [ ] Write RED tests using deterministic signature fixture, stale/future timestamps, duplicate payload, and conflicting replay.
- [ ] Verify RED.
- [ ] Implement constant-time SHA1 verifier and minimal XML event-key extraction only after verifier success.
- [ ] Implement transactional Inbox insert/dedup and dispatcher call ordering.
- [ ] Re-run focused tests to GREEN.

### Task 6: Unified runner, docs, PR CI, and main gate

**Files:**
- Modify: `tests/run.php`
- Create: `docs/migration/r20-member-oauth-webhook-compatibility.md`
- Create: `docs/verification/member-oauth-webhook-r7.md`
- Modify: `README.md`

**Interfaces:**
- Runner starts at 51 tests and must increase by the exact number of added R7 executable test files.

- [ ] Add every new test file exactly once to `tests/run.php`.
- [ ] Run full offline runner, PHPUnit bridge, PHP lint, and route smoke in CI.
- [ ] Scan R7 domain/application for ThinkPHP Facade/Db imports and legacy globals; scan fixtures/docs for accidental secrets.
- [ ] Open PR from `refactor/member-oauth-webhook-r7` to `main` and require green PR CI.
- [ ] After green PR CI, fast-forward `main` non-force to the validated R7 commit and require green push CI.
- [ ] Record final SHA, test count, workflow run IDs and compatibility classification in verification doc.
