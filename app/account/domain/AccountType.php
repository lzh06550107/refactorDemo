<?php

declare(strict_types=1);

namespace app\account\domain;

enum AccountType: string
{
    case OFFICIAL_ACCOUNT = 'official_account';
    case WECHAT_MINI_PROGRAM = 'wechat_mini_program';
    case WEBAPP = 'webapp';
    case PHONEAPP = 'phoneapp';
    case ALIPAY_MINI_PROGRAM = 'alipay_mini_program';
    case BAIDU_MINI_PROGRAM = 'baidu_mini_program';
    case TOUTIAO_MINI_PROGRAM = 'toutiao_mini_program';
}
