# EPIC-07 — Member & Integrations

## Epic Goal
在保持 WeEngine 对应业务语义的前提下，以 ThinkPHP 模块化架构完成 Member & Integrations。

## Stories
- [ST-07-01 Member/ExternalIdentity](./EPIC-07/ST-07-01.md)
- [ST-07-02 WeChat OAuth/JSSDK](./EPIC-07/ST-07-02.md)
- [ST-07-03 Webhook/Message Router](./EPIC-07/ST-07-03.md)
- [ST-07-04 MiniApp/OpenPlatform](./EPIC-07/ST-07-04.md)

## Epic DoD
- 所有 Story AC 通过。
- 数据迁移、回滚、审计、监控齐全。
- 不新增跨模块反向依赖。
- Golden Master 差异被分类为 MUST_COMPAT / INTENTIONAL_FIX / UNSUPPORTED_LEGACY。
