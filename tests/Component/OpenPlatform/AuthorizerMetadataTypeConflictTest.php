<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;

$now = new DateTimeImmutable('2026-09-09T10:15:00Z');
$provisioning = AuthorizerProvisioning::pending(
    'type-conflict-provisioning',
    'type-conflict-intent',
    'tenant-1',
    'platform-1',
    'wx-authorizer-1',
    $now,
)->withMetadata(AccountType::OFFICIAL_ACCOUNT, 2, $now->modify('+1 second'));

$conflict = $provisioning->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 2, $now->modify('+2 seconds'));
expectSame(AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT, $conflict->status(), 'trusted metadata versus historical ownership type mismatch becomes type conflict');
expectSame(AccountType::OFFICIAL_ACCOUNT, $conflict->accountType(), 'conflict preserves already frozen trusted metadata type and never rewrites it silently');
expectSame(null, $conflict->quotaConsumeEntryId(), 'type conflict remains before any second quota operation');
expectSame(null, $conflict->accountId(), 'type conflict does not create or move an Account');

$root = dirname(__DIR__, 3);
$workerSource = (string) file_get_contents($root . '/app/openplatform/application/AuthorizerProvisioningWorker.php');
$eventSource = (string) file_get_contents($root . '/app/openplatform/application/AuthorizationEventService.php');

expectTrue(str_contains($workerSource, 'ownership->accountType()'), 'worker compares trusted metadata type with historical canonical ownership type');
expectTrue(str_contains($workerSource, 'METADATA_TYPE_CONFLICT'), 'worker has explicit trusted type conflict stop path');
$typeCheckPos = strpos($workerSource, 'ownership->accountType()');
$reconnectPos = strpos($workerSource, '->reconnect(');
$quotaPos = strpos($workerSource, '->ensureConsumed(');
expectTrue($typeCheckPos !== false && $reconnectPos !== false && $typeCheckPos < $reconnectPos, 'type conflict is checked before historical-owner reconnect');
expectTrue($typeCheckPos !== false && $quotaPos !== false && $typeCheckPos < $quotaPos, 'type conflict is checked before any new quota consume');

expectTrue(str_contains($eventSource, 'AuthorizerMetadataSyncService'), 'event lifecycle may refresh trusted metadata after accepted authorization transition');
expectTrue(str_contains($eventSource, 'AuthorizerOwnershipRepository'), 'event lifecycle consults historical ownership without creating it');
expectTrue(str_contains($eventSource, 'accountType()'), 'event-only reconnect checks metadata type compatibility with historical ownership');
expectTrue(!str_contains($eventSource, 'AuthorizerProvisioningQuotaService'), 'event lifecycle has no quota dependency');
expectTrue(!str_contains($eventSource, 'AuthorizerAccountFinalizer'), 'event lifecycle cannot create Accounts');
