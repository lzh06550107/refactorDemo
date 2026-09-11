<?php

declare(strict_types=1);

namespace modules\miniapp\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MiniAppLoginResult
{
    public function __construct(
        private string $sessionToken,
        private string $memberId,
        private string $externalIdentityId,
        private DateTimeImmutable $expiresAt,
    ) {
        foreach (['sessionToken' => $sessionToken, 'memberId' => $memberId, 'externalIdentityId' => $externalIdentityId] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
    }

    public function sessionToken(): string { return $this->sessionToken; }
    public function memberId(): string { return $this->memberId; }
    public function externalIdentityId(): string { return $this->externalIdentityId; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
}
