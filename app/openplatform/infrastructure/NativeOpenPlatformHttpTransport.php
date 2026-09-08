<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\contract\OpenPlatformHttpTransport;
use RuntimeException;

final class NativeOpenPlatformHttpTransport implements OpenPlatformHttpTransport
{
    public function postJson(string $url, array $payload, int $timeoutSeconds): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $json,
                'timeout' => max(1, $timeoutSeconds),
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '' || strlen($body) > 1048576) {
            throw new RuntimeException('OpenPlatform HTTP request failed.');
        }
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenPlatform HTTP response is invalid.');
        }
        return $decoded;
    }
}
