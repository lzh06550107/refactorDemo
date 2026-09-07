# WeEngine 2.7.4 legacy entrypoint map

This R1 keeps the supplied R20 tree immutable and records only the first Strangler boundary.

| Legacy entry | R20 behavior | New owner |
|---|---|---|
| `/index.php` | Host/site binding and mobile/desktop gateway | Router / DomainResolver |
| `/web/index.php` | Admin bootstrap, session, ACL and controller forwarding | `app/admin` |
| `/app/index.php` | Public/H5 account runtime | `app/web` |
| `/api.php` | WeChat verification/message runtime | `app/api` webhook |
| `/payment/*` | Payment provider callbacks | `app/api` payment webhook |
| `/install.php` | Installer | deployment-only, disabled in production |

R1 does **not** execute legacy dynamic includes. `LegacyRouteAdapter` is intentionally deferred until Tenant/Account identity exists, matching the V4 M2 sequence.
