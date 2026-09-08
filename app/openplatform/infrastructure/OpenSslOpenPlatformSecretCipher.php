<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\OpenPlatformSecretCipher;

final readonly class OpenSslOpenPlatformSecretCipher implements OpenPlatformSecretCipher
{
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @param array<string,string> $keys */
    public function __construct(private array $keys, private string $activeKeyVersion)
    {
        if (!isset($keys[$activeKeyVersion]) || strlen($keys[$activeKeyVersion]) !== 32) {
            $this->configurationFailure();
        }
        foreach ($keys as $key) {
            if (strlen($key) !== 32) {
                $this->configurationFailure();
            }
        }
    }

    public function protect(string $plaintext): array
    {
        if ($plaintext === '') {
            $this->configurationFailure();
        }
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->keys[$this->activeKeyVersion],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            $this->configurationFailure();
        }
        return [
            'ciphertext' => base64_encode($iv . $tag . $ciphertext),
            'keyVersion' => $this->activeKeyVersion,
        ];
    }

    public function reveal(string $ciphertext, string $keyVersion): string
    {
        $key = $this->keys[$keyVersion] ?? null;
        $raw = base64_decode($ciphertext, true);
        if (!is_string($key) || strlen($key) !== 32 || !is_string($raw) || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            $this->configurationFailure();
        }
        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $encrypted = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        $plain = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plain) || $plain === '') {
            $this->configurationFailure();
        }
        return $plain;
    }

    private function configurationFailure(): never
    {
        throw new AppException(ErrorCode::INTERNAL_ERROR, 'OpenPlatform protected secret configuration or authentication failed.', 500);
    }
}
