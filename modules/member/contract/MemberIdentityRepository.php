<?php

declare(strict_types=1);

namespace modules\member\contract;

use modules\member\domain\ExternalIdentity;
use modules\member\domain\Member;
use modules\member\domain\MemberIdentityResult;
use modules\member\domain\ProviderIdentity;

interface MemberIdentityRepository
{
    public function findByProviderIdentity(ProviderIdentity $identity): ?MemberIdentityResult;

    public function insertOrGet(Member $member, ExternalIdentity $identity): MemberIdentityResult;
}
