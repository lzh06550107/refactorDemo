# IAM / Authorization

## 三个正交概念
1. Capability Definition：平台定义“有什么权限、谁原则上可拥有”。
2. Assignment：某用户在某 Tenant 获得哪些权限。
3. Resource Policy：这些权限作用于哪些资源。

菜单/导航只消费最终授权结果，不是权限源。

```text
EffectivePermission
= RoleCapability
∩ Assignment
∩ ResourcePolicy
```

模块访问还需：
`ModuleInstalled AND TenantEntitled AND AccountCompatible AND UserAuthorized AND ModuleActive`。
