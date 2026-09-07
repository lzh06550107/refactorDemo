<?php

declare(strict_types=1);

namespace app\quota\domain;

use InvalidArgumentException;

final readonly class QuotaBalance
{
    private function __construct(
        private int $granted,
        private int $consumed,
        private int $purchaseGranted,
        private int $purchaseConsumed,
    ) {
        if (min($granted, $consumed, $purchaseGranted, $purchaseConsumed) < 0) {
            throw new InvalidArgumentException('Quota balance cannot be negative.');
        }
    }

    /** @param list<QuotaLedgerEntry> $entries */
    public static function fromEntries(array $entries): self
    {
        $granted = $consumed = $purchaseGranted = $purchaseConsumed = 0;
        foreach ($entries as $entry) {
            if (!$entry instanceof QuotaLedgerEntry) {
                throw new InvalidArgumentException('Quota balance accepts only ledger entries.');
            }
            switch ($entry->type()) {
                case QuotaLedgerEntryType::GRANT:
                    $granted += $entry->amount();
                    if ($entry->grantSource() === QuotaGrantSource::PURCHASE) {
                        $purchaseGranted += $entry->amount();
                    }
                    break;
                case QuotaLedgerEntryType::CONSUME:
                    $consumed += $entry->amount();
                    $purchaseConsumed += $entry->purchaseCharge();
                    break;
                case QuotaLedgerEntryType::RELEASE:
                    $consumed -= $entry->amount();
                    $purchaseConsumed -= $entry->purchaseCharge();
                    break;
                case QuotaLedgerEntryType::EXPIRE:
                    $granted -= $entry->amount();
                    if ($entry->grantSource() === QuotaGrantSource::PURCHASE) {
                        $purchaseGranted -= $entry->amount();
                    }
                    break;
            }
        }
        return new self($granted, $consumed, $purchaseGranted, $purchaseConsumed);
    }

    public function granted(): int { return $this->granted; }
    public function consumed(): int { return $this->consumed; }
    public function available(): int { return max($this->granted - $this->consumed, 0); }
    public function purchaseGranted(): int { return $this->purchaseGranted; }
    public function purchaseConsumed(): int { return $this->purchaseConsumed; }
    public function purchaseRemaining(): int { return max($this->purchaseGranted - $this->purchaseConsumed, 0); }
}
