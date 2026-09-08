<?php

declare(strict_types=1);

namespace app\member\domain;

use InvalidArgumentException;

final class ProviderIdentity
{
    public function __construct(
        private readonly string $providerType,
        private readonly string $providerAccountId,
        private readonly string $externalSubject,
        private readonly ?string $unionId = null,
    ) {
        if (trim($providerType) === '' || trim($providerAccountId) === '' || trim($externalSubject) === '') {
            throw new InvalidArgumentException('Provider identity requires provider type, provider account and external subject.');
        }
    }

    public function providerType(): string
    {
        return $this->providerType;
    }

    public function providerAccountId(): string
    {
        return $this->providerAccountId;
    }

    public function externalSubject(): string
    {
        return $this->externalSubject;
    }

    public function unionId(): ?string
    {
        return $this->unionId;
    }

    public function providerKey(): string
    {
        return $this->providerType . '|' . $this->providerAccountId . '|' . $this->externalSubject;
    }
}
