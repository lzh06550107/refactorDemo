<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use app\admin\controller\V1\AdminAuthController;
use app\common\context\Principal;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\security\SecretValue;
use modules\iam\application\AuthenticateAdmin;
use modules\iam\application\CreateAdminSession;
use modules\iam\application\LogoutAdminSession;
use modules\iam\contract\AdminCredentialRepository;
use modules\iam\contract\AdminSessionStore;
use modules\iam\contract\AdminUserRepository;
use modules\iam\contract\SessionIdGenerator;
use modules\iam\contract\SessionTokenGenerator;
use modules\iam\domain\AdminCredential;
use modules\iam\domain\AdminSession;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;
use modules\iam\security\SessionTokenHasher;
use think\App;
use think\Request;
use think\Response;

$root = dirname(__DIR__, 3);
$user = new AdminUser('admin-http-1', 'root', AdminUserStatus::ACTIVE, null);
$credential = new AdminCredential($user, password_hash('correct-password', PASSWORD_DEFAULT));
$credentialRepository = new class($credential) implements AdminCredentialRepository {
    public function __construct(private AdminCredential $credential)
    {
    }

    public function findByUsername(string $username): ?AdminCredential
    {
        return $username === $this->credential->user()->username() ? $this->credential : null;
    }
};
$userRepository = new class($user) implements AdminUserRepository {
    public function __construct(private AdminUser $user)
    {
    }

    public function findById(string $id): ?AdminUser
    {
        return $id === $this->user->id() ? $this->user : null;
    }
};
$sessionStore = new class implements AdminSessionStore {
    public ?AdminSession $saved = null;
    public ?string $deletedTokenHash = null;

    public function save(AdminSession $session): void
    {
        $this->saved = $session;
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        $this->deletedTokenHash = $tokenHash;
    }
};
$tokenGenerator = new class implements SessionTokenGenerator {
    public function generate(): string
    {
        return 'raw-http-session-token';
    }
};
$idGenerator = new class implements SessionIdGenerator {
    public function generate(): string
    {
        return 'session-http-1';
    }
};
$hasher = new SessionTokenHasher(new SecretValue('http-session-pepper'));
$authenticate = new AuthenticateAdmin($credentialRepository);
$createSession = new CreateAdminSession($sessionStore, $tokenGenerator, $idGenerator, $hasher);
$logoutSession = new LogoutAdminSession($sessionStore, $hasher);

$app = new App($root);
$app->config->set([
    'admin_cookie_secure' => false,
    'admin_session_ttl_seconds' => 3600,
], 'weplatform');

$anonymousContext = new RequestContext(
    'request-auth-http-1',
    'trace-auth-http-1',
    RuntimeType::ADMIN,
    null,
    null,
    null,
    null,
    'zh-CN',
    '127.0.0.1',
);
$authenticatedContext = new RequestContext(
    'request-auth-http-2',
    'trace-auth-http-2',
    RuntimeType::ADMIN,
    null,
    null,
    null,
    new Principal($user->id(), 'admin'),
    'zh-CN',
    '127.0.0.1',
);

/** @return array<string,array{0:string,1:int,2:array<string,mixed>}> */
$cookiesOf = static function (Response $response): array {
    $property = new ReflectionProperty(Response::class, 'cookie');
    $property->setAccessible(true);
    $cookie = $property->getValue($response);
    return $cookie->getCookie();
};

$anonymousController = new AdminAuthController(
    $anonymousContext,
    $authenticate,
    $createSession,
    $logoutSession,
    $userRepository,
    $app,
);

$csrfResponse = $anonymousController->csrf();
expectSame(200, $csrfResponse->getCode(), 'csrf endpoint status');
expectSame(['ready' => true], $csrfResponse->getData()['data'] ?? null, 'csrf response exposes readiness only');
$csrfCookies = $cookiesOf($csrfResponse);
expectTrue(isset($csrfCookies['weplatform_admin_csrf']), 'csrf endpoint sets csrf cookie');
[$csrfToken, $csrfExpires, $csrfOptions] = $csrfCookies['weplatform_admin_csrf'];
expectSame(43, strlen($csrfToken), 'csrf token is Base64URL encoding of 32 random bytes');
expectTrue((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $csrfToken), 'csrf token uses Base64URL alphabet without padding');
expectSame(false, $csrfOptions['httponly'] ?? null, 'csrf cookie is readable by SPA');
expectSame('/', $csrfOptions['path'] ?? null, 'csrf cookie path is root');
expectTrue($csrfExpires > time(), 'csrf cookie has future expiry');
expectTrue(!str_contains((string) json_encode($csrfResponse->getData()), $csrfToken), 'csrf token is never returned in JSON');

