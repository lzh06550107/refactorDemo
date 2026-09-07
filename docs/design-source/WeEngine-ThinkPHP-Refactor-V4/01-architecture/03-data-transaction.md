# 数据与事务

## 规则
- 新核心只用 InnoDB。
- Domain Entity != ThinkORM Model。
- Repository Interface 在 Domain/Application，ThinkORM 实现在 Infrastructure。
- Application Service 决定事务边界。

## 断线重连
- 非事务 SELECT：允许有限重连重试。
- 非事务写：仅在幂等/可确认结果时重试。
- 事务内连接丢失：立即失败，禁止新连接继续原事务；必要时从 Application Transaction 入口整体重试。

## Outbox
```mermaid
sequenceDiagram
  participant S as AppService
  participant DB as MySQL
  participant D as Dispatcher
  participant Q as Queue
  participant C as Consumer
  S->>DB: BEGIN
  S->>DB: update aggregate
  S->>DB: insert outbox_event
  S->>DB: COMMIT
  D->>DB: claim unpublished outbox
  D->>Q: publish
  Q->>C: at-least-once
  C->>C: idempotency check
```
