<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

interface MiniAppHttpTransport
{
    public function get(string $url, array $query): array;
}
