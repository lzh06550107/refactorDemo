<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;

$expectedStatuses = [
    'PENDING_METADATA',
    'METADATA_READY',
    'QUOTA_CONSUMED',
    'PROVISIONED',
    'RECONNECTED',
    'QUOTA_BLOCKED',
    'BINDING_CONFLICT',
    'METADATA_FAILED',
    'METADATA_TYPE_CONFLICT',
    'PROVISION_FAILED',
    'AUTHORIZATION_INACTIVE',
];
expectSame($expectedStatuses, array_map(static fn (AuthorizerProvisioningStatus $status): string => $status->name, AuthorizerProvisioningStatus::cases()), 'provisioning business statuses are exactly the approved set');
expectTrue(!defined(AuthorizerProvisioningStatus::class . '::CANCELLED'), 'provisioning has no CANCELLED business state');

$now = new DateTimeImmutable('2026-09-09T05:00:00Z');
$pending = AuthorizerProvisioning::pending(
    'provisioning-1',
    'intent-1',
    'tenant-1',
    'platform-1',
    'wx-authorizer-1',
    $now,
);
expectSame(AuthorizerProvisioningStatus::PENDING_METADATA, $pending->status(), 'new provisioning starts pending metadata');
expectSame(null, $pending->accountType(), 'pending metadata has no guessed Account type');
expectSame(1, $pending->version(), 'new provisioning starts at version 1');

$ready = $pending->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 3, $now->modify('+1 second'));
expectSame(AuthorizerProvisioningStatus::METADATA_READY, $ready->status(), 'trusted metadata moves provisioning to metadata ready');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $ready->accountType(), 'trusted metadata freezes Account type');
expectSame(3, $ready->metadataVersion(), 'provisioning records trusted metadata version');
expectSame(2, $ready->version(), 'metadata transition advances aggregate version');

$sameType = $ready->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 4, $now->modify('+2 seconds'));
expectSame(AuthorizerProvisioningStatus::METADATA_READY, $sameType->status(), 'same trusted Account type remains metadata ready');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $sameType->accountType(), 'same-type refresh preserves frozen Account type');
expectSame(4, $sameType->metadataVersion(), 'same-type refresh can advance observed metadata version');

$typeConflict = $ready->withMetadata(AccountType::OFFICIAL_ACCOUNT, 5, $now->modify('+2 seconds'));
expectSame(AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT, $typeConflict->status(), 'different later trusted type becomes metadata type conflict');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $typeConflict->accountType(), 'metadata type conflict never replaces frozen Account type');
expectSame(5, $typeConflict->metadataVersion(), 'type conflict still records the observed metadata version');
expectSame('metadata_type_conflict', $typeConflict->lastErrorCode(), 'type conflict records sanitized business error code');

$quotaConsumed = $ready->withQuotaConsumed(
    'account_create:wechat_mini_program',
    'quota-entry-1',
    $now->modify('+3 seconds'),
);
expectSame(AuthorizerProvisioningStatus::QUOTA_CONSUMED, $quotaConsumed->status(), 'metadata ready can advance to quota consumed');
expectSame('account_create:wechat_mini_program', $quotaConsumed->quotaResourceKey(), 'frozen Account type selects persisted quota resource');
expectSame('quota-entry-1', $quotaConsumed->quotaConsumeEntryId(), 'quota consume entry is persisted for crash recovery');

$provisioned = $quotaConsumed->provisioned('account-new-1', $now->modify('+4 seconds'));
expectSame(AuthorizerProvisioningStatus::PROVISIONED, $provisioned->status(), 'quota consumed can finalize provisioned');
expectSame('account-new-1', $provisioned->accountId(), 'provisioned aggregate records created Account');
expectTrue($provisioned->completedAt() !== null, 'provisioned is terminal with completion time');

$reconnected = $ready->reconnected('account-existing-1', $now->modify('+3 seconds'));
expectSame(AuthorizerProvisioningStatus::RECONNECTED, $reconnected->status(), 'same owner path reconnects without quota');
expectSame('account-existing-1', $reconnected->accountId(), 'reconnect records existing Account');
expectSame(null, $reconnected->quotaConsumeEntryId(), 'reconnect never consumes Account-creation quota');

$quotaBlocked = $ready->quotaBlocked('quota_insufficient', $now->modify('+3 seconds'));
expectSame(AuthorizerProvisioningStatus::QUOTA_BLOCKED, $quotaBlocked->status(), 'quota failure is a terminal business outcome');
expectSame('quota_insufficient', $quotaBlocked->lastErrorCode(), 'quota blocked stores sanitized error code');

$bindingConflict = $ready->bindingConflict('authorizer_owned_elsewhere', $now->modify('+3 seconds'));
expectSame(AuthorizerProvisioningStatus::BINDING_CONFLICT, $bindingConflict->status(), 'other owner path becomes binding conflict');
expectSame(null, $bindingConflict->quotaConsumeEntryId(), 'binding conflict happens before quota consumption');

$metadataFailed = $pending->metadataFailed('provider_timeout', $now->modify('+1 second'));
expectSame(AuthorizerProvisioningStatus::METADATA_FAILED, $metadataFailed->status(), 'metadata provider failure is recorded without guessing type');
expectSame(null, $metadataFailed->accountType(), 'metadata failure leaves Account type unset');

$provisionFailed = $quotaConsumed->provisionFailed('db_deadlock', $now->modify('+4 seconds'));
expectSame(AuthorizerProvisioningStatus::PROVISION_FAILED, $provisionFailed->status(), 'finalization failure is recorded after quota consumed');
expectSame('quota-entry-1', $provisionFailed->quotaConsumeEntryId(), 'provision failure retains quota consume fact for retry/reconciliation');

$inactive = $pending->authorizationInactive($now->modify('+1 second'));
expectSame(AuthorizerProvisioningStatus::AUTHORIZATION_INACTIVE, $inactive->status(), 'inactive authorization stops provisioning before side effects');

$invalidTransitions = [
    static fn () => $pending->provisioned('illegal-account', $now->modify('+1 second')),
    static fn () => $pending->withQuotaConsumed('account_create:wechat_mini_program', 'illegal-quota', $now->modify('+1 second')),
    static fn () => $ready->provisioned('illegal-account', $now->modify('+3 seconds')),
    static fn () => $provisioned->metadataFailed('late-failure', $now->modify('+5 seconds')),
];
foreach ($invalidTransitions as $transition) {
    try {
        $transition();
        throw new RuntimeException('invalid provisioning transition must fail');
    } catch (LogicException) {
        // Expected: aggregate rejects invalid business jumps.
    }
}

$root = dirname(__DIR__, 3);
foreach ([
    $root . '/app/openplatform/contract/AuthorizerProvisioningRepository.php',
    $root . '/app/openplatform/infrastructure/ThinkPhpAuthorizerProvisioningRepository.php',
] as $file) {
    expectTrue(is_file($file), basename($file) . ' must exist for durable provisioning persistence');
}
