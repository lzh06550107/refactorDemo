<?php

declare(strict_types=1);

use app\legacy\contract\LegacyDatabase;
use app\member\compat\R20MemberIdentitySnapshotRepository;

$db = new class implements LegacyDatabase {
    public function fetchOne(string $table, array $where): ?array
    {
        if ($table !== 'mc_members') {
            return null;
        }
        expectSame(['uniacid' => 9, 'uid' => 33], $where, 'member snapshot query must stay scoped to legacy uniacid + uid');
        return [
            'uid' => 33,
            'uniacid' => 9,
            'groupid' => 7,
            'nickname' => 'Legacy Member',
            'avatar' => 'https://example.test/avatar.jpg',
            'status' => 1,
        ];
    }

    public function fetchAll(string $table, array $where): array
    {
        if ($table !== 'mc_mapping_fans') {
            return [];
        }
        expectSame(['uniacid' => 9, 'uid' => 33], $where, 'fan snapshot query must stay scoped to legacy uniacid + uid');
        return [[
            'fanid' => 101,
            'uniacid' => 9,
            'acid' => 22,
            'uid' => 33,
            'openid' => 'openid-provider-22',
            'unionid' => 'union-cross-account',
            'follow' => 1,
            'followtime' => 1700000000,
            'unfollowtime' => 0,
            'user_from' => 1,
        ]];
    }
};

$repository = new R20MemberIdentitySnapshotRepository($db);
$member = $repository->member(9, 33);
expectTrue($member !== null, 'legacy member snapshot must be returned');
expectSame(33, $member->legacyUid(), 'member uid is preserved');
expectSame(9, $member->legacyUniacid(), 'member uniacid is preserved independently');
expectSame(7, $member->legacyGroupId(), 'legacy group id is preserved');
expectSame('Legacy Member', $member->nickname(), 'nickname is preserved');
expectSame(1, $member->status(), 'legacy status is preserved');

$fans = $repository->fans(9, 33);
expectSame(1, count($fans), 'one fan mapping must be returned');
$fan = $fans[0];
expectSame(101, $fan->fanId(), 'fanid is preserved');
expectSame(9, $fan->legacyUniacid(), 'fan uniacid is preserved');
expectSame(22, $fan->legacyAcid(), 'provider acid is preserved independently from uniacid');
expectSame(33, $fan->legacyUid(), 'fan uid is preserved');
expectSame('openid-provider-22', $fan->openid(), 'openid is preserved without global normalization');
expectSame('union-cross-account', $fan->unionId(), 'unionid is preserved separately from openid');
expectSame(1, $fan->follow(), 'follow state is preserved');
expectSame(1700000000, $fan->followTime(), 'followtime is preserved');
expectSame(0, $fan->unfollowTime(), 'unfollowtime is preserved');
expectSame(1, $fan->userFrom(), 'user_from is preserved');
