# Strangler Migration Plan

```text
M1 Foundation + IAM + Tenant/Account
M2 Module Registry + Legacy Adapter
M3 Entitlement/Quota + Site/Theme
M4 Member/OAuth/Webhook
M5 Payment/Outbox/Worker
M6 Marketplace/Media/Notification/Ops
M7 Native module migration + retire legacy
```

旧入口由 LegacyRouteAdapter 决定流向新 Handler 或 Legacy Adapter；禁止 Big Bang。
