<?php

declare(strict_types=1);

namespace modules\oauth\contract;

use modules\oauth\domain\OAuthState;

interface OAuthStateRepository
{
    public function insert(OAuthState $state): void;
    public function findByNonceHash(string $nonceHash): ?OAuthState;
    public function lockByNonceHash(string $nonceHash): ?OAuthState;
    public function save(OAuthState $state): void;
}
