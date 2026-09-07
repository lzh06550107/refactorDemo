<?php

declare(strict_types=1);

namespace app\quota\domain;

use InvalidArgumentException;

final class AccountCreationQuotaPolicy
{
    public function evaluate(
        int $baseLimit,
        int $extraGroupLimit,
        int $extraLimit,
        int $purchasedLimit,
        int $consumed,
        int $purchasedConsumed,
        ?int $parentPoolRemaining = null,
        bool $unlimited = false,
    ): QuotaAvailability {
        if ($unlimited) {
            return QuotaAvailability::unlimited();
        }

        foreach ([
            'baseLimit' => $baseLimit,
            'extraGroupLimit' => $extraGroupLimit,
            'extraLimit' => $extraLimit,
            'purchasedLimit' => $purchasedLimit,
            'consumed' => $consumed,
            'purchasedConsumed' => $purchasedConsumed,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException($name . ' must not be negative.');
            }
        }
        if ($purchasedConsumed > $consumed) {
            throw new InvalidArgumentException('purchasedConsumed must not exceed consumed.');
        }
        if ($parentPoolRemaining !== null && $parentPoolRemaining < 0) {
            throw new InvalidArgumentException('parentPoolRemaining must not be negative.');
        }

        $purchaseRemaining = max($purchasedLimit - $purchasedConsumed, 0);
        $nonPurchaseCapacity = $baseLimit + $extraGroupLimit + $extraLimit;
        $nonPurchaseConsumed = max($consumed - $purchasedConsumed, 0);
        $nonPurchaseRemaining = max($nonPurchaseCapacity - $nonPurchaseConsumed, 0);
        if ($parentPoolRemaining !== null) {
            $nonPurchaseRemaining = min($nonPurchaseRemaining, $parentPoolRemaining);
        }

        return QuotaAvailability::limited(
            $purchaseRemaining + $nonPurchaseRemaining,
            $purchaseRemaining,
            $nonPurchaseRemaining,
            $parentPoolRemaining,
        );
    }
}
