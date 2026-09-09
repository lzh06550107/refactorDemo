<?php

declare(strict_types=1);

namespace app\iam\security;

use app\common\error\AppException;
use app\common\error\ErrorCode;

final readonly class BearerTokenParser
{
    public function parse(string $authorizationHeader): string
    {
        if (preg_match('/^Bearer ([^\s]+)$/i', trim($authorizationHeader), $matches) !== 1) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Administrator authorization header is invalid.', 401);
        }

        return $matches[1];
    }
}
