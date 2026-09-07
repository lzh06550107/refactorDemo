# 用户模块授权 vs 账号模块授权

## AS-IS 语义
`user_modules(uid)` 回答“人总体有些什么模块来源/能力”；`uni_modules_by_uniacid(uniacid)` 回答“当前账号最终能运行什么”。

## TO-BE
```text
TenantModuleEntitlement   商业/平台授权
AccountCompatibility      当前 AccountType 是否支持
ModuleActive              平台是否启用
UserAuthorization         当前用户是否能操作

EffectiveModuleAccess
= Installed
AND TenantEntitled
AND AccountCompatible
AND ModuleActive
AND UserAuthorized
```

购买资格与员工操作权限禁止合并成一张表。
