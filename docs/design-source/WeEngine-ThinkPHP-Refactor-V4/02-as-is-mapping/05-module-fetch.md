# module_fetch() 精确迁移

AS-IS：全局 `modules` + manifest 展开 + bindings + preview/logo + plugin + recycle/cloud + 当前 uniacid 下 `uni_account_modules` overlay，最后返回运行时结构。

TO-BE 拆分：
```text
GlobalDefinition
 + BindingMetadata
 + AssetUrlProjection
 + PluginRelation
 + Capability/ActiveState
 + OptionalControlPlaneMetadata
 + AccountModuleConfig
 = RuntimeModuleView
```

注意：RuntimeModuleView ≠ EffectiveModuleAccess；授权和运行态对象分开。
