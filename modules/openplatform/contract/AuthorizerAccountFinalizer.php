<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizerMetadataRecord;
use modules\openplatform\domain\AuthorizerProvisioning;
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
