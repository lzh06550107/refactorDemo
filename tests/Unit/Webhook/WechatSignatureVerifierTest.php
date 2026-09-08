<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\webhook\security\WechatSignatureVerifier;
use DateTimeImmutable;

$verifier = new WechatSignatureVerifier();
$now = new DateTimeImmutable('@1788840000');

$verifier->verify(
    'wechat-token',
    '34dd1fa1f97d34c4b781e9dbf2ba2186702a1135',
    '1788840000',
    'nonce-123',
    $now,
);

$signatureFor = static function (string $timestamp): string {
    $parts = ['wechat-token', $timestamp, 'nonce-123'];
    sort($parts, SORT_STRING);
    return sha1(implode('', $parts));
};

$expectUnauthorized = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), $message . ' error code');
        expectSame(401, $e->httpStatus(), $message . ' status');
        return;
    }
    throw new RuntimeException($message . ' no exception thrown');
};

$expectUnauthorized(
    fn () => $verifier->verify('wechat-token', $signatureFor('1788839699'), '1788839699', 'nonce-123', $now),
    'timestamp older than 300 seconds must fail',
);
$expectUnauthorized(
    fn () => $verifier->verify('wechat-token', $signatureFor('1788840301'), '1788840301', 'nonce-123', $now),
    'timestamp more than 300 seconds in the future must fail',
);
$expectUnauthorized(
    fn () => $verifier->verify('wechat-token', str_repeat('0', 40), '1788840000', 'nonce-123', $now),
    'invalid signature must fail',
);

$verifier->verify('wechat-token', $signatureFor('1788839700'), '1788839700', 'nonce-123', $now);
$verifier->verify('wechat-token', $signatureFor('1788840300'), '1788840300', 'nonce-123', $now);
