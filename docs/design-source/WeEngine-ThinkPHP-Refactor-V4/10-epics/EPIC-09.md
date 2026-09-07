# EPIC-09 — Marketplace & Package Lifecycle

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Marketplace & Package Lifecycle。

## Stories
- [ST-09-01 Artifact Catalog](./EPIC-09/ST-09-01.md)
- [ST-09-02 Package Install/Signature](./EPIC-09/ST-09-02.md)
- [ST-09-03 Upgrade/Rollback/Uninstall](./EPIC-09/ST-09-03.md)
- [ST-09-04 Purchase/Grant](./EPIC-09/ST-09-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
