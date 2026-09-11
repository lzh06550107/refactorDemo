<?php

declare(strict_types=1);

namespace modules\theme\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\theme\contract\ThemePublicationRepository;
use modules\theme\domain\SiteThemeRelease;
use modules\theme\domain\StyleSnapshot;
use modules\theme\domain\ThemePublication;
use DateTimeImmutable;
use JsonException;
use think\facade\Db;

final class ThinkPhpThemePublicationRepository implements ThemePublicationRepository
{
    public function findByIdempotencyKey(string $tenantId, string $siteId, string $key): ?ThemePublication
    {
        $row = Db::table('site_theme_releases')->where(['tenant_id' => $tenantId, 'site_id' => $siteId, 'idempotency_key' => $key])->find();
        return $row === null ? null : $this->publication((array) $row);
    }

    public function findRelease(string $tenantId, string $siteId, string $releaseId): ?ThemePublication
    {
        $row = Db::table('site_theme_releases')->where(['tenant_id' => $tenantId, 'site_id' => $siteId, 'id' => $releaseId])->find();
        return $row === null ? null : $this->publication((array) $row);
    }

    public function publish(ThemePublication $publication): void
    {
        Db::transaction(function () use ($publication): void {
            $release = $publication->release();
            $snapshot = $publication->snapshot();
            $site = Db::table('sites')->where(['tenant_id' => $release->tenantId(), 'id' => $release->siteId()])->lock(true)->find();
            if ($site === null) {
                throw new AppException(ErrorCode::NOT_FOUND, 'Site not found for theme publication.', 404);
            }
            $site = (array) $site;
            $existing = Db::table('site_theme_releases')->where([
                'tenant_id' => $release->tenantId(),
                'site_id' => $release->siteId(),
                'idempotency_key' => $release->idempotencyKey(),
            ])->lock(true)->find();
            if ($existing !== null) {
                $existing = (array) $existing;
                if (!$this->semanticIdempotencyMatches($existing, $publication)) {
                    throw new AppException(ErrorCode::CONFLICT, 'Theme idempotency key already belongs to another publication.', 409);
                }
                return;
            }
            $active = $site['active_theme_release_id'] === null ? null : (string) $site['active_theme_release_id'];
            if ($active !== $release->previousReleaseId()) {
                throw new AppException(ErrorCode::CONFLICT, 'Site theme release is stale; reload site state before publishing.', 409);
            }

            $storedSnapshot = Db::table('style_snapshots')->where('id', $snapshot->id())->lock(true)->find();
            if ($storedSnapshot === null) {
                Db::table('style_snapshots')->insert([
                    'id' => $snapshot->id(),
                    'tenant_id' => $snapshot->tenantId(),
                    'style_instance_id' => $snapshot->styleInstanceId(),
                    'theme_version_id' => $snapshot->themeVersionId(),
                    'revision' => $snapshot->revision(),
                    'values_json' => json_encode($snapshot->values(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'content_hash' => $snapshot->contentHash(),
                    'created_at' => $release->createdAt()->format('Y-m-d H:i:s.u'),
                ]);
            } else {
                $storedSnapshot = (array) $storedSnapshot;
                if ((string) $storedSnapshot['tenant_id'] !== $snapshot->tenantId() || (string) $storedSnapshot['content_hash'] !== $snapshot->contentHash()) {
                    throw new AppException(ErrorCode::CONFLICT, 'Style snapshot id already exists with different content.', 409);
                }
            }

            Db::table('site_theme_releases')->insert([
                'id' => $release->id(),
                'tenant_id' => $release->tenantId(),
                'site_id' => $release->siteId(),
                'theme_version_id' => $release->themeVersionId(),
                'style_snapshot_id' => $release->styleSnapshotId(),
                'previous_release_id' => $release->previousReleaseId(),
                'rollback_of_release_id' => $release->rollbackOfReleaseId(),
                'idempotency_key' => $release->idempotencyKey(),
                'created_at' => $release->createdAt()->format('Y-m-d H:i:s.u'),
            ]);
            Db::table('sites')->where(['tenant_id' => $release->tenantId(), 'id' => $release->siteId()])->update(['active_theme_release_id' => $release->id()]);
        });
    }

    /** @param array<string,mixed> $existingRelease */
    private function semanticIdempotencyMatches(array $existingRelease, ThemePublication $proposal): bool
    {
        $release = $proposal->release();
        if ((string) $existingRelease['theme_version_id'] !== $release->themeVersionId()
            || ($existingRelease['rollback_of_release_id'] === null ? null : (string) $existingRelease['rollback_of_release_id']) !== $release->rollbackOfReleaseId()) {
            return false;
        }
        $existingSnapshot = Db::table('style_snapshots')->where([
            'tenant_id' => $release->tenantId(),
            'id' => (string) $existingRelease['style_snapshot_id'],
        ])->lock(true)->find();
        return $existingSnapshot !== null
            && (string) ((array) $existingSnapshot)['content_hash'] === $proposal->snapshot()->contentHash();
    }

    /** @param array<string,mixed> $releaseRow */
    private function publication(array $releaseRow): ThemePublication
    {
        $snapshotRow = Db::table('style_snapshots')->where(['tenant_id' => $releaseRow['tenant_id'], 'id' => $releaseRow['style_snapshot_id']])->find();
        if ($snapshotRow === null) {
            throw new AppException(ErrorCode::CONFLICT, 'Theme release references a missing style snapshot.', 409);
        }
        $snapshotRow = (array) $snapshotRow;
        try {
            $values = json_decode((string) $snapshotRow['values_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AppException(ErrorCode::CONFLICT, 'Stored style snapshot JSON is invalid.', 409, null, $e);
        }
        if (!is_array($values)) {
            throw new AppException(ErrorCode::CONFLICT, 'Stored style snapshot must be an object.', 409);
        }
        $snapshot = StyleSnapshot::rehydrate(
            (string) $snapshotRow['id'],
            (string) $snapshotRow['tenant_id'],
            (string) $snapshotRow['style_instance_id'],
            (string) $snapshotRow['theme_version_id'],
            (int) $snapshotRow['revision'],
            array_map(static fn (mixed $v): string => (string) $v, $values),
            (string) $snapshotRow['content_hash'],
        );
        $release = new SiteThemeRelease(
            (string) $releaseRow['id'],
            (string) $releaseRow['tenant_id'],
            (string) $releaseRow['site_id'],
            (string) $releaseRow['theme_version_id'],
            (string) $releaseRow['style_snapshot_id'],
            $releaseRow['previous_release_id'] === null ? null : (string) $releaseRow['previous_release_id'],
            $releaseRow['rollback_of_release_id'] === null ? null : (string) $releaseRow['rollback_of_release_id'],
            (string) $releaseRow['idempotency_key'],
            new DateTimeImmutable((string) $releaseRow['created_at']),
        );
        return new ThemePublication($release, $snapshot);
    }
}
