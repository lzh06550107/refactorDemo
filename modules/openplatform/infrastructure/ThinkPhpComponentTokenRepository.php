<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\ComponentTokenRepository;
use modules\openplatform\contract\OpenPlatformSecretCipher;
use modules\openplatform\domain\ComponentAccessToken;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class ThinkPhpComponentTokenRepository implements ComponentTokenRepository
{
    public function __construct(private OpenPlatformSecretCipher $cipher)
    {
    }

    public function current(string $componentPlatformId): ?ComponentAccessToken
    {
        $row = Db::table('component_access_tokens')->where('component_platform_id', $componentPlatformId)->find();
        if (!is_array($row)) {
            return null;
        }
        $token = $this->cipher->reveal((string) $row['token_ciphertext'], (string) $row['token_key_version']);
        $platform = Db::table('component_platforms')->where('id', $componentPlatformId)->find();
        if (!is_array($platform)) {
            return null;
        }
        return new ComponentAccessToken(
            $componentPlatformId,
            (string) $platform['component_app_id'],
            $token,
            $this->date((string) $row['issued_at']),
            $this->date((string) $row['expires_at']),
            (int) $row['version'],
        );
    }

    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool
    {
        $protected = $this->cipher->protect($token->accessToken());
        return Db::transaction(function () use ($token, $holderId, $expectedVersion, $now, $protected): bool {
            $platformId = $token->componentPlatformId();
            $lease = Db::table('component_token_refresh_leases')->where('component_platform_id', $platformId)->lock(true)->find();
            if (
                !is_array($lease)
                || !is_string($lease['holder_id'] ?? null)
                || !hash_equals($holderId, (string) $lease['holder_id'])
                || empty($lease['lease_expires_at'])
                || $this->date((string) $lease['lease_expires_at']) <= $now
            ) {
                return false;
            }

            $current = Db::table('component_access_tokens')->where('component_platform_id', $platformId)->lock(true)->find();
            $actualVersion = is_array($current) ? (int) $current['version'] : 0;
            if ($actualVersion !== $expectedVersion) {
                return false;
            }
            $row = [
                'token_ciphertext' => $protected['ciphertext'],
                'token_key_version' => $protected['keyVersion'],
                'issued_at' => $this->sqlDate($token->issuedAt()),
                'expires_at' => $this->sqlDate($token->expiresAt()),
                'version' => $token->version(),
            ];
            if (is_array($current)) {
                Db::table('component_access_tokens')->where('component_platform_id', $platformId)->update($row);
            } else {
                Db::table('component_access_tokens')->insert(['component_platform_id' => $platformId] + $row);
            }
            return true;
        });
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
