# R8B OpenPlatform Component Trust Verification

This document records the TDD and release-gate evidence for the approved R8B design. The authoritative implementation plan is `docs/superpowers/plans/2026-09-08-openplatform-component-trust-r8b.md`.

## Baseline

- R8A/main base: `e7dd986ccad055005db6372168b8571b78131385`.
- R8B validation branch: `refactor/openplatform-component-trust-r8b`.
- R8B scope ends at authenticated `component_verify_ticket -> component_access_token`; R8C authorizer lifecycle remains excluded.

## TDD evidence

### Task 1 — schema and ComponentPlatform

RED commit `6e97177c8c9d583811de8fbe1a1a89a007c0e192` introduced the schema/domain contracts before production implementation. PR CI run `34200494450` failed at `Run offline contract suite` while Composer validation/install succeeded.

GREEN commit `f2a3e0c80fa9e291a1b7774335fc998b4b1c0a81` added the five-table `_007` schema, provider-account FK, platform domain/port, and stable error codes. PR CI run `34200623144` passed offline suite, PHPUnit bridge, PHP lint, and multi-app HTTP smoke.

### Task 2 — callback authentication and WeChat AES

RED commit `0e41270a85640a684f6cc8248c08ec9286457264` added signature/freshness, hardened XML, and WeChat CBC framing tests first. PR CI run `34200778115` failed at the offline suite as expected.

GREEN commit `301f63aff2cc9a45a07719837ef96197645bfccf` implemented the security boundary. PR CI run `34200904293` passed offline suite, PHPUnit bridge, PHP lint, and multi-app HTTP smoke.

### Tasks 3–6 — ticket state, token lifecycle, persistence and R8A/API integration

RED commit `65402517f995eb43b70ececa0bbd81084d2f0584` added ticket replay/latest, provider token/GCM, refresh lease/singleflight, ThinkPHP persistence, R8A adapter, and architecture/release contracts before the corresponding production implementation. PR CI run `34201253771` failed at `Run offline contract suite` as expected.

The GREEN candidate is produced by the subsequent implementation commit(s). The exact final branch SHA and final PR/main CI run IDs are intentionally not prewritten here; GitHub Actions and PR history are the authoritative immutable release evidence and must be green for the exact candidate before main is advanced.

## Security invariants verified by executable contracts

- Component Platform has no tenant ownership column; `component_app_id` is globally unique.
- Callback raw XML is bounded to 128 KiB and rejects DTD/entity declarations.
- Freshness and `msg_signature` are validated before decrypted ticket content is trusted.
- WeChat AES frame receiver and inner AppId must both match the configured Component AppId.
- Replay identity is platform-scoped; same replay key/different payload is rejected.
- Verify ticket and component access token are encrypted at rest with explicit key versions.
- Refresh state is platform-scoped and guarded by a short DB lease plus holder/version/expiry CAS.
- Provider network I/O is outside DB transactions; a still-usable old token survives provider failure.
- Domain/Application code does not import ThinkPHP facades or legacy R20 globals/helpers.
- API controller and R8A adapter delegate to Application services and do not access OpenPlatform repositories directly.
- No historical global component-token cache key is recreated.

## Required release gate

Before R8B can be declared complete:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php tests/run.php
php vendor/bin/phpunit
find app config tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

The exact R8B branch HEAD must then pass the repository GitHub Actions CI, including multi-app HTTP smoke. After a fresh main race check proves a fast-forward, `main` may move to that exact validated SHA with `force=false`. The push-triggered CI for the exact new main SHA must also finish `success` before R8B is considered released.
