<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\domain\AuthorizerProvisioning;
use modules\quota\application\QuotaService;
use modules\quota\domain\QuotaResource;
use DateTimeImmutable;
use LogicException;

final readonly class AuthorizerProvisioningQuotaService
{
    public function __construct(
        private QuotaService $quota,
        private AuthorizerProvisioningRepository $provisionings,
    ) {
    }

    public function ensureConsumed(AuthorizerProvisioning $provisioning, DateTimeImmutable $now): AuthorizerProvisioning
    {
        $current = $this->provisionings->find($provisioning->id()) ?? $provisioning;
        if ($current->quotaConsumeEntryId() !== null) {
            return $current;
        }
        if ($current->accountType() === null) {
            throw new LogicException('Account creation quota requires frozen trusted Account type.');
        }

        $resource = QuotaResource::accountCreate($current->accountType());
        $entry = $this->quota->consume(
            $current->tenantId(),
            $resource,
            1,
            sprintf(
                'openplatform-provision:%s:%s:%s',
                $current->componentPlatformId(),
                $current->authorizerAppId(),
                $current->tenantId(),
            ),
            $now,
        );

        $next = $current->withQuotaConsumed($resource->key(), $entry->id(), $now);
        if ($this->provisionings->save($next, $current->version())) {
            return $next;
        }

        $latest = $this->provisionings->find($current->id());
        if ($latest !== null && $latest->quotaConsumeEntryId() !== null) {
            if (!hash_equals($entry->id(), $latest->quotaConsumeEntryId())) {
                throw new AppException(ErrorCode::CONFLICT, 'Provisioning references a different quota consume entry.', 409);
            }
            return $latest;
        }

        throw new AppException(
            ErrorCode::CONFLICT,
            'Quota consume committed but provisioning reference CAS was lost.',
            409,
        );
    }

    public function ensureReleased(AuthorizerProvisioning $provisioning, DateTimeImmutable $now): AuthorizerProvisioning
    {
        $current = $this->provisionings->find($provisioning->id()) ?? $provisioning;
        if ($current->quotaReleaseEntryId() !== null) {
            return $current;
        }
        if ($current->quotaConsumeEntryId() === null || $current->accountType() === null) {
            throw new LogicException('Quota release requires a frozen Account type and prior consume entry.');
        }

        $resource = new QuotaResource(
            $current->quotaResourceKey() ?? QuotaResource::accountCreate($current->accountType())->key(),
        );
        $entry = $this->quota->release(
            $current->tenantId(),
            $resource,
            $current->quotaConsumeEntryId(),
            1,
            'openplatform-provision-release:' . $current->id(),
            $now,
        );

        $next = $current->withQuotaRelease($entry->id(), $now);
        if ($this->provisionings->save($next, $current->version())) {
            return $next;
        }

        $latest = $this->provisionings->find($current->id());
        if ($latest !== null && $latest->quotaReleaseEntryId() !== null) {
            if (!hash_equals($entry->id(), $latest->quotaReleaseEntryId())) {
                throw new AppException(ErrorCode::CONFLICT, 'Provisioning references a different quota release entry.', 409);
            }
            return $latest;
        }

        throw new AppException(
            ErrorCode::CONFLICT,
            'Quota release committed but provisioning reference CAS was lost.',
            409,
        );
    }
}
