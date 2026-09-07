<?php

declare(strict_types=1);

namespace app\common\error;

enum ErrorCode: string
{
    case INVALID_ARGUMENT = 'INVALID_ARGUMENT';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case CONFLICT = 'CONFLICT';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
}
