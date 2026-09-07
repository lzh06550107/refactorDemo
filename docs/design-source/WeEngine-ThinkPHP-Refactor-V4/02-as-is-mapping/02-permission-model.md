# 原权限模型与优化

AS-IS 至少包括：身份/账号角色 → 平台路由 ACL → users_permission → 模块内部权限 → 菜单展示。

TO-BE：
```text
Authentication
 -> TenantMembership
 -> Capability Registry
 -> Role/Assignment
 -> Resource Policy
 -> Effective Permission
    ├-> Backend Enforcement
    └-> Navigation Projection
```
