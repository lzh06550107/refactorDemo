<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use modules\account\domain\AccountType;
use InvalidArgumentException;

final readonly class AuthorizerMetadata
{
    /**
     * @param array<string,mixed> $businessInfo
     * @param array<string,mixed>|null $miniProgramInfo
     */
    public function __construct(
        private string $componentPlatformId,
        private string $authorizerAppId,
        private AccountType $accountType,
        private string $nickName,
        private ?string $headImageUrl,
        private string $originalId,
        private string $principalName,
        private string $alias,
        private ?int $serviceType,
        private ?int $verifyType,
        private array $businessInfo,
        private ?string $qrcodeUrl,
        private ?array $miniProgramInfo,
        private string $normalizedMetadataJson,
        private string $metadataHash,
    ) {
        if (trim($componentPlatformId) === '' || trim($authorizerAppId) === '') {
            throw new InvalidArgumentException('Authorizer metadata identity must not be empty.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $metadataHash)) {
            throw new InvalidArgumentException('Authorizer metadata hash must be a lowercase SHA-256 hex digest.');
        }
        if (trim($normalizedMetadataJson) === '') {
            throw new InvalidArgumentException('Normalized authorizer metadata JSON must not be empty.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function authorizerAppId(): string { return $this->authorizerAppId; }
    public function accountType(): AccountType { return $this->accountType; }
    public function nickName(): string { return $this->nickName; }
    public function headImageUrl(): ?string { return $this->headImageUrl; }
    public function originalId(): string { return $this->originalId; }
    public function principalName(): string { return $this->principalName; }
    public function alias(): string { return $this->alias; }
    public function serviceType(): ?int { return $this->serviceType; }
    public function verifyType(): ?int { return $this->verifyType; }

    /** @return array<string,mixed> */
    public function businessInfo(): array { return $this->businessInfo; }

    public function qrcodeUrl(): ?string { return $this->qrcodeUrl; }

    /** @return array<string,mixed>|null */
    public function miniProgramInfo(): ?array { return $this->miniProgramInfo; }

    public function normalizedMetadataJson(): string { return $this->normalizedMetadataJson; }
    public function metadataHash(): string { return $this->metadataHash; }
}
