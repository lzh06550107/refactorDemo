<?php

declare(strict_types=1);

namespace app\openplatform\security;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\domain\ComponentTicketEnvelope;
use DOMDocument;

final class WechatComponentEnvelopeParser
{
    private const MAX_RAW_BODY_BYTES = 131072;

    public function parseOuter(string $rawBody): ComponentTicketEnvelope
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_RAW_BODY_BYTES) {
            $this->reject('Invalid OpenPlatform callback XML payload.');
        }

        $document = $this->document($rawBody);
        $encrypted = $this->text($document, 'Encrypt');
        if ($encrypted === null || $encrypted === '') {
            $this->reject('Invalid OpenPlatform callback XML payload.');
        }

        $outerAppId = $this->text($document, 'AppId');
        return new ComponentTicketEnvelope($encrypted, $outerAppId === '' ? null : $outerAppId);
    }

    /** @return array{appId:string,infoType:string,ticket:string} */
    public function parseInnerTicket(string $xml): array
    {
        $document = $this->document($xml);
        $appId = $this->text($document, 'AppId');
        $infoType = $this->text($document, 'InfoType');
        $ticket = $this->text($document, 'ComponentVerifyTicket');
        if ($appId === null || $appId === '' || $infoType === null || $infoType === '' || $ticket === null || $ticket === '') {
            $this->reject('Invalid OpenPlatform ticket XML payload.');
        }

        return ['appId' => $appId, 'infoType' => $infoType, 'ticket' => $ticket];
    }

    private function document(string $xml): DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            $this->reject('Invalid OpenPlatform callback XML payload.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
            if (!$loaded || $document->doctype !== null || $document->documentElement === null) {
                $this->reject('Invalid OpenPlatform callback XML payload.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(DOMDocument $document, string $tagName): ?string
    {
        $node = $document->getElementsByTagName($tagName)->item(0);
        return $node === null ? null : trim((string) $node->textContent);
    }

    private function reject(string $message): never
    {
        throw new AppException(ErrorCode::INVALID_ARGUMENT, $message, 400);
    }
}
