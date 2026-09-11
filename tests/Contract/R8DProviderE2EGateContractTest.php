<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runner = $root . '/tests/ProviderE2E/run.php';
$guide = $root . '/docs/verification/v1-real-wechat-provider-e2e.md';

expectTrue(is_file($runner), 'Real WeChat Provider E2E runner must exist.');
expectTrue(is_file($guide), 'Real WeChat Provider E2E verification guide must exist.');

$source = (string) file_get_contents($runner);
foreach ([
    'WEPLATFORM_PROVIDER_E2E',
    'WEPLATFORM_PROVIDER_E2E_BASE_URL',
    'WEPLATFORM_PROVIDER_E2E_ADMIN_BEARER_TOKEN',
    'WEPLATFORM_PROVIDER_E2E_TENANT_ID',
    'WEPLATFORM_PROVIDER_E2E_COMPONENT_PLATFORM_ID',
    "'start'",
    "'verify'",
    'auto_provision_account',
    'authorization_url',
    'openplatform:provisioning-worker',
    '/api/v1/openplatform/provisionings/',
    "'provisioned'",
    "'reconnected'",
    'authorizer_account_ownerships',
    'quota_ledger_entries',
    'official_account_provider_accounts',
    'miniapp_provider_accounts',
] as $needle) {
    expectTrue(
        str_contains($source, $needle),
        'Provider E2E runner must contain gate: ' . $needle,
    );
}

expectTrue(
    preg_match("/getenv\\('WEPLATFORM_PROVIDER_E2E'\\).*===.*'1'/s", $source) === 1,
    'Provider E2E runner must require explicit WEPLATFORM_PROVIDER_E2E=1 opt-in.',
);

foreach ([
    'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON',
    'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64',
    'APP_SECRET',
    'ENCODING_AES_KEY',
] as $forbiddenCredentialInput) {
    expectTrue(
        !str_contains($source, $forbiddenCredentialInput),
        'Provider E2E runner must not accept provider credential material directly: ' . $forbiddenCredentialInput,
    );
}

foreach (['file_put_contents', 'var_dump(', 'print_r('] as $forbiddenOutput) {
    expectTrue(
        !str_contains($source, $forbiddenOutput),
        'Provider E2E runner must not persist or dump sensitive provider responses: ' . $forbiddenOutput,
    );
}

$guideSource = (string) file_get_contents($guide);
foreach ([
    'git rev-parse HEAD',
    'AppSecret',
    'EncodingAESKey',
    '不要',
    'provisioned',
    'reconnected',
] as $needle) {
    expectTrue(
        str_contains($guideSource, $needle),
        'Provider E2E guide must document: ' . $needle,
    );
}
