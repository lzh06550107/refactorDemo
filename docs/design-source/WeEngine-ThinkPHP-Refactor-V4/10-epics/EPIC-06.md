# EPIC-06 — Site & Theme

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Site & Theme。

## Stories
- [ST-06-01 Site/Domain Binding](./EPIC-06/ST-06-01.md)
- [ST-06-02 Theme/Version](./EPIC-06/ST-06-02.md)
- [ST-06-03 Style Draft/Snapshot/Release](./EPIC-06/ST-06-03.md)
- [ST-06-04 ViewContract/Safe Renderer](./EPIC-06/ST-06-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
