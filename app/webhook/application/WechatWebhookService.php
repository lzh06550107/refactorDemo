<?php

declare(strict_types=1);

namespace app\webhook\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\webhook\contract\ProviderEventDispatcher;
use app\webhook\contract\WebhookInboxRepository;
use app\webhook\domain\WechatWebhookEvent;
use app\webhook\domain\WechatWebhookRequest;
use app\webhook\domain\WebhookInboxResult;
use app\webhook\security\WechatSignatureVerifier;
use DateTimeImmutable;
use DOMDocument;

final readonly class WechatWebhookService
{
    public function __construct(
        private WechatSignatureVerifier $verifier,
        private WebhookInboxRepository $inbox,
        private ProviderEventDispatcher $dispatcher,
    ) {
    }

    public function handle(WechatWebhookRequest $request, DateTimeImmutable $now): WebhookInboxResult
    {
        $this->verifier->verify(
            $request->token(),
            $request->signature(),
            $request->timestamp(),
            $request->nonce(),
            $now,
        );

        $event = $this->parseVerifiedEvent($request);
        $result = $this->inbox->receive($event, $now);
        if ($result->duplicate()) {
            return $result;
        }

        $this->dispatcher->dispatch($event);
        $this->inbox->markDispatched($result->inboxId(), $now);

        return $result;
    }

    private function parseVerifiedEvent(WechatWebhookRequest $request): WechatWebhookEvent
    {
        $rawBody = $request->rawBody();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($rawBody, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
            if (!$loaded || $document->doctype !== null) {
                throw new AppException(ErrorCode::INVALID_ARGUMENT, 'Invalid WeChat webhook XML payload.', 400);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $msgId = $this->text($document, 'MsgId');
        if ($msgId !== null && $msgId !== '') {
            $eventKey = 'msg:' . $msgId;
        } else {
            $from = $this->text($document, 'FromUserName');
            $created = $this->text($document, 'CreateTime');
            $msgType = $this->text($document, 'MsgType');
            if ($from === null || $from === '' || $created === null || $created === '' || $msgType === null || $msgType === '') {
                throw new AppException(ErrorCode::INVALID_ARGUMENT, 'WeChat webhook payload lacks a stable event identity.', 400);
            }
            $event = $this->text($document, 'Event') ?? '';
            $eventValue = $this->text($document, 'EventKey') ?? '';
            $eventKey = 'event:' . hash('sha256', implode('|', [$from, $created, $msgType, $event, $eventValue]));
        }

        return new WechatWebhookEvent(
            $request->tenantId(),
            'wechat',
            $request->providerAccountId(),
            $eventKey,
            hash('sha256', $rawBody),
        );
    }

    private function text(DOMDocument $document, string $tagName): ?string
    {
        $node = $document->getElementsByTagName($tagName)->item(0);
        if ($node === null) {
            return null;
        }
        return trim((string) $node->textContent);
    }
}
