<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class AuthorizerInfoResponse
{
    /**
     * @param array<string,mixed> $businessInfo
     * @param array<string,mixed>|null $miniProgramInfo
     */
    public function __construct(
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
    ) {
        foreach ([$serviceType, $verifyType] as $type) {
            if ($type !== null && $type < -1) {
                throw new InvalidArgumentException('Authorizer metadata type id is invalid.');
            }
        }
    }

    /** @param array<string,mixed> $info */
    public static function fromAuthorizerInfo(array $info): self
    {
        $miniProgramInfo = null;
        if (array_key_exists('MiniProgramInfo', $info)) {
            if (!is_array($info['MiniProgramInfo'])) {
                throw new InvalidArgumentException('Malformed MiniProgramInfo.');
            }
            $miniProgramInfo = $info['MiniProgramInfo'];
        }

        $businessInfo = $info['business_info'] ?? [];
        if (!is_array($businessInfo)) {
            throw new InvalidArgumentException('Malformed business_info.');
        }

        return new self(
            self::stringValue($info['nick_name'] ?? null),
            self::nullableStringValue($info['head_img'] ?? null),
            self::stringValue($info['user_name'] ?? null),
            self::stringValue($info['principal_name'] ?? null),
            self::stringValue($info['alias'] ?? null),
            self::nestedInt($info['service_type_info'] ?? null),
            self::nestedInt($info['verify_type_info'] ?? null),
            $businessInfo,
            self::nullableStringValue($info['qrcode_url'] ?? null),
            $miniProgramInfo,
        );
    }

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

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function nullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Malformed authorizer metadata string.');
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function nestedInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_key_exists('id', $value) || !is_int($value['id'])) {
            throw new InvalidArgumentException('Malformed authorizer metadata type info.');
        }
        return $value['id'];
    }
}
