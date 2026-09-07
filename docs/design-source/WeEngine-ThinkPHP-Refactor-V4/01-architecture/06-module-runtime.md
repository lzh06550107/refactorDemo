# Module Runtime

## 新对象
- ModuleDefinition：全局身份/Publisher/状态。
- ModuleVersion：版本、Package Hash、Runtime、Compatibility。
- ModuleBinding：Admin/Web/API/Webhook/Worker 入口声明。
- ModuleCapability：支持哪些 AccountType/Runtime。
- ModulePluginRelation：插件与主模块依赖。
- TenantModule：租户启用/授权状态。
- AccountModuleConfig：账号级排序/快捷/配置。

## 替代旧 module_fetch()
```text
ModuleRepository              读取全局定义
ModuleBindingRepository       入口声明
ModulePluginResolver          插件关系
ModuleCapabilityResolver      support/recycle
ModuleCloudMetadataRepository 可选控制面状态
TenantModuleRepository        Tenant/Account overlay
        ↓
ModuleRuntimeResolver
        ↓
RuntimeModuleView
```

授权计算不塞进 RuntimeResolver；由 EntitlementService + AuthorizationService 独立完成。
