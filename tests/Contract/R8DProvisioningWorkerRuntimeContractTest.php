<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourceFile = $root . '/modules/openplatform/infrastructure/ThinkPhpProvisioningJobSource.php';
$commandFile = $root . '/app/worker/command/OpenPlatformProvisioningWorkerCommand.php';
$consoleFile = $root . '/config/console.php';
$runnerFile = $root . '/modules/openplatform/application/ProvisioningBatchRunner.php';
$readmeFile = $root . '/README.md';
$testRunnerFile = $root . '/tests/run.php';

foreach ([$sourceFile, $commandFile, $consoleFile, $runnerFile] as $file) {
    expectTrue(is_file($file), 'worker runtime file exists: ' . $file);
}

$source = (string) file_get_contents($sourceFile);
foreach ([
    "status = 'ready'",
    'next_attempt_at <= ?',
    "status = 'claimed'",
    'claim_expires_at IS NOT NULL',
    'claim_expires_at <= ?',
    'ORDER BY',
    'LIMIT ',
] as $needle) {
    expectTrue(str_contains($source, $needle), 'due source contains ' . $needle);
}
expectTrue(!str_contains(strtoupper($source), 'FOR UPDATE'), 'candidate discovery remains non-locking; tryClaim owns concurrency');
expectTrue(str_contains($source, '$limit < 1 || $limit > 1000'), 'source validates bounded batch size');

$runner = (string) file_get_contents($runnerFile);
expectTrue(str_contains($runner, 'catch (Throwable'), 'batch isolates one job exception');
expectTrue(!str_contains($runner, 'getMessage()'), 'batch never serializes exception plaintext');

$command = (string) file_get_contents($commandFile);
foreach ([
    "setName('openplatform:provisioning-worker')",
    "addOption('once'",
    "addOption('limit'",
    "addOption('sleep'",
    'ThinkPhpProvisioningJobSource',
    'AuthorizerProvisioningWorker::class',
    'ProvisioningBatchRunner',
] as $needle) {
    expectTrue(str_contains($command, $needle), 'CLI contains ' . $needle);
}
expectTrue(!str_contains($command, 'getMessage()'), 'CLI never writes exception messages');
expectTrue(!str_contains($command, 'SECRET_'), 'CLI contains no secret sentinel/logging path');

$console = (string) file_get_contents($consoleFile);
expectTrue(str_contains($console, 'OpenPlatformProvisioningWorkerCommand::class'), 'console registers provisioning worker command');


$readme = (string) file_get_contents($readmeFile);
expectTrue(str_contains($readme, 'Current implementation: R8D'), 'README reflects R8D implementation');
expectTrue(str_contains($readme, 'openplatform:provisioning-worker'), 'README documents worker startup');
expectTrue(str_contains($readme, 'systemd'), 'README documents production worker supervision');

$testRunner = (string) file_get_contents($testRunnerFile);
expectTrue(str_contains($testRunner, 'R8DProvisioningWorkerRuntimeContractTest.php'), 'offline runner registers worker runtime contract');
expectTrue(str_contains($testRunner, 'ProvisioningBatchRunnerTest.php'), 'offline runner registers batch runner behavior test');
