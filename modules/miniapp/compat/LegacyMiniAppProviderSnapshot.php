<?php

declare(strict_types=1);

namespace modules\miniapp\compat;

use modules\miniapp\domain\MiniAppConnectionMode;
use InvalidArgumentException;

final readonly class LegacyMiniAppProviderSnapshot
{
    public function __construct(
        private int $legacyUniacid,
        private int $legacyAcid,
        private string $providerAppId,
        private MiniAppConnectionMode $connectionMode,
        private bool $hasManualSecret,
        private bool $hasAuthorizerRefreshToken,
    ) {
        if ($legacyUniacid <= 0 || $legacyAcid <= 0) {
            throw new InvalidArgumentException('Legacy MiniApp snapshot requires positive uniacid and acid.');
        }
        if (trim($providerAppId) === '') {
            throw new InvalidArgumentException('Legacy MiniApp snapshot requires provider appid.');
        }
    }

    public function legacyUniacid(): int { return $this->legacyUniacid; }
    public function legacyAcid(): int { return $this->legacyAcid; }
    public function providerAppId(): string { return $this->providerAppId; }
    public function connectionMode(): MiniAppConnectionMode { return $this->connectionMode; }
    public function hasManualSecret(): bool { return $this->hasManualSecret; }
    public function hasAuthorizerRefreshToken(): bool { return $this->hasAuthorizerRefreshToken; }

    /**
     * Safe diagnostics/migration metadata. Secret and refresh-token values are intentionally absent.
     */
    public function toSafeArray(): array
    {
        return [
            'legacy_uniacid' => $this->legacyUniacid,
            'legacy_acid' => $this->legacyAcid,
            'provider_app_id' => $this->providerAppId,
            'connection_mode' => $this->connectionMode->value,
            'has_manual_secret' => $this->hasManualSecret,
            'has_authorizer_refresh_token' => $this->hasAuthorizerRefreshToken,
        ];
    }
}
