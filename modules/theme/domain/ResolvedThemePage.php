<?php

declare(strict_types=1);

namespace modules\theme\domain;

final readonly class ResolvedThemePage
{
    public function __construct(
        private ThemeManifest $manifest,
        private string $layoutTemplate,
        private string $pageTemplate,
        private string $headerTemplate,
        private string $footerTemplate,
    ) {
    }

    public function manifest(): ThemeManifest { return $this->manifest; }
    public function layoutTemplate(): string { return $this->layoutTemplate; }
    public function pageTemplate(): string { return $this->pageTemplate; }
    public function headerTemplate(): string { return $this->headerTemplate; }
    public function footerTemplate(): string { return $this->footerTemplate; }
}
