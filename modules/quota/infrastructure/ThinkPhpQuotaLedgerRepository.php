<?php

declare(strict_types=1);

namespace modules\quota\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\quota\contract\QuotaLedgerRepository;
use modules\quota\domain\QuotaAvailability;
use modules\quota\domain\QuotaChargeAllocator;
use modules\quota\domain\QuotaGrant;
use modules\quota\domain\QuotaGrantSource;
use modules\quota\domain\QuotaLedgerEntry;
use modules\quota\domain\QuotaLedgerEntryType;
use modules\quota\domain\QuotaResource;
use DateTimeImmutable;
use RuntimeException;
use think\facade\Db;

final class ThinkPhpQuotaLedgerRepository implements QuotaLedgerRepository
{
    public function availability(string $tenantId, QuotaResource $resource, DateTimeImmutable $at): QuotaAvailability
    {
        $balance = Db::table('quota_balances')->where([
            'tenant_id' => $tenantId,
            'resource_key' => $resource->key(),
        ])->find();
        if ($balance === null) {
            return QuotaAvailability::limited(0, 0, 0, null);
        }
        $balance = (array) $balance;
        $purchaseRemaining = max((int) $balance['purchase_granted_amount'] - (int) $balance['purchase_consumed_amount'], 0);
        $totalRemaining = max((int) $balance['granted_amount'] - (int) $balance['consumed_amount'], 0);
        $nonPurchaseRemaining = max($totalRemaining - $purchaseRemaining, 0);
        $parentPoolId = $this->activeParentPoolId($tenantId, $resource, $at, false);
        $parentRemaining = null;
        if ($parentPoolId !== null) {
            $pool = Db::table('quota_parent_pools')->where('id', $parentPoolId)->find();
            if ($pool !== null) {
                $pool = (array) $pool;
                $parentRemaining = max((int) $pool['limit_amount'] - (int) $pool['consumed_amount'], 0);
                $nonPurchaseRemaining = min($nonPurchaseRemaining, $parentRemaining);
            }
        }
        return QuotaAvailability::limited(
            $purchaseRemaining + $nonPurchaseRemaining,
            $purchaseRemaining,
            $nonPurchaseRemaining,
            $parentRemaining,
        );
    }

    public function findByIdempotencyKey(string $tenantId, QuotaResource $resource, string $idempotencyKey): ?QuotaLedgerEntry
    {
        $row = Db::table('quota_ledger_entries')->where([
            'tenant_id' => $tenantId,
            'resource_key' => $resource->key(),
            'idempotency_key' => $idempotencyKey,
        ])->find();
        return $row === null ? null : $this->entry((array) $row);
    }

