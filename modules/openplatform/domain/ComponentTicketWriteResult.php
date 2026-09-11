<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class ComponentTicketWriteResult
{
    public function __construct(
        private bool $duplicate,
        private bool $stale,
        private int $ticketVersion,
    ) {
        if ($ticketVersion < 0) {
            throw new InvalidArgumentException('Ticket version must not be negative.');
        }
    }

    public function duplicate(): bool { return $this->duplicate; }
    public function stale(): bool { return $this->stale; }
    public function ticketVersion(): int { return $this->ticketVersion; }
}
