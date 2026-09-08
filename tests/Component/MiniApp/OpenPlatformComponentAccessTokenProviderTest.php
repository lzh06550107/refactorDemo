<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\miniapp\contract\ComponentAccessTokenProvider as MiniAppComponentAccessTokenProvider;
use app\miniapp\infrastructure\OpenPlatformComponentAccessTokenProvider;
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

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$tokens = new class(new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token', new DateTimeImmutable('-1 minute'), new DateTimeImmutable('+1 hour'), 3)) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $this->token; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { throw new LogicException('unused'); }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new LogicException('unused'); }
};
$leases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new LogicException('cached token must avoid lease'); }
    public function release(string $componentPlatformId, string $holderId): void { throw new LogicException('cached token must avoid lease'); }
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { throw new LogicException('cached token must avoid secrets'); }
};
$client = new class implements ComponentTokenClient {
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new LogicException('cached token must avoid provider HTTP'); }
};
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$service = new ComponentAccessTokenService($platforms, $tickets, $tokens, $leases, $credentials, $client, $audit);
$adapter = new OpenPlatformComponentAccessTokenProvider($service);
expectTrue($adapter instanceof MiniAppComponentAccessTokenProvider, 'R8B adapter implements the existing R8A port');
$result = $adapter->forPlatform('platform-1');
expectSame('wx-component-1', $result->componentAppId(), 'R8A adapter preserves component AppId');
expectSame('component-token', $result->accessToken(), 'R8A adapter returns R8B component token without repository access');
