<?php

declare(strict_types=1);

namespace app\miniapp\contract;

interface MiniAppHttpTransport
{
    public function get(string $url, array $query): array;
}
