<?php

declare(strict_types=1);

namespace app\openplatform\security;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use DateTimeImmutable;

final readonly class WechatComponentSignatureVerifier
{
    public function __construct(private int $freshnessSeconds = 300)
    {
    }

    public function verify(
        string $verifyToken,
        string $timestamp,
        string $nonce,
        string $encryptedPayload,
        string $signature,
        DateTimeImmutable $now,
    ): void {
        if (
            trim($verifyToken) === ''
            || trim($nonce) === ''
            || trim($encryptedPayload) === ''
            || trim($signature) === ''
            || !preg_match('/^\d+$/', $timestamp)
        ) {
            $this->reject();
        }

        $sourceSeconds = (int) $timestamp;
        if (abs($now->getTimestamp() - $sourceSeconds) > $this->freshnessSeconds) {
            $this->reject();
        }

        $parts = [$verifyToken, $timestamp, $nonce, $encryptedPayload];
        sort($parts, SORT_STRING);
        $expected = sha1(implode('', $parts));
        if (!hash_equals($expected, $signature)) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid OpenPlatform callback authentication.', 401);
    }
}
