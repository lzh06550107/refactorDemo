# R20 -> R8C OpenPlatform Authorizer Lifecycle Migration

R8C extends the released R8B Component Platform trust chain into WeChat OpenPlatform authorizer authorization and refresh-token lifecycle while keeping authorizer credentials platform-scoped.

## Prerequisites

Apply `20260908_008_openplatform_authorizer_lifecycle_up.sql` only after R8A `_006` and R8B `_007`. Every referenced Component Platform, Tenant, and target Account must already exist. R8C does not create Tenant or Account rows.

## New state

The migration creates:

- `openplatform_authorization_intents` for one-time browser/event arbitration. Only SHA-256 state/pre-auth hashes are persisted.
- `authorizer_authorizations` keyed by `(component_platform_id, authorizer_app_id)` with protected refresh-token storage and lifecycle ordering metadata.
- `authorizer_access_tokens` keyed by the same composite identity with protected access-token storage.
- `authorizer_token_refresh_leases` for 30-second authorizer-specific singleflight refresh ownership.

Lifecycle replay continues to use the R8B `component_ticket_inbox` unique `(component_platform_id, replay_key)` gate. Ticket callbacks continue through the R8B ticket persistence path.

## Rollout

1. Apply `_008` after `_007`.
2. Keep all authorizer refresh/access-token key material in the existing OpenPlatform AES-256-GCM secret-cipher configuration; do not place plaintext credentials in SQL or application configuration.
3. Configure the canonical encrypted callback endpoint at `/api/v1/openplatform/components/{componentPlatformId}/events`. Keep `/ticket` configured only where R8B compatibility is still required.
4. Configure the server-owned HTTPS authorization callback URI used by `AuthorizationStartService`; clients must not supply arbitrary redirect URIs.
5. Start authorization only for an existing Tenant and existing WeChat Mini Program Account. Completion may create/update `miniapp_provider_accounts` for that Account, but never a Tenant or Account.
6. Existing manual MiniApp provider configuration may move to component mode only for the same provider AppId; conflicting provider/platform ownership fails closed.

## Credential migration

R8C deliberately does not bulk-import legacy plaintext authorizer credentials or historical authorization codes. A fresh provider authorization seeds the canonical platform-level authorization registry and protected access/refresh credentials.

## Rollback

Apply `20260908_008_openplatform_authorizer_lifecycle_down.sql` in reverse dependency order. Existing R8B Component Platform/ticket/token state remains intact. Any external secret-provider keys used to encrypt R8C authorizer credentials must be retired separately according to deployment key-management policy.

## Security invariants

- no plaintext callback state, `pre_auth_code`, authorization code, authorizer refresh token, or authorizer access token is persisted;
- provider/network I/O is outside database transactions;
- completion claims and token refresh leases are recoverable by expiry;
- authorizer token refresh is scoped by `(componentPlatformId, authorizerAppId)` and holder/auth-version/token-version CAS;
- `unauthorized` atomically removes usable refresh/access-token and lease state;
- R8C never creates Tenant or Account rows.
