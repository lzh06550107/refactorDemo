<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\miniapp\application\MiniAppEncryptedDataService;
use modules\miniapp\application\MiniAppSessionService;
use modules\miniapp\contract\MiniAppDataDecryptor;
use modules\miniapp\contract\MiniAppProviderAccountRepository;
use modules\miniapp\contract\MiniAppSessionRepository;
use modules\miniapp\contract\SessionKeyCipher;
use modules\miniapp\domain\MiniAppConnectionMode;
use modules\miniapp\domain\MiniAppProviderAccount;
use modules\miniapp\domain\MiniAppSession;
use modules\miniapp\domain\ProtectedSessionKey;
use modules\miniapp\infrastructure\OpenSslMiniAppDataDecryptor;

$now = new DateTimeImmutable('2026-09-08T06:35:00+00:00');
$token = str_repeat('c', 64);
$hash = hash('sha256', $token);
$session = MiniAppSession::issue(
    'session-profile', 'tenant-1', 'account-1', 'member-1', 'identity-1', $hash,
    new ProtectedSessionKey('ciphertext', 'k1'), $now->modify('-30 seconds'),
);
$sessionRepo = new class($session) implements MiniAppSessionRepository {
    public function __construct(private MiniAppSession $session) {}
    public function insert(MiniAppSession $session): void { $this->session = $session; }
    public function findByTokenHash(string $tokenHash): ?MiniAppSession
    {
        return hash_equals($this->session->tokenHash(), $tokenHash) ? $this->session : null;
    }
};
$sessionService = new MiniAppSessionService($sessionRepo);
$provider = new MiniAppProviderAccount(
    'tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::MANUAL, 'vault://miniapp/account-1', null,
);
$providerRepo = new class($provider) implements MiniAppProviderAccountRepository {
    public function __construct(private MiniAppProviderAccount $provider) {}
    public function findForTenantAccount(string $tenantId, string $accountId): ?MiniAppProviderAccount
    {
        return $tenantId === $this->provider->tenantId() && $accountId === $this->provider->accountId() ? $this->provider : null;
    }
};
$cipher = new class implements SessionKeyCipher {
    public function protect(string $sessionKey): ProtectedSessionKey { return new ProtectedSessionKey('ciphertext', 'k1'); }
    public function reveal(ProtectedSessionKey $protected): string { return 'raw-session-key'; }
};
$decryptor = new class implements MiniAppDataDecryptor {
    public string $plaintext = '{"nickName":"Alice","watermark":{"appid":"wx-app-1","timestamp":1}}';
    public function decrypt(string $encryptedData, string $iv, string $sessionKey): string
    {
        expectSame('raw-session-key', $sessionKey, 'session key is revealed only for in-memory decrypt boundary');
        return $this->plaintext;
    }
};
$service = new MiniAppEncryptedDataService($sessionService, $providerRepo, $cipher, $decryptor);
$rawData = '{"nickName":"Alice"}';
$signature = sha1($rawData . 'raw-session-key');
$profile = $service->decryptProfile('tenant-1', 'account-1', $token, $rawData, $signature, 'cipher-input', 'iv-input', $now);
expectSame('Alice', $profile->data()['nickName'] ?? null, 'decrypted profile returns typed profile data');
expectTrue(!array_key_exists('watermark', $profile->data()), 'watermark is validation metadata and not returned as profile data');

try {
    $service->decryptProfile('tenant-1', 'account-1', $token, $rawData, str_repeat('0', 40), 'cipher-input', 'iv-input', $now);
    throw new RuntimeException('legacy rawData signature mismatch must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'signature mismatch uses UNAUTHORIZED');
    expectSame(401, $e->httpStatus(), 'signature mismatch uses HTTP 401');
    expectTrue(!str_contains($e->getMessage(), 'raw-session-key'), 'signature error never exposes session key');
}

$decryptor->plaintext = '{"nickName":"Mallory","watermark":{"appid":"other-app","timestamp":1}}';
try {
    $service->decryptProfile('tenant-1', 'account-1', $token, $rawData, $signature, 'cipher-input', 'iv-input', $now);
    throw new RuntimeException('watermark appid mismatch must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'watermark mismatch uses UNAUTHORIZED');
    expectSame(401, $e->httpStatus(), 'watermark mismatch uses HTTP 401');
}

$decryptor->plaintext = 'not-json';
try {
    $service->decryptProfile('tenant-1', 'account-1', $token, $rawData, $signature, 'cipher-input', 'iv-input', $now);
    throw new RuntimeException('malformed decrypted payload must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'malformed plaintext uses UNAUTHORIZED');
}

$keyBytes = random_bytes(16);
$ivBytes = random_bytes(16);
$plain = json_encode(['nickName' => 'Crypto', 'watermark' => ['appid' => 'wx-app-1', 'timestamp' => 1]], JSON_THROW_ON_ERROR);
$encryptedBytes = openssl_encrypt($plain, 'AES-128-CBC', $keyBytes, OPENSSL_RAW_DATA, $ivBytes);
expectTrue(is_string($encryptedBytes), 'test fixture AES encryption succeeds');
$openssl = new OpenSslMiniAppDataDecryptor();
$decrypted = $openssl->decrypt(base64_encode($encryptedBytes), base64_encode($ivBytes), base64_encode($keyBytes));
expectSame($plain, $decrypted, 'OpenSSL decryptor implements WeChat AES-128-CBC payload semantics');

try {
    $openssl->decrypt('not-base64', base64_encode($ivBytes), base64_encode($keyBytes));
    throw new RuntimeException('malformed encrypted payload must fail closed');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'malformed ciphertext uses UNAUTHORIZED');
    expectTrue(!str_contains($e->getMessage(), base64_encode($keyBytes)), 'decrypt error never exposes session key');
}
