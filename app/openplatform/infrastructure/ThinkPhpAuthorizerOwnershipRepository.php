<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use modules\account\domain\AccountType;
use app\openplatform\contract\AuthorizerOwnershipRepository;
use app\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerOwnershipRepository implements AuthorizerOwnershipRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccountOwnership
    {
        $row = Db::table('authorizer_account_ownerships')->where([
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->find();
        if (!is_array($row)) {
            return null;
        }

        $accountType = AccountType::tryFrom((string) ($row['account_type'] ?? ''));
        if ($accountType === null) {
            throw new UnexpectedValueException('Persisted authorizer ownership has an unsupported Account type.');
        }

        return new AuthorizerAccountOwnership(
            (string) $row['component_platform_id'],
            (string) $row['authorizer_app_id'],
            (string) $row['tenant_id'],
            (string) $row['account_id'],
            $accountType,
            $this->date((string) $row['first_bound_at']),
            $this->date((string) $row['last_connected_at']),
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
