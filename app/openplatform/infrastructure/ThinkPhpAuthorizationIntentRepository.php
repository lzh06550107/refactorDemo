<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\domain\AuthorizationIntent;
use app\openplatform\domain\AuthorizationIntentMode;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ThinkPhpAuthorizationIntentRepository implements AuthorizationIntentRepository
{
    public function insert(AuthorizationIntent $intent): void
    {
        Db::table('openplatform_authorization_intents')->insert([
            'id' => $intent->id(),
            'component_platform_id' => $intent->componentPlatformId(),
            'tenant_id' => $intent->tenantId(),
            'intent_mode' => $intent->mode()->value,
            'target_account_id' => $intent->targetAccountId(),
            'state_hash' => $intent->stateHash(),
            'pre_auth_code_hash' => $intent->preAuthCodeHash(),
            'provider_pre_auth_expires_at' => $this->sqlDate($intent->providerPreAuthExpiresAt()),
            'requested_auth_type' => $intent->requestedAuthType(),
            'created_at' => $this->sqlDate($intent->createdAt()),
            'expires_at' => $this->sqlDate($intent->expiresAt()),
            'claim_holder_id' => null,
            'claim_expires_at' => null,
            'completed_at' => null,
            'completed_authorizer_app_id' => null,
            'version' => $intent->version(),
        ]);
    }

    public function findByStateHash(string $stateHash): ?AuthorizationIntent
    {
        $row = Db::table('openplatform_authorization_intents')->where('state_hash', $stateHash)->find();
        return is_array($row) ? $this->map($row) : null;
    }

    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent
    {
        $row = Db::table('openplatform_authorization_intents')->where([
            'component_platform_id' => $componentPlatformId,
            'pre_auth_code_hash' => $preAuthCodeHash,
        ])->find();
        return is_array($row) ? $this->map($row) : null;
    }

    public function tryClaim(
        string $intentId,
        string $holderId,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $expectedVersion,
    ): ?AuthorizationIntent {
        return Db::transaction(function () use ($intentId, $holderId, $now, $leaseSeconds, $expectedVersion): ?AuthorizationIntent {
            $row = Db::table('openplatform_authorization_intents')->where('id', $intentId)->lock(true)->find();
            if (!is_array($row)) {
                return null;
            }
            $intent = $this->map($row);
            if ($intent->version() !== $expectedVersion || !$intent->claimableAt($now)) {
                return null;
            }

            $claimExpiresAt = $now->modify('+' . max(1, $leaseSeconds) . ' seconds');
            if ($claimExpiresAt > $intent->effectiveExpiresAt()) {
                $claimExpiresAt = $intent->effectiveExpiresAt();
            }
            if ($claimExpiresAt <= $now) {
                return null;
            }

            $claimed = $intent->withClaim($holderId, $claimExpiresAt);
            Db::table('openplatform_authorization_intents')->where('id', $intentId)->update([
                'claim_holder_id' => $holderId,
                'claim_expires_at' => $this->sqlDate($claimExpiresAt),
                'version' => $claimed->version(),
            ]);
            return $claimed;
        });
    }

    public function releaseClaim(string $intentId, string $holderId): void
    {
        Db::transaction(function () use ($intentId, $holderId): void {
            $row = Db::table('openplatform_authorization_intents')->where('id', $intentId)->lock(true)->find();
            if (
                !is_array($row)
                || $row['completed_at'] !== null
                || !is_string($row['claim_holder_id'] ?? null)
                || !hash_equals($holderId, (string) $row['claim_holder_id'])
            ) {
                return;
            }
            Db::table('openplatform_authorization_intents')->where('id', $intentId)->update([
                'claim_holder_id' => null,
                'claim_expires_at' => null,
                'version' => (int) $row['version'] + 1,
            ]);
        });
    }

    public function complete(
        string $intentId,
        string $holderId,
        string $authorizerAppId,
        DateTimeImmutable $now,
        int $expectedVersion,
    ): bool {
        return Db::transaction(function () use ($intentId, $holderId, $authorizerAppId, $now, $expectedVersion): bool {
            $row = Db::table('openplatform_authorization_intents')->where('id', $intentId)->lock(true)->find();
            if (!is_array($row)) {
                return false;
            }
            $intent = $this->map($row);
            if (
                $intent->version() !== $expectedVersion
                || $intent->completed()
                || $intent->claimHolderId() === null
                || !hash_equals($holderId, $intent->claimHolderId())
                || $intent->claimExpiresAt() === null
                || $intent->claimExpiresAt() <= $now
            ) {
                return false;
            }

            $completed = $intent->completedBy($authorizerAppId, $now);
            Db::table('openplatform_authorization_intents')->where('id', $intentId)->update([
                'claim_holder_id' => null,
                'claim_expires_at' => null,
                'completed_at' => $this->sqlDate($now),
                'completed_authorizer_app_id' => $authorizerAppId,
                'version' => $completed->version(),
            ]);
            return true;
        });
    }

    private function map(array $row): AuthorizationIntent
    {
        return AuthorizationIntent::reconstitute(
            (string) $row['id'],
            (string) $row['component_platform_id'],
            (string) $row['tenant_id'],
            AuthorizationIntentMode::from((string) $row['intent_mode']),
            $this->nullableString($row['target_account_id'] ?? null),
            (string) $row['state_hash'],
            (string) $row['pre_auth_code_hash'],
            (string) $row['requested_auth_type'],
            $this->date((string) $row['created_at']),
            $this->date((string) $row['expires_at']),
            $this->date((string) $row['provider_pre_auth_expires_at']),
            $this->nullableString($row['claim_holder_id'] ?? null),
            empty($row['claim_expires_at']) ? null : $this->date((string) $row['claim_expires_at']),
            empty($row['completed_at']) ? null : $this->date((string) $row['completed_at']),
            $this->nullableString($row['completed_authorizer_app_id'] ?? null),
            (int) $row['version'],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
