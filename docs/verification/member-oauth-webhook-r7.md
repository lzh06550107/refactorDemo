# Member / OAuth / Webhook R7 Verification

## Release candidate

- Base `main` before R7: `13da0f72fdbe4b7fa2062179d1ce1b359922be1b` (R6).
- Final R7 implementation-code SHA before documentation: `e5e10e964d053247a22134f426654e55cc9a7c2b`.
- R7 is a strict descendant of the R6 base; GitHub compare reports `ahead` with `behind_by=0`.
- Unified offline runner count at the final implementation-code SHA: **61 executable test files**.
- Release-gate runner count after adding the architecture/security contract: **62 executable test files**.

The documentation/release-gate commit is intentionally separate from the implementation-code SHA above. GitHub Actions is the authoritative source for PR and final `main` push runs generated after this document is committed; embedding a commit's own SHA/run ID inside itself would require a self-referential commit chain.

## TDD evidence

### Member/OAuth application finalization

Implementation SHA:
`59d0b007b07da371ee5a8615f0aa249801f6772f`

CI run:
`34183969985` — `completed / success`.

The gate covered the Member identity service and OAuth orchestrator, including provider-account mismatch, state expiry, semantic replay, cross-tenant conflict, and one-transaction finalization behavior.

### ThinkPHP persistence adapters — RED

Test-only SHA:
`795e61f84943bba74b1cbbd38361fda40419beda`

CI run:
`34186846591` — expected failure.

Observed failure was isolated to the new persistence contract because `app/common/infrastructure/ThinkPhpTransactionManager.php` did not yet exist. All previously implemented executable tests passed.

### ThinkPHP persistence adapters — GREEN

Implementation SHA:
`eadcb4452b8bcd88867c3d22337da0ceb36c65e7`

CI run:
`34187024395` — `completed / success`.

Successful steps:

- Composer strict manifest validation;
- dependency install;
- 59-file offline runner;
- PHPUnit bridge;
- PHP lint;
- multi-app HTTP smoke.

Persistence contract invariants:

- `TransactionManager` delegates to `Db::transaction`;
- ExternalIdentity lookup includes provider type + provider Account + external subject;
- OAuth state finalization uses a row lock (`FOR UPDATE` semantics);
- OAuth binding lookup keeps tenant/business/provider context and excludes disabled bindings.

### Webhook — RED

Test-only SHA:
`5ff3fcda421737d0a87fb5a455ce4650165d44c6`

CI run:
`34187217175` — expected failure.

The two new tests failed only because `WechatSignatureVerifier` and `WebhookInboxRepository` did not yet exist. The previous 59 executable tests passed. Two harmless global-scope `DateTimeImmutable` import warnings were removed in the GREEN commit.

### Webhook — GREEN

Implementation SHA:
`e5e10e964d053247a22134f426654e55cc9a7c2b`

CI run:
`34187433331` — `completed / success`.

Successful steps:

- Composer strict manifest validation;
- dependency install;
- **61-file offline runner**;
- PHPUnit bridge;
- all PHP lint;
- `/health`, `/admin/health`, `/api/v1/health` multi-app smoke.

Webhook tests prove:

- timestamps at ±300 seconds are accepted and ±301 seconds are rejected;
- signature comparison is constant-time via `hash_equals`;
- signature verification happens before XML parsing;
- first delivery dispatches once;
- same event key + same body hash is an idempotent duplicate;
- same event key + different body hash returns `CONFLICT` 409;
- provider Account scope is preserved in the event key domain.

### Release documentation gate

Documentation candidate CI run:
`34187710580` — `completed / success`.

It re-ran Composer validation/install, the 61-file offline suite, PHPUnit, PHP lint, and multi-app HTTP smoke after README and R7 migration/verification documentation were added.

### Architecture/security release contract

`tests/Contract/R7ArchitectureSecurityContractTest.php` is the executable release scan. It raises the unified runner to 62 files and enforces:

1. R7 Member/OAuth/Webhook domain/application/security code cannot import ThinkPHP Facades/DB;
2. those layers cannot depend on legacy `$_W` or `$_GPC` globals;
3. R7 source/tests/release docs are scanned for common private-key, GitHub/AWS key, OpenAI-key, and assigned high-risk token/secret patterns;
4. deterministic fixture literals such as `wechat-token` remain allowed because they are not production credentials.

This contract must pass in the final PR candidate CI and again after the non-force `main` fast-forward.

## Architecture/security review gates

1. R7 domain/application code must not import ThinkPHP `Db`/Facade classes. Framework dependencies belong in `infrastructure` only.
2. R7 domain/application code must not depend on legacy `$_W`/`$_GPC` globals.
3. No real provider secret, access token, or raw OAuth code may be committed.
4. `LegacyDatabase` remains read-only and R7 must not write R20 member/fan tables.
5. PR candidate CI must be green before a non-force `main` fast-forward.
6. The resulting `main` push CI must be green before R7 is declared complete.

## Compatibility status

R7 compatibility classification is documented in:

`docs/migration/r20-member-oauth-webhook-compatibility.md`

The core distinction is deliberate: preserve R20 identity dimensions and borrowed-OAuth context, while treating global `openid`, unsafe redirects, unauthenticated XML parsing, and conflicting webhook replay as intentional security/correctness fixes.
