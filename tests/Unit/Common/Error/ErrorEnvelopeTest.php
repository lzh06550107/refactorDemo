<?php

declare(strict_types=1);

use app\common\error\ErrorEnvelope;

$success = ErrorEnvelope::success('req-1', ['application' => 'api']);
expectSame([
    'code' => 'OK',
    'message' => 'ok',
    'data' => ['application' => 'api'],
    'request_id' => 'req-1',
], $success, 'success envelope contract');

$error = ErrorEnvelope::error('req-2', 'NOT_FOUND', 'not found');
expectSame('NOT_FOUND', $error['code'], 'error code');
expectSame('req-2', $error['request_id'], 'error request id');
expectTrue(!array_key_exists('trace', $error), 'production error envelope must not leak stack trace');
expectTrue(!array_key_exists('exception', $error), 'production error envelope must not leak exception details');
