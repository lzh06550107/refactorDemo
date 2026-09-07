<?php

declare(strict_types=1);

namespace app\quota\domain;

enum QuotaLedgerEntryType: string
{
    case GRANT = 'grant';
    case CONSUME = 'consume';
    case RELEASE = 'release';
    case EXPIRE = 'expire';
}
