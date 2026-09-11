<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\domain\AuthorizerAuthorizationResult;
use DateTimeImmutable;

final readonly class AuthorizationCallbackService
{
    public function __construct(
        private AuthorizationIntentRepository $intents,
        private AuthorizationCompletionService $completion,
    ) {
    }

    public function handle(
        string $state,
        string $authorizationCode,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): AuthorizerAuthorizationResult {
        if (trim($state) === '' || trim($authorizationCode) === '') {
            $this->unauthorized();
        }

        $intent = $this->intents->findByStateHash(hash('sha256', $state));
        if ($intent === null) {
            $this->unauthorized();
        }

        return $this->completion->completeIntent(
            $intent,
            $authorizationCode,
            $now,
            $requestId,
            $traceId,
        );
    }

    private function unauthorized(): never
    {
        throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid OpenPlatform authorization callback.', 401);
    }
}
