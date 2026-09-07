<?php

declare(strict_types=1);

namespace app\site\domain;

use InvalidArgumentException;

final readonly class DomainName
{
    private function __construct(private string $value) {}

    public static function fromHostOrUrl(string $input): self
    {
        $input = trim($input);
        if ($input === '' || preg_match('/[\x00-\x1F\x7F]/', $input) === 1) {
            throw new InvalidArgumentException('Domain must not be empty or contain control characters.');
        }

        $host = $input;
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                throw new InvalidArgumentException('Invalid domain URL.');
            }
            if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
                throw new InvalidArgumentException('Only http/https domain URLs are supported.');
            }
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('Domain URL must not contain credentials, port, query or fragment.');
            }
            $path = (string) ($parts['path'] ?? '');
            if ($path !== '' && $path !== '/') {
                throw new InvalidArgumentException('Domain URL must not contain a path.');
            }
            $host = (string) $parts['host'];
        } elseif (str_contains($input, '@') || str_contains($input, ':') || str_contains($input, '/') || str_contains($input, '?') || str_contains($input, '#')) {
            throw new InvalidArgumentException('Raw domain must contain host only.');
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || strlen($host) > 253) {
            throw new InvalidArgumentException('Domain host length is invalid.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $labels = explode('.', $host);
            foreach ($labels as $label) {
                if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                    throw new InvalidArgumentException('Domain host syntax is invalid.');
                }
            }
        }
        return new self($host);
    }

    public function value(): string { return $this->value; }
}
