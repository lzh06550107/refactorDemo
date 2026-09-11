<?php

declare(strict_types=1);

namespace modules\quota\domain;

use InvalidArgumentException;

final readonly class QuotaAvailability
{
    private function __construct(
        private bool $unlimited,
        private ?int $available,
        private int $purchaseRemaining,
        private int $nonPurchaseRemaining,
        private ?int $parentPoolRemaining,
    ) {
    }

    public static function unlimited(): self
    {
        return new self(true, null, 0, 0, null);
    }

    public static function limited(int $available, int $purchaseRemaining, int $nonPurchaseRemaining, ?int $parentPoolRemaining): self
    {
        foreach ([$available, $purchaseRemaining, $nonPurchaseRemaining] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Quota availability values must not be negative.');
            }
        }
        if ($parentPoolRemaining !== null && $parentPoolRemaining < 0) {
            throw new InvalidArgumentException('Parent pool remaining must not be negative.');
        }
        if ($available !== $purchaseRemaining + $nonPurchaseRemaining) {
            throw new InvalidArgumentException('Available quota must equal purchase plus non-purchase remaining.');
        }
        return new self(false, $available, $purchaseRemaining, $nonPurchaseRemaining, $parentPoolRemaining);
    }

    public function isUnlimited(): bool { return $this->unlimited; }
    public function available(): ?int { return $this->available; }
    public function purchaseRemaining(): int { return $this->purchaseRemaining; }
    public function nonPurchaseRemaining(): int { return $this->nonPurchaseRemaining; }
    public function parentPoolRemaining(): ?int { return $this->parentPoolRemaining; }

    public function canConsume(int $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }
        return $this->unlimited || ($this->available !== null && $this->available >= $amount);
    }
}
