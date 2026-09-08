<?php

declare(strict_types=1);

namespace app\member\application;

use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\member\contract\MemberIdentityRepository;
use app\member\domain\ExternalIdentity;
use app\member\domain\Member;
use app\member\domain\MemberIdentityResult;
use app\member\domain\ProviderIdentity;
use InvalidArgumentException;

final readonly class MemberIdentityService
{
    public function __construct(
        private MemberIdentityRepository $repository,
        private TransactionManager $transactions,
    ) {
    }

    public function resolveOrCreate(string $tenantId, ProviderIdentity $providerIdentity): MemberIdentityResult
    {
        return $this->transactions->run(
            fn (): MemberIdentityResult => $this->resolveOrCreateWithinTransaction($tenantId, $providerIdentity),
        );
    }

    public function resolveOrCreateWithinTransaction(string $tenantId, ProviderIdentity $providerIdentity): MemberIdentityResult
    {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('tenantId must not be empty.');
        }

        $existing = $this->repository->findByProviderIdentity($providerIdentity);
        if ($existing !== null) {
            $this->assertTenant($tenantId, $existing);
            return $existing;
        }

        $member = new Member(bin2hex(random_bytes(16)), $tenantId);
        $external = new ExternalIdentity(
            bin2hex(random_bytes(16)),
            $tenantId,
            $member->id(),
            $providerIdentity,
        );

        $result = $this->repository->insertOrGet($member, $external);
        $this->assertTenant($tenantId, $result);
        return $result;
    }

    private function assertTenant(string $tenantId, MemberIdentityResult $result): void
    {
        if ($result->member()->tenantId() !== $tenantId || $result->externalIdentity()->tenantId() !== $tenantId) {
            throw new AppException(ErrorCode::CONFLICT, 'External identity already belongs to another tenant.', 409);
        }
    }
}
