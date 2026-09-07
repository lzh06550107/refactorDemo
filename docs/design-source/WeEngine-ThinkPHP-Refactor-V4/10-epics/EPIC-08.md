# EPIC-08 — Payment & Fulfillment

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Payment & Fulfillment。

## Stories
- [ST-08-01 PaymentOrder/Transaction](./EPIC-08/ST-08-01.md)
- [ST-08-02 Webhook Idempotency](./EPIC-08/ST-08-02.md)
- [ST-08-03 Refund](./EPIC-08/ST-08-03.md)
- [ST-08-04 Fulfillment/Entitlement](./EPIC-08/ST-08-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
