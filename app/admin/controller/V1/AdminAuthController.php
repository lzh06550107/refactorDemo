<?php

declare(strict_types=1);

namespace app\admin\controller\V1;

use app\admin\support\AdminCookiePolicy;
use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\http\ApiResponse;
use DateTimeImmutable;
use DateTimeZone;
use modules\iam\application\AuthenticateAdmin;
use modules\iam\application\CreateAdminSession;
use modules\iam\application\LogoutAdminSession;
use modules\iam\contract\AdminUserRepository;
use modules\iam\domain\AdminUserStatus;
use think\App;
use think\Request;
use think\response\Json;

final readonly class AdminAuthController
{
    public function __construct(
        private RequestContext $context,
        private AuthenticateAdmin $authenticateAdmin,
        private CreateAdminSession $createAdminSession,
        private LogoutAdminSession $logoutAdminSession,
        private AdminUserRepository $adminUsers,
        private App $app,
    ) {
    }

    public function csrf(): Json
    {
        $policy = $this->cookiePolicy();
        $ttlSeconds = $this->ttlSeconds();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ApiResponse::success($this->context, ['ready' => true])
            ->cookie($policy->csrfName(), $token, $policy->csrfOptions($ttlSeconds));
    }

    public function login(Request $request): Json
    {
        $now = $this->now();
        $user = $this->authenticateAdmin->execute(
            (string) $request->post('username', ''),
            (string) $request->post('password', ''),
            $now,
        );

        $ttlSeconds = $this->ttlSeconds();
        $issued = $this->createAdminSession->execute($user, $now, $ttlSeconds);
        $policy = $this->cookiePolicy();

        return ApiResponse::success($this->context, [
            'id' => $user->id(),
            'username' => $user->username(),
        ])->cookie(
            $policy->sessionName(),
            $issued->rawToken(),
            $policy->sessionOptions($ttlSeconds),
        );
    }

    public function me(): Json
    {
        $principal = $this->context->principal();
        if ($principal === null || $principal->type() !== 'admin') {
            throw $this->unauthorized();
        }

        $user = $this->adminUsers->findById($principal->id());
        if ($user === null || $user->status() !== AdminUserStatus::ACTIVE || $user->isExpired($this->now())) {
            throw $this->unauthorized();
        }

        return ApiResponse::success($this->context, [
            'id' => $user->id(),
            'username' => $user->username(),
        ]);
    }

    public function logout(Request $request): Json
    {
        $policy = $this->cookiePolicy();
        $this->logoutAdminSession->execute((string) $request->cookie($policy->sessionName(), ''));

        $expiredAt = new DateTimeImmutable('@1');
        $sessionOptions = $policy->sessionOptions(0);
        $sessionOptions['expire'] = $expiredAt;
        $csrfOptions = $policy->csrfOptions(0);
        $csrfOptions['expire'] = $expiredAt;

        return ApiResponse::success($this->context, ['logged_out' => true])
            ->cookie($policy->sessionName(), '', $sessionOptions)
            ->cookie($policy->csrfName(), '', $csrfOptions);
    }

    private function cookiePolicy(): AdminCookiePolicy
    {
        return new AdminCookiePolicy((bool) $this->app->config->get('weplatform.admin_cookie_secure', false));
    }

    private function ttlSeconds(): int
    {
        return (int) $this->app->config->get('weplatform.admin_session_ttl_seconds', 28800);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function unauthorized(): AppException
    {
        return new AppException(
            ErrorCode::UNAUTHORIZED,
            'Administrator session is invalid or expired.',
            401,
        );
    }
}
