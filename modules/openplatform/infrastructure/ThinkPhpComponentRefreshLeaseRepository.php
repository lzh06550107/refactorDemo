<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\ComponentRefreshLeaseRepository;
use modules\openplatform\domain\ComponentTokenRefreshLease;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ThinkPhpComponentRefreshLeaseRepository implements ComponentRefreshLeaseRepository
{
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease
    {
        return Db::transaction(function () use ($componentPlatformId, $holderId, $now, $leaseSeconds): ?ComponentTokenRefreshLease {
            $platform = Db::table('component_platforms')->where('id', $componentPlatformId)->lock(true)->find();
            if (!is_array($platform)) {
                return null;
            }
            $row = Db::table('component_token_refresh_leases')->where('component_platform_id', $componentPlatformId)->lock(true)->find();
            $expiresAt = $now->modify('+' . max(1, $leaseSeconds) . ' seconds');
            if (!is_array($row)) {
                Db::table('component_token_refresh_leases')->insert([
                    'component_platform_id' => $componentPlatformId,
                    'holder_id' => $holderId,
                    'lease_expires_at' => $this->sqlDate($expiresAt),
                    'version' => 1,
                ]);
                return new ComponentTokenRefreshLease($componentPlatformId, $holderId, $expiresAt, 1);
            }

            $activeHolder = $row['holder_id'] ?? null;
            $leaseExpiry = empty($row['lease_expires_at']) ? null : $this->date((string) $row['lease_expires_at']);
            if (is_string($activeHolder) && $activeHolder !== '' && $leaseExpiry !== null && $leaseExpiry > $now) {
                return null;
            }

            $version = (int) $row['version'] + 1;
            Db::table('component_token_refresh_leases')->where('component_platform_id', $componentPlatformId)->update([
                'holder_id' => $holderId,
                'lease_expires_at' => $this->sqlDate($expiresAt),
                'version' => $version,
            ]);
            return new ComponentTokenRefreshLease($componentPlatformId, $holderId, $expiresAt, $version);
        });
    }

    public function release(string $componentPlatformId, string $holderId): void
    {
        Db::transaction(function () use ($componentPlatformId, $holderId): void {
            $row = Db::table('component_token_refresh_leases')->where('component_platform_id', $componentPlatformId)->lock(true)->find();
            if (!is_array($row) || !is_string($row['holder_id'] ?? null) || !hash_equals($holderId, (string) $row['holder_id'])) {
                return;
            }
            Db::table('component_token_refresh_leases')->where('component_platform_id', $componentPlatformId)->update([
                'holder_id' => null,
                'lease_expires_at' => null,
                'version' => (int) $row['version'] + 1,
            ]);
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
