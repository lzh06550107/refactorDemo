# R20 MiniApp Identity Session Compatibility

R8A preserves verified R20 `code -> jscode2session -> openid/unionid/session_key` results and `watermark.appid` validation. It intentionally removes the unauthenticated client-supplied-openid session-restore path and replaces PHP Session storage of raw `session_key` with a short-lived opaque server session whose token is hashed at rest and whose `session_key` is encrypted at rest. R8B/R8C own the OpenPlatform ticket/token/authorizer lifecycle.
