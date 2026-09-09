<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizerProvisioningQueryService;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\domain\AuthorizerProvisioning;

$now = new DateTimeImmutable('2026-09-09T06:40:00Z');
$provisioning = AuthorizerProvisioning::pending(
    'prov-query-1',
    'intent-query-1',
    'tenant-1',
    'platform-1',
    'wx-query-1',
    $now,
)->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 1, $now->modify('+1 second'))
  ->quotaBlocked('quota_insufficient', $now->modify('+2 seconds'));

$repository = new class($provisioning) implements AuthorizerProvisioningRepository {
    public array $findForTenantCalls = [];
    public function __construct(private AuthorizerProvisioning $row) {}
    public function insert(AuthorizerProvisioning $provisioning): void {}
    public function find(string $id): ?AuthorizerProvisioning { return $id === $this->row->id() ? $this->row : null; }
    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning
    {
        $this->findForTenantCalls[] = [$id, $tenantId];
        return $id === $this->row->id() && $tenantId === $this->row->tenantId() ? $this->row : null;
    }
    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning { return null; }
    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool { return false; }
};

$service = new AuthorizerProvisioningQueryService($repository);
$result = $service->get('prov-query-1', 'tenant-1');
expectSame('prov-query-1', $result->id(), 'tenant-scoped query returns the provisioning resource');
expectSame('quota_blocked', $result->status()->value, 'business failure status is still queryable');
expectSame([['prov-query-1', 'tenant-1']], $repository->findForTenantCalls, 'query always scopes repository lookup by Tenant');

try {
    $service->get('prov-query-1', 'tenant-other');
    throw new RuntimeException('cross-Tenant provisioning lookup must not disclose existence');
} catch (AppException $e) {
    expectSame(ErrorCode::NOT_FOUND, $e->errorCode(), 'cross-Tenant lookup maps to tenant-scoped NOT_FOUND');
    expectSame(404, $e->httpStatus(), 'cross-Tenant lookup returns 404');
}
