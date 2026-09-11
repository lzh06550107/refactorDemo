<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class ComponentTicketEnvelope
{
    public function __construct(
        private string $encryptedPayload,
        private ?string $outerAppId = null,
    ) {
        if (trim($encryptedPayload) === '') {
            throw new InvalidArgumentException('Encrypted component payload must not be empty.');
        }
    }

    public function encryptedPayload(): string { return $this->encryptedPayload; }
    public function outerAppId(): ?string { return $this->outerAppId; }
}
