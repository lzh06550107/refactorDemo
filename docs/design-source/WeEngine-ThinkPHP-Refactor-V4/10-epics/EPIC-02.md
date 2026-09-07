# EPIC-02 — IAM

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 IAM。

## Stories
- [ST-02-01 管理员身份与会话](./EPIC-02/ST-02-01.md)
- [ST-02-02 Role/Permission/Policy](./EPIC-02/ST-02-02.md)
- [ST-02-03 菜单投影与服务端 ACL](./EPIC-02/ST-02-03.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
