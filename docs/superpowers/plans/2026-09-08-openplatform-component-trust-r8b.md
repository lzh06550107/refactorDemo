# R8B OpenPlatform Component Trust Chain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the authenticated WeChat OpenPlatform `component_verify_ticket -> component_access_token` trust chain required by R8A component-mode MiniApp login without importing R8C authorizer lifecycle scope.

**Architecture:** Add an `app/openplatform` bounded context with framework-free Domain/Application ports, hardened WeChat callback security, platform-scoped ticket/token persistence, and a DB-backed refresh lease. ThinkPHP DB and native HTTP/OpenSSL integrations remain in Infrastructure. R8A integrates only through `OpenPlatformComponentAccessTokenProvider`, while the API controller is transport glue only.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, think-orm 4.x, OpenSSL AES-256-CBC/GCM, DOMDocument/libxml hardened parsing, MySQL/InnoDB, existing offline PHP test runner + PHPUnit bridge + GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-09-08-openplatform-component-trust-r8b-design.md`

## Global Constraints

- Base release: R8A main `e7dd986ccad055005db6372168b8571b78131385`.
- Target branch: `refactor/openplatform-component-trust-r8b`.
- `ComponentPlatform` is operator/platform-scoped shared infrastructure and has no `tenant_id`.
- R8B stops at `ComponentPlatform -> authenticated component_verify_ticket -> ComponentVerifyTicket -> component_access_token`; authorizer authorization/token lifecycle stays R8C.
- Domain/Application code under `app/openplatform` must not import ThinkPHP facades or use `$_W`, `$_GPC`, or `pdo_*`.
- Ticket raw body limit is 128 KiB.
- Signature freshness default is ±300 seconds.
- WeChat component message decryption is AES-256-CBC with IV = first 16 key bytes and strict PKCS#7/frame validation.
- At-rest ticket/token encryption is AES-256-GCM with 32-byte key, 12-byte random IV, 16-byte tag, explicit key version.
- Token refresh skew defaults to 300 seconds; refresh lease defaults to 30 seconds.
- No raw XML, decrypted XML, verify token, EncodingAESKey, AppSecret, verify ticket, access token, or ciphertext may enter audit metadata/logging.
- Provider network I/O never executes inside a DB transaction.
- No global component-token cache key is allowed.
- All formal implementation work stays on the R8B validation branch until full branch CI is green.
- Main update is non-force fast-forward only after a fresh race check; exact main push SHA must pass CI again.

---

## File Structure

### New production files

- `app/openplatform/domain/ComponentPlatform.php` — platform id/AppId/secret refs/enabled state, no secret values.
- `app/openplatform/domain/ComponentVerifyTicket.php` — authenticated ticket value plus hash/source/received/version metadata held in memory.
- `app/openplatform/domain/ComponentAccessToken.php` — in-memory platform-scoped access token value, AppId, issued/expiry/version.
- `app/openplatform/domain/ComponentTicketEnvelope.php` — restricted outer callback envelope containing only encrypted payload and optional diagnostic outer AppId.
- `app/openplatform/domain/ComponentTokenRefreshLease.php` — holder/expiry/version value.
- `app/openplatform/domain/ComponentTicketWriteResult.php` — current ticket + semantic duplicate/stale outcome.
- `app/openplatform/domain/ComponentTokenResponse.php` — validated provider token + `expires_in`.
- `app/openplatform/contract/ComponentPlatformRepository.php` — platform lookup.
- `app/openplatform/contract/ComponentTicketRepository.php` — platform-scoped ticket/inbox transactional write + current ticket read.
- `app/openplatform/contract/ComponentTokenRepository.php` — current token read + lease-guarded CAS write.
- `app/openplatform/contract/ComponentRefreshLeaseRepository.php` — short lease acquire/release.
- `app/openplatform/contract/ComponentCredentialProvider.php` — resolve secret reference at point of use.
- `app/openplatform/contract/OpenPlatformSecretCipher.php` — AES-GCM protection port.
- `app/openplatform/contract/OpenPlatformHttpTransport.php` — bounded JSON POST transport.
- `app/openplatform/contract/ComponentTokenClient.php` — provider token API port consumed by Application.
- `app/openplatform/security/WechatComponentSignatureVerifier.php` — freshness + constant-time WeChat signature verification.
- `app/openplatform/security/WechatComponentEnvelopeParser.php` — hardened outer/inner XML parsing.
- `app/openplatform/security/WechatComponentMessageDecryptor.php` — EncodingAESKey normalization, CBC decrypt, strict padding/frame/AppId validation.
- `app/openplatform/application/ComponentTicketService.php` — exact 19-step authenticated ticket ingress orchestration.
- `app/openplatform/application/ComponentAccessTokenService.php` — cached token/refresh skew/lease/singleflight/fallback orchestration.
- `app/openplatform/infrastructure/WechatComponentTokenClient.php` — provider endpoint request/response validation.
- `app/openplatform/infrastructure/NativeOpenPlatformHttpTransport.php` — bounded native HTTP JSON transport with generic errors.
- `app/openplatform/infrastructure/OpenSslOpenPlatformSecretCipher.php` — versioned AES-256-GCM protection.
- `app/openplatform/infrastructure/ThinkPhpComponentPlatformRepository.php` — `component_platforms` mapping.
- `app/openplatform/infrastructure/ThinkPhpComponentTicketRepository.php` — encrypted ticket + replay inbox transaction.
- `app/openplatform/infrastructure/ThinkPhpComponentTokenRepository.php` — encrypted token + lease-guarded CAS transaction.
- `app/openplatform/infrastructure/ThinkPhpComponentRefreshLeaseRepository.php` — DB singleflight lease.
- `app/miniapp/infrastructure/OpenPlatformComponentAccessTokenProvider.php` — R8A contract adapter calling only the R8B Application service.
- `app/api/controller/V1/OpenPlatformTicketController.php` — external ticket callback transport adapter.
- `database/migrations/20260908_007_openplatform_component_trust_up.sql` — five R8B tables/FKs/uniques.
- `database/migrations/20260908_007_openplatform_component_trust_down.sql` — reverse-order rollback.
- `docs/migration/r20-openplatform-component-trust-r8b.md` — explicit secret-ref/ticket reseed rollout and no import of ambiguous global token cache.
- `docs/verification/openplatform-component-trust-r8b.md` — RED/GREEN and release evidence.

### Modified production/support files

- `app/common/error/ErrorCode.php` — add `BAD_GATEWAY` and `SERVICE_UNAVAILABLE`.
- `app/api/route/app.php` — add direct component ticket POST route.
- `tests/run.php` — append R8B tests without removing the R8A 72-entry baseline.
- `README.md` — record R8B bounded context and security invariants.

### New tests

- `tests/Unit/OpenPlatform/ComponentPlatformTest.php`
- `tests/Unit/OpenPlatform/WechatComponentSignatureVerifierTest.php`
- `tests/Unit/OpenPlatform/WechatComponentEnvelopeParserTest.php`
- `tests/Unit/OpenPlatform/WechatComponentMessageDecryptorTest.php`
- `tests/Component/OpenPlatform/ComponentTicketServiceTest.php`
- `tests/Unit/OpenPlatform/WechatComponentTokenClientTest.php`
- `tests/Component/OpenPlatform/ComponentAccessTokenServiceTest.php`
- `tests/Contract/OpenPlatformComponentTrustSchemaContractTest.php`
- `tests/Contract/ThinkPhpOpenPlatformPersistenceContractTest.php`
- `tests/Contract/R8BOpenPlatformArchitectureSecurityContractTest.php`
- `tests/Component/MiniApp/OpenPlatformComponentAccessTokenProviderTest.php`

---

### Task 1: Schema + ComponentPlatform

**Files:**
- Create: `app/openplatform/domain/ComponentPlatform.php`
- Create: `app/openplatform/contract/ComponentPlatformRepository.php`
- Create: `database/migrations/20260908_007_openplatform_component_trust_up.sql`
- Create: `database/migrations/20260908_007_openplatform_component_trust_down.sql`
- Create: `tests/Unit/OpenPlatform/ComponentPlatformTest.php`
- Create: `tests/Contract/OpenPlatformComponentTrustSchemaContractTest.php`
- Modify: `app/common/error/ErrorCode.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces `ComponentPlatform::__construct(string $id, string $componentAppId, string $appSecretRef, string $verifyTokenRef, string $encodingAesKeyRef, bool $enabled)`.
- Produces getters `id()`, `componentAppId()`, `appSecretRef()`, `verifyTokenRef()`, `encodingAesKeyRef()`, `enabled()`.
- Produces `ComponentPlatformRepository::findById(string $componentPlatformId): ?ComponentPlatform`.
- Adds error codes `BAD_GATEWAY` and `SERVICE_UNAVAILABLE`.

