<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ComponentVerifyTicket
{
    public function __construct(
        private string $componentPlatformId,
        private string $ticket,
        private string $ticketHash,
        private DateTimeImmutable $sourceTimestamp,
        private DateTimeImmutable $receivedAt,
        private int $version,
    ) {
        if (trim($componentPlatformId) === '' || $ticket === '' || !preg_match('/^[a-f0-9]{64}$/', $ticketHash) || $version < 0) {
            throw new InvalidArgumentException('Invalid ComponentVerifyTicket.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function ticket(): string { return $this->ticket; }
    public function ticketHash(): string { return $this->ticketHash; }
    public function sourceTimestamp(): DateTimeImmutable { return $this->sourceTimestamp; }
    public function receivedAt(): DateTimeImmutable { return $this->receivedAt; }
    public function version(): int { return $this->version; }

    public function withVersion(int $version): self
    {
        return new self($this->componentPlatformId, $this->ticket, $this->ticketHash, $this->sourceTimestamp, $this->receivedAt, $version);
    }
}
