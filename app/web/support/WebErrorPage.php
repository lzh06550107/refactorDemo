<?php

declare(strict_types=1);

namespace app\web\support;

final class WebErrorPage
{
    public static function notFound(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>页面不存在</title>
</head>
<body>
  <main>
    <h1>页面不存在</h1>
    <p>您访问的页面不存在或已被移除。</p>
  </main>
</body>
</html>
HTML;
    }
}
