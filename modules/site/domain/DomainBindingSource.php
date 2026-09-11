<?php

declare(strict_types=1);

namespace modules\site\domain;

enum DomainBindingSource: string
{
    case NATIVE = 'native';
    case R20_SITE_MULTI = 'r20_site_multi';
    case R20_ACCOUNT_BIND_DOMAIN = 'r20_account_bind_domain';
}
