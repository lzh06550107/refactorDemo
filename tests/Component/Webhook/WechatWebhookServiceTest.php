<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\webhook\application\WechatWebhookService;
use app\webhook\contract\ProviderEventDispatcher;
use app\webhook\contract\WebhookInboxRepository;
use app\webhook\domain\WechatWebhookEvent;
use app\webhook\domain\WechatWebhookRequest;
use app\webhook\domain\WebhookInboxResult;
use app\webhook\security\WechatSignatureVerifier;

final class InMemoryWebhookInboxRepository implements WebhookInboxRepository
{
    private array $rows = [];
    public int $markDispatchedCalls = 0;

    public function receive(WechatWebhookEvent $event, \DateTimeImmutable $receivedAt): WebhookInboxResult
    {
        $key = $event->providerType() . '|' . $event->providerAccountId() . '|' . $event->providerEventKey();
        if (isset($this->rows[$key])) {
            $row = $this->rows[$key];
            if ($row['hash'] !== $event->rawBodyHash()) {
                throw new AppException(ErrorCode::CONFLICT, 'Webhook replay payload conflicts with prior event.', 409);
            }
            return WebhookInboxResult::replayed($row['id']);
        }

        $id = 'inbox-' . (count($this->rows) + 1);
        $this->rows[$key] = ['id' => $id, 'hash' => $event->rawBodyHash(), 'dispatched' => false];
        return WebhookInboxResult::accepted($id);
    }

    public function markDispatched(string $inboxId, \DateTimeImmutable $dispatchedAt): void
    {
        $this->markDispatchedCalls++;
        foreach ($this->rows as &$row) {
            if ($row['id'] === $inboxId) {
                $row['dispatched'] = true;
                return;
            }
        }
        throw new RuntimeException('unknown inbox row');
    }
}

final class CountingProviderEventDispatcher implements ProviderEventDispatcher
{
    public int $calls = 0;
    public array $events = [];

    public function dispatch(WechatWebhookEvent $event): void
    {
        $this->calls++;
        $this->events[] = $event;
    }
}

$signatureFor = static function (string $timestamp, string $nonce = 'nonce-123'): string {
    $parts = ['wechat-token', $timestamp, $nonce];
    sort($parts, SORT_STRING);
    return sha1(implode('', $parts));
};

$now = new \DateTimeImmutable('@1788840000');
$timestamp = '1788840000';
$xml = '<xml><ToUserName><![CDATA[gh_test]]></ToUserName><FromUserName><![CDATA[user-openid]]></FromUserName><CreateTime>1788840000</CreateTime><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[hello]]></Content><MsgId>90001</MsgId></xml>';
$repo = new InMemoryWebhookInboxRepository();
$dispatcher = new CountingProviderEventDispatcher();
$service = new WechatWebhookService(new WechatSignatureVerifier(), $repo, $dispatcher);
$request = new WechatWebhookRequest(
    tenantId: 'tenant-1',
    providerAccountId: 'provider-account-1',
    token: 'wechat-token',
    signature: $signatureFor($timestamp),
    timestamp: $timestamp,
    nonce: 'nonce-123',
    rawBody: $xml,
);

$first = $service->handle($request, $now);
expectSame(false, $first->duplicate(), 'first webhook delivery must be accepted');
expectSame(1, $dispatcher->calls, 'first webhook delivery must dispatch once');
expectSame('wechat', $dispatcher->events[0]->providerType(), 'provider type must remain explicit');
expectSame('provider-account-1', $dispatcher->events[0]->providerAccountId(), 'provider account scope must be preserved');
expectSame('msg:90001', $dispatcher->events[0]->providerEventKey(), 'MsgId must form deterministic provider event key');
expectSame(hash('sha256', $xml), $dispatcher->events[0]->rawBodyHash(), 'raw body must be represented only by SHA-256 hash in event metadata');
expectSame(1, $repo->markDispatchedCalls, 'accepted event must be marked dispatched after dispatch');

$duplicate = $service->handle($request, $now);
expectSame(true, $duplicate->duplicate(), 'same event key and body hash must ACK as duplicate');
expectSame(1, $dispatcher->calls, 'duplicate webhook must not dispatch again');
expectSame(1, $repo->markDispatchedCalls, 'duplicate webhook must not be marked dispatched again');

$conflictingXml = str_replace('hello', 'changed', $xml);
$conflictingRequest = new WechatWebhookRequest(
    tenantId: 'tenant-1',
    providerAccountId: 'provider-account-1',
    token: 'wechat-token',
    signature: $signatureFor($timestamp),
    timestamp: $timestamp,
    nonce: 'nonce-123',
    rawBody: $conflictingXml,
);
try {
    $service->handle($conflictingRequest, $now);
    throw new RuntimeException('conflicting webhook replay must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'conflicting replay error code');
    expectSame(409, $e->httpStatus(), 'conflicting replay status');
}
expectSame(1, $dispatcher->calls, 'conflicting replay must not dispatch');

$badSignatureRequest = new WechatWebhookRequest(
    tenantId: 'tenant-1',
    providerAccountId: 'provider-account-1',
    token: 'wechat-token',
    signature: str_repeat('0', 40),
    timestamp: $timestamp,
    nonce: 'nonce-123',
    rawBody: '<not-valid-xml',
);
try {
    $service->handle($badSignatureRequest, $now);
    throw new RuntimeException('invalid signature must fail before XML parsing');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'signature verification must happen before parsing');
}
