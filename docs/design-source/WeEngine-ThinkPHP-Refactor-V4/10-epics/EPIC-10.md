# EPIC-10 — Deployment & Operations

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Deployment & Operations。

## Stories
- [ST-10-01 Self-host Single Tenant](./EPIC-10/ST-10-01.md)
- [ST-10-02 Self-host Multi Tenant](./EPIC-10/ST-10-02.md)
- [ST-10-03 SaaS Mode](./EPIC-10/ST-10-03.md)
- [ST-10-04 Optional Control Plane](./EPIC-10/ST-10-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
