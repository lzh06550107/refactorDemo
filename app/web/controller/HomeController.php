<?php

declare(strict_types=1);

namespace app\web\controller;

use app\web\support\WebAssetManifest;
use app\web\support\WebErrorPage;
use modules\theme\application\RenderThemePage;
use modules\theme\domain\ThemePageNotFound;
use think\Response;

final class HomeController
{
    public function __construct(
        private readonly RenderThemePage $renderThemePage,
        private readonly WebAssetManifest $assets,
    ) {
    }

    public function index(): Response
    {
        $assets = $this->assets->forEntry('src/js/main.js');
        $viewModel = [
            'site_title' => 'WePlatform',
            'page_title' => 'WePlatform',
            'page_description' => 'ThinkPHP 8 Web/H5 theme runtime',
            'asset_css_url' => $assets['css'],
            'asset_js_url' => $assets['js'],
        ];

        try {
            $html = $this->renderThemePage->execute('corporate', 'index', $viewModel);
        } catch (ThemePageNotFound) {
            return response(
                WebErrorPage::notFound(),
                404,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
