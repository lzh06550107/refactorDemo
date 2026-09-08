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
use app\openplatform\security\WechatComponentEnvelopeParser;
use app\openplatform\security\WechatComponentMessageDecryptor;
use app\openplatform\security\WechatComponentSignatureVerifier;
use DateTimeImmutable;
use DateTimeZone;

$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform
    {
        return $componentPlatformId === $this->platform->id() ? $this->platform : null;
    }
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
        if ($this->ticket === null || $incoming->sourceTimestamp() > $this->ticket->sourceTimestamp()) {
            $version = ($this->ticket?->version() ?? 0) + 1;
            $this->ticket = $incoming->withVersion($version);
            return new ComponentTicketWriteResult(false, false, $version);
        }
        if ($incoming->sourceTimestamp() == $this->ticket->sourceTimestamp()) {
            if (!hash_equals($incoming->ticketHash(), $this->ticket->ticketHash())) {
                throw new AppException(ErrorCode::CONFLICT, 'Ticket timestamp payload mismatch.', 409);
            }
            return new ComponentTicketWriteResult(true, false, $this->ticket->version());
        }
        return new ComponentTicketWriteResult(false, true, $this->ticket->version());
    }
};
$audit = new class implements AuditLogger {
    public array $events = [];
    public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); }
};
$service = new ComponentTicketService(
    $platforms,
    $credentials,
    new WechatComponentSignatureVerifier(),
    new WechatComponentEnvelopeParser(),
    new WechatComponentMessageDecryptor(),
    $tickets,
    $audit,
);

$encrypt = static function (string $xml, string $receiver, string $key): string {
    $frame = random_bytes(16) . pack('N', strlen($xml)) . $xml . $receiver;
    $pad = 32 - (strlen($frame) % 32);
    if ($pad === 0) { $pad = 32; }
    $frame .= str_repeat(chr($pad), $pad);
    $cipher = openssl_encrypt($frame, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
    if (!is_string($cipher)) { throw new RuntimeException('fixture encryption failed'); }
    return base64_encode($cipher);
};
$callback = static function (string $ticket, int $timestamp, string $nonce, string $innerAppId = 'wx-component-1', string $infoType = 'component_verify_ticket') use ($encrypt, $rawKey): array {
    $xml = '<xml><AppId><![CDATA[' . $innerAppId . ']]></AppId><InfoType><![CDATA[' . $infoType . ']]></InfoType><ComponentVerifyTicket><![CDATA[' . $ticket . ']]></ComponentVerifyTicket></xml>';
    $encrypted = $encrypt($xml, 'wx-component-1', $rawKey);
    $parts = ['verify-token', (string) $timestamp, $nonce, $encrypted];
    sort($parts, SORT_STRING);
    return [
        'raw' => '<xml><AppId>wx-diagnostic</AppId><Encrypt><![CDATA[' . $encrypted . ']]></Encrypt></xml>',
        'encrypted' => $encrypted,
        'signature' => sha1(implode('', $parts)),
    ];
};
$now = new DateTimeImmutable('2026-09-08T08:00:00Z', new DateTimeZone('UTC'));
$ts = $now->getTimestamp();
$first = $callback('ticket-new', $ts, 'nonce-1');
$service->ingest('platform-1', $first['raw'], (string) $ts, 'nonce-1', $first['signature'], $now, 'req-1', 'trace-1');
expectSame('ticket-new', $tickets->ticket?->ticket(), 'authenticated callback updates latest ticket');
expectSame(1, $tickets->ticket?->version(), 'first authenticated ticket starts version 1');
expectSame(1, count($audit->events), 'accepted callback emits audit');
expectSame(null, $audit->events[0]['tenant_id'], 'platform audit is not tenant-owned');
expectSame(null, $audit->events[0]['account_id'], 'platform audit is not account-owned');
expectSame('external:wechat-openplatform', $audit->events[0]['actor_id'], 'ticket actor is external OpenPlatform');
$auditJson = json_encode($audit->events[0]);
expectTrue(is_string($auditJson) && !str_contains($auditJson, 'ticket-new') && !str_contains($auditJson, $first['encrypted']), 'audit contains no ticket/ciphertext');

$service->ingest('platform-1', $first['raw'], (string) $ts, 'nonce-1', $first['signature'], $now, 'req-2', 'trace-2');
expectSame(1, $tickets->ticket?->version(), 'exact replay is semantic duplicate without version bump');
expectSame(2, count($audit->events), 'semantic duplicate remains successful and audited');

// Same platform/timestamp/nonce derives the same replay identity. A fresh encrypted
// payload under that replay identity must be rejected rather than accepted as duplicate.
$replayConflict = $callback('ticket-replay-conflict', $ts, 'nonce-1');
try {
    $service->ingest('platform-1', $replayConflict['raw'], (string) $ts, 'nonce-1', $replayConflict['signature'], $now, 'req-replay-conflict', 'trace-replay-conflict');
    throw new RuntimeException('same replay identity with different encrypted payload must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'replay payload mismatch maps to CONFLICT');
    expectSame(409, $e->httpStatus(), 'replay payload mismatch maps to HTTP 409');
}
expectSame('ticket-new', $tickets->ticket?->ticket(), 'replay payload conflict cannot mutate current ticket');
expectSame(2, count($audit->events), 'replay payload conflict emits no success audit');

// A different nonce avoids replay collision, but the signed source timestamp remains
// equal to the current ticket. Different authenticated ticket content must still conflict.
$sameTimestampConflict = $callback('ticket-same-timestamp-conflict', $ts, 'nonce-same-ts');
try {
    $service->ingest('platform-1', $sameTimestampConflict['raw'], (string) $ts, 'nonce-same-ts', $sameTimestampConflict['signature'], $now, 'req-same-ts', 'trace-same-ts');
    throw new RuntimeException('same source timestamp with different ticket must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'same-timestamp ticket mismatch maps to CONFLICT');
    expectSame(409, $e->httpStatus(), 'same-timestamp ticket mismatch maps to HTTP 409');
}
expectSame('ticket-new', $tickets->ticket?->ticket(), 'same-timestamp conflict cannot overwrite current ticket');
expectSame(1, $tickets->ticket?->version(), 'same-timestamp conflict cannot bump current ticket version');
expectSame(2, count($audit->events), 'same-timestamp conflict emits no success audit');

$older = $callback('ticket-old', $ts - 1, 'nonce-old');
$service->ingest('platform-1', $older['raw'], (string) ($ts - 1), 'nonce-old', $older['signature'], $now, 'req-3', 'trace-3');
expectSame('ticket-new', $tickets->ticket?->ticket(), 'older authenticated callback cannot overwrite current ticket');

foreach ([
    [$callback('bad-app', $ts, 'nonce-app', 'wx-other'), 'nonce-app', ErrorCode::FORBIDDEN, 403],
    [$callback('bad-type', $ts, 'nonce-type', 'wx-component-1', 'authorized'), 'nonce-type', ErrorCode::INVALID_ARGUMENT, 400],
] as [$case, $nonce, $errorCode, $status]) {
    try {
        $service->ingest('platform-1', $case['raw'], (string) $ts, $nonce, $case['signature'], $now, 'req-x', 'trace-x');
        throw new RuntimeException('invalid authenticated ticket callback must fail');
    } catch (AppException $e) {
        expectSame($errorCode, $e->errorCode(), 'ticket validation error code is stable');
        expectSame($status, $e->httpStatus(), 'ticket validation HTTP status is stable');
    }
}
