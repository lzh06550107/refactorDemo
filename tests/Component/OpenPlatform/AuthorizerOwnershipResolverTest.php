<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use modules\openplatform\application\AuthorizerOwnershipResolver;
use modules\openplatform\contract\AuthorizerOwnershipRepository;
use modules\openplatform\domain\AuthorizerAccountOwnership;
use modules\openplatform\domain\AuthorizerOwnershipResolution;

$now = new DateTimeImmutable('2026-09-09T04:45:00Z');
$ownership = new AuthorizerAccountOwnership(
    'platform-1',
    'wx-authorizer-1',
    'tenant-A',
    'account-A',
    AccountType::WECHAT_MINI_PROGRAM,
    $now->modify('-1 day'),
    $now->modify('-1 hour'),
);

$repository = new class($ownership) implements AuthorizerOwnershipRepository {
    public function __construct(private AuthorizerAccountOwnership $ownership) {}
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccountOwnership
    {
        return $componentPlatformId === $this->ownership->componentPlatformId()
            && $authorizerAppId === $this->ownership->authorizerAppId()
            ? $this->ownership
            : null;
    }
};
$resolver = new AuthorizerOwnershipResolver($repository);

expectSame(
    AuthorizerOwnershipResolution::UNOWNED,
    $resolver->resolve('platform-1', 'wx-unowned', 'tenant-A', 'account-A'),
    'unknown canonical authorizer is unowned',
);
expectSame(
    AuthorizerOwnershipResolution::SAME_OWNER,
    $resolver->resolve('platform-1', 'wx-authorizer-1', 'tenant-A', 'account-A'),
    'exact Tenant and Account resolve as same owner',
);
expectSame(
    AuthorizerOwnershipResolution::OTHER_OWNER,
    $resolver->resolve('platform-1', 'wx-authorizer-1', 'tenant-A', 'account-B'),
    'same Tenant but different Account resolves as other owner',
);
expectSame(
    AuthorizerOwnershipResolution::OTHER_OWNER,
    $resolver->resolve('platform-1', 'wx-authorizer-1', 'tenant-B', 'account-A'),
    'different Tenant resolves as other owner',
);
expectSame('tenant-A', $ownership->tenantId(), 'ownership retains canonical Tenant');
expectSame('account-A', $ownership->accountId(), 'ownership retains canonical Account');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $ownership->accountType(), 'ownership freezes AccountType');
expectTrue(!method_exists($ownership, 'enabled'), 'ownership has no connection enabled state and survives disabled provider bindings');

$root = dirname(__DIR__, 3);
$expectedFiles = [
    $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerOwnershipRepository.php',
    $root . '/modules/openplatform/contract/AuthorizerConnectionStore.php',
    $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerConnectionStore.php',
];
foreach ($expectedFiles as $file) {
    expectTrue(is_file($file), basename($file) . ' must exist for canonical ownership/connection projection');
}
