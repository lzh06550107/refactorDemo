<?php

declare(strict_types=1);

namespace app\common\context;

use think\Request;

final class RequestContextFactory
{
    public function __construct(private readonly CorrelationIdFactory $correlationIds = new CorrelationIdFactory())
    {
    }

    public function fromRequest(Request $request, RuntimeType $runtimeType): RequestContext
    {
        $requestId = $this->correlationIds->normalize((string) $request->header('x-request-id', ''));
        $traceId = $this->correlationIds->normalize((string) $request->header('x-trace-id', ''));
        $locale = (string) $request->header('accept-language', 'zh-CN');
        $locale = trim(explode(',', $locale)[0] ?? 'zh-CN') ?: 'zh-CN';

        return new RequestContext(
            requestId: $requestId,
            traceId: $traceId,
            runtimeType: $runtimeType,
            tenantId: null,
            accountId: null,
            siteId: null,
            principal: null,
            locale: $locale,
            clientIp: (string) $request->ip(),
        );
    }

}
