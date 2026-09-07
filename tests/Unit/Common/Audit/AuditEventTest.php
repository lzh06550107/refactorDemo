<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;

$event = new AuditEvent(
    actorId: 'u-1',
    tenantId: 't-1',
    accountId: 'a-1',
    action: 'foundation.health.read',
    result: 'success',
    requestId: 'req-1',
    traceId: 'trace-1',
    metadata: ['source' => 'test'],
);
$data = $event->toArray();
expectSame('u-1', $data['actor_id'], 'actor');
expectSame('t-1', $data['tenant_id'], 'tenant');
expectSame('a-1', $data['account_id'], 'account');
expectSame('foundation.health.read', $data['action'], 'action');
expectSame('success', $data['result'], 'result');
expectSame('req-1', $data['request_id'], 'request id');
expectSame('trace-1', $data['trace_id'], 'trace id');
expectThrows(
    static fn () => new AuditEvent('u', null, null, '', 'success', 'req', 'trace'),
    InvalidArgumentException::class,
    'empty action must fail'
);
