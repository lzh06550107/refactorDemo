<?php

declare(strict_types=1);

use app\theme\domain\StyleInstance;
use app\theme\domain\StyleSnapshot;

$style = new StyleInstance('style-1', 'tenant-1', 'version-1', 'Blue', 3, ['accent-color' => '#00f', 'logo' => 'a.png']);
$snapshotA = StyleSnapshot::capture('snap-a', $style);
$reordered = new StyleInstance('style-1', 'tenant-1', 'version-1', 'Blue', 3, ['logo' => 'a.png', 'accent-color' => '#00f']);
$snapshotB = StyleSnapshot::capture('snap-b', $reordered);
expectSame($snapshotA->contentHash(), $snapshotB->contentHash(), 'snapshot hash must be deterministic independent of map insertion order');
expectSame(['accent-color' => '#00f', 'logo' => 'a.png'], $snapshotA->values(), 'snapshot values are canonicalized');

$next = $style->withValues(['accent-color' => '#0ff']);
expectSame(4, $next->revision(), 'editing style creates a new revision');
expectSame('#0ff', $next->values()['accent-color'], 'edited value is applied');
expectThrows(fn () => new StyleInstance('x', 'tenant-1', 'version-1', 'Bad', 1, ['bad key!' => 'x']), InvalidArgumentException::class, 'legacy variable names outside safe grammar are rejected');
