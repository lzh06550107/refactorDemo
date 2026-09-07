# 编码边界

禁止：Controller 内 Db/Cache/Queue/HTTP 业务逻辑；Domain 内 ThinkPHP Facade；Application 直接 new 外部 SDK；common 堆业务 Service；透明重连继续事务；模块动态 include。

要求：Constructor DI；Repository Interface；DTO/Command/Query；统一 Error Contract；审计；幂等；测试可替换 Adapter。
