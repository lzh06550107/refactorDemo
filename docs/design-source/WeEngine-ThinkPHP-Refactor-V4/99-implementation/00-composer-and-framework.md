# ThinkPHP/Composer 基线

- 使用 ThinkPHP 8 系列，生产锁定经过回归验证的具体 patch 版本。
- 多应用使用 `topthink/think-multi-app`。
- Queue 使用 `topthink/think-queue`，但可靠业务事件通过 Outbox 投递。
- PSR-4 自动加载；业务 Domain/Application 避免 Facade。
- PHP 推荐选择团队验证过的 8.x LTS/稳定版本组合，并在 composer.lock 固化。
