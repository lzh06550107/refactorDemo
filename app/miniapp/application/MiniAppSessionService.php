<?php

declare(strict_types=1);

namespace app\miniapp\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\miniapp\contract\MiniAppSessionRepository;
use app\miniapp\domain\MiniAppSession;
use DateTimeImmutable;

final readonly class MiniAppSessionService
{
    public function __construct(private MiniAppSessionRepository $sessions)
    {
    }

    public function authenticate(string $tenantId, string $accountId, string $opaqueToken, DateTimeImmutable $now): MiniAppSession
    {
        if (trim($tenantId) === '' || trim($accountId) === '' || trim($opaqueToken) === '') {
            throw new AppException(ErrorCode::INVALID_ARGUMENT, 'MiniApp session authentication requires tenant, account and token.', 400);
        }

        $session = $this->sessions->findByTokenHash(hash('sha256', $opaqueToken));
        if ($session === null
            || $session->tenantId() !== $tenantId
            || $session->accountId() !== $accountId
            || !$session->isActiveAt($now)
        ) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp session is invalid.', 401);
        }

        return $session;
    }
}
