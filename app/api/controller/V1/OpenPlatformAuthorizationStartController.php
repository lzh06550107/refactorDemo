<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\http\ApiResponse;
use app\openplatform\application\AuthorizationStartService;
use app\openplatform\application\OpenPlatformAdminGuard;
use app\openplatform\application\OpenPlatformAudit;
use app\openplatform\domain\AuthorizationIntentMode;
use app\openplatform\domain\OpenPlatformPermission;
use DateTimeImmutable;
use DateTimeZone;
use think\Request;
use think\response\Json;
use ValueError;

final readonly class OpenPlatformAuthorizationStartController
{
    public function __construct(
        private AuthorizationStartService $service,
        private RequestContext $context,
        private OpenPlatformAdminGuard $guard,
        private OpenPlatformAudit $audit,
    ) {
    }

    public function create(string $componentPlatformId, Request $request): Json
    {
        $this->guard->require(OpenPlatformPermission::START);

        $tenantId = $this->context->tenantId();
        if ($tenantId === null || trim($tenantId) === '') {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Trusted administrator Tenant context is required.', 401);
        }

        $tenantAssertion = $request->param('tenantId', $request->param('tenant_id'));
        if ($tenantAssertion !== null && !hash_equals($tenantId, trim((string) $tenantAssertion))) {
            throw new AppException(ErrorCode::FORBIDDEN, 'Requested Tenant does not match trusted administrator context.', 403);
        }

        try {
            $mode = AuthorizationIntentMode::from((string) $request->param(
                'mode',
                AuthorizationIntentMode::BIND_EXISTING_ACCOUNT->value,
            ));
        } catch (ValueError) {
            $this->invalidArgument('Authorization intent mode is invalid.');
        }

        $rawTarget = $request->param('targetAccountId', $request->param('target_account_id'));
        $targetAccountId = $rawTarget === null ? null : trim((string) $rawTarget);
        if ($targetAccountId === '') {
            $targetAccountId = null;
        }
        if (
            ($mode === AuthorizationIntentMode::BIND_EXISTING_ACCOUNT && $targetAccountId === null)
            || ($mode === AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT && $targetAccountId !== null)
        ) {
            $this->invalidArgument('Authorization intent mode and target Account are inconsistent.');
        }

        $requestedAuthType = trim((string) $request->param(
            'requestedAuthType',
            $request->param('auth_type', '1'),
        ));
        if ($requestedAuthType === '') {
            $this->invalidArgument('Authorization auth type must not be empty.');
        }

        match ($mode) {
            AuthorizationIntentMode::BIND_EXISTING_ACCOUNT => $this->guard->require(
                OpenPlatformPermission::BIND,
                $targetAccountId,
            ),
            AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT => $this->guard->require(
                OpenPlatformPermission::PROVISION,
            ),
        };

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = $this->service->start(
            $componentPlatformId,
            $tenantId,
            $mode,
            $targetAccountId,
            $requestedAuthType,
            $now,
        );

        $principal = $this->context->principal();
        if ($principal !== null) {
            $this->audit->admin(
                $principal->id(),
                $tenantId,
                $targetAccountId,
                OpenPlatformAudit::AUTHORIZATION_START,
                $this->context->requestId(),
                $this->context->traceId(),
                ['component_platform_id' => $componentPlatformId],
                $now,
            );
        }

        return ApiResponse::success($this->context, [
            'state' => $result->state(),
            'authorization_url' => $result->authorizationUrl(),
            'expires_at' => $result->expiresAt()->format(DATE_ATOM),
        ]);
    }

    private function invalidArgument(string $message): never
    {
        throw new AppException(ErrorCode::INVALID_ARGUMENT, $message, 400);
    }
}
