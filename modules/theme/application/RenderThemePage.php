<?php

declare(strict_types=1);

namespace modules\theme\application;

use modules\theme\contract\ThemePackageRepository;
use modules\theme\rendering\ThemePageRenderer;

final readonly class RenderThemePage
{
    public function __construct(
        private ThemePackageRepository $repository,
        private ThemePageRenderer $renderer,
    ) {
    }

    /** @param array<string,mixed> $viewModel */
    public function execute(string $themeKey, string $pageKey, array $viewModel): string
    {
        return $this->renderer->render(
            $this->repository->resolve($themeKey, $pageKey),
            $viewModel,
        );
    }
}
