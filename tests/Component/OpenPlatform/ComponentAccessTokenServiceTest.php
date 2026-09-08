<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use DateTimeImmutable;
use DateTimeZone;

$now = new DateTimeImmutable('2026-09-08T08:00:00Z', new DateTimeZone('UTC'));
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$ticket = new ComponentVerifyTicket('platform-1', 'verify-ticket', hash('sha256', 'verify-ticket'), $now->modify('-1 minute'), $now->modify('-1 minute'), 1);
$tickets = new class($ticket) implements ComponentTicketRepository {
    public function __construct(public ?ComponentVerifyTicket $ticket) {}
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return $this->ticket; }
    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new LogicException('unused'); }
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { return $credentialRef === 'secret/app' ? 'app-secret' : 'other-secret'; }
};
$audit = new class implements AuditLogger {
    public array $events = [];
    public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); }
};

$make = static function (?ComponentAccessToken $current, bool $allowLease, mixed $providerResult, bool $casSucceeds = true) use ($platforms, $tickets, $credentials, $audit): array {
    $tokens = new class($current, $casSucceeds) implements ComponentTokenRepository {
        public int $casCalls = 0;
        public function __construct(public ?ComponentAccessToken $token, public bool $casSucceeds) {}
        public function current(string $componentPlatformId): ?ComponentAccessToken { return $this->token; }
        public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool
        {
            $this->casCalls++;
            if (!$this->casSucceeds) { return false; }
            $version = $this->token?->version() ?? 0;
            if ($version !== $expectedVersion) { return false; }
            $this->token = $token;
            return true;
        }
    };
    $leases = new class($allowLease) implements ComponentRefreshLeaseRepository {
        public int $acquireCalls = 0;
        public int $releaseCalls = 0;
        public function __construct(public bool $allow) {}
        public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease
        {
            $this->acquireCalls++;
            return $this->allow ? new ComponentTokenRefreshLease($componentPlatformId, $holderId, $now->modify('+' . $leaseSeconds . ' seconds'), 1) : null;
        }
        public function release(string $componentPlatformId, string $holderId): void { $this->releaseCalls++; }
    };
    $client = new class($providerResult) implements ComponentTokenClient {
        public int $calls = 0;
        public function __construct(public mixed $result) {}
        public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse
        {
            $this->calls++;
            if ($this->result instanceof Throwable) { throw $this->result; }
            return $this->result;
        }
    };
    $service = new ComponentAccessTokenService($platforms, $tickets, $tokens, $leases, $credentials, $client, $audit);
    return [$service, $tokens, $leases, $client];
};

$valid = new ComponentAccessToken('platform-1', 'wx-component-1', 'old-token', $now->modify('-1 hour'), $now->modify('+10 minutes'), 1);
[$service, $tokens, $leases, $client] = $make($valid, true, new ComponentTokenResponse('unused', 7200));
$result = $service->forPlatform('platform-1', $now);
expectSame('old-token', $result->accessToken(), 'token outside refresh skew returns cached token');
expectSame(0, $client->calls, 'token outside refresh skew performs no provider HTTP');
expectSame(0, $leases->acquireCalls, 'token outside refresh skew acquires no lease');

$nearExpiry = new ComponentAccessToken('platform-1', 'wx-component-1', 'near-token', $now->modify('-1 hour'), $now->modify('+200 seconds'), 1);
[$service, $tokens, $leases, $client] = $make($nearExpiry, true, new ComponentTokenResponse('fresh-token', 7200));
$result = $service->forPlatform('platform-1', $now);
expectSame('fresh-token', $result->accessToken(), 'lease winner refreshes token inside skew');
expectSame(2, $result->version(), 'successful refresh advances token version');
expectSame(1, $client->calls, 'lease winner performs exactly one provider refresh');
expectSame(1, $tokens->casCalls, 'lease winner persists by CAS once');
expectSame(1, $leases->releaseCalls, 'lease winner releases holder-scoped lease');

[$service, $tokens, $leases, $client] = $make($nearExpiry, false, new ComponentTokenResponse('unused', 7200));
$result = $service->forPlatform('platform-1', $now);
expectSame('near-token', $result->accessToken(), 'lease loser can use still-unexpired old token');
expectSame(0, $client->calls, 'lease loser does no provider HTTP');

$expired = new ComponentAccessToken('platform-1', 'wx-component-1', 'expired-token', $now->modify('-2 hours'), $now->modify('-1 second'), 1);
[$service] = $make($expired, false, new ComponentTokenResponse('unused', 7200));
try {
    $service->forPlatform('platform-1', $now);
    throw new RuntimeException('lease loser with expired token must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::SERVICE_UNAVAILABLE, $e->errorCode(), 'busy refresh with no usable token maps to SERVICE_UNAVAILABLE');
    expectSame(503, $e->httpStatus(), 'busy refresh with no usable token maps to 503');
}

$providerFailure = new AppException(ErrorCode::BAD_GATEWAY, 'OpenPlatform token provider unavailable.', 502);
[$service] = $make($nearExpiry, true, $providerFailure);
$result = $service->forPlatform('platform-1', $now);
expectSame('near-token', $result->accessToken(), 'provider failure preserves still-valid old token');

[$service] = $make($expired, true, $providerFailure);
try {
    $service->forPlatform('platform-1', $now);
    throw new RuntimeException('provider failure with expired token must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'provider failure with no usable old token maps to BAD_GATEWAY');
    expectSame(502, $e->httpStatus(), 'provider failure with no usable old token maps to 502');
}

$originalTicket = $tickets->ticket;
$tickets->ticket = null;
[$service] = $make(null, true, new ComponentTokenResponse('unused', 7200));
try {
    $service->forPlatform('platform-1', $now);
    throw new RuntimeException('missing verify ticket must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::SERVICE_UNAVAILABLE, $e->errorCode(), 'missing verify ticket maps to SERVICE_UNAVAILABLE');
    expectSame(503, $e->httpStatus(), 'missing verify ticket maps to 503');
}
$tickets->ticket = $originalTicket;
