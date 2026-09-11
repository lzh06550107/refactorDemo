<?php

declare(strict_types=1);

use modules\integration\legacy\contract\LegacyDatabase;
use modules\miniapp\compat\R20MiniAppProviderSnapshotRepository;
use modules\miniapp\domain\MiniAppConnectionMode;

$db = new class implements LegacyDatabase {
    public function fetchOne(string $table, array $where): ?array
    {
        if ($table === 'account') {
            $uniacid = (int) ($where['uniacid'] ?? 0);
            if ($uniacid === 40) {
                expectSame(['uniacid' => 40], $where, 'manual MiniApp account lookup stays scoped to legacy uniacid');
                return ['acid' => 401, 'uniacid' => 40, 'type' => 4];
            }
            if ($uniacid === 70) {
                expectSame(['uniacid' => 70], $where, 'authorized MiniApp account lookup stays scoped to legacy uniacid');
                return ['acid' => 701, 'uniacid' => 70, 'type' => 7];
            }
            return null;
        }

        if ($table === 'account_wxapp') {
            $acid = (int) ($where['acid'] ?? 0);
            if ($acid === 401) {
                expectSame(['acid' => 401], $where, 'manual MiniApp provider lookup stays scoped to legacy acid');
                return [
                    'acid' => 401,
                    'uniacid' => 40,
                    'key' => 'wx-manual-appid',
                    'secret' => 'manual-secret-must-not-leak',
                    'auth_refresh_token' => '',
                ];
            }
            if ($acid === 701) {
                expectSame(['acid' => 701], $where, 'authorized MiniApp provider lookup stays scoped to legacy acid');
                return [
                    'acid' => 701,
                    'uniacid' => 70,
                    'key' => 'wx-authorized-appid',
                    'secret' => '',
                    'auth_refresh_token' => 'authorizer-refresh-token-must-not-leak',
                ];
            }
        }

        return null;
    }

    public function fetchAll(string $table, array $where): array
    {
        return [];
    }
};

$repository = new R20MiniAppProviderSnapshotRepository($db);

$manual = $repository->forUniacid(40);
expectTrue($manual !== null, 'manual R20 MiniApp snapshot must exist');
expectSame(40, $manual->legacyUniacid(), 'manual uniacid preserved');
expectSame(401, $manual->legacyAcid(), 'manual acid preserved separately');
expectSame('wx-manual-appid', $manual->providerAppId(), 'manual MiniApp appid preserved');
expectSame(MiniAppConnectionMode::MANUAL, $manual->connectionMode(), 'R20 type 4 maps to manual MiniApp');
expectTrue($manual->hasManualSecret(), 'manual secret presence is preserved without exposing the value');
expectTrue(!$manual->hasAuthorizerRefreshToken(), 'manual MiniApp has no authorizer refresh token');

$authorized = $repository->forUniacid(70);
expectTrue($authorized !== null, 'authorized R20 MiniApp snapshot must exist');
expectSame(70, $authorized->legacyUniacid(), 'authorized uniacid preserved');
expectSame(701, $authorized->legacyAcid(), 'authorized acid preserved separately');
expectSame('wx-authorized-appid', $authorized->providerAppId(), 'authorized MiniApp appid preserved');
expectSame(MiniAppConnectionMode::COMPONENT, $authorized->connectionMode(), 'R20 type 7 maps to component-authorized MiniApp');
expectTrue(!$authorized->hasManualSecret(), 'authorized MiniApp does not require local manual secret');
expectTrue($authorized->hasAuthorizerRefreshToken(), 'authorized refresh-token presence is preserved without exposing token');

$safe = $authorized->toSafeArray();
expectTrue(!array_key_exists('secret', $safe), 'legacy MiniApp snapshot must not expose secret');
expectTrue(!array_key_exists('auth_refresh_token', $safe), 'legacy MiniApp snapshot must not expose refresh token');
expectTrue(!str_contains(json_encode($safe, JSON_THROW_ON_ERROR), 'authorizer-refresh-token-must-not-leak'), 'safe snapshot serialization cannot leak refresh token');

expectSame(null, $repository->forUniacid(999), 'unknown legacy MiniApp returns null');
