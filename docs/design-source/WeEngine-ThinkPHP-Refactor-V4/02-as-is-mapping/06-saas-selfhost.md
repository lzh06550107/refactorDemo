# SaaS / Self-host 迁移语义

原微擎不是两套代码，而是同一可本地安装的多账号平台在不同运营拓扑下运行。V4 保留这种产品思想，但把旧 `User/uniacid` 混合模型升级为明确的 `Tenant -> Account`。
