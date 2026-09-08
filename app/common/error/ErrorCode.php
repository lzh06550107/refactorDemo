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
    case BAD_GATEWAY = 'BAD_GATEWAY';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
}
