<?php

declare(strict_types=1);

function acceptanceWorkerRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $before = (int) $db->query('SELECT COUNT(*) FROM authorizer_provisioning_jobs')->fetchColumn();
    acceptanceAssert($before === 0, 'Fresh acceptance database must start with an empty provisioning queue.');

    $command = [
        PHP_BINARY,
        $runtime->config->root . '/think',
        'openplatform:provisioning-worker',
        '--once',
        '--limit=10',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $runtime->config->root,
        $runtime->config->childEnvironment(),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start provisioning worker acceptance process.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    acceptanceAssert($exitCode === 0, 'Provisioning worker empty-queue smoke must exit successfully.');
    acceptanceAssert(trim($stderr) === '', 'Provisioning worker empty-queue smoke must not emit stderr.');
    acceptanceAssert(
        str_contains($stdout, 'provisioning batch discovered=0 handled=0 failed=0'),
        'Provisioning worker empty-queue smoke must report zero discovered/handled/failed.',
    );

    $after = (int) $db->query('SELECT COUNT(*) FROM authorizer_provisioning_jobs')->fetchColumn();
    acceptanceAssert($after === 0, 'Provisioning worker empty-queue smoke must not create jobs.');
}
