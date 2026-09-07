# 原请求入口映射

架构层核心入口：root gateway、admin runtime、public runtime、WeChat webhook、payment callbacks。

```text
/index.php             -> Router/DomainResolver
/web/index.php         -> app/admin
/app/index.php         -> app/web
/api.php               -> app/api/Webhook
/payment/*             -> app/api/PaymentWebhook
install.php            -> deployment-only installer; production disabled
```
