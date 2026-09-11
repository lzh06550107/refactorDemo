<?php

declare(strict_types=1);

/**
 * @return array{exit:int,stdout:string,stderr:string}
 */
function acceptanceRunAdminBootstrap(
    AcceptanceRuntime $runtime,
    string $username,
    string $password,
): array {
    $environment = $runtime->config->childEnvironment();
    $environment['WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD'] = $password;

    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            $runtime->config->root . '/think',
            'admin:bootstrap',
            '--username=' . $username,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $runtime->config->root,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Admin bootstrap command.');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * @param list<array{username:string,password:string}> $attempts
 * @return list<array{username:string,password:string,exit:int,stdout:string,stderr:string}>
 */
function acceptanceRunConcurrentAdminBootstraps(AcceptanceRuntime $runtime, array $attempts): array
{
    $running = [];

    foreach ($attempts as $attempt) {
        $environment = $runtime->config->childEnvironment();
        $environment['WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD'] = $attempt['password'];
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $runtime->config->root . '/think',
                'admin:bootstrap',
                '--username=' . $attempt['username'],
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $runtime->config->root,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrent Admin bootstrap command.');
        }

        fclose($pipes[0]);
        $running[] = [
            'username' => $attempt['username'],
            'password' => $attempt['password'],
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $results = [];
    foreach ($running as $entry) {
        $stdout = (string) stream_get_contents($entry['stdout']);
        $stderr = (string) stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exitCode = proc_close($entry['process']);

        $results[] = [
            'username' => $entry['username'],
            'password' => $entry['password'],
            'exit' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    return $results;
}

function acceptanceAdminBootstrapRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $db->exec('DELETE FROM admin_sessions');
    $db->exec('DELETE FROM tenant_memberships');
    $db->exec('DELETE FROM admin_users');

    $firstPassword = 'accept-' . bin2hex(random_bytes(16));
    $first = acceptanceRunAdminBootstrap($runtime, 'accept-admin', $firstPassword);
    acceptanceAssert($first['exit'] === 0, 'Initial Admin bootstrap must exit 0 on an empty database.');
    acceptanceAssert(
        !str_contains($first['stdout'] . $first['stderr'], $firstPassword),
        'Initial Admin bootstrap output must never expose the plaintext password.',
    );

    $row = $db->query(
        "SELECT id, username, password_hash, status, expires_at, session_version FROM admin_users ORDER BY created_at ASC LIMIT 1",
    )->fetch(PDO::FETCH_ASSOC);
    acceptanceAssert(is_array($row), 'Initial Admin bootstrap must persist an administrator row.');
    acceptanceAssert((int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1, 'Initial Admin bootstrap must create exactly one administrator.');
    acceptanceAssert(($row['username'] ?? null) === 'accept-admin', 'Initial Admin bootstrap must persist the requested username.');
    acceptanceAssert(($row['status'] ?? null) === 'active', 'Initial Admin bootstrap must create an active administrator.');
    acceptanceAssert(($row['expires_at'] ?? null) === null, 'Initial Admin bootstrap administrator must not expire by default.');
    acceptanceAssert((int) ($row['session_version'] ?? -1) === 0, 'Initial Admin bootstrap must initialize session_version to zero.');
    $storedHash = is_string($row['password_hash'] ?? null) ? $row['password_hash'] : '';
    acceptanceAssert($storedHash !== '' && $storedHash !== $firstPassword, 'Initial Admin bootstrap must persist only a password hash.');
    acceptanceAssert(password_verify($firstPassword, $storedHash), 'Initial Admin bootstrap hash must verify the supplied password.');

    $secondPassword = 'accept-' . bin2hex(random_bytes(16));
    $second = acceptanceRunAdminBootstrap($runtime, 'other-admin', $secondPassword);
    acceptanceAssert($second['exit'] !== 0, 'A second Admin bootstrap must fail closed.');
    acceptanceAssert((int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1, 'A refused second Admin bootstrap must not mutate admin_users.');
    acceptanceAssert(
        !str_contains($second['stdout'] . $second['stderr'], $secondPassword),
        'Refused Admin bootstrap output must never expose the plaintext password.',
    );

    $db->exec('DELETE FROM admin_sessions');
    $db->exec('DELETE FROM tenant_memberships');
    $db->exec('DELETE FROM admin_users');

    $attempts = [
        [
            'username' => 'concurrent-admin-a',
            'password' => 'concurrent-' . bin2hex(random_bytes(16)),
        ],
        [
            'username' => 'concurrent-admin-b',
            'password' => 'concurrent-' . bin2hex(random_bytes(16)),
        ],
    ];
    $results = acceptanceRunConcurrentAdminBootstraps($runtime, $attempts);
    $successes = array_values(array_filter(
        $results,
        static fn (array $result): bool => $result['exit'] === 0,
    ));
    $failures = array_values(array_filter(
        $results,
        static fn (array $result): bool => $result['exit'] !== 0,
    ));

    acceptanceAssert(count($successes) === 1, 'Exactly one concurrent Admin bootstrap must succeed.');
    acceptanceAssert(count($failures) === 1, 'Exactly one concurrent Admin bootstrap must fail.');
    acceptanceAssert((int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1, 'Concurrent Admin bootstrap must leave exactly one administrator.');

    $winner = $successes[0];
    $winnerRow = $db->query('SELECT username, password_hash FROM admin_users LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    acceptanceAssert(is_array($winnerRow), 'Concurrent Admin bootstrap winner must be persisted.');
    acceptanceAssert(($winnerRow['username'] ?? null) === $winner['username'], 'Persisted concurrent administrator must match the successful process.');
    $winnerHash = is_string($winnerRow['password_hash'] ?? null) ? $winnerRow['password_hash'] : '';
    acceptanceAssert(password_verify($winner['password'], $winnerHash), 'Concurrent Admin bootstrap winner password must verify.');

    foreach ($results as $result) {
        acceptanceAssert(
            !str_contains($result['stdout'] . $result['stderr'], $result['password']),
            'Concurrent Admin bootstrap output must never expose a plaintext password.',
        );
    }

    $db->exec('DELETE FROM admin_sessions');
    $db->exec('DELETE FROM tenant_memberships');
    $db->exec('DELETE FROM admin_users');
}
