<?php

declare(strict_types=1);

namespace modules\iam\domain;

final readonly class LegacyAdminState
{
    public function __construct(
        public bool $isMainFounder,
        public bool $isViceFounder,
        public bool $isBound,
        public bool $isClerk,
        public bool $isExpired,
    ) {
    }
}
