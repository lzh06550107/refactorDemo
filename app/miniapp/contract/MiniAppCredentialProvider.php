<?php

declare(strict_types=1);

namespace app\miniapp\contract;

interface MiniAppCredentialProvider
{
    public function secretFor(string $credentialRef): string;
}
