<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class OpenPlatformAudit
{
    public const AUTHORIZATION_START = 'openplatform.authorizer.authorization.start';
    public const AUTHORIZATION_COMPLETE = 'openplatform.authorizer.authorization.complete';
    public const METADATA_REFRESH = 'openplatform.authorizer.metadata.refresh';
    public const METADATA_CHANGE = 'openplatform.authorizer.metadata.change';
    public const PROVISIONING_CREATED = 'openplatform.authorizer.provisioning.created';
    public const PROVISIONING_METADATA_READY = 'openplatform.authorizer.provisioning.metadata_ready';
    public const PROVISIONING_QUOTA_CONSUMED = 'openplatform.authorizer.provisioning.quota_consumed';
    public const PROVISIONING_QUOTA_RELEASED = 'openplatform.authorizer.provisioning.quota_released';
    public const PROVISIONING_PROVISIONED = 'openplatform.authorizer.provisioning.provisioned';
    public const PROVISIONING_RECONNECTED = 'openplatform.authorizer.provisioning.reconnected';
    public const PROVISIONING_BINDING_CONFLICT = 'openplatform.authorizer.provisioning.binding_conflict';
    public const PROVISIONING_FAILED = 'openplatform.authorizer.provisioning.failed';
    public const PROVISIONING_RETRY_REQUESTED = 'openplatform.authorizer.provisioning.retry_requested';
    public const CONNECTION_DISCONNECTED = 'openplatform.authorizer.connection.disconnected';

    private const SAFE_METADATA_KEYS = [
        'component_platform_id' => true,
        'authorizer_app_id' => true,
        'intent_id' => true,
        'provisioning_id' => true,
        'metadata_version' => true,
        'authorization_version' => true,
        'scope_count' => true,
        'quota_resource_key' => true,
        'quota_ledger_entry_id' => true,
        'error_code' => true,
        'error_stage' => true,
        'account_type' => true,
    ];

    public function __construct(private AuditLogger $logger)
    {
    }

    /** @return list<string> */
    public static function requiredActions(): array
    {
        return [
            self::AUTHORIZATION_START,
            self::AUTHORIZATION_COMPLETE,
            self::METADATA_REFRESH,
            self::METADATA_CHANGE,
            self::PROVISIONING_CREATED,
            self::PROVISIONING_METADATA_READY,
            self::PROVISIONING_QUOTA_CONSUMED,
            self::PROVISIONING_QUOTA_RELEASED,
            self::PROVISIONING_PROVISIONED,
            self::PROVISIONING_RECONNECTED,
            self::PROVISIONING_BINDING_CONFLICT,
            self::PROVISIONING_FAILED,
            self::PROVISIONING_RETRY_REQUESTED,
            self::CONNECTION_DISCONNECTED,
        ];
    }

    /** @param array<string,mixed> $metadata */
    public function admin(
        string $principalId,
        ?string $tenantId,
        ?string $accountId,
        string $action,
        string $requestId,
        string $traceId,
        array $metadata = [],
        ?DateTimeImmutable $now = null,
    ): void {
        $principalId = trim($principalId);
        if ($principalId === '') {
            throw new InvalidArgumentException('Admin audit principal id must not be empty.');
        }

        $this->record(
            'admin:' . $principalId,
            $tenantId,
            $accountId,
            $action,
            $requestId,
            $traceId,
            $metadata,
            $now,
        );
    }

    /** @param array<string,mixed> $metadata */
    public function provider(
        ?string $tenantId,
        ?string $accountId,
        string $action,
        string $requestId,
        string $traceId,
        array $metadata = [],
        ?DateTimeImmutable $now = null,
    ): void {
        $this->record(
            'external:wechat-openplatform',
            $tenantId,
            $accountId,
            $action,
            $requestId,
            $traceId,
            $metadata,
            $now,
        );
    }

    /** @param array<string,mixed> $metadata */
    public function system(
        ?string $tenantId,
        ?string $accountId,
        string $action,
        string $operationId,
        array $metadata = [],
        ?DateTimeImmutable $now = null,
    ): void {
        $operationId = trim($operationId);
        if ($operationId === '') {
            throw new InvalidArgumentException('System audit operation id must not be empty.');
        }

        $this->record(
            'system:openplatform-provisioning-worker',
            $tenantId,
            $accountId,
            $action,
            'system:' . $operationId,
            'system:' . $operationId,
            $metadata,
            $now,
        );
    }

    /** @param array<string,mixed> $metadata */
    private function record(
        string $actorId,
        ?string $tenantId,
        ?string $accountId,
        string $action,
        string $requestId,
        string $traceId,
        array $metadata,
        ?DateTimeImmutable $now,
    ): void {
        if (!in_array($action, self::requiredActions(), true)) {
            throw new InvalidArgumentException('Unsupported OpenPlatform audit action.');
        }

        try {
            $this->logger->record(new AuditEvent(
                $actorId,
                $tenantId,
                $accountId,
                $action,
                $this->resultFor($action),
                $requestId,
                $traceId,
                $this->safeMetadata($metadata),
                $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ));
        } catch (Throwable) {
            // Audit is post-decision telemetry and must never mutate business truth.
        }
    }

    private function resultFor(string $action): string
    {
        return in_array($action, [
            self::PROVISIONING_BINDING_CONFLICT,
            self::PROVISIONING_FAILED,
        ], true) ? 'failure' : 'success';
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,string|int|bool|null>
     */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !isset(self::SAFE_METADATA_KEYS[$key])) {
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                continue;
            }
            $safe[$key] = $value;
        }
        return $safe;
    }
}
