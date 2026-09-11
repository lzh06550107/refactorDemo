<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\account\domain\AccountStatus;
use modules\openplatform\domain\AuthorizerAccountOwnership;

interface AuthorizerAccountStateReader
{
    public function status(AuthorizerAccountOwnership $ownership): ?AccountStatus;
}