$loginRequest = (new Request())->withPost([
    'username' => 'root',
    'password' => 'correct-password',
]);
$loginResponse = $anonymousController->login($loginRequest);
expectSame(200, $loginResponse->getCode(), 'login endpoint status');
expectSame(
    ['id' => 'admin-http-1', 'username' => 'root'],
    $loginResponse->getData()['data'] ?? null,
    'login response returns safe admin profile',
);
expectTrue(!str_contains((string) json_encode($loginResponse->getData()), 'raw-http-session-token'), 'raw session token is never returned in JSON');
$loginCookies = $cookiesOf($loginResponse);
expectTrue(isset($loginCookies['weplatform_admin_session']), 'login sets session cookie');
[$sessionCookieValue, $sessionExpires, $sessionOptions] = $loginCookies['weplatform_admin_session'];
expectSame('raw-http-session-token', $sessionCookieValue, 'raw session token is delivered only in cookie');
expectSame(true, $sessionOptions['httponly'] ?? null, 'session cookie is HttpOnly');
expectSame('/', $sessionOptions['path'] ?? null, 'session cookie path is root');
expectTrue($sessionExpires > time(), 'session cookie has future expiry');
expectSame($hasher->hash('raw-http-session-token'), $sessionStore->saved?->tokenHash(), 'database/session store receives only token hash');

try {
    $anonymousController->me();
    throw new RuntimeException('me without authenticated principal must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'anonymous me error code');
    expectSame(401, $e->httpStatus(), 'anonymous me http status');
}

$authenticatedController = new AdminAuthController(
    $authenticatedContext,
    $authenticate,
    $createSession,
    $logoutSession,
    $userRepository,
    $app,
);
$meResponse = $authenticatedController->me();
expectSame(
    ['id' => 'admin-http-1', 'username' => 'root'],
    $meResponse->getData()['data'] ?? null,
    'me returns safe admin profile only',
);

$logoutResponse = $authenticatedController->logout(
    (new Request())->withCookie(['weplatform_admin_session' => 'raw-http-session-token']),
);
expectSame(200, $logoutResponse->getCode(), 'logout endpoint status');
expectSame($hasher->hash('raw-http-session-token'), $sessionStore->deletedTokenHash, 'logout revokes hashed database session');
$logoutCookies = $cookiesOf($logoutResponse);
foreach (['weplatform_admin_session', 'weplatform_admin_csrf'] as $cookieName) {
    expectTrue(isset($logoutCookies[$cookieName]), 'logout expires cookie: ' . $cookieName);
    [$value, $expires, $options] = $logoutCookies[$cookieName];
    expectSame('', $value, 'logout clears cookie value: ' . $cookieName);
    expectTrue($expires < time(), 'logout cookie expiry is in the past: ' . $cookieName);
    expectSame('/', $options['path'] ?? null, 'logout preserves root path: ' . $cookieName);
}
expectSame(true, $logoutCookies['weplatform_admin_session'][2]['httponly'] ?? null, 'expired session cookie keeps HttpOnly policy');
expectSame(false, $logoutCookies['weplatform_admin_csrf'][2]['httponly'] ?? null, 'expired csrf cookie keeps readable policy');

$routeFile = $root . '/app/admin/route/app.php';
$routes = (string) file_get_contents($routeFile);
foreach ([
    "Route::get('csrf', 'V1.AdminAuthController/csrf')",
    "Route::post('login', 'V1.AdminAuthController/login')",
    "Route::get('me', 'V1.AdminAuthController/me')",
    "Route::post('logout', 'V1.AdminAuthController/logout')",
] as $route) {
    expectTrue(str_contains($routes, $route), 'admin auth route is registered: ' . $route);
}
expectTrue(str_contains($routes, "Route::group('v1/auth'"), 'admin auth endpoints are grouped under v1/auth');
expectTrue(substr_count($routes, 'AdminCsrfMiddleware::class') === 2, 'csrf middleware protects login and logout only');
expectTrue(substr_count($routes, 'AdminSessionCookieMiddleware::class') === 2, 'session middleware protects me and logout only');
