# 相对原微擎的优化清单

| 旧实现 | 新实现 |
|---|---|
| `$_W` | immutable RequestContext |
| `$_GPC` | typed DTO + validation |
| c/a/do + dynamic include | explicit route + binding registry |
| permission.inc.php + users_permission 混合 | capability + assignment + policy |
| menu-based visibility | server authorization + menu projection |
| user/account module授权混合 | Authorization / Entitlement / Compatibility 分离 |
| permission_user_account_num | QuotaService + Ledger |
| module_fetch 超级合并 | RuntimeResolver composition |
| MyISAM/弱事务 | InnoDB/Application transaction |
| commit then Queue::push | Transactional Outbox |
| Theme PHP | Safe Renderer |
| 全局 mutable state | request-scoped context |
| HTTP cron | CLI worker/scheduler |
| arbitrary install PHP/SQL | package validation + migration runner |
| SaaS/self-host分叉 | single multi-tenant core + deployment policy |
