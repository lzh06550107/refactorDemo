<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\contract\AuthorizerAccountEligibility;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\domain\AuthorizationIntent;
use modules\openplatform\domain\AuthorizationIntentMode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizationStartResult
{
    public function __construct(
        private string $state,
        private string $authorizationUrl,
        private DateTimeImmutable $expiresAt,
    ) {
        if (trim($state) === '' || trim($authorizationUrl) === '') {
            throw new InvalidArgumentException('Invalid authorization start result.');
        }
    }

    public function state(): string { return $this->state; }
    public function authorizationUrl(): string { return $this->authorizationUrl; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
}

final readonly class AuthorizationStartService
{
    private const AUTHORIZATION_PAGE = 'https://mp.weixin.qq.com/cgi-bin/componentloginpage';

    public function __construct(
        private ComponentAccessTokenService $componentTokens,
        private AuthorizerClient $authorizerClient,
        private AuthorizationIntentRepository $intents,
        private AuthorizerAccountEligibility $eligibility,
        private string $callbackUri,
        private int $intentTtlSeconds = 600,
    ) {
        $parts = parse_url($callbackUri);
        if (
            $intentTtlSeconds <= 0
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException('Invalid OpenPlatform authorization callback configuration.');
        }
    }

    public function start(
        string $componentPlatformId,
        string $tenantId,
        AuthorizationIntentMode $mode,
        ?string $targetAccountId,
        string $requestedAuthType,
        DateTimeImmutable $now,
    ): AuthorizationStartResult {
        foreach ([$componentPlatformId, $tenantId, $requestedAuthType] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Authorization start identifiers must not be empty.');
            }
        }
        if (
            ($mode === AuthorizationIntentMode::BIND_EXISTING_ACCOUNT
                && ($targetAccountId === null || trim($targetAccountId) === ''))
            || ($mode === AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT && $targetAccountId !== null)
        ) {
            throw new InvalidArgumentException('Authorization start mode and target Account are inconsistent.');
        }

        match ($mode) {
            AuthorizationIntentMode::BIND_EXISTING_ACCOUNT => $this->eligibility->assertExistingAccountEligible(
                $tenantId,
                (string) $targetAccountId,
                $componentPlatformId,
            ),
            AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT => $this->eligibility->assertTenantEligible(
                $tenantId,
                $componentPlatformId,
            ),
        };

        $componentToken = $this->componentTokens->forPlatform($componentPlatformId, $now);
        $preAuth = $this->authorizerClient->createPreAuthCode(
            $componentToken->componentAppId(),
            $componentToken->accessToken(),
        );

        $state = bin2hex(random_bytes(32));
        $providerExpiresAt = $now->modify('+' . $preAuth->expiresIn() . ' seconds');
        $localExpiresAt = $now->modify('+' . $this->intentTtlSeconds . ' seconds');
        $effectiveExpiresAt = $providerExpiresAt < $localExpiresAt ? $providerExpiresAt : $localExpiresAt;

        $intent = AuthorizationIntent::pending(
            bin2hex(random_bytes(16)),
            $componentPlatformId,
            $tenantId,
            $mode,
            $targetAccountId,
            hash('sha256', $state),
            hash('sha256', $preAuth->preAuthCode()),
            $requestedAuthType,
            $now,
            $localExpiresAt,
            $providerExpiresAt,
        );
        $this->intents->insert($intent);

        $separator = str_contains($this->callbackUri, '?') ? '&' : '?';
        $redirectUri = $this->callbackUri . $separator . 'state=' . rawurlencode($state);
        $authorizationUrl = self::AUTHORIZATION_PAGE . '?' . http_build_query([
            'component_appid' => $componentToken->componentAppId(),
            'pre_auth_code' => $preAuth->preAuthCode(),
            'redirect_uri' => $redirectUri,
            'auth_type' => $requestedAuthType,
        ], '', '&', PHP_QUERY_RFC3986);

        return new AuthorizationStartResult($state, $authorizationUrl, $effectiveExpiresAt);
    }
}
