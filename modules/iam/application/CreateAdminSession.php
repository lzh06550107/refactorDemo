<?php

declare(strict_types=1);

namespace modules\iam\application;

use DateTimeImmutable;
use InvalidArgumentException;
use modules\iam\contract\AdminSessionStore;
use modules\iam\contract\SessionIdGenerator;
use modules\iam\contract\SessionTokenGenerator;
use modules\iam\domain\AdminSession;
use modules\iam\domain\AdminUser;
use modules\iam\domain\IssuedAdminSession;
use modules\iam\security\SessionTokenHasher;

final readonly class CreateAdminSession
{
    public function __construct(
        private AdminSessionStore $store,
        private SessionTokenGenerator $tokenGenerator,
        private SessionIdGenerator $idGenerator,
        private SessionTokenHasher $tokenHasher,
    ) {
    }

    public function execute(AdminUser $user, DateTimeImmutable $now, int $ttlSeconds): IssuedAdminSession
    {
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('Administrator session TTL must be positive.');
        }

        $rawToken = $this->tokenGenerator->generate();
        $session = new AdminSession(
            $this->idGenerator->generate(),
            $user->id(),
            $this->tokenHasher->hash($rawToken),
            $now,
            $now->modify('+' . $ttlSeconds . ' seconds'),
        );

        $this->store->save($session);

        return new IssuedAdminSession($session, $rawToken);
    }
}
