<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\ComponentTicketService;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\security\WechatComponentCallbackAuthenticator;
use app\openplatform\security\WechatComponentEnvelopeParser;
use app\openplatform\security\WechatComponentMessageDecryptor;
use app\openplatform\security\WechatComponentSignatureVerifier;

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
$authenticator = new WechatComponentCallbackAuthenticator(
    $platforms,
    $credentials,
    new WechatComponentSignatureVerifier(),
    new WechatComponentEnvelopeParser(),
    new WechatComponentMessageDecryptor(),
    300,
);
$tickets = new class implements ComponentTicketRepository {
    public array $inbox = [];
    public ?ComponentVerifyTicket $ticket = null;
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return $this->ticket; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult
    {
        if (isset($this->inbox[$replayKey])) {
            if (!hash_equals($this->inbox[$replayKey], $payloadHash)) {
                throw new AppException(ErrorCode::CONFLICT, 'Replay identity payload mismatch.', 409);
            }
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
$service = new ComponentTicketService($authenticator, $tickets, $audit);

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
    return [
        'raw' => '<xml><Encrypt><![CDATA[' . $encrypted . ']]></Encrypt></xml>',
        'signature' => sha1(implode('', $parts)),
    ];
};
$now = new DateTimeImmutable('2026-09-08T09:15:00Z');
$ts = $now->getTimestamp();
$ticketFixture = $callback('<xml><AppId>wx-component-1</AppId><InfoType>component_verify_ticket</InfoType><ComponentVerifyTicket>ticket-r8b</ComponentVerifyTicket></xml>', $ts, 'nonce-ticket');

$service->ingest('platform-1', $ticketFixture['raw'], (string) $ts, 'nonce-ticket', $ticketFixture['signature'], $now, 'req-1', 'trace-1');
expectSame('ticket-r8b', $tickets->ticket?->ticket(), '/ticket compatibility persists authenticated ticket');
expectSame(1, $tickets->ticket?->version(), '/ticket first ticket is version one');
$service->ingest('platform-1', $ticketFixture['raw'], (string) $ts, 'nonce-ticket', $ticketFixture['signature'], $now, 'req-2', 'trace-2');
expectSame(1, $tickets->ticket?->version(), '/ticket exact duplicate remains idempotent');
expectSame(2, count($audit->events), '/ticket exact duplicate preserves R8B success audit semantics');

$authorizedFixture = $callback('<xml><AppId>wx-component-1</AppId><InfoType>authorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid><AuthorizationCode>auth-code</AuthorizationCode></xml>', $ts, 'nonce-authorized');
try {
    $service->ingest('platform-1', $authorizedFixture['raw'], (string) $ts, 'nonce-authorized', $authorizedFixture['signature'], $now, 'req-3', 'trace-3');
    throw new RuntimeException('/ticket compatibility route must reject non-ticket event');
} catch (AppException $e) {
    expectSame(ErrorCode::INVALID_ARGUMENT, $e->errorCode(), '/ticket non-ticket event maps to INVALID_ARGUMENT');
    expectSame(400, $e->httpStatus(), '/ticket non-ticket event maps to 400');
}
expectSame('ticket-r8b', $tickets->ticket?->ticket(), 'non-ticket callback cannot mutate ticket state');
