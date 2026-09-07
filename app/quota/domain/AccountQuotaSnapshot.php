<?php

declare(strict_types=1);

namespace app\quota\domain;

use app\account\domain\AccountType;
use InvalidArgumentException;

final readonly class AccountQuotaSnapshot
{
    public function __construct(
        private AccountType $accountType,
        private int $groupLimit,
        private int $extraGroupLimit,
        private int $extraLimit,
        private int $purchasedAllowance,
        private int $consumed,
        private int $localAvailable,
        private ?int $parentAvailable,
        private int $purchasedAvailableHint,
        private bool $unlimited = false,
    ) {
        foreach ([
            $groupLimit,
            $extraGroupLimit,
            $extraLimit,
            $purchasedAllowance,
            $consumed,
            $localAvailable,
            $purchasedAvailableHint,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Account quota snapshot values must not be negative.');
            }
        }
        if ($parentAvailable !== null && $parentAvailable < 0) {
            throw new InvalidArgumentException('Parent available quota must not be negative.');
        }
    }

    public function accountType(): AccountType { return $this->accountType; }
    public function groupLimit(): int { return $this->groupLimit; }
    public function extraGroupLimit(): int { return $this->extraGroupLimit; }
    public function extraLimit(): int { return $this->extraLimit; }
    public function purchasedAllowance(): int { return $this->purchasedAllowance; }
    public function consumed(): int { return $this->consumed; }
    public function localAvailable(): int { return $this->localAvailable; }
    public function parentAvailable(): ?int { return $this->parentAvailable; }
    public function purchasedAvailableHint(): int { return $this->purchasedAvailableHint; }
    public function unlimited(): bool { return $this->unlimited; }
}
