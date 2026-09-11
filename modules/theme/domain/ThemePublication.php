<?php

declare(strict_types=1);

namespace modules\theme\domain;

use InvalidArgumentException;

final readonly class ThemePublication
{
    public function __construct(private SiteThemeRelease $release, private StyleSnapshot $snapshot)
    {
        if ($release->tenantId() !== $snapshot->tenantId() || $release->themeVersionId() !== $snapshot->themeVersionId() || $release->styleSnapshotId() !== $snapshot->id()) {
            throw new InvalidArgumentException('Theme publication release/snapshot mismatch.');
        }
    }
    public function release(): SiteThemeRelease { return $this->release; }
    public function snapshot(): StyleSnapshot { return $this->snapshot; }
}
