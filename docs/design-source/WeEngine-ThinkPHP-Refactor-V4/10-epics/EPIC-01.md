# EPIC-01 — Foundation Runtime

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Foundation Runtime。

## Stories
- [ST-01-01 多应用路由与 WebRoot](./EPIC-01/ST-01-01.md)
- [ST-01-02 RequestContext 与错误契约](./EPIC-01/ST-01-02.md)
- [ST-01-03 配置/密钥/审计基础设施](./EPIC-01/ST-01-03.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
