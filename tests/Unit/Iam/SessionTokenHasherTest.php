<?php

declare(strict_types=1);

use app\common\security\SecretValue;
use app\iam\security\SessionTokenHasher;

$hasher = new SessionTokenHasher(new SecretValue('pepper-123'));
$first = $hasher->hash('opaque-session-token');
$second = $hasher->hash('opaque-session-token');
$other = $hasher->hash('another-token');

expectSame(64, strlen($first), 'sha256 hex length');
expectSame($first, $second, 'hash is deterministic for lookup');
expectTrue($first !== $other, 'different token hashes differ');
expectTrue(!str_contains($first, 'opaque-session-token'), 'raw token not stored in hash');
expectThrows(fn () => $hasher->hash(''), InvalidArgumentException::class, 'empty session token rejected');
