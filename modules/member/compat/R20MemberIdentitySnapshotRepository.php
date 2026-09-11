<?php

declare(strict_types=1);

namespace modules\member\compat;

use app\legacy\contract\LegacyDatabase;

final class R20MemberIdentitySnapshotRepository
{
    public function __construct(private readonly LegacyDatabase $database)
    {
    }

    public function member(int $legacyUniacid, int $legacyUid): ?LegacyMemberSnapshot
    {
        $row = $this->database->fetchOne('mc_members', [
            'uniacid' => $legacyUniacid,
            'uid' => $legacyUid,
        ]);
        if ($row === null) {
            return null;
        }

        return new LegacyMemberSnapshot(
            (int) ($row['uid'] ?? 0),
            (int) ($row['uniacid'] ?? 0),
            (int) ($row['groupid'] ?? 0),
            (string) ($row['nickname'] ?? ''),
            (string) ($row['avatar'] ?? ''),
            (int) ($row['status'] ?? 0),
        );
    }

    /** @return list<LegacyFanIdentitySnapshot> */
    public function fans(int $legacyUniacid, int $legacyUid): array
    {
        $rows = $this->database->fetchAll('mc_mapping_fans', [
            'uniacid' => $legacyUniacid,
            'uid' => $legacyUid,
        ]);

        return array_map(
            static fn (array $row): LegacyFanIdentitySnapshot => new LegacyFanIdentitySnapshot(
                (int) ($row['fanid'] ?? 0),
                (int) ($row['uniacid'] ?? 0),
                (int) ($row['acid'] ?? 0),
                (int) ($row['uid'] ?? 0),
                (string) ($row['openid'] ?? ''),
                (string) ($row['unionid'] ?? ''),
                (int) ($row['follow'] ?? 0),
                (int) ($row['followtime'] ?? 0),
                (int) ($row['unfollowtime'] ?? 0),
                (int) ($row['user_from'] ?? 0),
            ),
            $rows,
        );
    }
}
