# Entitlement + Quota R5 Implementation Plan

## Goal

Implement the V4 Entitlement/Quota slice after the R4 module runtime: separate tenant commercial module entitlement from user authorization, preserve WeEngine R20 account-creation quota outcomes, and enforce quota consumption atomically at the final write boundary.

## Constraints

- IAM answers whether a principal may request `account.create`; Quota answers whether capacity remains.
- Tenant module entitlement is independent from module installation, account compatibility, and user authorization.
- Account creation quota is isolated per `AccountType`.
- R20 quota result fields are migrated as Golden-Master evidence rather than recomputed through a different formula.
- Purchase quota is consumed before non-purchase quota and never consumes a parent quota pool.
- Parent/reseller pools cap only non-purchase quota.
- Final consumption is transactionally rechecked; the R20 `uni_account_can_create()` unconditional-return bypass is classified `INTENTIONAL_FIX`.
- Domain/Application code must not depend on ThinkPHP Facades.

## Tasks

1. Add `TenantModuleEntitlement` lifecycle, repository contract, service, and ThinkPHP repository.
2. Add `AccountCreationQuotaPolicy`, `QuotaAvailability`, and R20 account quota snapshot adapter.
3. Add grant/consume/release/expire ledger domain with purchase/parent charge allocation.
4. Add idempotent `QuotaService`; a reused key with a different payload must return `CONFLICT`.
5. Add transactional ThinkPHP repository using a unique `quota_balances` row as the mutation lock anchor.
6. Add MySQL 8 migrations for entitlement, grants, balances, parent pools, and ledger.
7. Extend the unified offline runner from 34 to 41 tests and run GitHub CI including Composer, PHPUnit, lint, and multi-app HTTP smoke.

## Definition of Done

R5 is complete only when the R5 branch and final `main` commit both pass the repository CI. No download package is produced; GitHub remains the canonical code baseline.
