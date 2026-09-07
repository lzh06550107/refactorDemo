# WeEngine 2.7.4 → ThinkPHP 重构完整设计 V4

> 基线：WeEngine 2.7.4 / R20 深度源码分析；目标框架：ThinkPHP 8 系列。  
> 核心原则：**业务逻辑/业务语义一致，架构实现允许并鼓励优化**。  
> 拆分方法：**Requirement → Epic → Story → Acceptance Criteria → Task → Test → Quality Gate**。

## 1. 最终架构决策

```text
HTTP Applications
├── app/admin      管理后台
├── app/web        Public Web/H5/Site
└── app/api        REST/OpenAPI/Webhook/Payment Callback

NON-HTTP Applications
├── app/worker     Queue/Job/Scheduler/Outbox Dispatcher
└── app/common     Shared Kernel（严禁业务垃圾桶化）

Business Domains
└── modules/*      IAM/Tenant/Account/Site/Theme/Member/Payment/... 

Presentation Packages
└── themes/*       非服务器任意执行型展示包
```

## 2. V4 相比 V3 的关键增强

1. 正式纳入旧微擎 `module_fetch()` 的运行时合并语义，并拆解为新架构组件。
2. 正式区分 User Module Authorization 与 Account/Tenant Module Entitlement。
3. 将 `permission_user_account_num()` 从“权限”中拆为 Entitlement + Quota，但保留旧额度业务结果。
4. 权限明确分成 Capability Definition / Assignment / Resource Policy；菜单只是授权结果投影。
5. SaaS 与 Self-host 共用同一 Multi-tenant Core，不维护两套业务代码。
6. 明确 `Tenant != Account != Legacy uniacid`，迁移时建立映射而不是直接改名。
7. 支付、模块安装、发布、权益变更采用事务边界 + Outbox + 幂等。
8. Legacy WeEngine 动态 include / `$_W` / `$_GPC` 仅存在于 Compatibility Adapter。

## 3. 阅读顺序

1. `00-requirements/00-PRD.md`
2. `01-architecture/00-target-architecture.md`
3. `02-as-is-mapping/00-as-is-to-be-overview.md`
4. `10-epics/EPIC-*.md`
5. `90-migration-testing/`
6. `99-implementation/`
