<?php

declare(strict_types=1);

namespace app\quota\domain;

use InvalidArgumentException;

final class QuotaChargeAllocator
{
    public function forConsume(int $amount, int $purchaseRemaining): QuotaCharge
    {
        if ($amount <= 0 || $purchaseRemaining < 0) {
            throw new InvalidArgumentException('Consume allocation values are invalid.');
        }
        $purchase = min($amount, $purchaseRemaining);
        return new QuotaCharge($purchase, $amount - $purchase, 0);
    }

    public function forRelease(
        int $amount,
        int $originalAmount,
        int $originalPurchaseCharge,
        int $originalParentCharge,
        int $releasedAmount,
        int $releasedPurchaseCharge,
        int $releasedParentCharge,
    ): QuotaCharge {
        foreach ([
            $amount,
            $originalAmount,
            $originalPurchaseCharge,
            $originalParentCharge,
            $releasedAmount,
            $releasedPurchaseCharge,
            $releasedParentCharge,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Release allocation values must not be negative.');
            }
        }
        if ($amount <= 0 || $originalPurchaseCharge > $originalAmount || $originalParentCharge > $originalAmount - $originalPurchaseCharge) {
            throw new InvalidArgumentException('Original consume allocation is invalid.');
        }
        if ($releasedAmount > $originalAmount || $amount > $originalAmount - $releasedAmount) {
            throw new InvalidArgumentException('Release exceeds original consume amount.');
        }
        if ($releasedPurchaseCharge > $originalPurchaseCharge || $releasedParentCharge > $originalParentCharge) {
            throw new InvalidArgumentException('Previously released charge exceeds original charge.');
        }

        // Purchases are consumed first, so release reverses non-purchase consumption first.
        $originalNonPurchase = $originalAmount - $originalPurchaseCharge;
        $releasedNonPurchase = $releasedAmount - $releasedPurchaseCharge;
        if ($releasedNonPurchase < 0 || $releasedNonPurchase > $originalNonPurchase) {
            throw new InvalidArgumentException('Previously released non-purchase amount is invalid.');
        }
        $nonPurchase = min($amount, $originalNonPurchase - $releasedNonPurchase);
        $purchase = $amount - $nonPurchase;
        $parentRemaining = $originalParentCharge - $releasedParentCharge;
        $parent = min($nonPurchase, $parentRemaining);

        return new QuotaCharge($purchase, $nonPurchase, $parent);
    }
}
