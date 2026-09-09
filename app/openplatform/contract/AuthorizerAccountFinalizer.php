<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerMetadataRecord;
use app\openplatform\domain\AuthorizerProvisioning;
use DateTimeImmutable;

interface AuthorizerAccountFinalizer
{
    public function provision(
        AuthorizerProvisioning $provisioning,
        AuthorizerMetadataRecord $metadata,
        DateTimeImmutable $now,
    ): string;

    public function reconcile(AuthorizerProvisioning $provisioning): ?string;
}
