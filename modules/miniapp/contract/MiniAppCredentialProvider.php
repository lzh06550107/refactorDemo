<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

interface MiniAppCredentialProvider
{
    public function secretFor(string $credentialRef): string;
}
