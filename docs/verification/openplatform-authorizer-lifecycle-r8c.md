# R8C OpenPlatform Authorizer Lifecycle Verification

R8C verification is additive to every released R1-R8B gate. The release is not complete until the exact feature-branch head and the exact fast-forwarded `main` head both pass CI.

## Required behavior coverage

The offline suite covers:

- `_008` schema, composite authorizer identity, no plaintext secret/code columns, and rollback ordering;
- 32-byte opaque authorization state, SHA-256 state/pre-auth persistence, provider/local intent expiry and 30-second completion claims;
- hardened authenticated `/events` callback processing and R8B `/ticket` compatibility;
- browser/event completion arbitration so only the claim winner exchanges an authorization code;
- event-only `authorized`, `updateauthorized`, `unauthorized`, timestamp ordering, same-timestamp conflict handling, and exact replay idempotency;
- strict provider response parsing, including rejection of numeric-string `expires_in`;
- authorizer access-token 300-second refresh skew, 30-second lease, degraded fallback, rotated refresh-token commit, stale CAS recovery, and platform/authorizer isolation;
- ThinkPHP persistence row-lock/CAS patterns, insert-first lifecycle replay, AES-256-GCM repository boundaries, and atomic unauthorized cleanup;
- existing-Account-only MiniApp binding with component mode, cleared manual credential reference, exact Component Platform, and conflicting binding rejection;
- API route/controller architecture and secret-scan contracts.

## Local-equivalent gates

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run the repository's existing multi-app HTTP smoke commands unchanged after the PHP gates.

## Release gate

1. Require successful pull-request CI whose `head_sha` is exactly the R8C feature branch head.
2. Immediately before integration, fetch `main` and feature SHAs and compare them. Require the feature branch to be ahead with `behind_by=0`.
3. Advance `main` with a non-force fast-forward only (`force=false`).
4. Require an independent `push` CI run with `head_branch=main` and `head_sha` exactly equal to the released R8C commit.
5. Require Composer validate/install, offline suite, PHPUnit bridge, PHP lint, and HTTP smoke all to pass before declaring R8C released.

## Security review checklist

- no callback body/decrypted XML/provider credential material in controller responses, audit metadata, or exception messages;
- no plaintext state/pre-auth/auth-code/authorizer-token database columns;
- no network client invocation from repository transaction closures;
- no authorizer credential keyed by AppId alone;
- no Tenant/Account creation in R8C binding code;
- `unauthorized` blocks authorizer token retrieval immediately after commit;
- stale lease/claim holders cannot overwrite newer state.
