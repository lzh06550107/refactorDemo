<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\security\WechatComponentSignatureVerifier;

$verifier = new WechatComponentSignatureVerifier();
$now = new DateTimeImmutable('2026-09-08T08:00:00Z', new DateTimeZone('UTC'));
$timestamp = (string) $now->getTimestamp();
$nonce = 'nonce-1';
$encrypted = 'encrypted-payload';
$token = 'verify-token';
$parts = [$token, $timestamp, $nonce, $encrypted];
sort($parts, SORT_STRING);
$signature = sha1(implode('', $parts));

$verifier->verify($token, $timestamp, $nonce, $encrypted, $signature, $now);

foreach ([
    ['signature' => str_repeat('0', 40), 'timestamp' => $timestamp],
    ['signature' => null, 'timestamp' => (string) ($now->getTimestamp() - 301)],
    ['signature' => null, 'timestamp' => (string) ($now->getTimestamp() + 301)],
] as $case) {
    $caseTimestamp = $case['timestamp'];
    $caseParts = [$token, $caseTimestamp, $nonce, $encrypted];
    sort($caseParts, SORT_STRING);
    $caseSignature = $case['signature'] ?? sha1(implode('', $caseParts));
    try {
        $verifier->verify($token, $caseTimestamp, $nonce, $encrypted, $caseSignature, $now);
        throw new RuntimeException('invalid component signature/freshness must be rejected');
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'signature/freshness rejection uses UNAUTHORIZED');
        expectSame(401, $e->httpStatus(), 'signature/freshness rejection maps to 401');
    }
}

foreach ([-300, 300] as $offset) {
    $boundaryTimestamp = (string) ($now->getTimestamp() + $offset);
    $boundary = [$token, $boundaryTimestamp, $nonce, $encrypted];
    sort($boundary, SORT_STRING);
    $verifier->verify($token, $boundaryTimestamp, $nonce, $encrypted, sha1(implode('', $boundary)), $now);
}
