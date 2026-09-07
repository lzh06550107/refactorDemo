# 总体目标架构

```mermaid
flowchart TB
  Internet --> N[Nginx / public only]
  N --> TP[ThinkPHP public/index.php]
  TP --> A[app/admin]
  TP --> W[app/web]
  TP --> API[app/api]
  CLI[CLI/Queue/Scheduler] --> WK[app/worker]
  A --> M[modules/* Application]
  W --> M
  API --> M
  WK --> M
  M --> D[Domain]
  D --> I[Infrastructure Adapters]
  I --> DB[(MySQL 8)]
  I --> R[(Redis)]
  I --> O[(Outbox)]
  W --> TR[Theme Renderer]
  TR --> T[themes/*]
```

## 依赖方向
`HTTP/Worker -> Application -> Domain <- Infrastructure`。
Domain 不反向依赖入口或框架。

## 入口映射
- `/admin/*` → admin
- `/`、站点域名 → web
- `/api/v1/*` → api/public
- `/api/webhook/*` → api/webhook
- `/api/open/*` → api/open
- worker/common 无 HTTP。