- [ ] **Step 1: Write failing domain/schema tests**

```php
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/ref', 'verify/ref', 'aes/ref', true);
expectSame('platform-1', $platform->id(), 'platform id is explicit');
expectSame('wx-component-1', $platform->componentAppId(), 'component AppId is explicit');
expectTrue($platform->enabled(), 'platform can be enabled');
```

Schema assertions require exactly five R8B tables, global `component_app_id` uniqueness, no `tenant_id` in `component_platforms`, replay uniqueness on `(component_platform_id,replay_key)`, encrypted ticket/token columns, lease PK/FKs, InnoDB/utf8mb4, and reverse-order rollback.

- [ ] **Step 2: Verify RED**

Run `php tests/run.php`; expected failure names are `OpenPlatformComponentTrustSchemaContractTest.php` and/or `ComponentPlatformTest.php` because `_007` and `ComponentPlatform` do not exist.

- [ ] **Step 3: Implement minimal domain, error catalog, and migration**

`ComponentPlatform` validates every id/ref/AppId is non-empty and carries refs only. `_007` creates `component_platforms`, `component_ticket_inbox`, `component_verify_tickets`, `component_access_tokens`, and `component_token_refresh_leases` with the exact approved columns/FKs/unique keys.

- [ ] **Step 4: Verify GREEN**

