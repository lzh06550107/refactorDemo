<?php

declare(strict_types=1);

namespace app\member\compat;

final class LegacyMemberSnapshot
{
    public function __construct(
        private readonly int $legacyUid,
        private readonly int $legacyUniacid,
        private readonly int $legacyGroupId,
        private readonly string $nickname,
        private readonly string $avatar,
        private readonly int $status,
    ) {
    }

    public function legacyUid(): int { return $this->legacyUid; }
    public function legacyUniacid(): int { return $this->legacyUniacid; }
    public function legacyGroupId(): int { return $this->legacyGroupId; }
    public function nickname(): string { return $this->nickname; }
    public function avatar(): string { return $this->avatar; }
    public function status(): int { return $this->status; }
}
