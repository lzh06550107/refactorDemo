# 项目目录

```text
weplatform/
├── app/
│   ├── admin/{controller,middleware,route,validate}
│   ├── web/{controller,middleware,route}
│   ├── api/{controller/V1,controller/Webhook,controller/OpenApi,middleware,route}
│   ├── worker/{command,job,consumer,scheduler}
│   └── common/{context,contract,event,outbox,idempotency,security,support}
├── modules/
│   ├── iam/
│   ├── tenant/
│   ├── account/
│   ├── entitlement/
│   ├── module/
│   ├── marketplace/
│   ├── site/
│   ├── theme/
│   ├── member/
│   ├── integration/
│   ├── payment/
│   ├── media/
│   └── notification/
├── themes/
├── database/migrations/
├── config/
├── public/index.php
├── runtime/
├── tests/
├── vendor/
├── composer.json
└── think
```

业务模块内部采用 Domain/Application/Infrastructure 三层。`common` 禁止出现 OrderService/ProductService 等具体业务类。
