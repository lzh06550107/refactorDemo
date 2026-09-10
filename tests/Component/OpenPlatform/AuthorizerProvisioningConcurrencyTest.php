<?php

declare(strict_types=1);

use app\openplatform\domain\ProvisioningJob;
use app\openplatform\domain\ProvisioningJobStatus;

$now = new DateTimeImmutable('2026-09-10T08:00:00Z');
$job = ProvisioningJob::ready('concurrency-provisioning-1', $now, $now);

$repo = new class($job) {
    public ProvisioningJob $job;
    public array $writes = ['worker-a' => 0, 'worker-b' => 0];

    public function __construct(ProvisioningJob $job)
    {
        $this->job = $job;
    }

    public function tryClaim(string $holderId, DateTimeImmutable $at): ?ProvisioningJob
    {
        if (!$this->job->claimableAt($at)) {
            return null;
        }
        return $this->job = $this->job->claimedBy($holderId, $at, 60);
    }
};

$winner = $repo->tryClaim('worker-a', $now);
expectTrue($winner !== null, 'first worker wins the 60 second provisioning lease');
expectSame(ProvisioningJobStatus::CLAIMED, $repo->job->status(), 'winner leaves durable job CLAIMED');
expectSame($now->modify('+60 seconds')->getTimestamp(), $repo->job->claimExpiresAt()?->getTimestamp(), 'claim lease is exactly 60 seconds');

$loser = $repo->tryClaim('worker-b', $now);
expectSame(null, $loser, 'second worker loses the simultaneous claim');
if ($winner !== null) {
    $repo->writes['worker-a']++;
}
if ($loser !== null) {
    $repo->writes['worker-b']++;
}
expectSame(1, $repo->writes['worker-a'], 'claim winner may perform provider/quota/Account work');
expectSame(0, $repo->writes['worker-b'], 'claim loser performs zero provider/quota/Account writes');
expectTrue(!$repo->job->claimableAt($now->modify('+59 seconds')), 'lease cannot be stolen before expiry');
expectTrue($repo->job->claimableAt($now->modify('+60 seconds')), 'expired claim is recoverable at the exact lease boundary');

$recovered = $repo->tryClaim('worker-b', $now->modify('+60 seconds'));
expectTrue($recovered !== null, 'new holder recovers expired provisioning claim');
expectSame('worker-b', $repo->job->claimHolderId(), 'recovery transfers lease ownership to new holder');
expectSame(2, $repo->job->attemptCount(), 'expired-claim recovery advances attempt count once');

expectThrows(
    static fn () => $winner?->completedBy('worker-a', $now->modify('+60 seconds')),
    LogicException::class,
    'expired holder late-save is rejected',
);
$completed = $repo->job->completedBy('worker-b', $now->modify('+61 seconds'));
expectSame(ProvisioningJobStatus::COMPLETED, $completed->status(), 'current live holder may complete recovered job');
