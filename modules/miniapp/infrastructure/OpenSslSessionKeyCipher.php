<?php

declare(strict_types=1);

namespace modules\miniapp\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\miniapp\contract\SessionKeyCipher;
use modules\miniapp\domain\ProtectedSessionKey;
use InvalidArgumentException;

final readonly class OpenSslSessionKeyCipher implements SessionKeyCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @var array<string,string> */
    private array $keys;

    public function __construct(array $keys, private string $keyVersion)
    {
        if (trim($keyVersion) === '' || !isset($keys[$keyVersion])) {
            throw new InvalidArgumentException('Active MiniApp session-key encryption key version is not configured.');
        }
        foreach ($keys as $version => $key) {
            if (!is_string($version) || trim($version) === '' || !is_string($key) || strlen($key) !== 32) {
                throw new InvalidArgumentException('MiniApp session-key encryption keys must be versioned 32-byte values.');
            }
        }
        $this->keys = $keys;
    }

    public function protect(string $sessionKey): ProtectedSessionKey
    {
        if ($sessionKey === '') {
            throw new InvalidArgumentException('MiniApp session key must not be empty.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $sessionKey,
            self::CIPHER,
            $this->keys[$this->keyVersion],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new AppException(ErrorCode::INTERNAL_ERROR, 'MiniApp session credential protection failed.', 500);
        }

        return new ProtectedSessionKey(base64_encode($iv . $tag . $ciphertext), $this->keyVersion);
    }

    public function reveal(ProtectedSessionKey $protected): string
    {
        $version = $protected->keyVersion();
        $key = $this->keys[$version] ?? null;
        if (!is_string($key)) {
            throw new AppException(ErrorCode::INTERNAL_ERROR, 'MiniApp session credential cannot be opened.', 500);
        }

        $payload = base64_decode($protected->ciphertext(), true);
        if (!is_string($payload) || strlen($payload) <= self::IV_BYTES + self::TAG_BYTES) {
            throw new AppException(ErrorCode::INTERNAL_ERROR, 'MiniApp session credential cannot be opened.', 500);
        }

        $iv = substr($payload, 0, self::IV_BYTES);
        $tag = substr($payload, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::IV_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new AppException(ErrorCode::INTERNAL_ERROR, 'MiniApp session credential cannot be opened.', 500);
        }

        return $plaintext;
    }
}
