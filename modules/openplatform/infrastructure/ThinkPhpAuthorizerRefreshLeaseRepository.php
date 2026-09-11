<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\AuthorizerRefreshLeaseRepository;
use modules\openplatform\domain\AuthorizerTokenRefreshLease;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ThinkPhpAuthorizerRefreshLeaseRepository implements AuthorizerRefreshLeaseRepository
{
    public function tryAcquire(
        string $componentPlatformId,
        string $authorizerAppId,
        string $holderId,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?AuthorizerTokenRefreshLease {
        return Db::transaction(function () use ($componentPlatformId, $authorizerAppId, $holderId, $now, $leaseSeconds): ?AuthorizerTokenRefreshLease {
            $key = [
                'component_platform_id' => $componentPlatformId,
                'authorizer_app_id' => $authorizerAppId,
            ];
            $authorization = Db::table('authorizer_authorizations')->where($key)->lock(true)->find();
            if (!is_array($authorization) || (string) $authorization['status'] !== 'active') {
                return null;
            }

            $row = Db::table('authorizer_token_refresh_leases')->where($key)->lock(true)->find();
            $expiresAt = $now->modify('+' . max(1, $leaseSeconds) . ' seconds');
            if (!is_array($row)) {
                Db::table('authorizer_token_refresh_leases')->insert($key + [
                    'holder_id' => $holderId,
                    'lease_expires_at' => $this->sqlDate($expiresAt),
                    'version' => 1,
                ]);
                return new AuthorizerTokenRefreshLease($componentPlatformId, $authorizerAppId, $holderId, $expiresAt, 1);
            }

            $activeHolder = $row['holder_id'] ?? null;
            $leaseExpiry = empty($row['lease_expires_at']) ? null : $this->date((string) $row['lease_expires_at']);
            if (is_string($activeHolder) && $activeHolder !== '' && $leaseExpiry !== null && $leaseExpiry > $now) {
                return null;
            }

            $version = (int) $row['version'] + 1;
            Db::table('authorizer_token_refresh_leases')->where($key)->update([
                'holder_id' => $holderId,
                'lease_expires_at' => $this->sqlDate($expiresAt),
                'version' => $version,
            ]);
            return new AuthorizerTokenRefreshLease($componentPlatformId, $authorizerAppId, $holderId, $expiresAt, $version);
        });
    }

    public function release(string $componentPlatformId, string $authorizerAppId, string $holderId): void
    {
        Db::transaction(function () use ($componentPlatformId, $authorizerAppId, $holderId): void {
            $key = [
                'component_platform_id' => $componentPlatformId,
                'authorizer_app_id' => $authorizerAppId,
            ];
            $row = Db::table('authorizer_token_refresh_leases')->where($key)->lock(true)->find();
            if (
                !is_array($row)
                || !is_string($row['holder_id'] ?? null)
                || !hash_equals($holderId, (string) $row['holder_id'])
            ) {
                return;
            }
            Db::table('authorizer_token_refresh_leases')->where($key)->update([
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
