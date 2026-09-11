<?php

declare(strict_types=1);

namespace modules\miniapp\application;

use app\common\audit\AuditEvent;
use app\common\context\RequestContext;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\member\application\MemberIdentityService;
use modules\member\domain\ProviderIdentity;
use modules\miniapp\contract\MiniAppCodeExchangeClient;
use modules\miniapp\contract\MiniAppProviderAccountRepository;
use modules\miniapp\contract\MiniAppSessionRepository;
use modules\miniapp\contract\SessionKeyCipher;
use modules\miniapp\domain\MiniAppLoginResult;
use modules\miniapp\domain\MiniAppSession;
use DateTimeImmutable;

final readonly class MiniAppLoginService
{
    public function __construct(
        private MiniAppProviderAccountRepository $providers,
        private MiniAppCodeExchangeClient $exchange,
        private MemberIdentityService $members,
        private MiniAppSessionRepository $sessions,
        private SessionKeyCipher $cipher,
        private TransactionManager $transactions,
        private AuditLogger $audit,
    ) {
    }

    public function login(RequestContext $context, string $code, DateTimeImmutable $now): MiniAppLoginResult
    {
        $tenantId = trim((string) $context->tenantId());
        $accountId = trim((string) $context->accountId());
        if ($tenantId === '' || $accountId === '' || trim($code) === '') {
            throw new AppException(ErrorCode::INVALID_ARGUMENT, 'MiniApp login requires tenant, account and code.', 400);
        }

        $provider = $this->providers->findForTenantAccount($tenantId, $accountId);
        if ($provider === null) {
            throw new AppException(ErrorCode::FORBIDDEN, 'MiniApp provider account is not configured.', 403);
        }
        if ($provider->tenantId() !== $tenantId || $provider->accountId() !== $accountId) {
            throw new AppException(ErrorCode::FORBIDDEN, 'MiniApp provider account boundary mismatch.', 403);
        }

        $codeSession = $this->exchange->exchange($provider, $code);
        if ($codeSession->providerAppId() !== $provider->providerAppId()) {
            throw new AppException(ErrorCode::FORBIDDEN, 'MiniApp provider response does not match configured app.', 403);
        }

        $providerIdentity = new ProviderIdentity(
            'wechat_mini_program',
            $accountId,
            $codeSession->openId(),
            $codeSession->unionId(),
        );

        return $this->transactions->run(function () use ($tenantId, $accountId, $provider, $providerIdentity, $codeSession, $context, $now): MiniAppLoginResult {
            $identity = $this->members->resolveOrCreateWithinTransaction($tenantId, $providerIdentity);
            $sessionToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $sessionToken);
            $protected = $this->cipher->protect($codeSession->sessionKey());
            $session = MiniAppSession::issue(
                bin2hex(random_bytes(16)),
                $tenantId,
                $accountId,
                $identity->member()->id(),
                $identity->externalIdentity()->id(),
                $tokenHash,
                $protected,
                $now,
            );
            $this->sessions->insert($session);

            $this->audit->record(new AuditEvent(
                actorId: $identity->member()->id(),
                tenantId: $tenantId,
                accountId: $accountId,
                action: 'miniapp.login',
                result: 'success',
                requestId: $context->requestId(),
                traceId: $context->traceId(),
                metadata: [
                    'provider_type' => 'wechat_mini_program',
                    'provider_app_id' => $provider->providerAppId(),
                ],
                occurredAt: $now,
            ));

            return new MiniAppLoginResult(
                $sessionToken,
                $identity->member()->id(),
                $identity->externalIdentity()->id(),
                $session->expiresAt(),
            );
        });
    }
}
