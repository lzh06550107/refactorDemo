# NFR

## Security
- public/ 唯一 WebRoot。
- worker/common 无 HTTP Route。
- 所有 tenant scoped SQL 必须显式 tenant_id/account_id 约束或 Repository 自动注入。
- Module package 需 hash/signature/manifest validation。
- Webhook 防重放 + 幂等键 + 原始 body 验签。

## Reliability
- MySQL 8/InnoDB/utf8mb4。
- 关键状态与 Outbox 同事务。
- Queue 至少一次投递，Consumer 必须幂等。
- DB 断线时禁止透明恢复原事务。

## Performance
- 热点读取允许 Redis Cache，但数据库是事实源。
- 租户/账号/模块权限可物化缓存，必须有版本号和明确失效链。

## Observability
request_id/trace_id/tenant_id/account_id/user_id/module_key 进入结构化日志与审计。
