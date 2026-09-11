<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\application\ComponentTicketService;
use modules\openplatform\application\OpenPlatformEventService;
use modules\openplatform\contract\ComponentCredentialProvider;
use modules\openplatform\contract\ComponentEventInboxRepository;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\contract\ComponentTicketRepository;
use modules\openplatform\domain\ComponentPlatform;
use modules\openplatform\domain\ComponentTicketWriteResult;
use modules\openplatform\domain\ComponentVerifyTicket;
use modules\openplatform\security\WechatComponentCallbackAuthenticator;
use modules\openplatform\security\WechatComponentEnvelopeParser;
use modules\openplatform\security\WechatComponentMessageDecryptor;
use modules\openplatform\security\WechatComponentSignatureVerifier;

$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$rawKey = random_bytes(32);
$encodingKey = rtrim(base64_encode($rawKey), '=');
$credentials = new class($encodingKey) implements ComponentCredentialProvider {
    public function __construct(private string $encodingKey) {}
    public function secretFor(string $credentialRef): string
    {
        return match ($credentialRef) {
            'secret/verify' => 'verify-token',
            'secret/aes' => $this->encodingKey,
            'secret/app' => 'app-secret',
            default => throw new RuntimeException('unexpected secret ref'),
        };
    }
};
$authenticator = new WechatComponentCallbackAuthenticator($platforms, $credentials, new WechatComponentSignatureVerifier(), new WechatComponentEnvelopeParser(), new WechatComponentMessageDecryptor(), 300);
$eventInbox = new class implements ComponentEventInboxRepository {
    public array $payloads = [];
    public int $firstAccepts = 0;
    public function accept(string $componentPlatformId, string $replayKey, string $payloadHash, string $infoType, DateTimeImmutable $sourceTimestamp, DateTimeImmutable $receivedAt): bool
    {
        $key = $componentPlatformId . ':' . $replayKey;
        if (isset($this->payloads[$key])) {
            if (!hash_equals($this->payloads[$key], $payloadHash)) {
                throw new AppException(ErrorCode::CONFLICT, 'Replay identity payload mismatch.', 409);
            }
            return false;
        }
        $this->payloads[$key] = $payloadHash;
        $this->firstAccepts++;
        return true;
    }
};
$tickets = new class implements ComponentTicketRepository {
    public array $inbox = [];
    public ?ComponentVerifyTicket $ticket = null;
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return $this->ticket; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult
    {
        if (isset($this->inbox[$replayKey])) {
            return new ComponentTicketWriteResult(true, false, $this->ticket?->version() ?? 0);
        }
        $this->inbox[$replayKey] = $payloadHash;
        $version = ($this->ticket?->version() ?? 0) + 1;
        $this->ticket = $incoming->withVersion($version);
        return new ComponentTicketWriteResult(false, false, $version);
    }
};
$audit = new class implements AuditLogger {
    public array $events = [];
    public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); }
};
$service = new OpenPlatformEventService($authenticator, $eventInbox, new ComponentTicketService($authenticator, $tickets, $audit));

$encrypt = static function (string $xml, string $receiver, string $key): string {
    $frame = random_bytes(16) . pack('N', strlen($xml)) . $xml . $receiver;
    $pad = 32 - (strlen($frame) % 32);
    if ($pad === 0) { $pad = 32; }
    $frame .= str_repeat(chr($pad), $pad);
    $cipher = openssl_encrypt($frame, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
    if (!is_string($cipher)) { throw new RuntimeException('fixture encryption failed'); }
    return base64_encode($cipher);
};
$callback = static function (string $innerXml, int $timestamp, string $nonce) use ($encrypt, $rawKey): array {
    $encrypted = $encrypt($innerXml, 'wx-component-1', $rawKey);
    $parts = ['verify-token', (string) $timestamp, $nonce, $encrypted];
    sort($parts, SORT_STRING);
    return ['raw' => '<xml><Encrypt><![CDATA[' . $encrypted . ']]></Encrypt></xml>', 'signature' => sha1(implode('', $parts))];
};

$now = new DateTimeImmutable('2026-09-08T09:15:00Z');
$ts = $now->getTimestamp();
$authorized = $callback('<xml><AppId>wx-component-1</AppId><InfoType>authorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid><AuthorizationCode>auth-code-1</AuthorizationCode><PreAuthCode>pre-auth-1</PreAuthCode></xml>', $ts, 'nonce-authorized');
$service->ingest('platform-1', $authorized['raw'], (string) $ts, 'nonce-authorized', $authorized['signature'], $now, 'req-1', 'trace-1');
expectSame(1, $eventInbox->firstAccepts, 'first authenticated lifecycle event passes unified replay inbox');
$service->ingest('platform-1', $authorized['raw'], (string) $ts, 'nonce-authorized', $authorized['signature'], $now, 'req-2', 'trace-2');
expectSame(1, $eventInbox->firstAccepts, 'exact authenticated lifecycle event replay is semantic duplicate');

$conflict = $callback('<xml><AppId>wx-component-1</AppId><InfoType>authorized</InfoType><AuthorizerAppid>wx-authorizer-2</AuthorizerAppid><AuthorizationCode>auth-code-2</AuthorizationCode></xml>', $ts, 'nonce-authorized');
try {
    $service->ingest('platform-1', $conflict['raw'], (string) $ts, 'nonce-authorized', $conflict['signature'], $now, 'req-3', 'trace-3');
    throw new RuntimeException('same replay identity with different encrypted payload must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'unified event replay payload mismatch maps to CONFLICT');
    expectSame(409, $e->httpStatus(), 'unified event replay payload mismatch maps to 409');
}

$ticket = $callback('<xml><AppId>wx-component-1</AppId><InfoType>component_verify_ticket</InfoType><ComponentVerifyTicket>ticket-through-events</ComponentVerifyTicket></xml>', $ts, 'nonce-ticket-events');
$service->ingest('platform-1', $ticket['raw'], (string) $ts, 'nonce-ticket-events', $ticket['signature'], $now, 'req-4', 'trace-4');
expectSame('ticket-through-events', $tickets->ticket?->ticket(), '/events dispatches authenticated ticket through existing ticket persistence');
expectSame(1, $eventInbox->firstAccepts, 'ticket replay remains authoritative in existing R8B ticket repository rather than double-writing generic inbox');
