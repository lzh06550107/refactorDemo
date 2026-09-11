<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

interface ComponentCredentialProvider
{
    public function secretFor(string $credentialRef): string;
}
