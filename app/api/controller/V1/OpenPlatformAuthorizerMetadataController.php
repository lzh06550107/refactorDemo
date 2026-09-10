<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\http\ApiResponse;
use app\openplatform\application\AuthorizerMetadataRefreshService;
use app\openplatform\application\OpenPlatformAdminGuard;
use app\openplatform\application\OpenPlatformAudit;
use app\openplatform\domain\OpenPlatformPermission;
use DateTimeImmutable;
use DateTimeZone;
use think\response\Json;

final readonly class OpenPlatformAuthorizerMetadataController
{
    public function __construct(
        private AuthorizerMetadataRefreshService $refreshService,
        private RequestContext $context,
        private OpenPlatformAdminGuard $guard,
        private OpenPlatformAudit $audit,
    ) {
    }

    public function refresh(string $componentPlatformId, string $authorizerAppId): Json
    {
        $this->guard->require(OpenPlatformPermission::REFRESH_METADATA);
        $tenantId = $this->context->tenantId();
        if ($tenantId === null || trim($tenantId) === '') {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Trusted administrator Tenant context is required.', 401);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $metadata = $this->refreshService->refresh(
            $tenantId,
            $componentPlatformId,
            $authorizerAppId,
            $now,
        );

        $principal = $this->context->principal();
        if ($principal !== null) {
            $this->audit->admin(
                $principal->id(),
                $tenantId,
                null,
                OpenPlatformAudit::METADATA_REFRESH,
                $this->context->requestId(),
                $this->context->traceId(),
                [
                    'component_platform_id' => $metadata->componentPlatformId(),
                    'authorizer_app_id' => $metadata->authorizerAppId(),
                    'metadata_version' => $metadata->version(),
                    'account_type' => $metadata->accountType()->value,
                ],
                $now,
            );
        }

        return ApiResponse::success($this->context, [
            'component_platform_id' => $metadata->componentPlatformId(),
            'authorizer_app_id' => $metadata->authorizerAppId(),
            'account_type' => $metadata->accountType()->value,
            'nick_name' => $metadata->nickName(),
            'version' => $metadata->version(),
            'provider_fetched_at' => $metadata->providerFetchedAt()->format(DATE_ATOM),
        ]);
    }
}
