# R8C WeChat Authorizer Client Protocol Amendment

Status: normative correction to the approved R8C design and implementation plan  
Applies to: `docs/superpowers/specs/2026-09-08-openplatform-authorizer-lifecycle-r8c-design.md` and `docs/superpowers/plans/2026-09-08-openplatform-authorizer-lifecycle-r8c.md`

## Reason

Implementation review against the current WeChat OpenPlatform request shape found that the three authorizer APIs require the third-party platform AppId (`component_appid`) in the JSON body in addition to `component_access_token` in the request query string.

The earlier R8C `AuthorizerClient` pseudo-signatures omitted `componentAppId`, which would leave the infrastructure client unable to build a valid provider request.

This amendment changes only the provider-port method parameters. It does not change R8C ownership, isolation, credential, transaction, replay, account-binding, or release semantics.

## Normative interface

The R8C `AuthorizerClient` MUST use:

```php
interface AuthorizerClient
{
    public function createPreAuthCode(
        string $componentAppId,
        string $componentAccessToken,
    ): PreAuthCodeResponse;

    public function queryAuthorization(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizationCode,
    ): AuthorizerAuthorizationResponse;

    public function refreshAuthorizerToken(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizerAppId,
        string $authorizerRefreshToken,
    ): AuthorizerRefreshResponse;
}
```

## Provider request mapping

### Create pre-auth code

```text
POST /cgi-bin/component/api_create_preauthcode?component_access_token=<token>
JSON: { "component_appid": "<component appid>" }
```

### Query authorization

```text
POST /cgi-bin/component/api_query_auth?component_access_token=<token>
JSON: {
  "component_appid": "<component appid>",
  "authorization_code": "<authorization code>"
}
```

### Refresh authorizer access token

```text
POST /cgi-bin/component/api_authorizer_token?component_access_token=<token>
JSON: {
  "component_appid": "<component appid>",
  "authorizer_appid": "<authorizer appid>",
  "authorizer_refresh_token": "<authorizer refresh token>"
}
```

`componentAppId` must come from the already resolved R8B `ComponentAccessToken` / `ComponentPlatform` boundary; callers must not accept it from untrusted browser input.

All original secret-redaction and network-outside-transaction requirements remain unchanged.
