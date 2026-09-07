<?php

declare(strict_types=1);

namespace app\common\audit;

use app\common\contract\AuditLogger;
use app\common\security\SecretValue;
use Psr\Log\LoggerInterface;

final class StructuredAuditLogger implements AuditLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function record(AuditEvent $event): void
    {
        $payload = $this->redact($event->toArray());
        $this->logger->info('audit', $payload);
    }

    private function redact(mixed $value): mixed
    {
        if ($value instanceof SecretValue) {
            return '[REDACTED]';
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->redact($item);
        }
        return $result;
    }
}
