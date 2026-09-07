# R20 Entitlement / Account Quota Compatibility

## R20 source semantics

`permission_user_account_num()` calculates per-account-type creation capacity from multiple inputs rather than a single user-group limit. The effective R20 result includes the base user/founder group, `users_extra_limit`, `users_create_group`, store-purchased capacity, and already-created/consumed accounts. Users under a vice-founder/reseller hierarchy are additionally capped by the superior pool.

R5 does not rename this computation to IAM. IAM only decides whether the user may invoke account creation; Quota decides whether capacity exists.

## Golden-Master mapping

`R20AccountQuotaSnapshotRepository` normalizes the already-computed R20 result for each account type. It preserves the legacy final `*_limit`, purchased allowance/hint, consumed count, and parent `founder_*_limit` when `vice_group_name` proves a parent pool is actually present.

A zero `founder_*_limit` without `vice_group_name` is not treated as a zero-capacity parent pool; it means no parent constraint was resolved for this snapshot.

## New quota model

- `QuotaResource`: resource key, including `account_create:<AccountType>`.
- `QuotaGrant`: PLAN / PURCHASE / MANUAL / PROMOTION / MIGRATION capacity.
- `QuotaLedgerEntry`: GRANT / CONSUME / RELEASE / EXPIRE immutable events.
- `quota_balances`: transactional projection and row-lock anchor.
- `ParentQuotaPool`: superior/reseller capacity.
- `QuotaService`: idempotent application boundary.

Purchase quota is consumed first and never charges a parent pool. Only non-purchase consumption is capped and charged against the parent pool. Release reverses that order: non-purchase/parent charge is restored before purchase quota.

## Intentional compatibility fix

The R20 save-path helper `uni_account_can_create()` contains an unconditional early `return 1`, so UI/pre-check quota calculations are not a reliable final write gate. R5 deliberately does not preserve that bypass.

The new ThinkPHP quota repository locks the unique `(tenant_id, resource_key)` balance row inside a transaction, rechecks local and parent capacity, checks the idempotency key, and only then writes the consumption ledger/update. This behavior is classified `INTENTIONAL_FIX`.

## Idempotency and concurrency

- `(tenant_id, resource_key, idempotency_key)` is unique in the ledger.
- Replaying the same operation returns the original result.
- Reusing the same idempotency key for a different operation/payload returns `CONFLICT`.
- First-grant creation upserts `quota_balances` before `SELECT ... FOR UPDATE` so concurrent first writes serialize on one durable anchor.
