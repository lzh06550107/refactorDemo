<?php

declare(strict_types=1);

namespace modules\quota\domain;

enum QuotaGrantSource: string
{
    case PLAN = 'plan';
    case PURCHASE = 'purchase';
    case MANUAL = 'manual';
    case PROMOTION = 'promotion';
    case MIGRATION = 'migration';
}
