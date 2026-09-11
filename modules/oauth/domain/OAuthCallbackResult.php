<?php

declare(strict_types=1);

namespace modules\oauth\domain;

final readonly class OAuthCallbackResult
{
    public function __construct(
        private string $tenantId,
        private string $businessAccountId,
        private string $oauthProviderAccountId,
        private string $memberId,
        private string $externalIdentityId,
        private string $returnUrl,
    ) {
    }

    public function tenantId(): string { return $this->tenantId; }
    public function businessAccountId(): string { return $this->businessAccountId; }
    public function oauthProviderAccountId(): string { return $this->oauthProviderAccountId; }
    public function memberId(): string { return $this->memberId; }
    public function externalIdentityId(): string { return $this->externalIdentityId; }
    public function returnUrl(): string { return $this->returnUrl; }
}
