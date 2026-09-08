# R20 Member / OAuth / Webhook Compatibility — R7

## Scope

R7 introduces the first writable Member/OAuth/Webhook runtime on the ThinkPHP side while keeping WeEngine 2.7.4/R20 member/fan data strictly read-only. The compatibility boundary is intentionally asymmetric: legacy rows can be observed and mapped, but all new identity, OAuth-state, and webhook-inbox writes go to new tables created by migration `_005`.

## Member identity

The new canonical identity model is tenant-scoped:

```text
Tenant
  -> Member
  -> ExternalIdentity(provider_type, provider_account_id, external_subject)
```

The external identity key is the three-part provider key. `openid` is never globally unique across公众号/小程序/provider Accounts.

R20 snapshots preserve source dimensions without collapsing them:

- `mc_members`: `uid`, `uniacid`, `groupid`, `nickname`, `avatar`, `status`.
- `mc_mapping_fans`: `fanid`, `uniacid`, `acid`, `uid`, `openid`, `unionid`, `follow`, `followtime`, `unfollowtime`, `user_from`.

`R20MemberIdentitySnapshotRepository` uses the existing read-only `LegacyDatabase` port. It does not infer a missing Account mapping and never writes `mc_members` or `mc_mapping_fans`.

## Borrowed OAuth

R20-style borrowed OAuth requires two different Account concepts to remain explicit:

- **business Account**: the Account whose business page initiated OAuth;
- **OAuth provider Account**: the Account whose provider credential performs OAuth.

R7 stores both in `OAuthState`. They are never silently replaced by one another.

Security/runtime rules:

1. generate 32 random bytes and return the hex token only to the client/provider flow;
2. persist only `SHA-256(stateToken)`;
3. state TTL is 600 seconds;
4. validate return URLs before state creation;
5. exchange the provider authorization code before acquiring the final DB row lock;
6. open one final transaction, lock the state with `FOR UPDATE`, and revalidate it;
7. verify returned provider type/provider Account against the state;
8. resolve/create the provider-account-scoped ExternalIdentity;
9. persist Member/ExternalIdentity result plus state consumption atomically;
10. a completed state supports semantic replay without exchanging the code again.

Failure classification:

- invalid/missing/expired/not-consumable state: `UNAUTHORIZED` / HTTP 401;
- provider Account mismatch: `FORBIDDEN` / HTTP 403;
- external identity already owned by another tenant: `CONFLICT` / HTTP 409.

No provider secret, access token, or raw OAuth authorization code is stored in Member identity persistence.

## Return URL compatibility

R7 does not reproduce arbitrary legacy redirect behavior.

Allowed destinations are:

- a relative path beginning with `/` but not `//`;
- an HTTPS absolute URL whose origin is explicitly allowlisted.

Control characters, backslashes, URL userinfo, non-HTTPS absolute destinations, and unknown origins are rejected. This is an intentional security tightening rather than a byte-for-byte legacy behavior clone.

## WeChat webhook ingress

Webhook handling is deliberately ordered as:

```text
raw query/signature metadata
  -> timestamp freshness + SHA1 signature verification
  -> XML parsing
  -> stable provider event key
  -> Inbox insert/dedup
  -> dispatch
  -> dispatched_at marker
```

The signature verifier:

- accepts a maximum absolute timestamp drift of 300 seconds;
- sorts token/timestamp/nonce as required by the WeChat signature algorithm;
- compares the SHA1 digest with `hash_equals`;
- rejects the request before XML parsing when verification fails.

XML parsing uses network-disabled parsing and rejects DTD-bearing payloads. R7 only extracts the minimum fields needed to derive a stable event key.

Event-key policy:

- message with `MsgId`: `msg:{MsgId}`;
- event without `MsgId`: stable SHA-256 over `FromUserName|CreateTime|MsgType|Event|EventKey`.

Inbox uniqueness is:

```text
(provider_type, provider_account_id, provider_event_key)
```

Replay semantics:

- same key + same raw-body SHA-256: duplicate ACK, no second dispatch;
- same key + different raw-body SHA-256: `CONFLICT` / HTTP 409.

The Inbox persists the raw-body hash, not the raw XML body.

## Database ownership

New writable R7 tables:

- `members`
- `external_identities`
- `oauth_bindings`
- `oauth_states`
- `webhook_inbox`

Legacy R20 member/fan tables remain read-only. R7 does not introduce a write path through `LegacyDatabase`.

## Compatibility classification

| Area | Classification | Reason |
| --- | --- | --- |
| `mc_members` / `mc_mapping_fans` observation | COMPATIBLE_READ | source identifiers are preserved independently |
| global `openid` identity | INTENTIONAL_FIX | provider Account scope is mandatory |
| borrowed OAuth business/provider separation | COMPATIBLE_SEMANTICS | both contexts are explicit and preserved |
| OAuth state storage | SECURITY_FIX | opaque token; hash-at-rest; TTL; locked finalization |
| arbitrary redirect targets | SECURITY_FIX | relative/allowlisted HTTPS only |
| WeChat signature-before-parse | SECURITY_FIX | untrusted XML is not parsed before authentication |
| duplicate webhook delivery | COMPATIBLE_SEMANTICS | duplicate delivery is ACKed without duplicate dispatch |
| same event key with changed payload | INTENTIONAL_FIX | conflicting replay is surfaced as 409 |

## Deferred beyond R7

R7 intentionally does not implement:

- full MiniApp token/session chain;
- AES encrypted WeChat message runtime;
- legacy module reply processors;
- payment/refund/fulfillment flows;
- Credit Ledger / member balance migration.

Those capabilities must be added as later slices without weakening the R7 identity and ingress invariants.
