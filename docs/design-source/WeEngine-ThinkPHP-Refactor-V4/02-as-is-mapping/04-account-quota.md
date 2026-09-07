# permission_user_account_num() → Quota 模型

AS-IS 结果近似：
```text
基础用户组/副创始人组额度
+ users_extra_limit
+ users_create_group
+ 商城购买额度
- 已创建/已消费额度
并受上级副创始人总额度约束
```

TO-BE：
- QuotaPolicy：每 AccountType 的策略。
- QuotaGrant：PLAN/PURCHASE/MANUAL/PROMOTION/MIGRATION。
- QuotaLedger：grant/consume/release/expire。
- ParentQuotaPool：渠道/副创始人池。
- QuotaService：计算 available。

IAM 只判断 `account.create`；Quota 再判断是否还有额度。
