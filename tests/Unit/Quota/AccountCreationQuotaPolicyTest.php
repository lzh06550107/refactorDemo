<?php

declare(strict_types=1);

use app\quota\domain\AccountCreationQuotaPolicy;

$policy = new AccountCreationQuotaPolicy();

$limited = $policy->evaluate(
    baseLimit: 3,
    extraGroupLimit: 2,
    extraLimit: 1,
    purchasedLimit: 4,
    consumed: 5,
    purchasedConsumed: 3,
    parentPoolRemaining: 1,
);
expectSame(2, $limited->available(), 'parent pool caps only non-purchase quota while purchase quota remains usable');
expectSame(1, $limited->purchaseRemaining(), 'one purchased quota should remain');
expectSame(1, $limited->nonPurchaseRemaining(), 'parent pool should cap non-purchase remainder to one');
expectSame(1, $limited->parentPoolRemaining(), 'parent pool remainder must be exposed');

$withoutParent = $policy->evaluate(
    baseLimit: 3,
    extraGroupLimit: 2,
    extraLimit: 1,
    purchasedLimit: 4,
    consumed: 5,
    purchasedConsumed: 3,
);
expectSame(5, $withoutParent->available(), 'without a parent pool local purchase and non-purchase quota must both remain available');
expectSame(1, $withoutParent->purchaseRemaining(), 'purchase remainder must be independent of parent pool');
expectSame(4, $withoutParent->nonPurchaseRemaining(), 'local non-purchase capacity should not be capped without a parent pool');

$unlimited = $policy->evaluate(0, 0, 0, 0, 0, 0, null, true);
expectTrue($unlimited->isUnlimited(), 'explicit founder/root quota must support unlimited mode');
expectTrue($unlimited->canConsume(1000000), 'unlimited quota must accept arbitrary positive consumption');

expectThrows(
    static fn () => $policy->evaluate(1, 0, 0, 0, 1, 2),
    InvalidArgumentException::class,
    'purchased consumed cannot exceed total consumed',
);
