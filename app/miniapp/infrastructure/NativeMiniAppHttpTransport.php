<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use app\miniapp\contract\MiniAppHttpTransport;
use JsonException;
use RuntimeException;

final readonly class NativeMiniAppHttpTransport implements MiniAppHttpTransport
{
    public function __construct(private int $timeoutSeconds = 10)
    {
        if ($timeoutSeconds <= 0) {
            throw new RuntimeException('MiniApp HTTP timeout must be positive.');
        }
    }

    public function get(string $url, array $query): array
    {
        $handle = \curl_init();
        if ($handle === false) {
            throw new RuntimeException('MiniApp HTTP transport is unavailable.');
        }

        $requestUrl = $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        \curl_setopt_array($handle, [
            CURLOPT_URL => $requestUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        try {
            $body = \curl_exec($handle);
            $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($body) || $status < 200 || $status >= 300) {
                throw new RuntimeException('MiniApp provider returned an invalid HTTP response.');
            }
        } finally {
            \curl_close($handle);
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('MiniApp provider returned an invalid JSON response.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('MiniApp provider returned an invalid response payload.');
        }

        return $decoded;
    }
}
