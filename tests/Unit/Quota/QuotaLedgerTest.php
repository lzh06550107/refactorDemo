<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\quota\domain\QuotaBalance;
use app\quota\domain\QuotaChargeAllocator;
use app\quota\domain\QuotaGrantSource;
use app\quota\domain\QuotaLedgerEntry;
use app\quota\domain\QuotaResource;

$at = new DateTimeImmutable('2026-09-07T12:00:00+00:00');
$resource = QuotaResource::accountCreate(AccountType::OFFICIAL_ACCOUNT);
$entries = [
    QuotaLedgerEntry::grant('e-plan', 'tenant-1', $resource, 5, 'g-plan', QuotaGrantSource::PLAN, 'grant-plan', $at),
    QuotaLedgerEntry::grant('e-buy', 'tenant-1', $resource, 3, 'g-buy', QuotaGrantSource::PURCHASE, 'grant-buy', $at),
    QuotaLedgerEntry::consume('e-consume', 'tenant-1', $resource, 4, 3, 1, 'consume-1', $at),
];
$balance = QuotaBalance::fromEntries($entries);
expectSame(8, $balance->granted(), 'ledger grants must sum plan and purchase quota');
expectSame(4, $balance->consumed(), 'consume entry must reduce quota availability');
expectSame(4, $balance->available(), 'remaining total quota must be reconstructed from ledger');
expectSame(0, $balance->purchaseRemaining(), 'purchased quota is consumed first');

$releaseCharge = (new QuotaChargeAllocator())->forRelease(
    amount: 2,
    originalAmount: 4,
    originalPurchaseCharge: 3,
    originalParentCharge: 1,
    releasedAmount: 0,
    releasedPurchaseCharge: 0,
    releasedParentCharge: 0,
);
expectSame(1, $releaseCharge->purchase(), 'release reverses non-purchase first and purchase second');
expectSame(1, $releaseCharge->nonPurchase(), 'first released unit must restore non-purchase quota');
expectSame(1, $releaseCharge->parent(), 'restored non-purchase unit must release its parent-pool charge');

$entries[] = QuotaLedgerEntry::release('e-release', 'tenant-1', $resource, 2, 1, 1, 'e-consume', 'release-1', $at);
$afterRelease = QuotaBalance::fromEntries($entries);
expectSame(2, $afterRelease->consumed(), 'partial release must lower consumed total');
expectSame(1, $afterRelease->purchaseRemaining(), 'partial release restores purchase quota only after non-purchase is restored');
