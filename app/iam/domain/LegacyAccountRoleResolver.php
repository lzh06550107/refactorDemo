<?php

declare(strict_types=1);

namespace app\iam\domain;

final class LegacyAccountRoleResolver
{
    public function forAccount(LegacyAdminState $state, ?LegacyAccountRole $accountRole): LegacyAccountRole
    {
        if ($state->isMainFounder) {
            return LegacyAccountRole::FOUNDER;
        }
        if ($state->isExpired) {
            return LegacyAccountRole::EXPIRED;
        }
        if (!$state->isBound) {
            return LegacyAccountRole::UNBOUND_USER;
        }
        if ($state->isClerk) {
            return LegacyAccountRole::CLERK;
        }

        return $accountRole ?? LegacyAccountRole::NONE;
    }

    /** @param list<LegacyAccountRole> $accountRoles */
    public function highest(LegacyAdminState $state, array $accountRoles): LegacyAccountRole
    {
        $preAccount = $this->forAccount($state, null);
        if ($preAccount !== LegacyAccountRole::NONE) {
            return $preAccount;
        }
        if ($state->isViceFounder) {
            return LegacyAccountRole::VICE_FOUNDER;
        }

        foreach ([
            LegacyAccountRole::VICE_FOUNDER,
            LegacyAccountRole::OWNER,
            LegacyAccountRole::MANAGER,
            LegacyAccountRole::OPERATOR,
        ] as $candidate) {
            if (in_array($candidate, $accountRoles, true)) {
                return $candidate;
            }
        }

        // MUST_COMPAT: R20 permission_account_user_role() falls back to owner here.
        return LegacyAccountRole::OWNER;
    }
}
