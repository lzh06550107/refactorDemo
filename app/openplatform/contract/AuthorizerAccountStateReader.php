<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\account\domain\AccountStatus;
use app\openplatform\domain\AuthorizerAccountOwnership;

interface AuthorizerAccountStateReader
{
    public function status(AuthorizerAccountOwnership $ownership): ?AccountStatus;
}
