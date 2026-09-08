<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\oauth\domain\ReturnUrlPolicy;

$policy = new ReturnUrlPolicy(['https://allowed.example', 'https://portal.example:8443']);
expectSame('/member/home?tab=profile', $policy->validate('/member/home?tab=profile'), 'relative application path is accepted');
expectSame('https://allowed.example/callback?x=1', $policy->validate('https://allowed.example/callback?x=1'), 'allowlisted HTTPS origin is accepted');
expectSame('https://portal.example:8443/return', $policy->validate('https://portal.example:8443/return'), 'allowlist includes explicit non-default HTTPS port');

foreach (['//evil.example/path', 'javascript:alert(1)', 'http://allowed.example/insecure', 'https://evil.example/path', 'member/home'] as $unsafe) {
    try {
        $policy->validate($unsafe);
        throw new RuntimeException('unsafe return URL must be rejected: ' . $unsafe);
    } catch (AppException $e) {
        expectSame(ErrorCode::INVALID_ARGUMENT, $e->errorCode(), 'unsafe return URL uses INVALID_ARGUMENT');
        expectSame(400, $e->httpStatus(), 'unsafe return URL uses HTTP 400');
    }
}
