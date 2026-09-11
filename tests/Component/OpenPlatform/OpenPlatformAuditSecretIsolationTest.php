<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use modules\openplatform\application\OpenPlatformAudit;

$capturingLogger = new class implements AuditLogger {
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event->toArray();
    }
};

$audit = new OpenPlatformAudit($capturingLogger);
$now = new DateTimeImmutable('2026-09-10T08:10:00Z');

$audit->admin(
    'principal-1',
    'tenant-1',
    null,
    OpenPlatformAudit::AUTHORIZATION_START,
    'request-1',
    'trace-1',
    [
        'component_platform_id' => 'platform-1',
        'state' => 'SECRET_STATE_123',
        'pre_auth_code' => 'SECRET_PREAUTH_123',
    ],
    $now,
);
$audit->provider(
    'tenant-1',
    null,
    OpenPlatformAudit::METADATA_CHANGE,
    'request-2',
    'trace-2',
    [
        'authorizer_app_id' => 'wx-authorizer-1',
        'authorization_code' => 'SECRET_AUTH_CODE_123',
        'refresh_token' => 'SECRET_REFRESH_TOKEN_123',
        'access_token' => 'SECRET_ACCESS_TOKEN_123',
    ],
    $now,
);
$audit->system(
    'tenant-1',
    'account-1',
    OpenPlatformAudit::PROVISIONING_QUOTA_CONSUMED,
    'provisioning-1',
    [
        'provisioning_id' => 'provisioning-1',
        'quota_ledger_entry_id' => 'ledger-1',
        'raw_payload' => 'SECRET_ACCESS_TOKEN_123',
    ],
    $now,
);
$audit->system(
    'tenant-1',
    null,
    OpenPlatformAudit::PROVISIONING_BINDING_CONFLICT,
    'provisioning-2',
    ['error_code' => 'binding_conflict', 'error_stage' => 'ownership'],
    $now,
);

expectSame(4, count($capturingLogger->events), 'audit boundary records four safe lifecycle events');
expectSame('admin:principal-1', $capturingLogger->events[0]['actor_id'] ?? null, 'admin actor is namespaced by principal id');
expectSame('external:wechat-openplatform', $capturingLogger->events[1]['actor_id'] ?? null, 'provider actor is canonical WeChat OpenPlatform identity');
expectSame('system:openplatform-provisioning-worker', $capturingLogger->events[2]['actor_id'] ?? null, 'worker actor is canonical system identity');
expectSame('failure', $capturingLogger->events[3]['result'] ?? null, 'binding conflict is audited as failure');

$encoded = json_encode($capturingLogger->events, JSON_THROW_ON_ERROR);
foreach ([
    'SECRET_STATE_123',
    'SECRET_PREAUTH_123',
    'SECRET_AUTH_CODE_123',
    'SECRET_REFRESH_TOKEN_123',
    'SECRET_ACCESS_TOKEN_123',
] as $sentinel) {
    expectTrue(!str_contains($encoded, $sentinel), 'secret sentinel is absent from captured audit payload: ' . $sentinel);
}
expectTrue(str_contains($encoded, 'platform-1'), 'safe component platform id remains auditable');
expectTrue(str_contains($encoded, 'ledger-1'), 'safe quota ledger id remains auditable');

$required = OpenPlatformAudit::requiredActions();
foreach ([
    OpenPlatformAudit::AUTHORIZATION_START,
    OpenPlatformAudit::AUTHORIZATION_COMPLETE,
    OpenPlatformAudit::METADATA_REFRESH,
    OpenPlatformAudit::METADATA_CHANGE,
    OpenPlatformAudit::PROVISIONING_CREATED,
    OpenPlatformAudit::PROVISIONING_METADATA_READY,
    OpenPlatformAudit::PROVISIONING_QUOTA_CONSUMED,
    OpenPlatformAudit::PROVISIONING_QUOTA_RELEASED,
    OpenPlatformAudit::PROVISIONING_PROVISIONED,
    OpenPlatformAudit::PROVISIONING_RECONNECTED,
    OpenPlatformAudit::PROVISIONING_BINDING_CONFLICT,
    OpenPlatformAudit::PROVISIONING_FAILED,
    OpenPlatformAudit::PROVISIONING_RETRY_REQUESTED,
    OpenPlatformAudit::CONNECTION_DISCONNECTED,
] as $action) {
    expectTrue(in_array($action, $required, true), 'Task 13 audit catalog includes ' . $action);
}

$throwingLogger = new class implements AuditLogger {
    public function record(AuditEvent $event): void
    {
        throw new RuntimeException('simulated audit backend failure');
    }
};
$failSafeAudit = new OpenPlatformAudit($throwingLogger);
$failSafeAudit->system(
    'tenant-1',
    null,
    OpenPlatformAudit::PROVISIONING_FAILED,
    'provisioning-failure',
    ['error_code' => 'internal_error', 'error_stage' => 'finalization'],
    $now,
);
expectTrue(true, 'audit backend failure never mutates business control flow');
