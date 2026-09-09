<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\http\ApiResponse;
use app\openplatform\application\AuthorizerProvisioningQueryService;
use app\openplatform\application\AuthorizerProvisioningRetryService;
use app\openplatform\application\OpenPlatformAdminGuard;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\OpenPlatformPermission;
use DateTimeImmutable;
use DateTimeZone;
use think\response\Json;

final readonly class OpenPlatformProvisioningController
{
    public function __construct(
        private AuthorizerProvisioningQueryService $query,
        private AuthorizerProvisioningRetryService $retryService,
        private RequestContext $context,
        private OpenPlatformAdminGuard $guard,
    ) {
    }

    public function show(string $id): Json
    {
        $this->guard->require(OpenPlatformPermission::READ);
        $tenantId = $this->tenantId();
        $result = $this->query->get($id, $tenantId);

        return ApiResponse::success($this->context, $this->serialize($result));
    }

    public function retry(string $id): Json
    {
        $this->guard->require(OpenPlatformPermission::RETRY_PROVISION);
        $tenantId = $this->tenantId();
        $result = $this->retryService->retry(
            $id,
            $tenantId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return ApiResponse::success($this->context, [
            'id' => $result->id(),
            'status' => $result->status()->value,
            'version' => $result->version(),
        ])->code(202);
    }

    private function tenantId(): string
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId === null || trim($tenantId) === '') {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Trusted administrator Tenant context is required.', 401);
        }
        return $tenantId;
    }

    /** @return array<string,mixed> */
    private function serialize(AuthorizerProvisioning $result): array
    {
        return [
            'id' => $result->id(),
            'component_platform_id' => $result->componentPlatformId(),
            'authorizer_app_id' => $result->authorizerAppId(),
            'account_type' => $result->accountType()?->value,
            'status' => $result->status()->value,
            'metadata_version' => $result->metadataVersion(),
            'quota_resource_key' => $result->quotaResourceKey(),
            'quota_consume_entry_id' => $result->quotaConsumeEntryId(),
            'quota_release_entry_id' => $result->quotaReleaseEntryId(),
            'account_id' => $result->accountId(),
            'last_error_code' => $result->lastErrorCode(),
            'last_error_stage' => $result->lastErrorStage(),
            'version' => $result->version(),
            'updated_at' => $result->updatedAt()->format(DATE_ATOM),
            'completed_at' => $result->completedAt()?->format(DATE_ATOM),
        ];
    }
}
