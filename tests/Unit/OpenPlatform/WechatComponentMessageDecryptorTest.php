<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\security\WechatComponentMessageDecryptor;

$decryptor = new WechatComponentMessageDecryptor();
$rawKey = random_bytes(32);
$encodingAesKey = rtrim(base64_encode($rawKey), '=');
$componentAppId = 'wx-component-1';
$xml = '<xml><AppId><![CDATA[wx-component-1]]></AppId><InfoType><![CDATA[component_verify_ticket]]></InfoType><ComponentVerifyTicket><![CDATA[ticket-value]]></ComponentVerifyTicket></xml>';

$encryptFrame = static function (string $payloadXml, string $receiver, string $key): string {
    $frame = random_bytes(16) . pack('N', strlen($payloadXml)) . $payloadXml . $receiver;
    $pad = 32 - (strlen($frame) % 32);
    if ($pad === 0) {
        $pad = 32;
    }
    $frame .= str_repeat(chr($pad), $pad);
    $ciphertext = openssl_encrypt(
        $frame,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        substr($key, 0, 16),
    );
    if (!is_string($ciphertext)) {
        throw new RuntimeException('test fixture encryption failed');
    }
    return base64_encode($ciphertext);
};

$encrypted = $encryptFrame($xml, $componentAppId, $rawKey);
expectSame($xml, $decryptor->decrypt($encrypted, $encodingAesKey, $componentAppId), 'valid WeChat AES frame decrypts exactly');

try {
    $decryptor->decrypt($encryptFrame($xml, 'wx-other-platform', $rawKey), $encodingAesKey, $componentAppId);
    throw new RuntimeException('framed receiver mismatch must be rejected');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'framed receiver mismatch maps to FORBIDDEN');
    expectSame(403, $e->httpStatus(), 'framed receiver mismatch maps to 403');
}

$validCipher = base64_decode($encrypted, true);
expectTrue(is_string($validCipher), 'encrypted fixture is valid base64');
$badPaddingPlain = openssl_decrypt(
    $validCipher,
    'aes-256-cbc',
    $rawKey,
    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    substr($rawKey, 0, 16),
);
expectTrue(is_string($badPaddingPlain) && $badPaddingPlain !== '', 'test fixture can be decrypted for deterministic corruption');
$badPaddingPlain[strlen($badPaddingPlain) - 1] = chr(0);
$badPaddingCipher = openssl_encrypt(
    $badPaddingPlain,
    'aes-256-cbc',
    $rawKey,
    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    substr($rawKey, 0, 16),
);
expectTrue(is_string($badPaddingCipher), 'deterministic malformed padding fixture encrypts');

foreach ([
    ['payload' => 'not-base64***', 'key' => $encodingAesKey],
    ['payload' => base64_encode($badPaddingCipher), 'key' => $encodingAesKey],
    ['payload' => $encrypted, 'key' => rtrim(base64_encode(random_bytes(31)), '=')],
] as $bad) {
    try {
        $decryptor->decrypt($bad['payload'], $bad['key'], $componentAppId);
        throw new RuntimeException('malformed encrypted component callback must be rejected');
    } catch (AppException $e) {
        expectSame(ErrorCode::INVALID_ARGUMENT, $e->errorCode(), 'malformed encrypted payload maps to INVALID_ARGUMENT');
        expectSame(400, $e->httpStatus(), 'malformed encrypted payload maps to 400');
    }
}
