<?php

declare(strict_types=1);

namespace app\adminui\controller;

use think\Response;

final readonly class SpaController
{
    public function __construct(private ?string $indexPath = null)
    {
    }

    public function index(): Response
    {
        $path = $this->indexPath ?? app()->getRootPath() . 'public/admin/index.html';
        $html = @file_get_contents($path);

        if (!is_string($html)) {
            return Response::create(
                '<!doctype html><html><body><h1>Admin UI unavailable</h1></body></html>',
                'html',
                503,
            );
        }

        return Response::create($html, 'html', 200);
    }
}
