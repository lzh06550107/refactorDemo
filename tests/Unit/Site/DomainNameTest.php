<?php

declare(strict_types=1);

use modules\site\domain\DomainName;

expectSame('example.com', DomainName::fromHostOrUrl('HTTPS://Example.COM/')->value(), 'URL domains normalize to lower-case host');
expectSame('www.example.com', DomainName::fromHostOrUrl('www.Example.com.')->value(), 'raw hosts normalize trailing dot');
expectSame('127.0.0.1', DomainName::fromHostOrUrl('http://127.0.0.1')->value(), 'IPv4 host is allowed');

foreach (['', 'https://example.com/path', 'https://example.com?q=1', 'user@example.com', 'example.com:8080', 'http://example.com#x'] as $invalid) {
    expectThrows(fn () => DomainName::fromHostOrUrl($invalid), InvalidArgumentException::class, 'invalid domain input must be rejected: ' . $invalid);
}
