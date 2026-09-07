<?php

declare(strict_types=1);

namespace app\common\error;

use RuntimeException;

final class AppException extends RuntimeException
{
    public function __construct(
        private readonly ErrorCode $errorCode,
        string $message,
        private readonly int $httpStatus = 400,
        private readonly mixed $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): ErrorCode { return $this->errorCode; }
    public function httpStatus(): int { return $this->httpStatus; }
    public function data(): mixed { return $this->data; }
}
