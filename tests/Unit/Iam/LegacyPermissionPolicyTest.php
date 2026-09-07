<?php

declare(strict_types=1);

use app\iam\domain\LegacyPermissionAssignment;
use app\iam\domain\LegacyPermissionPolicy;
use app\iam\domain\Permission;

$policy = new LegacyPermissionPolicy();
$permission = new Permission('platform_reply_keyword');

expectTrue(
    $policy->allows($permission, LegacyPermissionAssignment::roleDefault(), true),
    'R20 absent users_permission row must preserve role-default allow'
);
expectTrue(
    !$policy->allows($permission, LegacyPermissionAssignment::roleDefault(), false),
    'role-default fallback must still respect the role ACL'
);
expectTrue(
    $policy->allows($permission, LegacyPermissionAssignment::all(), false),
    'explicit all must allow any permission'
);
expectTrue(
    $policy->allows($permission, LegacyPermissionAssignment::explicit(['platform_reply_keyword']), false),
    'explicit exact permission must allow'
);
expectTrue(
    $policy->allows(new Permission('account_manage'), LegacyPermissionAssignment::explicit(['account*']), false, 'account'),
    'R20 frame wildcard must allow permission in the same frame'
);
expectTrue(
    !$policy->allows(new Permission('wxapp_manage'), LegacyPermissionAssignment::explicit(['account*']), true, 'wxapp'),
    'explicit list must deny missing permission even when role default would allow'
);
