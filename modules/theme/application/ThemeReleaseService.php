<?php

declare(strict_types=1);

namespace modules\theme\application;

use app\common\audit\AuditEvent;
use app\common\context\RequestContext;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\site\domain\Site;
use modules\theme\contract\ThemePublicationRepository;
use modules\theme\domain\SiteThemeRelease;
use modules\theme\domain\StyleInstance;
use modules\theme\domain\StyleSnapshot;
use modules\theme\domain\ThemePublication;
use modules\theme\domain\ThemeVersion;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ThemeReleaseService
{
    public function __construct(private ThemePublicationRepository $repository, private AuditLogger $auditLogger) {}

    public function publish(RequestContext $context, Site $site, ThemeVersion $version, StyleInstance $style, string $snapshotId, string $releaseId, string $idempotencyKey, DateTimeImmutable $at): SiteThemeRelease
    {
        $this->assertRequestContext($context, $site);
        $this->assertPublishContext($site, $version, $style, $idempotencyKey);
        $snapshot = StyleSnapshot::capture($snapshotId, $style);
        $existing = $this->repository->findByIdempotencyKey($site->tenantId(), $site->id(), $idempotencyKey);
        if ($existing !== null) {
            $release = $existing->release();
            if ($release->rollbackOfReleaseId() !== null || $release->themeVersionId() !== $version->id() || $existing->snapshot()->contentHash() !== $snapshot->contentHash()) {
                throw new AppException(ErrorCode::CONFLICT, 'Idempotency key was already used for a different theme publication.', 409);
            }
            $this->audit($context, $site, 'theme.publish', $release, ['replayed' => true]);
            return $release;
        }
        $release = new SiteThemeRelease($releaseId, $site->tenantId(), $site->id(), $version->id(), $snapshot->id(), $site->activeThemeReleaseId(), null, $idempotencyKey, $at);
        $this->repository->publish(new ThemePublication($release, $snapshot));
        $this->audit($context, $site, 'theme.publish', $release, ['replayed' => false]);
        return $release;
    }

    public function rollback(RequestContext $context, Site $site, string $targetReleaseId, string $newReleaseId, string $idempotencyKey, DateTimeImmutable $at): SiteThemeRelease
    {
        $this->assertRequestContext($context, $site);
        if (!$site->isEnabled()) {
            throw new AppException(ErrorCode::FORBIDDEN, 'Disabled site cannot publish a theme.', 403);
        }
        if (trim($targetReleaseId) === '' || trim($newReleaseId) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Rollback identifiers must not be empty.');
        }
        $target = $this->repository->findRelease($site->tenantId(), $site->id(), $targetReleaseId);
        if ($target === null || $target->release()->tenantId() !== $site->tenantId() || $target->release()->siteId() !== $site->id()) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Target theme release not found.', 404);
        }
        $existing = $this->repository->findByIdempotencyKey($site->tenantId(), $site->id(), $idempotencyKey);
        if ($existing !== null) {
            if ($existing->release()->rollbackOfReleaseId() !== $targetReleaseId || $existing->snapshot()->contentHash() !== $target->snapshot()->contentHash()) {
                throw new AppException(ErrorCode::CONFLICT, 'Idempotency key was already used for a different theme rollback.', 409);
            }
            $this->audit($context, $site, 'theme.rollback', $existing->release(), ['target_release_id' => $targetReleaseId, 'replayed' => true]);
            return $existing->release();
        }
        $release = new SiteThemeRelease(
            $newReleaseId,
            $site->tenantId(),
            $site->id(),
            $target->release()->themeVersionId(),
            $target->snapshot()->id(),
            $site->activeThemeReleaseId(),
            $targetReleaseId,
            $idempotencyKey,
            $at,
        );
        $this->repository->publish(new ThemePublication($release, $target->snapshot()));
        $this->audit($context, $site, 'theme.rollback', $release, ['target_release_id' => $targetReleaseId, 'replayed' => false]);
        return $release;
    }

    private function assertRequestContext(RequestContext $context, Site $site): void
    {
        if ($context->principal() === null) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Theme publication requires an authenticated principal.', 401);
        }
        if ($context->tenantId() !== $site->tenantId() || $context->accountId() !== $site->accountId() || ($context->siteId() !== null && $context->siteId() !== $site->id())) {
            throw new AppException(ErrorCode::CONFLICT, 'Request context crosses site tenant/account boundary.', 409);
        }
    }

    private function assertPublishContext(Site $site, ThemeVersion $version, StyleInstance $style, string $idempotencyKey): void
    {
        if (!$site->isEnabled()) {
            throw new AppException(ErrorCode::FORBIDDEN, 'Disabled site cannot publish a theme.', 403);
        }
        if ($site->tenantId() !== $style->tenantId() || $version->id() !== $style->themeVersionId()) {
            throw new AppException(ErrorCode::CONFLICT, 'Theme publication crosses tenant/version boundary.', 409);
        }
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('idempotencyKey must not be empty.');
        }
    }

    /** @param array<string,mixed> $metadata */
    private function audit(RequestContext $context, Site $site, string $action, SiteThemeRelease $release, array $metadata): void
    {
        $this->auditLogger->record(new AuditEvent(
            actorId: $context->principal()?->id() ?? 'unknown',
            tenantId: $site->tenantId(),
            accountId: $site->accountId(),
            action: $action,
            result: 'success',
            requestId: $context->requestId(),
            traceId: $context->traceId(),
            metadata: $metadata + [
                'site_id' => $site->id(),
                'release_id' => $release->id(),
                'idempotency_key' => $release->idempotencyKey(),
            ],
            occurredAt: $release->createdAt(),
        ));
    }
}
