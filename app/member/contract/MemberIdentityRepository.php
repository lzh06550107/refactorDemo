<?php

declare(strict_types=1);

namespace app\member\contract;

use app\member\domain\ExternalIdentity;
use app\member\domain\Member;
use app\member\domain\MemberIdentityResult;
use app\member\domain\ProviderIdentity;

interface MemberIdentityRepository
{
    public function findByProviderIdentity(ProviderIdentity $identity): ?MemberIdentityResult;

    public function insertOrGet(Member $member, ExternalIdentity $identity): MemberIdentityResult;
}
