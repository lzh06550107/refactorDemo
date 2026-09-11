<?php

declare(strict_types=1);

namespace modules\miniapp\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\miniapp\contract\MiniAppDataDecryptor;

final class OpenSslMiniAppDataDecryptor implements MiniAppDataDecryptor
{
    public function decrypt(string $encryptedData, string $iv, string $sessionKey): string
    {
        $ciphertext = base64_decode($encryptedData, true);
        $ivBytes = base64_decode($iv, true);
        $keyBytes = base64_decode($sessionKey, true);
        if ($ciphertext === false || $ciphertext === '' || $ivBytes === false || $keyBytes === false
            || strlen($ivBytes) !== 16 || strlen($keyBytes) !== 16
        ) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-128-CBC', $keyBytes, OPENSSL_RAW_DATA, $ivBytes);
        if (!is_string($plaintext) || $plaintext === '') {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
        }

        return $plaintext;
    }
}
