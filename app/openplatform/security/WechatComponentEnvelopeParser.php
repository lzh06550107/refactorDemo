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

    /**
     * @return array{
     *   appId:string,
     *   infoType:string,
     *   componentVerifyTicket:?string,
     *   authorizerAppId:?string,
     *   authorizationCode:?string,
     *   authorizationCodeExpiredTime:?int,
     *   preAuthCode:?string
     * }
     */
    public function parseInnerEvent(string $xml): array
    {
        $document = $this->document($xml);
        $appId = $this->text($document, 'AppId');
        $infoType = $this->text($document, 'InfoType');
        if ($appId === null || $appId === '' || $infoType === null || $infoType === '') {
            $this->reject('Invalid OpenPlatform callback event XML payload.');
        }

        $ticket = null;
        $authorizerAppId = null;
        $authorizationCode = null;
        $authorizationCodeExpiredTime = null;
        $preAuthCode = null;

        switch ($infoType) {
            case 'component_verify_ticket':
                $ticket = $this->text($document, 'ComponentVerifyTicket');
                if ($ticket === null || $ticket === '') {
                    $this->reject('Invalid OpenPlatform ticket XML payload.');
                }
                break;

            case 'authorized':
            case 'updateauthorized':
                $authorizerAppId = $this->text($document, 'AuthorizerAppid');
                $authorizationCode = $this->text($document, 'AuthorizationCode');
                $preAuthCode = $this->text($document, 'PreAuthCode');
                $expired = $this->text($document, 'AuthorizationCodeExpiredTime');
                if ($authorizerAppId === null || $authorizerAppId === '' || $authorizationCode === null || $authorizationCode === '') {
                    $this->reject('Invalid OpenPlatform authorization event XML payload.');
                }
                if ($expired !== null && $expired !== '') {
                    if (!preg_match('/^\d+$/', $expired)) {
                        $this->reject('Invalid OpenPlatform authorization-code expiry.');
                    }
                    $authorizationCodeExpiredTime = (int) $expired;
                }
                if ($preAuthCode === '') {
                    $preAuthCode = null;
                }
                break;

            case 'unauthorized':
                $authorizerAppId = $this->text($document, 'AuthorizerAppid');
                if ($authorizerAppId === null || $authorizerAppId === '') {
                    $this->reject('Invalid OpenPlatform unauthorized event XML payload.');
                }
                break;

            default:
                throw new AppException(ErrorCode::INVALID_ARGUMENT, 'Unsupported OpenPlatform callback InfoType.', 400);
        }

        return [
            'appId' => $appId,
            'infoType' => $infoType,
            'componentVerifyTicket' => $ticket,
            'authorizerAppId' => $authorizerAppId,
            'authorizationCode' => $authorizationCode,
            'authorizationCodeExpiredTime' => $authorizationCodeExpiredTime,
            'preAuthCode' => $preAuthCode,
        ];
    }

    /** @return array{appId:string,infoType:string,ticket:string} */
    public function parseInnerTicket(string $xml): array
    {
        $event = $this->parseInnerEvent($xml);
        if ($event['infoType'] !== 'component_verify_ticket' || $event['componentVerifyTicket'] === null) {
            $this->reject('Invalid OpenPlatform ticket XML payload.');
        }
        return [
            'appId' => $event['appId'],
            'infoType' => $event['infoType'],
            'ticket' => $event['componentVerifyTicket'],
        ];
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
