<?php

declare(strict_types=1);

use app\web\support\WebErrorPage;

$html = WebErrorPage::notFound();
expectTrue(str_starts_with($html, '<!doctype html>'), 'web 404 page is a complete HTML document');
expectTrue(str_contains($html, '页面不存在'), 'web 404 page has a generic Chinese title');
expectTrue(str_contains($html, '您访问的页面不存在或已被移除。'), 'web 404 page has a generic Chinese message');

foreach (['Exception', 'trace', '/var/', 'C:\\', 'themes/', 'Stack trace', '#0 '] as $forbidden) {
    expectTrue(!str_contains($html, $forbidden), 'web 404 page must not leak diagnostic detail: ' . $forbidden);
}
