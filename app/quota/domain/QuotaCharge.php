<?php

declare(strict_types=1);

namespace app\quota\domain;

use InvalidArgumentException;

final readonly class QuotaCharge
{
    public function __construct(
        private int $purchase,
        private int $nonPurchase,
        private int $parent,
    ) {
        if (min($purchase, $nonPurchase, $parent) < 0 || $parent > $nonPurchase) {
            throw new InvalidArgumentException('Quota charge allocation is invalid.');
        }
    }

    public function purchase(): int { return $this->purchase; }
    public function nonPurchase(): int { return $this->nonPurchase; }
    public function parent(): int { return $this->parent; }
    public function total(): int { return $this->purchase + $this->nonPurchase; }
}
