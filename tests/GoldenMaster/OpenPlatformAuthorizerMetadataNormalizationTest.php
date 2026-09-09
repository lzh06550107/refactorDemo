<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\openplatform\application\AuthorizerMetadataNormalizer;
use app\openplatform\domain\AuthorizerInfoResponse;

$fixtureRoot = __DIR__ . '/fixtures/openplatform';
$officialPath = $fixtureRoot . '/official-account-authorizer-info.json';
$miniPath = $fixtureRoot . '/mini-program-authorizer-info.json';

expectTrue(is_file($officialPath), 'Official Account metadata fixture exists');
expectTrue(is_file($miniPath), 'Mini Program metadata fixture exists');

$forbiddenFixtureFields = [
    'component_access_token',
    'authorizer_access_token',
    'authorizer_refresh_token',
    'authorization_code',
    'pre_auth_code',
    'credential_ref',
    'session_key',
    'aes_key',
];
foreach ([$officialPath, $miniPath] as $path) {
    $raw = (string) file_get_contents($path);
    foreach ($forbiddenFixtureFields as $forbidden) {
        expectTrue(!str_contains($raw, $forbidden), basename($path) . ' contains no credential field ' . $forbidden);
    }
}

/** @return array<string,mixed> */
$decode = static function (string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('metadata fixture must decode to an object');
    }
    return $decoded;
};

/** @param array<string,mixed> $payload */
$toInfo = static function (array $payload): AuthorizerInfoResponse {
    $info = $payload['authorizer_info'] ?? null;
    if (!is_array($info)) {
        throw new RuntimeException('fixture must contain authorizer_info');
    }
    return AuthorizerInfoResponse::fromAuthorizerInfo($info);
};

$normalizer = new AuthorizerMetadataNormalizer();
$official = $normalizer->normalize('platform-fixture', 'wx-official-fixture', $toInfo($decode($officialPath)));
$mini = $normalizer->normalize('platform-fixture', 'wx-miniapp-fixture', $toInfo($decode($miniPath)));

expectSame(AccountType::OFFICIAL_ACCOUNT, $official->accountType(), 'Official Account fixture remains classified by missing MiniProgramInfo');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $mini->accountType(), 'Mini Program fixture remains classified by MiniProgramInfo presence');
expectSame('金亚包装公众号', $official->nickName(), 'Official Account fixture nickname is stable');
expectSame('金亚包装小程序', $mini->nickName(), 'Mini Program fixture nickname is stable');
expectSame('gh_official_fixture', $official->originalId(), 'Official Account fixture original id is stable');
expectSame('gh_miniapp_fixture', $mini->originalId(), 'Mini Program fixture original id is stable');
expectTrue($official->miniProgramInfo() === null, 'Official Account fixture contains no MiniProgramInfo');
expectTrue($mini->miniProgramInfo() !== null, 'Mini Program fixture preserves MiniProgramInfo');
