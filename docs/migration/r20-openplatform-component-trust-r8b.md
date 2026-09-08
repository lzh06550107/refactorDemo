# R20 -> R8B OpenPlatform Component Trust Migration

R8B deliberately replaces the ambiguous R20 global component-token cache with an explicit Component Platform trust chain. This migration does not copy OpenPlatform secrets or cached tokens into plaintext database columns.

## Ownership and scope

`component_platforms` is operator/platform-scoped shared infrastructure and intentionally has no `tenant_id`. Business isolation remains at the R8A Account binding: `miniapp_provider_accounts.component_platform_id` must reference an existing Component Platform.

## Rollout

1. Apply `20260908_007_openplatform_component_trust_up.sql` after the R8A `_006` migration.
2. Create one `component_platforms` row for each configured WeChat Component Platform. Store only `app_secret_ref`, `verify_token_ref`, and `encoding_aes_key_ref` references in the row.
3. Place the corresponding AppSecret, verify token, and EncodingAESKey values in the deployment's configured secret provider. Do not put the raw values in SQL, repository configuration, logs, or source control.
4. Update each R8A component-mode `miniapp_provider_accounts` row to reference the correct `component_platform_id`. The `_007` FK makes the platform existence constraint explicit; the application service additionally requires the platform to be enabled at use time.
5. Configure WeChat to deliver encrypted component callbacks to `/api/v1/openplatform/components/{componentPlatformId}/ticket`.
6. The next fresh callback with a valid `msg_signature`, valid WeChat AES frame, matching receiver/AppId, and `InfoType=component_verify_ticket` seeds `component_verify_tickets` using AES-256-GCM protected storage.
7. The first component-token demand after a current ticket exists refreshes `/cgi-bin/component/api_component_token` and seeds `component_access_tokens`, also encrypted at rest.

## Deliberately not imported

The historical fixed cache key equivalent to `account_component_assesstoken` is not imported. Its Component Platform scope is ambiguous, so reusing it would reintroduce the cross-platform trust problem that R8B fixes. Historical raw verify tickets and component access tokens are likewise not bulk-copied.

## Rollback

`20260908_007_openplatform_component_trust_down.sql` first removes the R8A provider-to-platform foreign key and then drops refresh lease, token, ticket, inbox, and platform tables in reverse dependency order. Secret-provider values are external to this migration and must be retired separately according to deployment key-management policy.

## R8C boundary

This migration stops at `component_verify_ticket -> component_access_token`. It does not create `pre_auth_code`, authorization callback/code exchange, authorizer refresh/access-token state, or Account binding lifecycle beyond the existing R8A `component_platform_id` reference.
