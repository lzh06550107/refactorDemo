<?php

declare(strict_types=1);

namespace modules\miniapp\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\miniapp\contract\MiniAppDataDecryptor;
use modules\miniapp\contract\MiniAppProviderAccountRepository;
use modules\miniapp\contract\SessionKeyCipher;
use modules\miniapp\domain\MiniAppEncryptedProfile;
use DateTimeImmutable;
use Throwable;

final readonly class MiniAppEncryptedDataService
{
    public function __construct(
        private MiniAppSessionService $sessions,
        private MiniAppProviderAccountRepository $providers,
        private SessionKeyCipher $cipher,
        private MiniAppDataDecryptor $decryptor,
    ) {
    }

    public function decryptProfile(
        string $tenantId,
        string $accountId,
        string $sessionToken,
        string $rawData,
        string $signature,
        string $encryptedData,
        string $iv,
        DateTimeImmutable $now,
    ): MiniAppEncryptedProfile {
        foreach ([$tenantId, $accountId, $sessionToken, $rawData, $signature, $encryptedData, $iv] as $value) {
            if (trim($value) === '') {
                throw new AppException(ErrorCode::INVALID_ARGUMENT, 'MiniApp encrypted-profile request is incomplete.', 400);
            }
        }

        $session = $this->sessions->authenticate($tenantId, $accountId, $sessionToken, $now);
        $provider = $this->providers->findForTenantAccount($tenantId, $accountId);
        if ($provider === null) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
        }

        try {
            $sessionKey = $this->cipher->reveal($session->protectedSessionKey());
            $expected = sha1($rawData . $sessionKey);
            if (!hash_equals($expected, $signature)) {
                throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
            }

            $plaintext = $this->decryptor->decrypt($encryptedData, $iv, $sessionKey);
            $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
            }
            $watermark = $decoded['watermark'] ?? null;
            if (!is_array($watermark) || ($watermark['appid'] ?? null) !== $provider->providerAppId()) {
                throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
            }
            unset($decoded['watermark']);

            return new MiniAppEncryptedProfile($decoded);
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp encrypted data is invalid.', 401);
        }
    }
}
