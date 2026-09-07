# PRD — ThinkPHP 重构 WeEngine 平台

## 1. 产品目标

在不破坏 WeEngine 2.7.4 核心业务语义的前提下，使用 ThinkPHP 重写平台内核，目标不是逐行翻译，而是得到一个可长期维护、可私有部署、可 SaaS 运营、支持模块/主题生态和多终端账号的平台。

## 2. 业务兼容原则

### MUST_COMPAT
- 用户、平台角色、账号角色与模块权限结果。
- 多账号、公众号/小程序/WebApp 等 AccountType 语义。
- 模块安装、绑定入口、插件/主模块、账号模块配置。
- 用户模块授权与账号最终可运行模块的差异。
- 账号创建额度的基础套餐、额外额度、购买额度、消费与副创始人上限。
- 站点、域名、主题、样式、发布关系。
- 会员/粉丝/OAuth/JSSDK、消息回调、支付回调、模块支付通知。
- 应用市场购买/授权和模块生命周期的业务结果。

### INTENTIONAL_FIX
- 全局 `$_W/$_GPC` → typed RequestContext/DTO。
- 动态 include 路由 → Registry/Binding。
- MyISAM/弱事务 → MySQL 8/InnoDB/明确事务。
- “菜单隐藏即权限” → 后端统一 Policy，菜单仅投影。
- Queue 双写 → Transactional Outbox。
- Theme 任意 PHP → Safe Renderer。
- 断线透明续事务 → 事务整体失败/重试策略。

## 3. 功能需求

| ID | Requirement |
|---|---|
| FR-001 | admin/web/api 三个 HTTP 应用，worker/common 非 HTTP。 |
| FR-002 | Tenant 与 Account 解耦，支持多种 AccountType/Capability。 |
| FR-003 | 支持 Platform/Tenant IAM、RBAC、Policy、Resource Scope。 |
| FR-004 | 支持 ModuleDefinition/Version/Binding/Plugin/Capability/Lifecycle。 |
| FR-005 | 支持 TenantModule Entitlement 与 User Authorization 分离。 |
| FR-006 | 支持账号创建 Quota、套餐、加额、购买、消费、父级封顶。 |
| FR-007 | 支持 Site/Domain/Theme/StyleSnapshot/SiteThemeRelease。 |
| FR-008 | 支持 Member/ExternalIdentity/OAuth/JSSDK/消息回调。 |
| FR-009 | 支持 Payment/Refund/Fulfillment/Entitlement。 |
| FR-010 | 支持 Marketplace：MODULE/THEME/PLUGIN。 |
| FR-011 | 支持 SaaS、Self-host Single Tenant、Self-host Multi Tenant。 |
| FR-012 | 支持 Legacy WeEngine Adapter 与分阶段迁移。 |
| FR-013 | 支持 Outbox/Queue/Job/Scheduler/Idempotency。 |
| FR-014 | 支持审计、可观测性、备份恢复和发布门禁。 |

## 4. 非功能要求

- Security：默认拒绝、最小权限、租户隔离、Webhook 原始 body 验签。
- Reliability：关键写事务化，消费者幂等，失败可恢复。
- Maintainability：Domain/Application 不依赖 ThinkPHP Facade。
- Testability：每个 Story 必须有 Unit/Component/Integration/Contract/E2E 映射。
- Deployability：同一代码包覆盖 SaaS/Self-host，仅配置运营策略不同。
- Compatibility：迁移阶段建立 Golden Master 对照。
