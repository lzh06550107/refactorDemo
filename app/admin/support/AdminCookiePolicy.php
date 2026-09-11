<?php

declare(strict_types=1);

namespace app\admin\support;

final readonly class AdminCookiePolicy
{
    public function __construct(private bool $secure)
    {
    }

    public function sessionName(): string
    {
        return 'weplatform_admin_session';
    }

    public function csrfName(): string
    {
        return 'weplatform_admin_csrf';
    }

    /** @return array{expire:int,path:string,secure:bool,httponly:bool,samesite:string} */
    public function sessionOptions(int $ttlSeconds): array
    {
        return [
            'expire' => $ttlSeconds,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'lax',
        ];
    }

    /** @return array{expire:int,path:string,secure:bool,httponly:bool,samesite:string} */
    public function csrfOptions(int $ttlSeconds): array
    {
        return [
            'expire' => $ttlSeconds,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => false,
            'samesite' => 'lax',
        ];
    }
}
