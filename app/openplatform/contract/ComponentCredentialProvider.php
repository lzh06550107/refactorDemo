<?php

declare(strict_types=1);

namespace app\openplatform\contract;

interface ComponentCredentialProvider
{
    public function secretFor(string $credentialRef): string;
}
