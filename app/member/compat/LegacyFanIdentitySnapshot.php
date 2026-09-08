<?php

declare(strict_types=1);

namespace app\member\compat;

final class LegacyFanIdentitySnapshot
{
    public function __construct(
        private readonly int $fanId,
        private readonly int $legacyUniacid,
        private readonly int $legacyAcid,
        private readonly int $legacyUid,
        private readonly string $openid,
        private readonly string $unionId,
        private readonly int $follow,
        private readonly int $followTime,
        private readonly int $unfollowTime,
        private readonly int $userFrom,
    ) {
    }

    public function fanId(): int { return $this->fanId; }
    public function legacyUniacid(): int { return $this->legacyUniacid; }
    public function legacyAcid(): int { return $this->legacyAcid; }
    public function legacyUid(): int { return $this->legacyUid; }
    public function openid(): string { return $this->openid; }
    public function unionId(): string { return $this->unionId; }
    public function follow(): int { return $this->follow; }
    public function followTime(): int { return $this->followTime; }
    public function unfollowTime(): int { return $this->unfollowTime; }
    public function userFrom(): int { return $this->userFrom; }
}
