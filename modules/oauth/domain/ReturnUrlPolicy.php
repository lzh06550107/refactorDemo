<?php

declare(strict_types=1);

namespace modules\oauth\domain;

use app\common\error\AppException;
use app\common\error\ErrorCode;

final class ReturnUrlPolicy
{
    /** @var array<string, true> */
    private array $allowedOrigins = [];

    /** @param list<string> $allowedOrigins */
    public function __construct(array $allowedOrigins = [])
    {
        foreach ($allowedOrigins as $origin) {
            $canonical = $this->canonicalOrigin($origin);
            if ($canonical === null) {
                throw new AppException(ErrorCode::INVALID_ARGUMENT, 'OAuth return origin must be an absolute HTTPS origin.', 400);
            }
            $this->allowedOrigins[$canonical] = true;
        }
    }

    public function validate(string $returnUrl): string
    {
        if ($returnUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $returnUrl) === 1 || str_contains($returnUrl, '\\')) {
            $this->reject();
        }

        if (str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
            return $returnUrl;
        }

        $parts = parse_url($returnUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            $this->reject();
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->reject();
        }

        $origin = $this->originFromParts($parts);
        if (!isset($this->allowedOrigins[$origin])) {
            $this->reject();
        }

        return $returnUrl;
    }

    private function canonicalOrigin(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return null;
        }

        return $this->originFromParts($parts);
    }

    /** @param array<string, mixed> $parts */
    private function originFromParts(array $parts): string
    {
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return 'https://' . $host . $port;
    }

    private function reject(): never
    {
        throw new AppException(ErrorCode::INVALID_ARGUMENT, 'OAuth return URL is not allowed.', 400);
    }
}
