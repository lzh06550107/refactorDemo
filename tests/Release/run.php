<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require __DIR__ . '/ReleaseGateContractTest.php';

$exitCode = 0;
try {
    releaseGateContractTest($root);
    fwrite(STDOUT, "[PASS] ReleaseGateContractTest\n");

    releasePreflight($root);
    fwrite(STDOUT, "[PASS] ReleasePreflight\n");

    releaseRunCommand(
        [PHP_BINARY, $root . '/tests/run.php'],
        $root,
        'Offline test gate',
    );

    releaseRunCommand(
        [PHP_BINARY, $root . '/vendor/bin/phpunit'],
        $root,
        'PHPUnit gate',
    );

    releaseLintProject($root);
    fwrite(STDOUT, "[PASS] Project php -l gate\n");

    $thinkList = releaseRunCommand(
        [PHP_BINARY, $root . '/think', 'list'],
        $root,
        'ThinkPHP console gate',
    );
    releaseGateAssert(
        str_contains($thinkList, 'openplatform:provisioning-worker'),
        'ThinkPHP console must expose openplatform:provisioning-worker.',
    );
    fwrite(STDOUT, "[PASS] Provisioning worker command gate\n");

    releaseRunCommand(
        [PHP_BINARY, $root . '/tests/Acceptance/run.php'],
        $root,
        'Local MySQL acceptance gate',
    );

    fwrite(STDOUT, "[PASS] Local release gate\n");
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, '[FAIL] Local release gate: ' . $e->getMessage() . PHP_EOL);
}

exit($exitCode);

function releasePreflight(string $root): void
{
    if (!is_file($root . '/composer.lock')) {
        throw new RuntimeException('composer.lock is required for a reproducible release.');
    }
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('vendor/autoload.php is required; run composer install locally first.');
    }
    if (!is_file($root . '/vendor/bin/phpunit')) {
        throw new RuntimeException('vendor/bin/phpunit is required; install development dependencies locally.');
    }
    if (!is_file($root . '/think')) {
        throw new RuntimeException('ThinkPHP console entrypoint is missing.');
    }
    if (!is_file($root . '/tests/Acceptance/run.php')) {
        throw new RuntimeException('Local acceptance runner is missing.');
    }
    foreach ([
        'pdo_mysql' => 'MySQL acceptance',
        'mbstring' => 'ThinkPHP runtime',
        'dom' => 'PHPUnit runtime',
        'xmlwriter' => 'PHPUnit runtime',
    ] as $extension => $purpose) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException($extension . ' extension is required for ' . $purpose . '.');
        }
    }
    if (getenv('WEPLATFORM_ACCEPTANCE') !== '1') {
        throw new RuntimeException('Set WEPLATFORM_ACCEPTANCE=1 explicitly before the local release gate.');
    }
}

/**
 * @param list<string> $command
 */
function releaseRunCommand(array $command, string $cwd, string $label): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException($label . ' could not be started.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
        if (!str_ends_with($stdout, PHP_EOL)) {
            fwrite(STDOUT, PHP_EOL);
        }
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
        if (!str_ends_with($stderr, PHP_EOL)) {
            fwrite(STDERR, PHP_EOL);
        }
    }
    if ($exitCode !== 0) {
        throw new RuntimeException($label . ' failed with exit code ' . $exitCode . '.');
    }

    fwrite(STDOUT, '[PASS] ' . $label . PHP_EOL);
    return $stdout;
}

function releaseLintProject(string $root): void
{
    $directory = new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    );
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $entry): bool {
            if (!$entry->isDir()) {
                return true;
            }
            return !in_array($entry->getFilename(), ['.git', 'vendor', 'runtime'], true);
        },
    );
    $files = new RecursiveIteratorIterator($filter);

    $count = 0;
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        releaseLintFile($file->getPathname(), $root);
        $count++;
    }

    releaseGateAssert($count > 0, 'Project php -l gate found no PHP source files.');
    fwrite(STDOUT, '[PASS] PHP linted files=' . $count . PHP_EOL);
}

function releaseLintFile(string $path, string $cwd): void
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $path], $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start php -l for ' . $path . '.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $detail = trim($stderr !== '' ? $stderr : $stdout);
        throw new RuntimeException('php -l failed for ' . $path . ($detail === '' ? '.' : ': ' . $detail));
    }
}
