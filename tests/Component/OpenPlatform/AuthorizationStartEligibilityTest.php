<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizationStartService;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\contract\AuthorizerAccountEligibility;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\domain\AuthorizationIntent;
use app\openplatform\domain\AuthorizationIntentMode;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;

$platformCalls = new ArrayObject(['count' => 0]);
$platforms = new class($platformCalls) implements ComponentPlatformRepository {
    public function __construct(private ArrayObject $calls) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform
    {
        $this->calls['count'] = (int) $this->calls['count'] + 1;
        throw new RuntimeException('component token service must not run before local eligibility');
    }
};
$tokens = new class implements ComponentTokenRepository {
    public function current(string $componentPlatformId): ?ComponentAccessToken { throw new RuntimeException('component token lookup must not run'); }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { throw new RuntimeException('component token CAS must not run'); }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { throw new RuntimeException('component ticket lookup must not run'); }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('component ticket write must not run'); }
};
$leases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('component lease must not run'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { throw new RuntimeException('component credential lookup must not run'); }
};
$componentClient = new class implements ComponentTokenClient {
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('component provider must not run'); }
};
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $tokens, $leases, $credentials, $componentClient, $audit);

$authorizerClient = new class implements AuthorizerClient {
    public int $preAuthCalls = 0;
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse
    {
        $this->preAuthCalls++;
        throw new RuntimeException('provider pre-auth must not run before local eligibility');
    }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse { throw new RuntimeException('not used'); }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('not used'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse { throw new RuntimeException('not used'); }
};
$intents = new class implements AuthorizationIntentRepository {
    public int $insertCalls = 0;
    public function insert(AuthorizationIntent $intent): void { $this->insertCalls++; }
    public function findByStateHash(string $stateHash): ?AuthorizationIntent { return null; }
    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent { return null; }
    public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent { return null; }
    public function releaseClaim(string $intentId, string $holderId): void {}
    public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool { return false; }
};
$eligibility = new class implements AuthorizerAccountEligibility {
    public array $calls = [];
    public function assertTenantEligible(string $tenantId, string $componentPlatformId): void
    {
        $this->calls[] = ['tenant', $tenantId, $componentPlatformId];
        throw new AppException(ErrorCode::FORBIDDEN, 'Tenant is not eligible.', 403);
    }
    public function assertExistingAccountEligible(string $tenantId, string $accountId, string $componentPlatformId): AccountType
    {
        $this->calls[] = ['account', $tenantId, $accountId, $componentPlatformId];
        throw new AppException(ErrorCode::FORBIDDEN, 'Account is not eligible.', 403);
    }
};

$service = new AuthorizationStartService(
    $componentTokens,
    $authorizerClient,
    $intents,
    $eligibility,
    'https://example.test/api/v1/openplatform/authorization/callback',
    600,
);

try {
    $service->start(
        'platform-1',
        'tenant-1',
        AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT,
        null,
        '1',
        new DateTimeImmutable('2026-09-09T00:00:00Z'),
    );
    throw new RuntimeException('ineligible Tenant must fail before provider access');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'eligibility failure is preserved');
}
expectSame([['tenant', 'tenant-1', 'platform-1']], $eligibility->calls, 'auto mode validates trusted Tenant and component platform locally');
expectSame(0, (int) $platformCalls['count'], 'eligibility failure performs zero component token/provider work');
expectSame(0, $authorizerClient->preAuthCalls, 'eligibility failure performs zero pre-auth provider calls');
expectSame(0, $intents->insertCalls, 'eligibility failure persists no authorization intent');
