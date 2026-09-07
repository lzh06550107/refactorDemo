# EPIC-03 — Tenant & Account

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Tenant & Account。

## Stories
- [ST-03-01 Tenant 模型](./EPIC-03/ST-03-01.md)
- [ST-03-02 Account/AccountType/Capability](./EPIC-03/ST-03-02.md)
- [ST-03-03 Domain/Site/Account Resolver](./EPIC-03/ST-03-03.md)
- [ST-03-04 账号生命周期](./EPIC-03/ST-03-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