Run `php tests/run.php`; Task 1 tests and all pre-existing tests must pass.

- [ ] **Step 5: Commit**

`git commit -m "feat: add OpenPlatform component trust schema"`

---

### Task 2: Signature + Hardened Envelope + WeChat AES

**Files:**
- Create: `app/openplatform/domain/ComponentTicketEnvelope.php`
- Create: `app/openplatform/security/WechatComponentSignatureVerifier.php`
- Create: `app/openplatform/security/WechatComponentEnvelopeParser.php`
- Create: `app/openplatform/security/WechatComponentMessageDecryptor.php`
- Create: `tests/Unit/OpenPlatform/WechatComponentSignatureVerifierTest.php`
- Create: `tests/Unit/OpenPlatform/WechatComponentEnvelopeParserTest.php`
- Create: `tests/Unit/OpenPlatform/WechatComponentMessageDecryptorTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- `WechatComponentEnvelopeParser::parseOuter(string $rawBody): ComponentTicketEnvelope`.
- `WechatComponentEnvelopeParser::parseInnerTicket(string $xml): array{appId:string,infoType:string,ticket:string}`.
- `WechatComponentSignatureVerifier::verify(string $verifyToken, string $timestamp, string $nonce, string $encryptedPayload, string $signature, DateTimeImmutable $now): void`.
- `WechatComponentMessageDecryptor::decrypt(string $encryptedPayload, string $encodingAesKey, string $expectedComponentAppId): string`.

- [ ] **Step 1: Write failing security tests**

Tests construct a real WeChat-compatible CBC frame (`16 random + N-length + XML + receiver`) and assert valid signature/freshness/decrypt passes; bad signature, ±301 second timestamps, DOCTYPE/entity declarations, body >131072 bytes, malformed Base64/key/padding/frame, and framed receiver mismatch fail with the stable status required by the spec.

- [ ] **Step 2: Verify RED**

Run the three new Unit test files through `tests/run.php`; expected failure is missing R8B security classes.

- [ ] **Step 3: Implement minimal security code**

Use `sort($parts, SORT_STRING)`, SHA-1, `hash_equals()`, DOMDocument with `LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS`, explicit rejection of `DOCTYPE`/`ENTITY`, AES-256-CBC with `OPENSSL_ZERO_PADDING`, strict PKCS#7 validation for block size 32, network-order `unpack('N', ...)`, exact frame-length validation, and constant-time receiver comparison.

- [ ] **Step 4: Verify GREEN**

Run `php tests/run.php`; all security cases and prior suite pass.

- [ ] **Step 5: Commit**

`git commit -m "feat: authenticate OpenPlatform ticket envelopes"`

---

### Task 3: Ticket Inbox + Latest Ticket Service

**Files:**
- Create: `app/openplatform/domain/ComponentVerifyTicket.php`
- Create: `app/openplatform/domain/ComponentTicketWriteResult.php`
- Create: `app/openplatform/contract/ComponentTicketRepository.php`
- Create: `app/openplatform/contract/ComponentCredentialProvider.php`
- Create: `app/openplatform/application/ComponentTicketService.php`
- Create: `tests/Component/OpenPlatform/ComponentTicketServiceTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- `ComponentTicketRepository::current(string $componentPlatformId): ?ComponentVerifyTicket`.
- `ComponentTicketRepository::accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult` performs authoritative replay + latest-ticket decision atomically.
- `ComponentCredentialProvider::secretFor(string $credentialRef): string`.
- `ComponentTicketService::ingest(string $componentPlatformId, string $rawBody, string $timestamp, string $nonce, string $msgSignature, DateTimeImmutable $now, string $requestId, string $traceId): void`.

