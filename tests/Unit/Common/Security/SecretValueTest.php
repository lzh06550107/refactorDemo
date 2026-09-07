<?php

declare(strict_types=1);

use app\common\security\SecretValue;

$secret = new SecretValue('super-secret');
expectSame('[REDACTED]', (string) $secret, 'string rendering must redact');
expectSame(['value' => '[REDACTED]'], $secret->__debugInfo(), 'debug rendering must redact');
expectSame('super-secret', $secret->reveal(), 'explicit reveal must return raw value');
expectThrows(static fn () => new SecretValue(''), InvalidArgumentException::class, 'empty secret must be rejected');
