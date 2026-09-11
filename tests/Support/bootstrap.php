<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $roots = [
        'app\\' => '/app/',
        'modules\\' => '/modules/',
    ];

    foreach ($roots as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = dirname(__DIR__, 2) . $directory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }
});

function expectTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function expectThrows(callable $callback, string $exceptionClass, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException($message . ' wrong exception=' . $e::class . ' message=' . $e->getMessage());
    }
    throw new RuntimeException($message . ' no exception thrown');
}
