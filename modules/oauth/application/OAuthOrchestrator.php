<?php

declare(strict_types=1);

namespace modules\oauth\application;

use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\member\application\MemberIdentityService;
use modules\member\domain\ProviderIdentity;
use modules\oauth\contract\OAuthBindingRepository;
use modules\oauth\contract\OAuthProviderClient;
use modules\oauth\contract\OAuthStateRepository;
use modules\oauth\domain\OAuthCallbackResult;
use modules\oauth\domain\OAuthStartResult;
use modules\oauth\domain\OAuthState;
use modules\oauth\domain\ReturnUrlPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OAuthOrchestrator
{
    private const STATE_TTL_SECONDS = 600;

    public function __construct(
        private OAuthBindingRepository $bindings,
        private OAuthStateRepository $states,
        private OAuthProviderClient $provider,
        private MemberIdentityService $members,
        private TransactionManager $transactions,
        private ReturnUrlPolicy $returnUrls,
    ) {
    }

    public function start(
        string $tenantId,
        string $businessAccountId,
        string $providerType,
        string $returnUrl,
        DateTimeImmutable $now,
    ): OAuthStartResult {
        if (trim($tenantId) === '' || trim($businessAccountId) === '' || trim($providerType) === '') {
            throw new InvalidArgumentException('OAuth start context must not be empty.');
        }

        $validatedReturnUrl = $this->returnUrls->validate($returnUrl);
        $binding = $this->bindings->findEnabled($tenantId, $businessAccountId, $providerType);
        if ($binding === null) {
            throw new AppException(ErrorCode::FORBIDDEN, 'OAuth provider is not enabled for this business account.', 403);
        }

        $stateToken = bin2hex(random_bytes(32));
        $nonceHash = hash('sha256', $stateToken);
        $expiresAt = $now->modify('+' . self::STATE_TTL_SECONDS . ' seconds');
        $state = new OAuthState(
            bin2hex(random_bytes(16)),
            $nonceHash,
            $tenantId,
            $businessAccountId,
            $binding->oauthProviderAccountId(),
            $providerType,
            $validatedReturnUrl,
            $now,
            $expiresAt,
        );
        $this->states->insert($state);

        return new OAuthStartResult(
            $stateToken,
            $this->provider->authorizationUrl($binding, $stateToken),
            $expiresAt,
        );
    }

    public function callback(string $stateToken, string $code, DateTimeImmutable $now): OAuthCallbackResult
    {
        if (trim($stateToken) === '') {
            $this->unauthorized('OAuth state is missing.');
        }
        if (trim($code) === '') {
            $this->unauthorized('OAuth authorization code is missing.');
        }

        $nonceHash = hash('sha256', $stateToken);
        $snapshot = $this->states->findByNonceHash($nonceHash);
        $this->assertConsumable($snapshot, $now);
        if ($snapshot->hasCompleteResult()) {
            return $this->resultFromCompletedState($snapshot);
        }

        $providerIdentity = $this->provider->exchangeCode($snapshot, $code);
        $this->assertProviderMatches($snapshot, $providerIdentity);

        return $this->transactions->run(function () use ($nonceHash, $now, $providerIdentity): OAuthCallbackResult {
            $locked = $this->states->lockByNonceHash($nonceHash);
            $this->assertConsumable($locked, $now);
            if ($locked->hasCompleteResult()) {
                return $this->resultFromCompletedState($locked);
            }

            $this->assertProviderMatches($locked, $providerIdentity);
            $identity = $this->members->resolveOrCreateWithinTransaction($locked->tenantId(), $providerIdentity);
            $completed = $locked->complete(
                $identity->member()->id(),
                $identity->externalIdentity()->id(),
                $now,
            );
            $this->states->save($completed);

            return $this->resultFromCompletedState($completed);
        });
    }

    private function assertConsumable(?OAuthState $state, DateTimeImmutable $now): void
    {
        if ($state === null || $state->isExpired($now)) {
            $this->unauthorized('OAuth state is invalid or expired.');
        }
        if ($state->isConsumed() && !$state->hasCompleteResult()) {
            $this->unauthorized('OAuth state is not consumable.');
        }
    }

    private function assertProviderMatches(OAuthState $state, ProviderIdentity $identity): void
    {
        if ($identity->providerType() !== $state->providerType()
            || $identity->providerAccountId() !== $state->oauthProviderAccountId()) {
            throw new AppException(ErrorCode::FORBIDDEN, 'OAuth provider account does not match state.', 403);
        }
    }

    private function resultFromCompletedState(OAuthState $state): OAuthCallbackResult
    {
        if (!$state->hasCompleteResult()) {
            $this->unauthorized('OAuth state result is incomplete.');
        }

        return new OAuthCallbackResult(
            $state->tenantId(),
            $state->businessAccountId(),
            $state->oauthProviderAccountId(),
            (string) $state->resultMemberId(),
            (string) $state->resultExternalIdentityId(),
            $state->returnUrl(),
        );
    }

    private function unauthorized(string $message): never
    {
        throw new AppException(ErrorCode::UNAUTHORIZED, $message, 401);
    }
}
