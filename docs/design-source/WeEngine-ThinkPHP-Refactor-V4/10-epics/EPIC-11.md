# EPIC-11 — Migration & Compatibility

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Migration & Compatibility。

## Stories
- [ST-11-01 Legacy Data Mapping](./EPIC-11/ST-11-01.md)
- [ST-11-02 Legacy Route/Module Adapter](./EPIC-11/ST-11-02.md)
- [ST-11-03 Strangler Cutover](./EPIC-11/ST-11-03.md)
- [ST-11-04 Rollback & Reconciliation](./EPIC-11/ST-11-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