- [ ] **Step 1: Write failing ticket-service tests**

Use in-memory platform/ticket repositories and deterministic real crypto fixtures. Assert exact replay is semantic success with one mutation, same replay key/different payload is 409, wrong inner AppId is 403, wrong InfoType is 400, older authenticated ticket never overwrites newer, same timestamp/same hash is idempotent, same timestamp/different hash is 409, and audit metadata contains only platform/AppId/duplicate/version/outcome.

- [ ] **Step 2: Verify RED**

Run `php tests/run.php`; expected failure is missing ticket domain/contracts/service.

- [ ] **Step 3: Implement exact ingress order**

Resolve enabled platform before parsing body, verify outer/freshness/signature before decrypting, validate framed + inner AppId, derive `replay_key` and `payload_hash` exactly from the spec, then call one repository transaction boundary. Audit is emitted only after repository success and logger exceptions do not undo an accepted callback.

- [ ] **Step 4: Verify GREEN**

Run `php tests/run.php`; Task 3 and all prior tests pass.

- [ ] **Step 5: Commit**

`git commit -m "feat: persist authenticated component verify tickets"`

---

### Task 4: Component Token Client + Secret Cipher

**Files:**
- Create: `app/openplatform/domain/ComponentTokenResponse.php`
- Create: `app/openplatform/contract/ComponentTokenClient.php`
- Create: `app/openplatform/contract/OpenPlatformHttpTransport.php`
- Create: `app/openplatform/contract/OpenPlatformSecretCipher.php`
- Create: `app/openplatform/infrastructure/WechatComponentTokenClient.php`
- Create: `app/openplatform/infrastructure/NativeOpenPlatformHttpTransport.php`
- Create: `app/openplatform/infrastructure/OpenSslOpenPlatformSecretCipher.php`
- Create: `tests/Unit/OpenPlatform/WechatComponentTokenClientTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- `OpenPlatformHttpTransport::postJson(string $url, array $payload, int $timeoutSeconds): array`.
- `ComponentTokenClient::refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse`.
- `OpenPlatformSecretCipher::protect(string $plaintext): array{ciphertext:string,keyVersion:string}`.
- `OpenPlatformSecretCipher::reveal(string $ciphertext, string $keyVersion): string`.

- [ ] **Step 1: Write failing provider/cipher tests**

Assert exact provider URL/body, positive integer `expires_in`, non-empty token, provider `errcode`/transport failure -> generic `BAD_GATEWAY/502`, thrown messages omit request secret/ticket/token/provider body, GCM output is randomized, key version is recorded, round-trip works, and tampering/unknown key version fails `INTERNAL_ERROR/500`.

- [ ] **Step 2: Verify RED**

Run `php tests/run.php`; expected failure is missing token client/cipher classes.

- [ ] **Step 3: Implement minimal provider client and GCM cipher**

Use bounded native POST timeout (10 seconds default), generic error messages, and the existing R8A GCM storage layout pattern (`base64(iv || tag || ciphertext)`) with R8B-specific generic error text.

- [ ] **Step 4: Verify GREEN**

Run `php tests/run.php`; Task 4 and prior suite pass.

- [ ] **Step 5: Commit**

`git commit -m "feat: add component token client and secret cipher"`

---

### Task 5: Refresh Lease / Singleflight + ComponentAccessTokenService

**Files:**
- Create: `app/openplatform/domain/ComponentAccessToken.php`
- Create: `app/openplatform/domain/ComponentTokenRefreshLease.php`
- Create: `app/openplatform/contract/ComponentTokenRepository.php`
- Create: `app/openplatform/contract/ComponentRefreshLeaseRepository.php`
- Create: `app/openplatform/application/ComponentAccessTokenService.php`
- Create: `tests/Component/OpenPlatform/ComponentAccessTokenServiceTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- `ComponentTokenRepository::current(string $componentPlatformId): ?ComponentAccessToken`.
- `ComponentTokenRepository::compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool` atomically validates lease holder/unexpired lease + token version.
- `ComponentRefreshLeaseRepository::tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease`.
- `ComponentRefreshLeaseRepository::release(string $componentPlatformId, string $holderId): void`.
- `ComponentAccessTokenService::forPlatform(string $componentPlatformId, ?DateTimeImmutable $now = null): ComponentAccessToken`.

