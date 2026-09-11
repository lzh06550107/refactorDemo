# Admin Browser Security Deployment

The Admin SPA uses server-side cookie sessions and double-submit CSRF protection.

## Production HTTPS requirement

Production deployments must run the Admin surface behind HTTPS and set:

```env
WEPLATFORM_ADMIN_COOKIE_SECURE=true
WEPLATFORM_ADMIN_SESSION_TTL_SECONDS=28800
```

`WEPLATFORM_ADMIN_COOKIE_SECURE=false` is only for local HTTP development. Do not use it for an Internet-facing or production deployment.

The session cookie is `weplatform_admin_session` and is HttpOnly. The CSRF cookie is `weplatform_admin_csrf` and is readable by the Admin SPA so it can echo the value in `X-CSRF-Token` for state-changing `/admin-api/v1/*` requests. Both cookies use `SameSite=Lax` and path `/`.

The raw Admin session token must not be logged or stored in browser Web Storage. The database stores only the server-side token hash.
