<?php

declare(strict_types=1);

namespace modules\integration\legacy\support;

use InvalidArgumentException;

final class LegacySerializedValueDecoder
{
    public function array(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Legacy serialized value must be a string or array.');
        }

        $decoded = $this->decode($value);
        if (!is_array($decoded) || $this->containsObject($decoded)) {
            throw new InvalidArgumentException('Legacy serialized value must decode to an object-free array.');
        }
        return $decoded;
    }

    private function decode(string $value): mixed
    {
        $decoded = @unserialize($value, ['allowed_classes' => false]);
        if ($decoded !== false || $value === 'b:0;') {
            return $decoded;
        }

        $repaired = preg_replace_callback(
            '!s:(\d+):"(.*?)";!s',
            static fn (array $match): string => 's:' . strlen($match[2]) . ':"' . $match[2] . '";',
            $value,
        );
        if (!is_string($repaired) || $repaired === $value) {
            throw new InvalidArgumentException('Legacy serialized value is invalid.');
        }

        $decoded = @unserialize($repaired, ['allowed_classes' => false]);
        if ($decoded === false && $repaired !== 'b:0;') {
            throw new InvalidArgumentException('Legacy serialized value is invalid after safe repair.');
        }
        return $decoded;
    }

    private function containsObject(array $value): bool
    {
        foreach ($value as $item) {
            if (is_object($item)) {
                return true;
            }
            if (is_array($item) && $this->containsObject($item)) {
                return true;
            }
        }
        return false;
    }
}
