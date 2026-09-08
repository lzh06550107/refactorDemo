# R8A MiniApp Identity / Session Verification

## Baseline

- Base `main`: `e99c3f57161748031c7a83ae08b0a765b75f0569` (R7).
- Development branch: `refactor/miniapp-identity-session-r8a`.
- Draft PR: #4.
- GitHub Actions is the authoritative execution environment.

## TDD evidence

| Slice | Evidence | Result |
| --- | --- | --- |
| Task 1 provider/session domain + schema | CI `34193103680` | GREEN; 65 runner entries plus PHPUnit/lint/HTTP smoke. |
| Task 2 R20 provider snapshot | RED `34193400960`; GREEN `34193480018` | RED failed only on missing adapter; GREEN passed 66 runner entries and all gates. |
| Task 3 code exchange | GREEN `34193978628` | 67 runner entries and all gates passed. |
| Task 4 atomic login | RED `34194766852`; GREEN `34194940794` | RED failed only on missing MiniApp provider/session application ports; GREEN passed 68 entries and all gates. |
| Task 5 session restore + encrypted data | RED `34195167976`; GREEN `34195381346` | RED failed only on missing session service; GREEN passed 70 entries and all gates. |
| Task 6 persistence/security | RED `34195622716` | Prior 70 entries passed; new persistence/cipher contracts failed because final adapters were intentionally absent. |

## R8A release gate target

The final branch candidate must pass all of the following on the exact candidate SHA:

- `composer validate --strict`;
- dependency installation;
- unified `php tests/run.php` with **72/72** entries;
- PHPUnit bridge;
- PHP lint over `app`, `config`, and `tests`;
- ThinkPHP HTTP smoke for `/health`, `/admin/health`, `/api/v1/health`.

After branch CI is green, `main` may be moved only by non-force fast-forward to that exact tested SHA. The same SHA must then pass the `main` push CI before R8A is declared complete.

## Security invariants under test

- No MiniApp authentication path accepts a client-supplied `openid` as evidence.
- External identity is scoped by `provider_type + internal Account.id + openid`.
- The client receives a 256-bit opaque session token; persistence contains only its SHA-256 hash.
- Raw WeChat `session_key` is never persisted, logged, audited, or returned.
- Session-key persistence uses AES-256-GCM with randomized 96-bit IVs, authentication tags, and explicit key versions.
- R20 `sha1(rawData + session_key)` and `watermark.appid` checks remain executable compatibility behavior.
- MiniApp Domain/Application code is isolated from ThinkPHP Facades and legacy `$_W`/`$_GPC`/`pdo_*` APIs.
- R20 provider tables are read-only in this slice.

## Final evidence

Pending the Task 6 GREEN candidate and subsequent `main` push verification. This section is updated only after the exact candidate has completed both gates.
