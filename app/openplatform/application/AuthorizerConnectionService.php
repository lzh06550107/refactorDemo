<?php

declare(strict_types=1);

namespace app\openplatform\application;

use modules\account\domain\AccountStatus;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAccountStateReader;
use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;

final readonly class AuthorizerConnectionService
{
    public function __construct(
        private AuthorizerConnectionStore $connections,
        private ?AuthorizerAccountStateReader $accounts = null,
    ) {
    }

    public function reconnect(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void
    {
        if ($this->accounts !== null) {
            $status = $this->accounts->status($ownership);
            if ($status === null || $status === AccountStatus::DELETED) {
                throw new AppException(
                    ErrorCode::CONFLICT,
                    'Owned Account is deleted or unavailable and requires manual review.',
                    409,
                );
            }
            // ACTIVE and SUSPENDED both restore only the provider connection projection.
            // The Tenant-owned Account business status is never changed here.
        }

        $this->connections->enableExisting($ownership, $now);
    }

    public function disconnect(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void
    {
        $this->connections->disable($componentPlatformId, $authorizerAppId, $now);
    }
}
