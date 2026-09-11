<?php

declare(strict_types=1);

namespace modules\oauth\infrastructure;

use modules\oauth\contract\OAuthStateRepository;
use modules\oauth\domain\OAuthState;
use DateTimeImmutable;
use think\facade\Db;

final class ThinkPhpOAuthStateRepository implements OAuthStateRepository
{
    public function insert(OAuthState $state): void
    {
        Db::table('oauth_states')->insert($this->row($state));
    }

    public function findByNonceHash(string $nonceHash): ?OAuthState
    {
        $row = Db::table('oauth_states')->where('nonce_hash', $nonceHash)->find();
        return $row === null ? null : $this->state((array) $row);
    }

    public function lockByNonceHash(string $nonceHash): ?OAuthState
    {
        $row = Db::table('oauth_states')->where('nonce_hash', $nonceHash)->lock(true)->find();
        return $row === null ? null : $this->state((array) $row);
    }

    public function save(OAuthState $state): void
    {
        Db::table('oauth_states')->where('id', $state->id())->update($this->row($state));
    }

    private function state(array $row): OAuthState
    {
        return new OAuthState(
            (string) $row['id'],
            (string) $row['nonce_hash'],
            (string) $row['tenant_id'],
            (string) $row['business_account_id'],
            (string) $row['oauth_provider_account_id'],
            (string) $row['provider_type'],
            (string) $row['return_url'],
            new DateTimeImmutable((string) $row['issued_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            empty($row['consumed_at']) ? null : new DateTimeImmutable((string) $row['consumed_at']),
            $this->nullableString($row['result_member_id'] ?? null),
            $this->nullableString($row['result_external_identity_id'] ?? null),
        );
    }

    private function row(OAuthState $state): array
    {
        return [
            'id' => $state->id(),
            'nonce_hash' => $state->nonceHash(),
            'tenant_id' => $state->tenantId(),
            'business_account_id' => $state->businessAccountId(),
            'oauth_provider_account_id' => $state->oauthProviderAccountId(),
            'provider_type' => $state->providerType(),
            'return_url' => $state->returnUrl(),
            'issued_at' => $this->sqlDate($state->issuedAt()),
            'expires_at' => $this->sqlDate($state->expiresAt()),
            'consumed_at' => $state->consumedAt() === null ? null : $this->sqlDate($state->consumedAt()),
            'result_member_id' => $state->resultMemberId(),
            'result_external_identity_id' => $state->resultExternalIdentityId(),
        ];
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
