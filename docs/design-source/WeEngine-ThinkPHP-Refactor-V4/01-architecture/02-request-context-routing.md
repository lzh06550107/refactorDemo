# RequestContext 与路由

旧 `$_W` 拆为不可变上下文：

```text
RequestContext
- requestId
- traceId
- runtimeType
- tenantId?
- accountId?
- siteId?
- principal?
- locale
- clientIp
```

旧 `$_GPC` 改为 Request DTO + Validator。

```mermaid
sequenceDiagram
  participant C as Client
  participant N as Nginx
  participant T as ThinkPHP Router
  participant M as Middleware
  participant H as Handler
  participant S as Application Service
  C->>N: request
  N->>T: public/index.php
  T->>M: route match
  M->>M: requestId/domain/tenant/account/auth/policy
  M->>H: RequestContext + DTO
  H->>S: Command/Query
  S-->>H: Result
  H-->>C: Response
```
