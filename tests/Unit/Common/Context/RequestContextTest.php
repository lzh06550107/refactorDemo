<?php

declare(strict_types=1);

use app\common\context\Principal;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;

$principal = new Principal('user-9', 'user');
$context = new RequestContext(
    requestId: 'req-123',
    traceId: 'trace-456',
    runtimeType: RuntimeType::API,
    tenantId: 'tenant-1',
    accountId: 'account-2',
    siteId: null,
    principal: $principal,
    locale: 'zh-CN',
    clientIp: '127.0.0.1',
);

expectSame('req-123', $context->requestId(), 'request id');
expectSame('trace-456', $context->traceId(), 'trace id');
expectSame(RuntimeType::API, $context->runtimeType(), 'runtime type');
expectSame('tenant-1', $context->tenantId(), 'tenant id');
expectSame('account-2', $context->accountId(), 'account id');
expectSame(null, $context->siteId(), 'site id');
expectSame($principal, $context->principal(), 'principal');
expectSame('zh-CN', $context->locale(), 'locale');
expectSame('127.0.0.1', $context->clientIp(), 'client ip');

$reflection = new ReflectionClass(RequestContext::class);
foreach ($reflection->getProperties() as $property) {
    expectTrue($property->isReadOnly(), 'RequestContext properties must be readonly: ' . $property->getName());
}
