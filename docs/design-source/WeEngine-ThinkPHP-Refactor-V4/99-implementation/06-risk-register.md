# 风险登记

1. Legacy `uniacid` 被误等同 Tenant → 高风险跨租户错误。
2. module_fetch/uni_modules/user_modules 语义混淆 → 模块可见/可运行错误。
3. Quota 多来源遗漏 → 客户创建额度回归。
4. 动态 Legacy 模块执行 → 供应链/权限风险。
5. 支付回调重复 → 重复发货/权益。
6. DB 重连掩盖事务丢失 → 部分提交。
7. Theme 兼容强行保留 PHP → RCE 风险。
8. SaaS 与 Self-host 两套代码 → 长期分叉。
