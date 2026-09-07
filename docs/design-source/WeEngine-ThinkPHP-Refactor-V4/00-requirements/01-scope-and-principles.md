# 范围与设计原则

## In Scope
IAM、Tenant/Account、Module/Marketplace、Site/Theme、Member/Identity、Integration、Payment、Media/Notification、Worker、Migration、Deployment。

## Out of Scope（首版）
- 不拆微服务；先做模块化单体。
- 不引入 Kafka；Redis Queue 足够，可靠性由 Outbox 提供。
- 不承诺所有历史第三方模块零修改运行。
- 不允许新 Theme 执行任意服务器 PHP。

## 原则
1. 业务语义兼容优先于代码结构兼容。
2. `app/*` 只代表入口，`modules/*` 才代表业务能力。
3. Controller 只做协议适配；事务边界在 Application Service。
4. Domain 不依赖 ThinkPHP、DB、Cache、Queue、HTTP。
5. 事实源与缓存/物化结果分开建模。
6. Entitlement（买了/获授）与 Authorization（某人能操作）正交。
