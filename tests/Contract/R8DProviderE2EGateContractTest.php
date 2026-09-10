<?php

declare(strict_types=1);

function providerE2EGateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function providerE2EGateContractTest(string $root): void
{
    $runner = $root . '/tests/ProviderE2E/run.php';
    $guide = $root . '/docs/verification/v1-real-wechat-provider-e2e.md';

    providerE2EGateAssert(is_file($runner), 'Real WeChat Provider E2E runner must exist.');
    providerE2EGateAssert(is_file($guide), 'Real WeChat Provider E2E verification guide must exist.');

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
        providerE2EGateAssert(
            str_contains($source, $needle),
            'Provider E2E runner must contain gate: ' . $needle,
        );
    }

    providerE2EGateAssert(
        preg_match("/getenv\\('WEPLATFORM_PROVIDER_E2E'\\).*===.*'1'/s", $source) === 1,
        'Provider E2E runner must require explicit WEPLATFORM_PROVIDER_E2E=1 opt-in.',
    );

    foreach ([
        'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON',
        'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64',
        'APP_SECRET',
        'ENCODING_AES_KEY',
    ] as $forbiddenCredentialInput) {
        providerE2EGateAssert(
            !str_contains($source, $forbiddenCredentialInput),
            'Provider E2E runner must not accept provider credential material directly: ' . $forbiddenCredentialInput,
        );
    }

    foreach (['file_put_contents', 'var_dump(', 'print_r('] as $forbiddenOutput) {
        providerE2EGateAssert(
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
        providerE2EGateAssert(
            str_contains($guideSource, $needle),
            'Provider E2E guide must document: ' . $needle,
        );
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        providerE2EGateContractTest(dirname(__DIR__, 2));
        fwrite(STDOUT, "[PASS] R8DProviderE2EGateContractTest\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, '[FAIL] R8DProviderE2EGateContractTest: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
