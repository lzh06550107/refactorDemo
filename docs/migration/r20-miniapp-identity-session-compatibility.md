# R20 MiniApp Identity / Session Compatibility

## Scope

R8A migrates the WeChat Mini Program end-user identity/session path from WeEngine 2.7.4/R20 into the ThinkPHP strangler platform. It does not implement the OpenPlatform ticket/component-token/authorizer lifecycle; those trust-chain pieces remain R8B/R8C.

## R20 facts preserved

- `account.type = 4` is a directly configured WeChat Mini Program (manual mode).
- `account.type = 7` is an OpenPlatform-authorized WeChat Mini Program (component mode).
- `account_wxapp.key` is the Mini Program AppID.
- The R20 code-login path exchanges a one-time `code` with WeChat and receives `openid`, optional `unionid`, and `session_key`.
- Direct mode uses `/sns/jscode2session`; component mode uses `/sns/component/jscode2session` with a component AppID/access token.
- Legacy encrypted profile processing validates `sha1(rawData + session_key)` and requires decrypted `watermark.appid` to equal the current Mini Program AppID.
- WeChat encrypted profile payloads use AES-128-CBC with Base64-encoded key, IV, and ciphertext.

## Compatibility classification

| R20 behavior | R8A classification | R8A behavior |
| --- | --- | --- |
| `code -> jscode2session -> openid/unionid/session_key` | MUST_COMPAT | Preserved through `MiniAppCodeExchangeClient`. |
| Direct and component jscode2session endpoints | MUST_COMPAT | Preserved; component credentials enter through `ComponentAccessTokenProvider`. |
| `watermark.appid` validation | MUST_COMPAT | Preserved before decrypted profile data is accepted. |
| `sha1(rawData + session_key)` legacy profile signature | MUST_COMPAT | Preserved with constant-time `hash_equals`. |
| Client-supplied `openid` restores authenticated PHP Session | SECURITY_FIX / UNSUPPORTED_LEGACY | Removed. No R8A API authenticates from a client-provided openid. |
| Raw `session_key` kept in PHP Session | REPLACED_BY_SECURE_SESSION | Replaced by a short-lived server session with encrypted-at-rest session key. |
| Generic PHP session id returned to MiniApp | REPLACED_BY_SECURE_SESSION | Replaced by a 256-bit opaque token; only its SHA-256 hash is stored. |

## Provider-account identity boundary

R8A does not use a global `openid` key. The canonical external identity is:

```text
provider_type = wechat_mini_program
provider_account_id = internal Account.id
external_subject = WeChat openid
union_id = optional WeChat unionid attribute
```

Using internal `Account.id` as the provider-account scope means the same openid under two business accounts cannot silently collapse into one identity.

## R20 provider configuration migration

The R20 read-only snapshot treats account mode as an explicit fact from `account.type`; it does not infer mode from whether an authorizer refresh token happens to be present. `account_wxapp.key` maps to `provider_app_id`.

Legacy appsecret and authorizer-token values are never exposed through snapshot serialization. Migration may record presence/reference metadata and resolve a manual appsecret through the dedicated credential-provider boundary. R20 tables remain read-only.

R20 does not provide a stable per-account foreign key to a component-platform row. Consequently R8A does not invent one in its Golden Master. A new-system `component_platform_id` is an explicit platform binding and the production component ticket/token lifecycle is deferred to R8B/R8C.

## Secure session replacement

A successful R8A login performs WeChat code exchange before opening the final database transaction. Inside one transaction it:

1. resolves/creates the provider-account-scoped Member/ExternalIdentity;
2. generates 32 random bytes for the opaque MiniApp token;
3. persists only `SHA-256(token)`;
4. protects the WeChat `session_key` before persistence;
5. inserts the MiniApp session and writes the success audit record.

The default session lifetime is exactly 1800 seconds. Missing, expired, revoked, cross-tenant, and cross-account sessions all fail closed as unauthenticated.

The `session_key` at-rest protector uses AES-256-GCM with a random 96-bit IV, a 128-bit authentication tag, and an explicit key version. Encryption keys are supplied externally to the cipher and are not stored in the database or source tree.

## Encrypted profile compatibility

The authenticated server session is resolved first. Only then is its protected `session_key` revealed in memory for the legacy-compatible profile operation. R8A:

1. verifies `sha1(rawData . session_key)` with `hash_equals`;
2. decrypts the WeChat AES-128-CBC payload;
3. parses JSON;
4. requires `watermark.appid === configured provider_app_id`;
5. strips watermark metadata before returning profile data.

Malformed signatures, ciphertext, JSON, or watermark data fail with stable application errors and never place session keys or decrypted plaintext in error messages.

## Deliberately deferred

R8A only defines the `ComponentAccessTokenProvider` port. The following are not implemented here:

- `component_verify_ticket` ingestion and replay/signature hardening;
- component access-token refresh/caching;
- authorizer refresh/access-token lifecycle;
- OpenPlatform account-authorization mutation flows.

Those belong to R8B/R8C so the end-user MiniApp identity boundary remains independently testable and releasable.
