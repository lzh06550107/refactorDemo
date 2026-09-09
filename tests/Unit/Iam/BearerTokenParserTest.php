<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\iam\security\BearerTokenParser;

$parser = new BearerTokenParser();

expectSame('abc123', $parser->parse('Bearer abc123'), 'Bearer token is extracted exactly');

foreach (['', 'Basic abc123', 'Bearer ', 'Bearer a b'] as $header) {
    try {
        $parser->parse($header);
        throw new RuntimeException('malformed Authorization header must fail');
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'malformed bearer error code');
        expectSame(401, $e->httpStatus(), 'malformed bearer http status');
    }
}
