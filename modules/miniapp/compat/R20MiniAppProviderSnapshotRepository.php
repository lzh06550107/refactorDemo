<?php

declare(strict_types=1);

namespace modules\miniapp\compat;

use app\legacy\contract\LegacyDatabase;
use modules\miniapp\domain\MiniAppConnectionMode;

final readonly class R20MiniAppProviderSnapshotRepository
{
    private const ACCOUNT_TYPE_APP_NORMAL = 4;
    private const ACCOUNT_TYPE_APP_AUTH = 7;

    public function __construct(private LegacyDatabase $database)
    {
    }

    public function forUniacid(int $legacyUniacid): ?LegacyMiniAppProviderSnapshot
    {
        if ($legacyUniacid <= 0) {
            return null;
        }

        $account = $this->database->fetchOne('account', ['uniacid' => $legacyUniacid]);
        if ($account === null) {
            return null;
        }

        $legacyAcid = (int) ($account['acid'] ?? 0);
        $legacyType = (int) ($account['type'] ?? 0);
        $mode = match ($legacyType) {
            self::ACCOUNT_TYPE_APP_NORMAL => MiniAppConnectionMode::MANUAL,
            self::ACCOUNT_TYPE_APP_AUTH => MiniAppConnectionMode::COMPONENT,
            default => null,
        };
        if ($legacyAcid <= 0 || $mode === null) {
            return null;
        }

        $provider = $this->database->fetchOne('account_wxapp', ['acid' => $legacyAcid]);
        if ($provider === null) {
            return null;
        }

        $providerAppId = trim((string) ($provider['key'] ?? ''));
        if ($providerAppId === '') {
            return null;
        }

        return new LegacyMiniAppProviderSnapshot(
            $legacyUniacid,
            $legacyAcid,
            $providerAppId,
            $mode,
            trim((string) ($provider['secret'] ?? '')) !== '',
            trim((string) ($provider['auth_refresh_token'] ?? '')) !== '',
        );
    }
}
