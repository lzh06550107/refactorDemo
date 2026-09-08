<?php

declare(strict_types=1);

namespace app\member\domain;

final readonly class MemberIdentityResult
{
    public function __construct(
        private Member $member,
        private ExternalIdentity $externalIdentity,
    ) {
    }

    public function member(): Member { return $this->member; }
    public function externalIdentity(): ExternalIdentity { return $this->externalIdentity; }
}
