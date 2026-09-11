<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

interface OpenPlatformHttpTransport
{
    /** @return array<string,mixed> */
    public function postJson(string $url, array $payload, int $timeoutSeconds): array;
}
