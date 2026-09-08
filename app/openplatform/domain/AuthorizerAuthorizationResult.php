<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use InvalidArgumentException;

final readonly class AuthorizerAuthorizationResult
{
    private function __construct(
        private string $status,
        private ?string $authorizerAppId,
    ) {
        if (!in_array($status, ['completed', 'processing'], true)) {
            throw new InvalidArgumentException('Unsupported authorization completion result.');
        }
        if ($status === 'completed' && ($authorizerAppId === null || trim($authorizerAppId) === '')) {
            throw new InvalidArgumentException('Completed authorization result requires authorizer AppId.');
        }
        if ($status === 'processing' && $authorizerAppId !== null) {
            throw new InvalidArgumentException('Processing authorization result must not expose authorizer AppId.');
        }
    }

    public static function completed(string $authorizerAppId): self
    {
        return new self('completed', $authorizerAppId);
    }

    public static function processing(): self
    {
        return new self('processing', null);
    }

    public function status(): string { return $this->status; }
    public function authorizerAppId(): ?string { return $this->authorizerAppId; }
}
