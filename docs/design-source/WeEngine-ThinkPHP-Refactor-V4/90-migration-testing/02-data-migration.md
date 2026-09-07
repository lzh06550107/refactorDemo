# 数据迁移

## 映射原则
- legacy `uniacid` 不直接改名为 tenant_id；先识别用户/组织/账号关系，生成 Tenant + Account + LegacyMapping。
- `modules` → ModuleDefinition/Version；bindings/plugin/recycle/cloud 分表迁移。
- `uni_account_modules` → AccountModuleConfig/TenantModule runtime metadata。
- users_group/founder_group/extra_limit/create_group/store purchase → Plan/QuotaGrant/EntitlementGrant。
- users_permission → Assignment/Policy migration。

每批迁移需 checksum、row count、reconciliation report。
