# EPIC-04 — Module Platform

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Module Platform。

## Stories
- [ST-04-01 Module Registry/Version](./EPIC-04/ST-04-01.md)
- [ST-04-02 Binding Runtime Router](./EPIC-04/ST-04-02.md)
- [ST-04-03 Plugin/Main Dependency](./EPIC-04/ST-04-03.md)
- [ST-04-04 TenantModule/AccountModuleConfig](./EPIC-04/ST-04-04.md)
- [ST-04-05 Legacy Module Adapter](./EPIC-04/ST-04-05.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
