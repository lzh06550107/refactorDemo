# EPIC-12 — Reliability, Test & Delivery

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Reliability, Test & Delivery。

## Stories
- [ST-12-01 Outbox/Queue/Idempotency](./EPIC-12/ST-12-01.md)
- [ST-12-02 Golden Master](./EPIC-12/ST-12-02.md)
- [ST-12-03 Security/Performance Gates](./EPIC-12/ST-12-03.md)
- [ST-12-04 CI/CD/Observability](./EPIC-12/ST-12-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
