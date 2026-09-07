<?php

declare(strict_types=1);

namespace app\common\error;

final class ErrorEnvelope
{
    public static function success(string $requestId, mixed $data = null, string $message = 'ok'): array
    {
        return [
            'code' => 'OK',
            'message' => $message,
            'data' => $data,
            'request_id' => $requestId,
        ];
    }

    public static function error(string $requestId, string $code, string $message, mixed $data = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'request_id' => $requestId,
        ];
    }
}