    public function grant(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        if (!$grant->isActiveAt($at)) {
            throw new AppException(ErrorCode::CONFLICT, 'Quota grant is not active at the requested time.', 409);
        }
        return Db::transaction(function () use ($grant, $idempotencyKey, $at): QuotaLedgerEntry {
            // One lockable balance row serializes all mutations for this tenant/resource.
            $balance = $this->lockedBalance($grant->tenantId(), $grant->resource(), true);
            $existing = $this->lockedIdempotent($grant->tenantId(), $grant->resource(), $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            $grantRow = Db::table('quota_grants')->where('id', $grant->id())->lock(true)->find();
            if ($grantRow !== null) {
                $alreadyApplied = Db::table('quota_ledger_entries')->where([
                    'grant_id' => $grant->id(),
                    'entry_type' => QuotaLedgerEntryType::GRANT->value,
                ])->lock(true)->find();
                if ($alreadyApplied !== null) {
                    throw new AppException(ErrorCode::CONFLICT, 'Quota grant has already been applied.', 409);
                }
            } else {
                Db::table('quota_grants')->insert([
                    'id' => $grant->id(),
                    'tenant_id' => $grant->tenantId(),
                    'resource_key' => $grant->resource()->key(),
                    'source' => $grant->source()->value,
                    'amount' => $grant->amount(),
                    'parent_pool_id' => $grant->parentPoolId(),
                    'starts_at' => $this->sqlDate($grant->startsAt()),
                    'ends_at' => $this->sqlDate($grant->endsAt()),
                ]);
            }
            $purchaseDelta = $grant->source() === QuotaGrantSource::PURCHASE ? $grant->amount() : 0;
            Db::table('quota_balances')->where([
                'tenant_id' => $grant->tenantId(),
                'resource_key' => $grant->resource()->key(),
            ])->update([
                'granted_amount' => (int) $balance['granted_amount'] + $grant->amount(),
                'purchase_granted_amount' => (int) $balance['purchase_granted_amount'] + $purchaseDelta,
                'version' => (int) $balance['version'] + 1,
            ]);
            return $this->insertEntry([
                'id' => $this->id(),
                'tenant_id' => $grant->tenantId(),
                'resource_key' => $grant->resource()->key(),
                'grant_id' => $grant->id(),
                'grant_source' => $grant->source()->value,
                'entry_type' => QuotaLedgerEntryType::GRANT->value,
                'amount' => $grant->amount(),
                'purchase_charge' => 0,
                'parent_pool_charge' => 0,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $this->sqlDate($at),
            ]);
        });
    }

    public function expire(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        return Db::transaction(function () use ($grant, $idempotencyKey, $at): QuotaLedgerEntry {
            $balance = $this->lockedBalance($grant->tenantId(), $grant->resource(), false);
            $existing = $this->lockedIdempotent($grant->tenantId(), $grant->resource(), $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            $grantRow = Db::table('quota_grants')->where('id', $grant->id())->lock(true)->find();
            if ($grantRow === null) {
                throw new AppException(ErrorCode::NOT_FOUND, 'Quota grant not found.', 404);
            }
            $grantRow = (array) $grantRow;
            if (!empty($grantRow['expired_at'])) {
                throw new AppException(ErrorCode::CONFLICT, 'Quota grant has already expired.', 409);
            }
            $purchaseDelta = $grant->source() === QuotaGrantSource::PURCHASE ? $grant->amount() : 0;
            Db::table('quota_balances')->where([
                'tenant_id' => $grant->tenantId(),
                'resource_key' => $grant->resource()->key(),
            ])->update([
                'granted_amount' => max((int) $balance['granted_amount'] - $grant->amount(), 0),
                'purchase_granted_amount' => max((int) $balance['purchase_granted_amount'] - $purchaseDelta, 0),
                'version' => (int) $balance['version'] + 1,
            ]);
            Db::table('quota_grants')->where('id', $grant->id())->update(['expired_at' => $this->sqlDate($at)]);
            return $this->insertEntry([
                'id' => $this->id(),
                'tenant_id' => $grant->tenantId(),
                'resource_key' => $grant->resource()->key(),
                'grant_id' => $grant->id(),
                'grant_source' => $grant->source()->value,
                'entry_type' => QuotaLedgerEntryType::EXPIRE->value,
                'amount' => $grant->amount(),
                'purchase_charge' => 0,
                'parent_pool_charge' => 0,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $this->sqlDate($at),
            ]);
        });
    }

    public function consume(string $tenantId, QuotaResource $resource, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        return Db::transaction(function () use ($tenantId, $resource, $amount, $idempotencyKey, $at): QuotaLedgerEntry {
            $balance = $this->lockedBalance($tenantId, $resource, false);
            $existing = $this->lockedIdempotent($tenantId, $resource, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            $available = max((int) $balance['granted_amount'] - (int) $balance['consumed_amount'], 0);
            if ($available < $amount) {
                throw new AppException(ErrorCode::FORBIDDEN, 'Account creation quota exhausted.', 403, ['available' => $available]);
            }
            $purchaseRemaining = max((int) $balance['purchase_granted_amount'] - (int) $balance['purchase_consumed_amount'], 0);
            $charge = (new QuotaChargeAllocator())->forConsume($amount, $purchaseRemaining);
            $purchaseCharge = $charge->purchase();
            $nonPurchaseCharge = $charge->nonPurchase();
            $parentPoolId = $this->activeParentPoolId($tenantId, $resource, $at, true);
            $parentPoolCharge = 0;
            if ($parentPoolId !== null && $nonPurchaseCharge > 0) {
                $pool = Db::table('quota_parent_pools')->where('id', $parentPoolId)->lock(true)->find();
                if ($pool === null) {
                    throw new AppException(ErrorCode::CONFLICT, 'Parent quota pool not found.', 409);
                }
                $pool = (array) $pool;
                $parentRemaining = max((int) $pool['limit_amount'] - (int) $pool['consumed_amount'], 0);
                if ($parentRemaining < $nonPurchaseCharge) {
                    throw new AppException(ErrorCode::FORBIDDEN, 'Parent account quota pool exhausted.', 403, ['available' => $parentRemaining]);
                }
                $parentPoolCharge = $nonPurchaseCharge;
                Db::table('quota_parent_pools')->where('id', $parentPoolId)->update([
                    'consumed_amount' => (int) $pool['consumed_amount'] + $parentPoolCharge,
                    'version' => (int) $pool['version'] + 1,
                ]);
            }
            Db::table('quota_balances')->where([
                'tenant_id' => $tenantId,
                'resource_key' => $resource->key(),
            ])->update([
                'consumed_amount' => (int) $balance['consumed_amount'] + $amount,
                'purchase_consumed_amount' => (int) $balance['purchase_consumed_amount'] + $purchaseCharge,
                'version' => (int) $balance['version'] + 1,
            ]);
            return $this->insertEntry([
                'id' => $this->id(),
                'tenant_id' => $tenantId,
                'resource_key' => $resource->key(),
                'entry_type' => QuotaLedgerEntryType::CONSUME->value,
                'amount' => $amount,
                'purchase_charge' => $purchaseCharge,
                'parent_pool_id' => $parentPoolCharge > 0 ? $parentPoolId : null,
                'parent_pool_charge' => $parentPoolCharge,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $this->sqlDate($at),
            ]);
        });
    }

    public function release(string $tenantId, QuotaResource $resource, string $consumeEntryId, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        return Db::transaction(function () use ($tenantId, $resource, $consumeEntryId, $amount, $idempotencyKey, $at): QuotaLedgerEntry {
            $balance = $this->lockedBalance($tenantId, $resource, false);
            $existing = $this->lockedIdempotent($tenantId, $resource, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            $consume = Db::table('quota_ledger_entries')->where([
                'id' => $consumeEntryId,
                'tenant_id' => $tenantId,
                'resource_key' => $resource->key(),
                'entry_type' => QuotaLedgerEntryType::CONSUME->value,
            ])->lock(true)->find();
            if ($consume === null) {
                throw new AppException(ErrorCode::NOT_FOUND, 'Quota consume entry not found.', 404);
            }
            $consume = (array) $consume;
            $releases = Db::table('quota_ledger_entries')->where([
                'tenant_id' => $tenantId,
                'resource_key' => $resource->key(),
                'entry_type' => QuotaLedgerEntryType::RELEASE->value,
                'consume_entry_id' => $consumeEntryId,
            ])->lock(true)->select();
            $releasedAmount = $releasedPurchase = $releasedParent = 0;
            foreach ($this->rows($releases) as $release) {
                $releasedAmount += (int) $release['amount'];
                $releasedPurchase += (int) $release['purchase_charge'];
                $releasedParent += (int) $release['parent_pool_charge'];
            }
            if ($amount > (int) $consume['amount'] - $releasedAmount) {
                throw new AppException(ErrorCode::CONFLICT, 'Release exceeds unreleased consume amount.', 409);
            }
            $releaseCharge = (new QuotaChargeAllocator())->forRelease(
                amount: $amount,
                originalAmount: (int) $consume['amount'],
                originalPurchaseCharge: (int) $consume['purchase_charge'],
                originalParentCharge: (int) $consume['parent_pool_charge'],
                releasedAmount: $releasedAmount,
                releasedPurchaseCharge: $releasedPurchase,
                releasedParentCharge: $releasedParent,
            );
            $releasePurchase = $releaseCharge->purchase();
            $releaseParent = $releaseCharge->parent();

            Db::table('quota_balances')->where([
                'tenant_id' => $tenantId,
                'resource_key' => $resource->key(),
            ])->update([
                'consumed_amount' => max((int) $balance['consumed_amount'] - $amount, 0),
                'purchase_consumed_amount' => max((int) $balance['purchase_consumed_amount'] - $releasePurchase, 0),
                'version' => (int) $balance['version'] + 1,
            ]);
            $parentPoolId = !empty($consume['parent_pool_id']) ? (string) $consume['parent_pool_id'] : null;
            if ($releaseParent > 0 && $parentPoolId !== null) {
                $pool = Db::table('quota_parent_pools')->where('id', $parentPoolId)->lock(true)->find();
                if ($pool === null) {
                    throw new AppException(ErrorCode::CONFLICT, 'Parent quota pool not found during release.', 409);
                }
                $pool = (array) $pool;
                Db::table('quota_parent_pools')->where('id', $parentPoolId)->update([
                    'consumed_amount' => max((int) $pool['consumed_amount'] - $releaseParent, 0),
                    'version' => (int) $pool['version'] + 1,
                ]);
            }
            return $this->insertEntry([
                'id' => $this->id(),
                'tenant_id' => $tenantId,
                'resource_key' => $resource->key(),
                'entry_type' => QuotaLedgerEntryType::RELEASE->value,
                'amount' => $amount,
                'purchase_charge' => $releasePurchase,
                'parent_pool_id' => $releaseParent > 0 ? $parentPoolId : null,
                'parent_pool_charge' => $releaseParent,
                'consume_entry_id' => $consumeEntryId,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $this->sqlDate($at),
            ]);
        });
    }

    private function lockedIdempotent(string $tenantId, QuotaResource $resource, string $key): ?QuotaLedgerEntry
    {
        $row = Db::table('quota_ledger_entries')->where([
            'tenant_id' => $tenantId,
            'resource_key' => $resource->key(),
            'idempotency_key' => $key,
        ])->lock(true)->find();
        return $row === null ? null : $this->entry((array) $row);
    }

    /** @return array<string,mixed> */
    private function lockedBalance(string $tenantId, QuotaResource $resource, bool $create): array
    {
        $where = ['tenant_id' => $tenantId, 'resource_key' => $resource->key()];
        if ($create) {
            // Upsert the unique projection row first so concurrent first-grant transactions serialize on one anchor.
            Db::execute(
                'INSERT INTO quota_balances (tenant_id, resource_key, granted_amount, consumed_amount, purchase_granted_amount, purchase_consumed_amount, version) VALUES (?, ?, 0, 0, 0, 0, 0) ON DUPLICATE KEY UPDATE version = version',
                [$tenantId, $resource->key()],
            );
        }
        $row = Db::table('quota_balances')->where($where)->lock(true)->find();
        if ($row === null) {
            throw new AppException(ErrorCode::FORBIDDEN, 'No quota has been granted for this resource.', 403);
        }
        return (array) $row;
    }

    private function activeParentPoolId(string $tenantId, QuotaResource $resource, DateTimeImmutable $at, bool $lock): ?string
    {
        $query = Db::table('quota_grants')
            ->where(['tenant_id' => $tenantId, 'resource_key' => $resource->key()])
            ->whereNotNull('parent_pool_id')
            ->whereNull('expired_at')
            ->whereRaw('(starts_at IS NULL OR starts_at <= ?)', [$this->sqlDate($at)])
            ->whereRaw('(ends_at IS NULL OR ends_at >= ?)', [$this->sqlDate($at)]);
        if ($lock) {
            $query->lock(true);
        }
        $rows = $this->rows($query->field('parent_pool_id')->select());
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['parent_pool_id'] ?? ''), $rows))));
        if (count($ids) > 1) {
            throw new RuntimeException('Multiple active parent quota pools for one tenant/resource are not supported.');
        }
        return $ids[0] ?? null;
    }

    /** @param array<string,mixed> $row */
    private function insertEntry(array $row): QuotaLedgerEntry
    {
        Db::table('quota_ledger_entries')->insert($row);
        return $this->entry($row);
    }

    /** @param array<string,mixed> $row */
    private function entry(array $row): QuotaLedgerEntry
    {
        $resource = new QuotaResource((string) $row['resource_key']);
        $at = new DateTimeImmutable((string) $row['occurred_at']);
        $type = QuotaLedgerEntryType::from((string) $row['entry_type']);
        return match ($type) {
            QuotaLedgerEntryType::GRANT => QuotaLedgerEntry::grant((string) $row['id'], (string) $row['tenant_id'], $resource, (int) $row['amount'], (string) $row['grant_id'], QuotaGrantSource::from((string) $row['grant_source']), (string) $row['idempotency_key'], $at),
            QuotaLedgerEntryType::EXPIRE => QuotaLedgerEntry::expire((string) $row['id'], (string) $row['tenant_id'], $resource, (int) $row['amount'], (string) $row['grant_id'], QuotaGrantSource::from((string) $row['grant_source']), (string) $row['idempotency_key'], $at),
            QuotaLedgerEntryType::CONSUME => QuotaLedgerEntry::consume((string) $row['id'], (string) $row['tenant_id'], $resource, (int) $row['amount'], (int) ($row['purchase_charge'] ?? 0), (int) ($row['parent_pool_charge'] ?? 0), (string) $row['idempotency_key'], $at),
            QuotaLedgerEntryType::RELEASE => QuotaLedgerEntry::release((string) $row['id'], (string) $row['tenant_id'], $resource, (int) $row['amount'], (int) ($row['purchase_charge'] ?? 0), (int) ($row['parent_pool_charge'] ?? 0), (string) $row['consume_entry_id'], (string) $row['idempotency_key'], $at),
        };
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $result): array
    {
        if (is_object($result) && method_exists($result, 'toArray')) {
            return array_values($result->toArray());
        }
        return array_values((array) $result);
    }

    private function sqlDate(?DateTimeImmutable $at): ?string
    {
        return $at?->format('Y-m-d H:i:s.u');
    }

    private function id(): string
    {
        return bin2hex(random_bytes(16));
    }
}
