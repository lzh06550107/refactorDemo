<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use modules\account\domain\AccountType;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerProvisioningRepository implements AuthorizerProvisioningRepository
{
    public function insert(AuthorizerProvisioning $provisioning): void
    {
        Db::table('authorizer_provisionings')->insert($this->row($provisioning));
    }

    public function find(string $id): ?AuthorizerProvisioning
    {
        $row = Db::table('authorizer_provisionings')->where('id', $id)->find();
        return is_array($row) ? $this->map($row) : null;
    }

    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning
    {
        $row = Db::table('authorizer_provisionings')->where([
            'id' => $id,
            'tenant_id' => $tenantId,
        ])->find();
        return is_array($row) ? $this->map($row) : null;
    }

    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning
    {
        $row = Db::table('authorizer_provisionings')->where('source_intent_id', $sourceIntentId)->find();
        return is_array($row) ? $this->map($row) : null;
    }

    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool
    {
        return Db::transaction(function () use ($next, $expectedVersion): bool {
            $current = Db::table('authorizer_provisionings')->where('id', $next->id())->lock(true)->find();
            if (!is_array($current) || (int) $current['version'] !== $expectedVersion) {
                return false;
            }
            if ($next->version() !== $expectedVersion + 1 || !$this->sameIdentity($current, $next)) {
                return false;
            }

            $row = $this->row($next);
            unset($row['id'], $row['source_intent_id'], $row['tenant_id'], $row['component_platform_id'], $row['authorizer_app_id'], $row['created_at']);
            return Db::table('authorizer_provisionings')->where([
                'id' => $next->id(),
                'version' => $expectedVersion,
            ])->update($row) === 1;
        });
    }

    private function sameIdentity(array $current, AuthorizerProvisioning $next): bool
    {
        return hash_equals((string) $current['source_intent_id'], $next->sourceIntentId())
            && hash_equals((string) $current['tenant_id'], $next->tenantId())
            && hash_equals((string) $current['component_platform_id'], $next->componentPlatformId())
            && hash_equals((string) $current['authorizer_app_id'], $next->authorizerAppId());
    }

    /** @return array<string,mixed> */
    private function row(AuthorizerProvisioning $provisioning): array
    {
        return [
            'id' => $provisioning->id(),
            'source_intent_id' => $provisioning->sourceIntentId(),
            'tenant_id' => $provisioning->tenantId(),
            'component_platform_id' => $provisioning->componentPlatformId(),
            'authorizer_app_id' => $provisioning->authorizerAppId(),
            'account_type' => $provisioning->accountType()?->value,
            'status' => $provisioning->status()->value,
            'metadata_version' => $provisioning->metadataVersion(),
            'quota_resource_key' => $provisioning->quotaResourceKey(),
            'quota_consume_entry_id' => $provisioning->quotaConsumeEntryId(),
            'quota_release_entry_id' => $provisioning->quotaReleaseEntryId(),
            'account_id' => $provisioning->accountId(),
            'last_error_code' => $provisioning->lastErrorCode(),
            'last_error_stage' => $provisioning->lastErrorStage(),
            'created_at' => $this->sqlDate($provisioning->createdAt()),
            'updated_at' => $this->sqlDate($provisioning->updatedAt()),
            'completed_at' => $provisioning->completedAt() === null ? null : $this->sqlDate($provisioning->completedAt()),
            'version' => $provisioning->version(),
        ];
    }

    private function map(array $row): AuthorizerProvisioning
    {
        $accountTypeValue = $this->nullableString($row['account_type'] ?? null);
        $accountType = $accountTypeValue === null ? null : AccountType::tryFrom($accountTypeValue);
        if ($accountTypeValue !== null && $accountType === null) {
            throw new UnexpectedValueException('Persisted authorizer provisioning has an unsupported Account type.');
        }

        $status = AuthorizerProvisioningStatus::tryFrom((string) ($row['status'] ?? ''));
        if ($status === null) {
            throw new UnexpectedValueException('Persisted authorizer provisioning has an unsupported status.');
        }

        return AuthorizerProvisioning::reconstitute(
            (string) $row['id'],
            (string) $row['source_intent_id'],
            (string) $row['tenant_id'],
            (string) $row['component_platform_id'],
            (string) $row['authorizer_app_id'],
            $accountType,
            $status,
            isset($row['metadata_version']) ? (int) $row['metadata_version'] : null,
            $this->nullableString($row['quota_resource_key'] ?? null),
            $this->nullableString($row['quota_consume_entry_id'] ?? null),
            $this->nullableString($row['quota_release_entry_id'] ?? null),
            $this->nullableString($row['account_id'] ?? null),
            $this->nullableString($row['last_error_code'] ?? null),
            $this->nullableString($row['last_error_stage'] ?? null),
            $this->date((string) $row['created_at']),
            $this->date((string) $row['updated_at']),
            empty($row['completed_at']) ? null : $this->date((string) $row['completed_at']),
            (int) $row['version'],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