- [ ] **Step 1: Write failing lifecycle tests**

Assert token outside skew avoids provider HTTP; token inside skew refreshes; lease loser + valid old token returns old; lease loser + expired/missing token -> 503; winner double-check avoids redundant refresh; provider success persists versioned token; provider failure + old unexpired token returns old; failure + expired/missing -> 502; missing verify ticket -> 503; stale lease holder cannot CAS; platform A/B never share token/lease state.

- [ ] **Step 2: Verify RED**

Run `php tests/run.php`; expected failure is missing token service/domain/contracts.

- [ ] **Step 3: Implement minimal singleflight service**

Use a 300-second refresh skew and 30-second lease by default. Network refresh is outside repository transactions. `finally` attempts holder-scoped release. CAS failure rereads current state and never overwrites a newer winner. Audit occurs only after a successful new-token CAS and contains no secret/token material.

- [ ] **Step 4: Verify GREEN**

Run `php tests/run.php`; Task 5 and prior suite pass.

- [ ] **Step 5: Commit**

`git commit -m "feat: singleflight component access token refresh"`

---

### Task 6: ThinkPHP Persistence + API/R8A Adapter + Release Gates

**Files:**
- Create: `app/openplatform/infrastructure/ThinkPhpComponentPlatformRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpComponentTicketRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpComponentTokenRepository.php`
- Create: `app/openplatform/infrastructure/ThinkPhpComponentRefreshLeaseRepository.php`
- Create: `app/miniapp/infrastructure/OpenPlatformComponentAccessTokenProvider.php`
- Create: `app/api/controller/V1/OpenPlatformTicketController.php`
- Create: `tests/Contract/ThinkPhpOpenPlatformPersistenceContractTest.php`
- Create: `tests/Contract/R8BOpenPlatformArchitectureSecurityContractTest.php`
- Create: `tests/Component/MiniApp/OpenPlatformComponentAccessTokenProviderTest.php`
- Create: `docs/migration/r20-openplatform-component-trust-r8b.md`
- Create: `docs/verification/openplatform-component-trust-r8b.md`
- Modify: `app/api/route/app.php`
- Modify: `README.md`
- Modify: `tests/run.php`

