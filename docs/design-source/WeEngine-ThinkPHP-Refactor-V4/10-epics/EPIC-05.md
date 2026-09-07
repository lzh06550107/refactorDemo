# EPIC-05 — Entitlement & Quota

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Entitlement & Quota。

## Stories
- [ST-05-01 Tenant Module Entitlement](./EPIC-05/ST-05-01.md)
- [ST-05-02 Account Creation Quota](./EPIC-05/ST-05-02.md)
- [ST-05-03 Quota Ledger 与父级池](./EPIC-05/ST-05-03.md)
- [ST-05-04 Entitlement 生命周期](./EPIC-05/ST-05-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
