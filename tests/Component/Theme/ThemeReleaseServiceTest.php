<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\context\Principal;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\site\domain\Site;
use app\site\domain\SiteStatus;
use app\theme\application\ThemeReleaseService;
use app\theme\contract\ThemePublicationRepository;
use app\theme\domain\SiteThemeRelease;
use app\theme\domain\StyleInstance;
use app\theme\domain\ThemePublication;
use app\theme\domain\ThemeVersion;

$repo = new class implements ThemePublicationRepository {
    /** @var array<string,ThemePublication> */ public array $byIdempotency = [];
    /** @var array<string,ThemePublication> */ public array $byRelease = [];
    public function findByIdempotencyKey(string $tenantId, string $siteId, string $key): ?ThemePublication { return $this->byIdempotency[$tenantId . '|' . $siteId . '|' . $key] ?? null; }
    public function findRelease(string $tenantId, string $siteId, string $releaseId): ?ThemePublication { return $this->byRelease[$tenantId . '|' . $siteId . '|' . $releaseId] ?? null; }
    public function publish(ThemePublication $publication): void {
        $r = $publication->release();
        $this->byIdempotency[$r->tenantId() . '|' . $r->siteId() . '|' . $r->idempotencyKey()] = $publication;
        $this->byRelease[$r->tenantId() . '|' . $r->siteId() . '|' . $r->id()] = $publication;
    }
};
$audit = new class implements AuditLogger { public array $events = []; public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); } };
$service = new ThemeReleaseService($repo, $audit);
$context = new RequestContext('req-1', 'trace-1', RuntimeType::ADMIN, 'tenant-1', 'account-1', 'site-1', new Principal('user-1', 'admin'), 'zh-CN', '127.0.0.1');
$site = new Site('site-1', 'tenant-1', 'account-1', 'Main', SiteStatus::ENABLED, true);
$version = new ThemeVersion('version-1', 'theme-1', '1.0', 'themes/default', str_repeat('b', 64));
$style = new StyleInstance('style-1', 'tenant-1', 'version-1', 'Default', 1, ['accent' => '#111']);
$at = new DateTimeImmutable('2026-09-07T12:00:00Z');
$first = $service->publish($context, $site, $version, $style, 'snap-1', 'release-1', 'idem-1', $at);
expectSame('release-1', $first->id(), 'publish creates release');
expectSame(null, $first->previousReleaseId(), 'first release has no predecessor');
$replay = $service->publish($context, $site, $version, $style, 'snap-other-id', 'release-other-id', 'idem-1', $at);
expectSame('release-1', $replay->id(), 'semantic idempotent replay returns original release');

$changed = $style->withValues(['accent' => '#222']);
try {
    $service->publish($context, $site, $version, $changed, 'snap-2', 'release-2', 'idem-1', $at);
    throw new RuntimeException('idempotency payload mismatch should fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'idempotency mismatch should be conflict');
}

$siteAfterFirst = $site->withActiveThemeRelease('release-1');
$second = $service->publish($context, $siteAfterFirst, $version, $changed, 'snap-2', 'release-2', 'idem-2', $at);
expectSame('release-1', $second->previousReleaseId(), 'release links predecessor');
$siteAfterSecond = $siteAfterFirst->withActiveThemeRelease('release-2');
$rollback = $service->rollback($context, $siteAfterSecond, 'release-1', 'release-3', 'idem-rb', $at);
expectSame('release-1', $rollback->rollbackOfReleaseId(), 'rollback creates a new release referencing target');
expectSame('release-2', $rollback->previousReleaseId(), 'rollback preserves current predecessor');
expectSame('theme.publish', $audit->events[0]['action'], 'publish audit action is recorded');
expectSame('user-1', $audit->events[0]['actor_id'], 'audit contains actor');
expectSame('tenant-1', $audit->events[0]['tenant_id'], 'audit contains tenant');
expectSame('account-1', $audit->events[0]['account_id'], 'audit contains account');
expectSame('req-1', $audit->events[0]['request_id'], 'audit contains request id');
expectSame('theme.rollback', $audit->events[count($audit->events)-1]['action'], 'rollback audit action is recorded');

$wrongContext = new RequestContext('req-x', 'trace-x', RuntimeType::ADMIN, 'tenant-1', 'account-other', 'site-1', new Principal('user-1', 'admin'), 'zh-CN', '127.0.0.1');
try {
    $service->publish($wrongContext, $site, $version, $style, 'snap-c', 'release-c', 'idem-c', $at);
    throw new RuntimeException('request context/account mismatch should fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'request context/account mismatch should be stable conflict');
}

$crossTenantStyle = new StyleInstance('style-x', 'tenant-2', 'version-1', 'Bad', 1, ['accent' => '#fff']);
try {
    $service->publish($context, $site, $version, $crossTenantStyle, 'snap-x', 'release-x', 'idem-x', $at);
    throw new RuntimeException('cross-tenant style should fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'cross-tenant theme publication should be stable conflict');
}
