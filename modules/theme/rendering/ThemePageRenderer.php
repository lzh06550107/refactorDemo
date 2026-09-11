<?php

declare(strict_types=1);

namespace modules\theme\rendering;

use modules\theme\domain\InvalidThemePackage;
use modules\theme\domain\ResolvedThemePage;

final readonly class ThemePageRenderer
{
    private const HEADER_TOKEN = '@@HEADER_HTML@@';
    private const CONTENT_TOKEN = '@@CONTENT_HTML@@';
    private const FOOTER_TOKEN = '@@FOOTER_HTML@@';

    public function __construct(private SafeThemeRenderer $renderer)
    {
    }

    /** @param array<string,mixed> $viewModel */
    public function render(ResolvedThemePage $page, array $viewModel): string
    {
        $this->assertFragmentContract($page);

        $header = $this->renderer->render(
            $page->headerTemplate(),
            new ViewContract(['site_title']),
            $this->select($viewModel, ['site_title']),
        );
        $content = $this->renderer->render(
            $page->pageTemplate(),
            new ViewContract(['site_title', 'page_title', 'page_description']),
            $this->select($viewModel, ['site_title', 'page_title', 'page_description']),
        );
        $footer = $this->renderer->render(
            $page->footerTemplate(),
            new ViewContract(['site_title']),
            $this->select($viewModel, ['site_title']),
        );
        $layout = $this->renderer->render(
            $page->layoutTemplate(),
            new ViewContract(['page_title', 'page_description', 'asset_css_url', 'asset_js_url']),
            $this->select($viewModel, ['page_title', 'page_description', 'asset_css_url', 'asset_js_url']),
        );

        return strtr($layout, [
            self::HEADER_TOKEN => $header,
            self::CONTENT_TOKEN => $content,
            self::FOOTER_TOKEN => $footer,
        ]);
    }

    private function assertFragmentContract(ResolvedThemePage $page): void
    {
        $layout = $page->layoutTemplate();
        $required = [self::HEADER_TOKEN, self::CONTENT_TOKEN, self::FOOTER_TOKEN];
        foreach ($required as $token) {
            if (substr_count($layout, $token) !== 1) {
                throw new InvalidThemePackage('Theme layout must contain each trusted fragment token exactly once.');
            }
        }

        preg_match_all('/@@[A-Z][A-Z0-9_]*@@/', $layout, $matches);
        $found = $matches[0] ?? [];
        sort($found);
        $expected = $required;
        sort($expected);
        if ($found !== $expected) {
            throw new InvalidThemePackage('Theme layout contains an unsupported trusted fragment token.');
        }

        foreach ([$page->headerTemplate(), $page->pageTemplate(), $page->footerTemplate()] as $fragment) {
            if (preg_match('/@@[A-Z][A-Z0-9_]*@@/', $fragment) === 1) {
                throw new InvalidThemePackage('Theme fragment contains an unresolved trusted fragment token.');
            }
        }
    }

    /** @param array<string,mixed> $viewModel @param list<string> $fields @return array<string,mixed> */
    private function select(array $viewModel, array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $viewModel)) {
                $selected[$field] = $viewModel[$field];
            }
        }
        return $selected;
    }
}
