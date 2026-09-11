<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizerAccountFinalizer;
use modules\openplatform\domain\AuthorizerMetadataRecord;
use modules\openplatform\domain\AuthorizerProvisioning;
use modules\openplatform\domain\AuthorizerProvisioningStatus;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerAccountFinalizer implements AuthorizerAccountFinalizer
{
    public function provision(
        AuthorizerProvisioning $provisioning,
        AuthorizerMetadataRecord $metadata,
        DateTimeImmutable $now,
    ): string {
        $this->assertMetadata($provisioning, $metadata);

        return Db::transaction(function () use ($provisioning, $metadata, $now): string {
            $row = Db::table('authorizer_provisionings')
                ->where('id', $provisioning->id())
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new AppException(ErrorCode::NOT_FOUND, 'Authorizer provisioning not found.', 404);
            }
            $this->assertProvisioningRow($row, $provisioning);

            $authorization = Db::table('authorizer_authorizations')->where([
                'component_platform_id' => $provisioning->componentPlatformId(),
                'authorizer_app_id' => $provisioning->authorizerAppId(),
            ])->lock(true)->find();
            if (!is_array($authorization) || (string) ($authorization['status'] ?? '') !== 'active') {
                throw new AppException(ErrorCode::CONFLICT, 'Authorizer authorization is not active.', 409);
            }

            $ownership = Db::table('authorizer_account_ownerships')->where([
                'component_platform_id' => $provisioning->componentPlatformId(),
                'authorizer_app_id' => $provisioning->authorizerAppId(),
            ])->lock(true)->find();
            if (is_array($ownership)) {
                if ((string) ($ownership['tenant_id'] ?? '') !== $provisioning->tenantId()) {
                    throw new AppException(ErrorCode::CONFLICT, 'Canonical authorizer is owned by another Tenant.', 409);
                }
                $accountId = (string) ($ownership['account_id'] ?? '');
                if ($accountId === '') {
                    throw new AppException(ErrorCode::CONFLICT, 'Canonical ownership has no Account.', 409);
                }
                $this->markProvisioned($row, $accountId, $now);
                return $accountId;
            }

            $accountType = $provisioning->accountType();
            if ($accountType === null) {
                throw new LogicException('Finalization requires frozen Account type.');
            }
            $accountId = bin2hex(random_bytes(16));
            $accountName = $this->accountName($metadata, $accountType, $provisioning->authorizerAppId());
            $sqlNow = $this->sqlDate($now);

            Db::table('accounts')->insert([
                'id' => $accountId,
                'tenant_id' => $provisioning->tenantId(),
                'name' => $accountName,
                'type' => $accountType->value,
                'status' => 'active',
                'created_at' => $sqlNow,
                'updated_at' => $sqlNow,
            ]);

            $providerRow = [
                'account_id' => $accountId,
                'tenant_id' => $provisioning->tenantId(),
                'provider_app_id' => $provisioning->authorizerAppId(),
                'connection_mode' => 'component',
                'credential_ref' => null,
                'component_platform_id' => $provisioning->componentPlatformId(),
                'enabled' => 1,
                'created_at' => $sqlNow,
                'updated_at' => $sqlNow,
            ];
            if ($accountType === AccountType::WECHAT_MINI_PROGRAM) {
                Db::table('miniapp_provider_accounts')->insert($providerRow);
            } elseif ($accountType === AccountType::OFFICIAL_ACCOUNT) {
                Db::table('official_account_provider_accounts')->insert($providerRow);
            } else {
                throw new AppException(ErrorCode::CONFLICT, 'Unsupported OpenPlatform Account type.', 409);
            }

            Db::table('authorizer_account_ownerships')->insert([
                'component_platform_id' => $provisioning->componentPlatformId(),
                'authorizer_app_id' => $provisioning->authorizerAppId(),
                'tenant_id' => $provisioning->tenantId(),
                'account_id' => $accountId,
                'account_type' => $accountType->value,
                'first_bound_at' => $sqlNow,
                'last_connected_at' => $sqlNow,
                'created_at' => $sqlNow,
                'updated_at' => $sqlNow,
            ]);

            $this->markProvisioned($row, $accountId, $now);
            return $accountId;
        });
    }

    public function reconcile(AuthorizerProvisioning $provisioning): ?string
    {
        return Db::transaction(function () use ($provisioning): ?string {
            $row = Db::table('authorizer_provisionings')
                ->where('id', $provisioning->id())
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                return null;
            }

            $ownership = Db::table('authorizer_account_ownerships')->where([
                'component_platform_id' => $provisioning->componentPlatformId(),
                'authorizer_app_id' => $provisioning->authorizerAppId(),
            ])->lock(true)->find();
            if (!is_array($ownership) || (string) ($ownership['tenant_id'] ?? '') !== $provisioning->tenantId()) {
                return null;
            }

            $accountId = (string) ($ownership['account_id'] ?? '');
            $accountType = (string) ($ownership['account_type'] ?? '');
            if ($accountId === '' || $provisioning->accountType() === null || $accountType !== $provisioning->accountType()->value) {
                return null;
            }

            $account = Db::table('accounts')->where([
                'id' => $accountId,
                'tenant_id' => $provisioning->tenantId(),
                'type' => $accountType,
            ])->find();
            if (!is_array($account)) {
                return null;
            }

            $providerTable = $provisioning->accountType() === AccountType::WECHAT_MINI_PROGRAM
                ? 'miniapp_provider_accounts'
                : 'official_account_provider_accounts';
            $provider = Db::table($providerTable)->where([
                'account_id' => $accountId,
                'tenant_id' => $provisioning->tenantId(),
                'provider_app_id' => $provisioning->authorizerAppId(),
                'connection_mode' => 'component',
                'component_platform_id' => $provisioning->componentPlatformId(),
            ])->find();
            if (!is_array($provider)) {
                return null;
            }

            if ((string) ($row['status'] ?? '') !== 'provisioned' || (string) ($row['account_id'] ?? '') !== $accountId) {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $this->markProvisioned($row, $accountId, $now);
            }
            return $accountId;
        });
    }

    private function assertMetadata(AuthorizerProvisioning $provisioning, AuthorizerMetadataRecord $metadata): void
    {
        if (
            $metadata->componentPlatformId() !== $provisioning->componentPlatformId()
            || $metadata->authorizerAppId() !== $provisioning->authorizerAppId()
            || $provisioning->accountType() === null
            || $metadata->accountType() !== $provisioning->accountType()
        ) {
            throw new AppException(ErrorCode::CONFLICT, 'Trusted authorizer metadata does not match provisioning.', 409);
        }
    }

    /** @param array<string,mixed> $row */
    private function assertProvisioningRow(array $row, AuthorizerProvisioning $provisioning): void
    {
        if (
            (string) ($row['tenant_id'] ?? '') !== $provisioning->tenantId()
            || (string) ($row['component_platform_id'] ?? '') !== $provisioning->componentPlatformId()
            || (string) ($row['authorizer_app_id'] ?? '') !== $provisioning->authorizerAppId()
            || (string) ($row['account_type'] ?? '') !== ($provisioning->accountType()?->value ?? '')
            || !in_array((string) ($row['status'] ?? ''), [
                AuthorizerProvisioningStatus::QUOTA_CONSUMED->value,
                AuthorizerProvisioningStatus::PROVISION_FAILED->value,
            ], true)
            || empty($row['quota_consume_entry_id'])
        ) {
            throw new AppException(ErrorCode::CONFLICT, 'Authorizer provisioning is not finalizable.', 409);
        }
    }

    private function accountName(AuthorizerMetadataRecord $metadata, AccountType $type, string $authorizerAppId): string
    {
        $nickName = trim($metadata->nickName());
        if ($nickName !== '') {
            return $nickName;
        }

        $suffix = substr($authorizerAppId, -8);
        return $type === AccountType::WECHAT_MINI_PROGRAM
            ? '微信小程序 · ' . $suffix
            : '微信公众号 · ' . $suffix;
    }

    /** @param array<string,mixed> $row */
    private function markProvisioned(array $row, string $accountId, DateTimeImmutable $now): void
    {
        $updated = Db::table('authorizer_provisionings')
            ->where('id', (string) $row['id'])
            ->where('version', (int) $row['version'])
            ->update([
                'status' => 'provisioned',
                'account_id' => $accountId,
                'last_error_code' => null,
                'last_error_stage' => null,
                'completed_at' => $this->sqlDate($now),
                'updated_at' => $this->sqlDate($now),
                'version' => (int) $row['version'] + 1,
            ]);
        if ($updated !== 1) {
            throw new AppException(ErrorCode::CONFLICT, 'Authorizer provisioning finalization lost CAS ownership.', 409);
        }
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