**Interfaces:**
- Production repositories implement the Task 1/3/5 contracts using `think\facade\Db` only under Infrastructure.
- Ticket repository protects ticket before `Db::transaction()`, inserts inbox first, handles duplicate-key race by re-reading under lock, locks current ticket row, and applies timestamp/hash/version rules.
- Token repository reveals current encrypted token on read and protects the new token before CAS transaction; CAS locks lease/token rows and checks holder, lease expiry, and expected version before write.
- Lease repository uses short row-locking transactions and supports expired-lease takeover.
- `OpenPlatformComponentAccessTokenProvider::forPlatform(string $componentPlatformId): app\miniapp\domain\ComponentAccessToken` delegates to `ComponentAccessTokenService` only.
- `OpenPlatformTicketController` reads raw body/query/path/request context, calls `ComponentTicketService`, and returns `text/plain` `success` without accessing repositories directly.

- [ ] **Step 1: Write failing persistence/adapter/architecture tests**

Source-contract tests assert platform-scoped `where('component_platform_id', ...)`, `lock(true)`, `Db::transaction`, holder/version/expiry CAS predicates, cipher use, no plaintext persistence columns, no ThinkPHP/legacy imports in Domain/Application, no committed secret patterns, no global cache key, controller has no repository import, route is direct POST, adapter depends on service not repository, migration/release docs exist, and offline runner retains all previous R8A entries plus R8B tests.

- [ ] **Step 2: Verify RED**

Run `php tests/run.php`; expected failures identify missing ThinkPHP repositories, route/controller, adapter, and release docs.

- [ ] **Step 3: Implement production persistence and transport glue**

Follow existing ThinkPHP repository conventions (`Db::table`, `Db::transaction`, `lock(true)`, `Y-m-d H:i:s.u`). Add only API transport glue and R8A adapter; do not introduce R8C code or direct repository access from MiniApp/API controller.

- [ ] **Step 4: Verify full GREEN locally/CI**

Run all required commands:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Then run/confirm the repository GitHub Actions CI including multi-app HTTP smoke.

- [ ] **Step 5: Commit**

`git commit -m "feat: integrate OpenPlatform component trust chain"`

- [ ] **Step 6: PR validation gate**

Create/update the R8B PR against `main`, verify the exact branch HEAD workflow run is `success`, and inspect each CI step.

- [ ] **Step 7: Race-check main**

Fetch `main` again and compare `main...refactor/openplatform-component-trust-r8b`; require `behind_by == 0` and the candidate history to be a fast-forward of current main.

- [ ] **Step 8: Non-force fast-forward main**

Move `main` to the validated candidate with `force=false`. Do not create a merge commit and do not force-push.

- [ ] **Step 9: Verify exact main push CI**

Fetch workflow runs for the exact new main SHA and require the push-triggered CI conclusion to be `success` before declaring R8B complete.

---

## Self-Review

- Spec coverage: Tasks 1-6 cover ownership/schema, signature/freshness/XML/AES, replay/latest ticket, at-rest encryption/provider API, refresh lease/singleflight/fallback, ThinkPHP persistence, R8A adapter, API route, audit/secret invariants, rollout docs, and release gates.
- Placeholder scan: no TBD/TODO/follow-up placeholders are used; each task names concrete files/interfaces/tests/commands.
- Type consistency: Application depends only on Domain/contract/security; Infrastructure implements contracts; MiniApp adapter depends only on the public Application service; API controller depends only on the service.
- Explicit non-goals preserved: no `pre_auth_code`, authorization callback/code exchange, authorizer refresh/access token, Account binding, code release, payment, Redis, or global component-token cache.
