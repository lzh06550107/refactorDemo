<?php

declare(strict_types=1);

namespace app\common\audit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AuditEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        private string $actorId,
        private ?string $tenantId,
        private ?string $accountId,
        private string $action,
        private string $result,
        private string $requestId,
        private string $traceId,
        private array $metadata = [],
        ?DateTimeImmutable $occurredAt = null,
    ) {
        foreach (['actorId' => $actorId, 'action' => $action, 'result' => $result, 'requestId' => $requestId, 'traceId' => $traceId] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId,
            'tenant_id' => $this->tenantId,
            'account_id' => $this->accountId,
            'action' => $this->action,
            'result' => $this->result,
            'request_id' => $this->requestId,
            'trace_id' => $this->traceId,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'metadata' => $this->metadata,
        ];
    }
}
