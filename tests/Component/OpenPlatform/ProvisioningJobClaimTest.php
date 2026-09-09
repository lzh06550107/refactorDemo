<?php

declare(strict_types=1);

use app\openplatform\domain\ProvisioningJob;
use app\openplatform\domain\ProvisioningJobStatus;

expectSame(
    ['READY', 'CLAIMED', 'COMPLETED', 'DEAD'],
    array_map(static fn (ProvisioningJobStatus $status): string => $status->name, ProvisioningJobStatus::cases()),
    'provisioning job statuses are exactly the approved set',
);

$now = new DateTimeImmutable('2026-09-09T05:10:00Z');
$ready = ProvisioningJob::ready('provisioning-1', $now, $now);
expectSame(ProvisioningJobStatus::READY, $ready->status(), 'new durable job starts READY');
expectSame(0, $ready->attemptCount(), 'new durable job has zero attempts');
expectTrue($ready->claimableAt($now), 'READY due-now job is claimable');

$first = $ready->claimedBy('holder-A', $now, 60);
expectSame(ProvisioningJobStatus::CLAIMED, $first->status(), 'claim moves job to CLAIMED');
expectSame('holder-A', $first->claimHolderId(), 'claim records exact holder');
expectSame($now->modify('+60 seconds')->getTimestamp(), $first->claimExpiresAt()?->getTimestamp(), 'claim TTL is exactly 60 seconds');
expectSame(1, $first->attemptCount(), 'first claim increments attempt count once');
expectTrue(!$first->claimableAt($now->modify('+59 seconds')), 'live claim blocks competing worker');

try {
    $first->claimedBy('holder-B', $now->modify('+1 second'), 60);
    throw new RuntimeException('live provisioning lease must block competing claim');
} catch (LogicException) {
    // Expected.
}

$takeoverAt = $now->modify('+61 seconds');
expectTrue($first->claimableAt($takeoverAt), 'expired claim is recoverable');
$second = $first->claimedBy('holder-B', $takeoverAt, 60);
expectSame('holder-B', $second->claimHolderId(), 'expired lease can be taken by new holder');
expectSame(2, $second->attemptCount(), 'expired-lease takeover increments attempt count');

foreach ([
    static fn () => $second->releasedBy('holder-A', $takeoverAt->modify('+1 second'), $takeoverAt->modify('+60 seconds'), 'stale'),
    static fn () => $second->completedBy('holder-A', $takeoverAt->modify('+1 second')),
    static fn () => $second->deadBy('holder-A', 'stale', $takeoverAt->modify('+1 second')),
] as $staleWrite) {
    try {
        $staleWrite();
        throw new RuntimeException('stale old holder must not mutate claimed job');
    } catch (LogicException) {
        // Expected.
    }
}

$retryAt = $takeoverAt->modify('+120 seconds');
$released = $second->releasedBy('holder-B', $takeoverAt->modify('+1 second'), $retryAt, 'provider_timeout');
expectSame(ProvisioningJobStatus::READY, $released->status(), 'current holder can release job back to READY');
expectSame(null, $released->claimHolderId(), 'release clears claim holder');
expectSame(null, $released->claimExpiresAt(), 'release clears claim expiry');
expectSame($retryAt->getTimestamp(), $released->nextAttemptAt()->getTimestamp(), 'release persists next retry time');
expectSame('provider_timeout', $released->lastErrorCode(), 'release stores sanitized retry error');
expectSame(2, $released->attemptCount(), 'release does not increment attempt count');
expectTrue(!$released->claimableAt($retryAt->modify('-1 second')), 'released job is not claimable before next attempt');
expectTrue($released->claimableAt($retryAt), 'released job becomes claimable when retry is due');

$third = $released->claimedBy('holder-C', $retryAt, 60);
$completed = $third->completedBy('holder-C', $retryAt->modify('+1 second'));
expectSame(ProvisioningJobStatus::COMPLETED, $completed->status(), 'current holder can complete claimed job');
expectTrue(!$completed->claimableAt($retryAt->modify('+120 seconds')), 'completed job is terminal');

$deadCandidate = ProvisioningJob::ready('provisioning-2', $now, $now)->claimedBy('holder-D', $now, 60);
$dead = $deadCandidate->deadBy('holder-D', 'max_attempts', $now->modify('+1 second'));
expectSame(ProvisioningJobStatus::DEAD, $dead->status(), 'current holder can mark claimed job DEAD');
expectSame('max_attempts', $dead->lastErrorCode(), 'dead job records sanitized terminal error');
expectTrue(!$dead->claimableAt($now->modify('+120 seconds')), 'dead job is terminal');

$root = dirname(__DIR__, 3);
$repositoryPath = $root . '/app/openplatform/infrastructure/ThinkPhpProvisioningJobRepository.php';
expectTrue(is_file($repositoryPath), 'ThinkPHP provisioning job repository must exist');
$source = is_file($repositoryPath) ? (string) file_get_contents($repositoryPath) : '';
expectTrue(str_contains($source, "Db::table('authorizer_provisioning_jobs')"), 'job repository uses durable provisioning job table');
expectTrue(str_contains($source, 'Db::transaction') && str_contains($source, 'lock(true)'), 'job acquisition is serialized by a short row-locked transaction');
expectTrue(str_contains($source, "'READY'") || str_contains($source, "'ready'"), 'job repository recognizes READY claims');
expectTrue(str_contains($source, "'CLAIMED'") || str_contains($source, "'claimed'"), 'job repository supports expired CLAIMED takeover');
expectTrue(str_contains($source, 'next_attempt_at'), 'job repository gates READY claims by next_attempt_at');
expectTrue(str_contains($source, 'claim_holder_id') && str_contains($source, 'claim_expires_at'), 'job repository persists lease holder and expiry');
expectTrue(str_contains($source, 'attempt_count'), 'job repository persists attempt count');
expectTrue(str_contains($source, 'hash_equals'), 'release/complete/dead validate exact current holder');
