<?php

declare(strict_types=1);

use app\iam\domain\LegacyAccountRole;
use app\iam\domain\LegacyAccountRoleResolver;
use app\iam\domain\LegacyAdminState;

$resolver = new LegacyAccountRoleResolver();

$founder = new LegacyAdminState(isMainFounder: true, isViceFounder: false, isBound: false, isClerk: true, isExpired: true);
expectSame(LegacyAccountRole::FOUNDER, $resolver->forAccount($founder, LegacyAccountRole::OPERATOR), 'main founder must override all lower states');

$expired = new LegacyAdminState(false, false, true, false, true);
expectSame(LegacyAccountRole::EXPIRED, $resolver->forAccount($expired, LegacyAccountRole::OWNER), 'expired must be resolved before account membership');

$unbound = new LegacyAdminState(false, false, false, false, false);
expectSame(LegacyAccountRole::UNBOUND_USER, $resolver->forAccount($unbound, LegacyAccountRole::OWNER), 'unbound must be resolved before account membership');

$clerk = new LegacyAdminState(false, false, true, true, false);
expectSame(LegacyAccountRole::CLERK, $resolver->forAccount($clerk, LegacyAccountRole::OWNER), 'clerk user type must override account membership');

$normal = new LegacyAdminState(false, false, true, false, false);
expectSame(LegacyAccountRole::MANAGER, $resolver->forAccount($normal, LegacyAccountRole::MANAGER), 'concrete account membership role');
expectSame(LegacyAccountRole::NONE, $resolver->forAccount($normal, null), 'missing concrete account membership remains empty');

$viceFounder = new LegacyAdminState(false, true, true, false, false);
expectSame(LegacyAccountRole::VICE_FOUNDER, $resolver->highest($viceFounder, [LegacyAccountRole::OPERATOR]), 'vice founder global state wins cross-account');
expectSame(LegacyAccountRole::OWNER, $resolver->highest($normal, [LegacyAccountRole::OPERATOR, LegacyAccountRole::OWNER, LegacyAccountRole::MANAGER]), 'owner outranks manager/operator');
expectSame(LegacyAccountRole::MANAGER, $resolver->highest($normal, [LegacyAccountRole::OPERATOR, LegacyAccountRole::MANAGER]), 'manager outranks operator');
expectSame(LegacyAccountRole::OWNER, $resolver->highest($normal, []), 'R20 compatibility fallback treats bound normal user with no account roles as owner');
