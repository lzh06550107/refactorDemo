<?php

declare(strict_types=1);

namespace app\openplatform\security;

use app\common\error\AppException;
use app\common\error\ErrorCode;

final class WechatComponentMessageDecryptor
{
    private const PKCS7_BLOCK_SIZE = 32;

    public function decrypt(string $encryptedPayload, string $encodingAesKey, string $expectedComponentAppId): string
    {
        $key = $this->decodeKey($encodingAesKey);
        $ciphertext = base64_decode($encryptedPayload, true);
        if (!is_string($ciphertext) || $ciphertext === '' || strlen($ciphertext) % 16 !== 0) {
            $this->malformed();
        }

        $plain = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            substr($key, 0, 16),
        );
        if (!is_string($plain) || $plain === '') {
            $this->malformed();
        }

        $plain = $this->stripPadding($plain);
        if (strlen($plain) < 20) {
            $this->malformed();
        }

        $length = unpack('Nlength', substr($plain, 16, 4));
        $messageLength = is_array($length) ? (int) ($length['length'] ?? -1) : -1;
        if ($messageLength < 1 || 20 + $messageLength >= strlen($plain) + 1) {
            $this->malformed();
        }

        $xml = substr($plain, 20, $messageLength);
        $receiver = substr($plain, 20 + $messageLength);
        if ($xml === '' || $receiver === '') {
            $this->malformed();
        }
        if (!hash_equals($expectedComponentAppId, $receiver)) {
            throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform callback receiver mismatch.', 403);
        }

        return $xml;
    }

    private function decodeKey(string $encodingAesKey): string
    {
        $candidate = trim($encodingAesKey);
        if ($candidate === '') {
            $this->malformed();
        }
        $candidate .= str_repeat('=', (4 - strlen($candidate) % 4) % 4);
        $key = base64_decode($candidate, true);
        if (!is_string($key) || strlen($key) !== 32) {
            $this->malformed();
        }
        return $key;
    }

    private function stripPadding(string $plain): string
    {
        $padding = ord($plain[strlen($plain) - 1]);
        if ($padding < 1 || $padding > self::PKCS7_BLOCK_SIZE || strlen($plain) < $padding) {
            $this->malformed();
        }
        $suffix = substr($plain, -$padding);
        if (!hash_equals(str_repeat(chr($padding), $padding), $suffix)) {
            $this->malformed();
        }
        return substr($plain, 0, -$padding);
    }

    private function malformed(): never
    {
        throw new AppException(ErrorCode::INVALID_ARGUMENT, 'Invalid encrypted OpenPlatform callback payload.', 400);
    }
}
