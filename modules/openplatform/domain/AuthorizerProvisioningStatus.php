<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

enum AuthorizerProvisioningStatus: string
{
    case PENDING_METADATA = 'pending_metadata';
    case METADATA_READY = 'metadata_ready';
    case QUOTA_CONSUMED = 'quota_consumed';
    case PROVISIONED = 'provisioned';
    case RECONNECTED = 'reconnected';
    case QUOTA_BLOCKED = 'quota_blocked';
    case BINDING_CONFLICT = 'binding_conflict';
    case METADATA_FAILED = 'metadata_failed';
    case METADATA_TYPE_CONFLICT = 'metadata_type_conflict';
    case PROVISION_FAILED = 'provision_failed';
    case AUTHORIZATION_INACTIVE = 'authorization_inactive';
}
