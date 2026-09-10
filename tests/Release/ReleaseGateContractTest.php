<?php

declare(strict_types=1);

function releaseGateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function releaseGateContractTest(string $root): void
{
    $run = $root . '/tests/Release/run.php';
    releaseGateAssert(is_file($run), 'Local release runner must exist.');

    $source = (string) file_get_contents($run);

    foreach ([
        "composer.lock",
        "vendor/autoload.php",
        "pdo_mysql",
        "tests/run.php",
        "vendor/bin/phpunit",
        "RecursiveDirectoryIterator",
        'releaseLintProject($root)',
        "php -l",
        "openplatform:provisioning-worker",
        "tests/Acceptance/run.php",
        "WEPLATFORM_ACCEPTANCE",
    ] as $needle) {
        releaseGateAssert(str_contains($source, $needle), 'Release runner must contain gate: ' . $needle);
    }

    foreach (['pdo_mysql', 'mbstring', 'dom', 'xmlwriter'] as $extension) {
        releaseGateAssert(
            str_contains($source, "'" . $extension . "' =>"),
            'Release preflight extension list must contain: ' . $extension,
        );
    }
    releaseGateAssert(
        str_contains($source, 'extension_loaded($extension)'),
        'Release preflight must evaluate every required extension.',
    );

    $offline = strpos($source, "tests/run.php");
    $phpunit = strpos($source, "vendor/bin/phpunit");
    $lint = strpos($source, 'releaseLintProject($root)');
    $think = strpos($source, "openplatform:provisioning-worker");
    $acceptance = strpos($source, "tests/Acceptance/run.php");
    releaseGateAssert(
        is_int($offline) && is_int($phpunit) && is_int($lint) && is_int($think) && is_int($acceptance)
        && $offline < $phpunit && $phpunit < $lint && $lint < $think && $think < $acceptance,
        'Release gates must run in approved order.',
    );

    foreach (["'.git'", "'vendor'", "'runtime'"] as $excluded) {
        releaseGateAssert(str_contains($source, $excluded), 'Project lint must exclude generated/dependency tree: ' . $excluded);
    }

    releaseGateAssert(!str_contains(strtolower($source), 'github actions'), 'GitHub Actions must not participate in local release gate.');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        releaseGateContractTest(dirname(__DIR__, 2));
        fwrite(STDOUT, "[PASS] ReleaseGateContractTest\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, '[FAIL] ReleaseGateContractTest: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
