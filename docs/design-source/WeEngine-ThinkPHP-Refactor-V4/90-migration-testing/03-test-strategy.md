# 测试策略

Requirement → BDD Example Mapping → AC → Risk → Test Layer → TDD → Quality Gate → Manual Acceptance。

测试层：Unit / Component / Contract / Integration / API / E2E / Migration / Security / Performance / Chaos-lite。

高风险场景：跨租户、支付重复回调、DB 断线、Outbox 重复投递、权限绕过、模块包恶意输入、Theme 注入。
