<?php

declare(strict_types=1);

namespace app\webhook\security;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use DateTimeImmutable;

final class WechatSignatureVerifier
{
    private const FRESHNESS_SECONDS = 300;

    public function verify(
        string $token,
        string $signature,
        string $timestamp,
        string $nonce,
        DateTimeImmutable $now,
    ): void {
        if (trim($token) === '' || trim($nonce) === '' || !preg_match('/^\d{1,20}$/', $timestamp)) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid WeChat webhook signature metadata.', 401);
        }

        $requestTimestamp = (int) $timestamp;
        if (abs($now->getTimestamp() - $requestTimestamp) > self::FRESHNESS_SECONDS) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'WeChat webhook timestamp is outside the freshness window.', 401);
        }

        $parts = [$token, $timestamp, $nonce];
        sort($parts, SORT_STRING);
        $expected = sha1(implode('', $parts));
        $provided = strtolower(trim($signature));

        if (!hash_equals($expected, $provided)) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid WeChat webhook signature.', 401);
        }
    }
}
