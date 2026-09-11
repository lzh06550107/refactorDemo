<?php

declare(strict_types=1);

namespace modules\member\domain;

use InvalidArgumentException;

final class ExternalIdentity
{
    public function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $memberId,
        private readonly ProviderIdentity $providerIdentity,
        private readonly ?int $legacyUniacid = null,
        private readonly ?int $legacyAcid = null,
        private readonly ?int $legacyUid = null,
    ) {
        if (trim($id) === '' || trim($tenantId) === '' || trim($memberId) === '') {
            throw new InvalidArgumentException('External identity requires id, tenant and member.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function providerIdentity(): ProviderIdentity
    {
        return $this->providerIdentity;
    }

    public function legacyUniacid(): ?int
    {
        return $this->legacyUniacid;
    }

    public function legacyAcid(): ?int
    {
        return $this->legacyAcid;
    }

    public function legacyUid(): ?int
    {
        return $this->legacyUid;
    }
}
