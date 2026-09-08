<?php

declare(strict_types=1);

namespace app\member\infrastructure;

use app\member\contract\MemberIdentityRepository;
use app\member\domain\ExternalIdentity;
use app\member\domain\Member;
use app\member\domain\MemberIdentityResult;
use app\member\domain\MemberStatus;
use app\member\domain\ProviderIdentity;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class ThinkPhpMemberIdentityRepository implements MemberIdentityRepository
{
    public function findByProviderIdentity(ProviderIdentity $identity): ?MemberIdentityResult
    {
        $row = Db::table('external_identities')->where([
            'provider_type' => $identity->providerType(),
            'provider_account_id' => $identity->providerAccountId(),
            'external_subject' => $identity->externalSubject(),
        ])->lock(true)->find();

        if ($row === null) {
            return null;
        }

        return $this->result((array) $row);
    }

    public function insertOrGet(Member $member, ExternalIdentity $identity): MemberIdentityResult
    {
        $existing = $this->findByProviderIdentity($identity->providerIdentity());
        if ($existing !== null) {
            return $existing;
        }

        Db::table('members')->insert([
            'id' => $member->id(),
            'tenant_id' => $member->tenantId(),
            'status' => $member->status()->value,
            'display_name' => $member->displayName(),
            'avatar_url' => $member->avatarUrl(),
        ]);

        try {
            Db::table('external_identities')->insert([
                'id' => $identity->id(),
                'tenant_id' => $identity->tenantId(),
                'member_id' => $identity->memberId(),
                'provider_type' => $identity->providerIdentity()->providerType(),
                'provider_account_id' => $identity->providerIdentity()->providerAccountId(),
                'external_subject' => $identity->providerIdentity()->externalSubject(),
                'union_id' => $identity->providerIdentity()->unionId(),
                'legacy_uniacid' => $identity->legacyUniacid(),
                'legacy_acid' => $identity->legacyAcid(),
                'legacy_uid' => $identity->legacyUid(),
            ]);
        } catch (Throwable $e) {
            Db::table('members')->where('id', $member->id())->delete();
            $winner = $this->findByProviderIdentity($identity->providerIdentity());
            if ($winner !== null) {
                return $winner;
            }
            throw $e;
        }

        return new MemberIdentityResult($member, $identity);
    }

    private function result(array $identityRow): MemberIdentityResult
    {
        $memberRow = Db::table('members')->where('id', (string) $identityRow['member_id'])->find();
        if ($memberRow === null) {
            throw new RuntimeException('External identity references a missing member.');
        }
        $memberRow = (array) $memberRow;

        $member = new Member(
            (string) $memberRow['id'],
            (string) $memberRow['tenant_id'],
            MemberStatus::from((string) $memberRow['status']),
            $this->nullableString($memberRow['display_name'] ?? null),
            $this->nullableString($memberRow['avatar_url'] ?? null),
        );
        $providerIdentity = new ProviderIdentity(
            (string) $identityRow['provider_type'],
            (string) $identityRow['provider_account_id'],
            (string) $identityRow['external_subject'],
            $this->nullableString($identityRow['union_id'] ?? null),
        );
        $external = new ExternalIdentity(
            (string) $identityRow['id'],
            (string) $identityRow['tenant_id'],
            (string) $identityRow['member_id'],
            $providerIdentity,
            $this->nullableInt($identityRow['legacy_uniacid'] ?? null),
            $this->nullableInt($identityRow['legacy_acid'] ?? null),
            $this->nullableInt($identityRow['legacy_uid'] ?? null),
        );

        return new MemberIdentityResult($member, $external);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
