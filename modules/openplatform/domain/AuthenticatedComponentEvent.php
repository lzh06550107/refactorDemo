<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticatedComponentEvent
{
    public function __construct(
        private string $componentPlatformId,
        private string $componentAppId,
        private string $infoType,
        private DateTimeImmutable $sourceTimestamp,
        private string $replayKey,
        private string $payloadHash,
        private ?string $componentVerifyTicket = null,
        private ?string $authorizerAppId = null,
        private ?string $authorizationCode = null,
        private ?int $authorizationCodeExpiredTime = null,
        private ?string $preAuthCode = null,
    ) {
        foreach (['componentPlatformId' => $componentPlatformId, 'componentAppId' => $componentAppId, 'infoType' => $infoType] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
        foreach (['replayKey' => $replayKey, 'payloadHash' => $payloadHash] as $name => $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new InvalidArgumentException($name . ' must be a lowercase SHA-256 hex digest.');
            }
        }
        if (!in_array($infoType, ['component_verify_ticket', 'authorized', 'updateauthorized', 'unauthorized'], true)) {
            throw new InvalidArgumentException('Unsupported authenticated component event type.');
        }
        if ($infoType === 'component_verify_ticket' && ($componentVerifyTicket === null || trim($componentVerifyTicket) === '')) {
            throw new InvalidArgumentException('Ticket event requires ComponentVerifyTicket.');
        }
        if (in_array($infoType, ['authorized', 'updateauthorized'], true)) {
            if ($authorizerAppId === null || trim($authorizerAppId) === '' || $authorizationCode === null || trim($authorizationCode) === '') {
                throw new InvalidArgumentException('Authorization event requires authorizer AppId and authorization code.');
            }
        }
        if ($infoType === 'unauthorized' && ($authorizerAppId === null || trim($authorizerAppId) === '')) {
            throw new InvalidArgumentException('Unauthorized event requires authorizer AppId.');
        }
        if ($authorizationCodeExpiredTime !== null && $authorizationCodeExpiredTime < 0) {
            throw new InvalidArgumentException('Authorization-code expiry must not be negative.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function componentAppId(): string { return $this->componentAppId; }
    public function infoType(): string { return $this->infoType; }
    public function sourceTimestamp(): DateTimeImmutable { return $this->sourceTimestamp; }
    public function replayKey(): string { return $this->replayKey; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function componentVerifyTicket(): ?string { return $this->componentVerifyTicket; }
    public function authorizerAppId(): ?string { return $this->authorizerAppId; }
    public function authorizationCode(): ?string { return $this->authorizationCode; }
    public function authorizationCodeExpiredTime(): ?int { return $this->authorizationCodeExpiredTime; }
    public function preAuthCode(): ?string { return $this->preAuthCode; }
}
