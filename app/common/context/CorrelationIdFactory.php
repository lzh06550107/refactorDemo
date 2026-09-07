<?php

declare(strict_types=1);

namespace app\common\context;

final class CorrelationIdFactory
{
    public function normalize(?string $candidate): string
    {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $candidate) === 1) {
            return $candidate;
        }

        return bin2hex(random_bytes(16));
    }
}
