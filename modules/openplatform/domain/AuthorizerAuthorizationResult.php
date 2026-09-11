<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class AuthorizerAuthorizationResult
{
    private function __construct(
        private string $status,
        private ?string $authorizerAppId,
        private ?string $provisioningId,
    ) {
        if (!in_array($status, ['completed', 'processing', 'provisioning'], true)) {
            throw new InvalidArgumentException('Unsupported authorization completion result.');
        }
        if ($status === 'completed' && ($authorizerAppId === null || trim($authorizerAppId) === '' || $provisioningId !== null)) {
            throw new InvalidArgumentException('Completed authorization result requires only authorizer AppId.');
        }
        if ($status === 'processing' && ($authorizerAppId !== null || $provisioningId !== null)) {
            throw new InvalidArgumentException('Processing authorization result must not expose authorizer or provisioning identity.');
        }
        if (
            $status === 'provisioning'
            && ($authorizerAppId === null || trim($authorizerAppId) === '' || $provisioningId === null || trim($provisioningId) === '')
        ) {
            throw new InvalidArgumentException('Provisioning authorization result requires authorizer AppId and provisioning id.');
        }
    }

    public static function completed(string $authorizerAppId): self
    {
        return new self('completed', $authorizerAppId, null);
    }

    public static function processing(): self
    {
        return new self('processing', null, null);
    }

    public static function provisioning(string $authorizerAppId, string $provisioningId): self
    {
        return new self('provisioning', $authorizerAppId, $provisioningId);
    }

    public function status(): string { return $this->status; }
    public function authorizerAppId(): ?string { return $this->authorizerAppId; }
    public function provisioningId(): ?string { return $this->provisioningId; }
}
