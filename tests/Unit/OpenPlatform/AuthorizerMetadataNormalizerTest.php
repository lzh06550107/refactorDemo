<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\openplatform\application\AuthorizerMetadataNormalizer;
use app\openplatform\domain\AuthorizerInfoResponse;

$normalizer = new AuthorizerMetadataNormalizer();

$official = new AuthorizerInfoResponse(
    '金亚包装公众号',
    'https://example.test/official-head.png',
    'gh_official_001',
    '南昌金亚包装有限公司',
    'jinya-pack',
    2,
    0,
    ['open_pay' => 1, 'open_card' => 0, 'open_store' => 1],
    'https://example.test/official-qrcode.png',
    null,
);
$officialMetadata = $normalizer->normalize('platform-1', 'wx-official-1', $official);
expectSame(AccountType::OFFICIAL_ACCOUNT, $officialMetadata->accountType(), 'absence of MiniProgramInfo classifies Official Account');
expectSame('金亚包装公众号', $officialMetadata->nickName(), 'trusted nickname is preserved');
expectSame('gh_official_001', $officialMetadata->originalId(), 'provider user_name becomes safe original id');
expectSame(2, $officialMetadata->serviceType(), 'service type is preserved only as metadata');
expectSame(0, $officialMetadata->verifyType(), 'verify type is preserved only as metadata');

$mini = new AuthorizerInfoResponse(
    '金亚包装小程序',
    'https://example.test/miniapp-head.png',
    'gh_miniapp_001',
    '南昌金亚包装有限公司',
    'jinya-mini',
    0,
    0,
    ['open_pay' => 1],
    'https://example.test/miniapp-qrcode.png',
    [
        'network' => [
            'UploadDomain' => ['https://upload.example.test'],
            'RequestDomain' => ['https://api.example.test'],
        ],
        'visit_status' => 0,
        'categories' => [['second' => '办公', 'first' => '工具']],
    ],
);
$miniMetadata = $normalizer->normalize('platform-1', 'wx-mini-1', $mini);
expectSame(AccountType::WECHAT_MINI_PROGRAM, $miniMetadata->accountType(), 'presence of MiniProgramInfo classifies Mini Program');
expectTrue($miniMetadata->miniProgramInfo() !== null, 'Mini Program metadata retains whitelisted MiniProgramInfo');

$miniReordered = new AuthorizerInfoResponse(
    '金亚包装小程序',
    'https://example.test/miniapp-head.png',
    'gh_miniapp_001',
    '南昌金亚包装有限公司',
    'jinya-mini',
    0,
    0,
    ['open_pay' => 1],
    'https://example.test/miniapp-qrcode.png',
    [
        'categories' => [['first' => '工具', 'second' => '办公']],
        'visit_status' => 0,
        'network' => [
            'RequestDomain' => ['https://api.example.test'],
            'UploadDomain' => ['https://upload.example.test'],
        ],
    ],
);
$miniMetadataReordered = $normalizer->normalize('platform-1', 'wx-mini-1', $miniReordered);
expectSame($miniMetadata->normalizedMetadataJson(), $miniMetadataReordered->normalizedMetadataJson(), 'recursive associative key order is canonicalized');
expectSame($miniMetadata->metadataHash(), $miniMetadataReordered->metadataHash(), 'same semantic metadata yields the same SHA-256 hash');
expectSame(64, strlen($miniMetadata->metadataHash()), 'metadata hash is lowercase SHA-256 hex');
expectTrue(ctype_xdigit($miniMetadata->metadataHash()), 'metadata hash contains only hexadecimal characters');

$normalized = $miniMetadata->normalizedMetadataJson();
foreach (['component_access_token', 'authorizer_access_token', 'authorizer_refresh_token', 'authorization_code', 'pre_auth_code', 'credential_ref'] as $forbidden) {
    expectTrue(!str_contains($normalized, $forbidden), 'normalized metadata never contains credential field ' . $forbidden);
}
