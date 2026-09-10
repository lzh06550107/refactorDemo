<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
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

$now = new DateTimeImmutable('2026-09-08T08:30:00Z', new DateTimeZone('UTC'));
$platformA = new ComponentPlatform('platform-a', 'wx-component-a', 'secret/a', 'verify/a', 'aes/a', true);
$platformB = new ComponentPlatform('platform-b', 'wx-component-b', 'secret/b', 'verify/b', 'aes/b', true);
$platforms = new class($platformA, $platformB) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $a, private ComponentPlatform $b) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform
    {
        return match ($componentPlatformId) {
            'platform-a' => $this->a,
            'platform-b' => $this->b,
            default => null,
        };
    }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new LogicException('unused'); }
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { throw new LogicException('cached token must not resolve secrets'); }
};
$client = new class implements ComponentTokenClient {
    public int $calls = 0;
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse
    {
        $this->calls++;
        throw new LogicException('cached token must not call provider');
    }
};
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$leases = new class implements ComponentRefreshLeaseRepository {
    public int $calls = 0;
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease
    {
        $this->calls++;
        throw new LogicException('outside-skew token must not acquire lease');
    }
    public function release(string $componentPlatformId, string $holderId): void { throw new LogicException('unused'); }
};
$tokens = new class($now) implements ComponentTokenRepository {
    /** @var array<string,ComponentAccessToken> */
    public array $state;
    public function __construct(DateTimeImmutable $now)
    {
        $this->state = [
            'platform-a' => new ComponentAccessToken('platform-a', 'wx-component-a', 'token-a', $now->modify('-1 hour'), $now->modify('+1 hour'), 4),
            'platform-b' => new ComponentAccessToken('platform-b', 'wx-component-b', 'token-b', $now->modify('-1 hour'), $now->modify('+1 hour'), 7),
        ];
    }
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $this->state[$componentPlatformId] ?? null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { throw new LogicException('cached token must not CAS'); }
};
$service = new ComponentAccessTokenService($platforms, $tickets, $tokens, $leases, $credentials, $client, $audit);
expectSame('token-a', $service->forPlatform('platform-a', $now)->accessToken(), 'Platform A reads only Platform A token');
expectSame('token-b', $service->forPlatform('platform-b', $now)->accessToken(), 'Platform B reads only Platform B token');
expectSame(0, $client->calls, 'cross-platform cached token reads perform no provider HTTP');
expectSame(0, $leases->calls, 'cross-platform cached token reads share no lease state');

$near = new ComponentAccessToken('platform-a', 'wx-component-a', 'near-token', $now->modify('-1 hour'), $now->modify('+120 seconds'), 1);
$winner = new ComponentAccessToken('platform-a', 'wx-component-a', 'winner-token', $now, $now->modify('+2 hours'), 2);
$casTokens = new class($near, $winner) implements ComponentTokenRepository {
    public int $casCalls = 0;
    public function __construct(public ComponentAccessToken $token, private ComponentAccessToken $winner) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $componentPlatformId === 'platform-a' ? $this->token : null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool
    {
        $this->casCalls++;
        $this->token = $this->winner;
        return false;
    }
};
$ticket = new ComponentVerifyTicket('platform-a', 'verify-ticket-a', hash('sha256', 'verify-ticket-a'), $now->modify('-1 minute'), $now->modify('-1 minute'), 1);
$refreshTickets = new class($ticket) implements ComponentTicketRepository {
    public function __construct(private ComponentVerifyTicket $ticket) {}
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return $componentPlatformId === 'platform-a' ? $this->ticket : null; }
    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new LogicException('unused'); }
};
$refreshLeases = new class implements ComponentRefreshLeaseRepository {
    public int $releaseCalls = 0;
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease
    {
        return new ComponentTokenRefreshLease($componentPlatformId, $holderId, $now->modify('+' . $leaseSeconds . ' seconds'), 3);
    }
    public function release(string $componentPlatformId, string $holderId): void { $this->releaseCalls++; }
};
$refreshCredentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { return 'resolved-app-secret'; }
};
$refreshClient = new class implements ComponentTokenClient {
    public int $calls = 0;
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse
    {
        $this->calls++;
        return new ComponentTokenResponse('loser-token', 7200);
    }
};
$refreshService = new ComponentAccessTokenService($platforms, $refreshTickets, $casTokens, $refreshLeases, $refreshCredentials, $refreshClient, $audit);
$resolved = $refreshService->forPlatform('platform-a', $now);
expectSame('winner-token', $resolved->accessToken(), 'CAS loser rereads and returns the newer effective winner');
expectSame(2, $resolved->version(), 'stale refresh holder cannot overwrite newer token version');
expectSame(1, $casTokens->casCalls, 'stale winner attempts only one CAS');
expectSame(1, $refreshLeases->releaseCalls, 'stale winner still attempts holder-scoped lease release');
