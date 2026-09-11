<?php

declare(strict_types=1);

const PROVIDER_E2E_SUCCESS_STATUSES = ['provisioned', 'reconnected'];
const PROVIDER_E2E_FAILURE_STATUSES = [
    'quota_blocked',
    'binding_conflict',
    'metadata_failed',
    'metadata_type_conflict',
    'provision_failed',
    'authorization_inactive',
];

function providerE2EFail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit($exitCode);
}

function providerE2ERequiredProcessEnv(string $key): string
{
    $value = getenv($key);
    if (!is_string($value) || trim($value) === '') {
        providerE2EFail($key . ' is required.');
    }
    return trim($value);
}

function providerE2EOptionalProcessEnv(string $key, string $default): string
{
    $value = getenv($key);
    return !is_string($value) || trim($value) === '' ? $default : trim($value);
}

/** @return array<string,string> */
function providerE2EDotEnv(string $root): array
{
    $path = $root . '/.env';
    if (!is_file($path)) {
        return [];
    }

    $result = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '') {
            continue;
        }
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $result[$key] = $value;
    }

    return $result;
}

/** @param array<string,string> $dotEnv */
function providerE2EEnvOrDotEnv(string $key, array $dotEnv, ?string $default = null): ?string
{
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (array_key_exists($key, $dotEnv)) {
        return $dotEnv[$key];
    }
    return $default;
}

/**
 * @param array<string,string> $headers
 * @param array<string,mixed>|null $body
 * @return array<string,mixed>
 */
function providerE2EHttpJson(string $method, string $url, array $headers, ?array $body = null): array
{
    $headerLines = ['Accept: application/json'];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];

    if ($body !== null) {
        $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $options['http']['header'] .= "\r\nContent-Type: application/json";
        $options['http']['content'] = $encoded;
    }

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $status = preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1 ? (int) $matches[1] : 0;

    if ($response === false) {
        throw new RuntimeException('HTTP request failed before a response was received.');
    }

    try {
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('HTTP response was not valid JSON.');
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('HTTP response envelope was invalid.');
    }

    $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'UNKNOWN';
    $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'request failed';
    if ($status < 200 || $status >= 300 || $code !== 'OK') {
        throw new RuntimeException(sprintf('HTTP %d provider E2E API error %s: %s', $status, $code, $message));
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        throw new RuntimeException('HTTP success envelope did not contain object data.');
    }
    return $data;
}

/** @return array<string,string> */
function providerE2EAdminHeaders(string $token, string $tenantId): array
{
    return [
        'Authorization' => 'Bearer ' . $token,
        'X-Tenant-Id' => $tenantId,
    ];
}

function providerE2EStart(string $baseUrl, string $componentPlatformId, string $token, string $tenantId): void
{
    $authType = providerE2EOptionalProcessEnv('WEPLATFORM_PROVIDER_E2E_REQUESTED_AUTH_TYPE', '3');
    $data = providerE2EHttpJson(
        'POST',
        $baseUrl . '/api/v1/openplatform/components/' . rawurlencode($componentPlatformId) . '/authorization-intents',
        providerE2EAdminHeaders($token, $tenantId),
        [
            'mode' => 'auto_provision_account',
            'requestedAuthType' => $authType,
        ],
    );

    $authorizationUrl = $data['authorization_url'] ?? null;
    if (!is_string($authorizationUrl) || !str_starts_with($authorizationUrl, 'https://')) {
        throw new RuntimeException('Authorization start did not return a valid HTTPS authorization_url.');
    }

    fwrite(STDOUT, "[MANUAL] Open the following one-time authorization URL in a browser and finish WeChat authorization.\n");
    fwrite(STDOUT, "Do not paste it into chat, tickets, logs, or documentation.\n\n");
    fwrite(STDOUT, $authorizationUrl . PHP_EOL . PHP_EOL);
    fwrite(STDOUT, "After WeChat redirects to the callback, copy only data.provisioning_id from the JSON response.\n");
    fwrite(STDOUT, "Then run: php tests/ProviderE2E/run.php verify <provisioning_id>\n");
}

function providerE2ERunWorkerOnce(string $root): void
{
    $command = [
        PHP_BINARY,
        $root . '/think',
        'openplatform:provisioning-worker',
        '--once',
        '--limit=100',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start openplatform:provisioning-worker.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    unset($stdout, $stderr);
    if ($exitCode !== 0) {
        throw new RuntimeException('openplatform:provisioning-worker returned a non-zero exit code.');
    }
}

/** @param array<string,mixed> $apiData */
function providerE2EAssertDatabaseConsistency(string $root, string $tenantId, string $provisioningId, array $apiData): void
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('pdo_mysql is required for Provider E2E consistency checks.');
    }

    $dotEnv = providerE2EDotEnv($root);
    $host = (string) providerE2EEnvOrDotEnv('DATABASE_HOSTNAME', $dotEnv, '127.0.0.1');
    $port = (string) providerE2EEnvOrDotEnv('DATABASE_HOSTPORT', $dotEnv, '3306');
    $database = (string) providerE2EEnvOrDotEnv('DATABASE_DATABASE', $dotEnv, '');
    $username = (string) providerE2EEnvOrDotEnv('DATABASE_USERNAME', $dotEnv, '');
    $password = (string) providerE2EEnvOrDotEnv('DATABASE_PASSWORD', $dotEnv, '');
    $charset = (string) providerE2EEnvOrDotEnv('DATABASE_CHARSET', $dotEnv, 'utf8mb4');
    if ($database === '' || $username === '') {
        throw new RuntimeException('Database name and username are required for Provider E2E consistency checks.');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );

    $statement = $pdo->prepare(
        'SELECT id, tenant_id, component_platform_id, authorizer_app_id, account_type, status, '
        . 'quota_resource_key, quota_consume_entry_id, quota_release_entry_id, account_id '
        . 'FROM authorizer_provisionings WHERE id = ? AND tenant_id = ?',
    );
    $statement->execute([$provisioningId, $tenantId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Provisioning row was not found in the expected Tenant.');
    }

    foreach (['status', 'component_platform_id', 'authorizer_app_id', 'account_id'] as $key) {
        if (($apiData[$key] ?? null) !== ($row[$key] ?? null)) {
            throw new RuntimeException('Provisioning API/database mismatch for ' . $key . '.');
        }
    }

    $status = (string) $row['status'];
    $accountId = (string) ($row['account_id'] ?? '');
    $componentPlatformId = (string) $row['component_platform_id'];
    $authorizerAppId = (string) $row['authorizer_app_id'];
    $accountType = (string) ($row['account_type'] ?? '');
    if ($accountId === '' || $accountType === '') {
        throw new RuntimeException('Successful provisioning must have account_id and account_type.');
    }

    $accountCount = providerE2EScalarInt(
        $pdo,
        'SELECT COUNT(*) FROM accounts WHERE id = ? AND tenant_id = ? AND type = ?',
        [$accountId, $tenantId, $accountType],
    );
    if ($accountCount !== 1) {
        throw new RuntimeException('Expected exactly one matching Account.');
    }

    $ownershipCount = providerE2EScalarInt(
        $pdo,
        'SELECT COUNT(*) FROM authorizer_account_ownerships '
        . 'WHERE component_platform_id = ? AND authorizer_app_id = ? AND tenant_id = ? AND account_id = ?',
        [$componentPlatformId, $authorizerAppId, $tenantId, $accountId],
    );
    if ($ownershipCount !== 1) {
        throw new RuntimeException('Expected exactly one canonical authorizer ownership row.');
    }

    $bindingTable = match ($accountType) {
        'official_account' => 'official_account_provider_accounts',
        'wechat_mini_program' => 'miniapp_provider_accounts',
        default => throw new RuntimeException('Unsupported Provider E2E Account type: ' . $accountType),
    };
    $bindingCount = providerE2EScalarInt(
        $pdo,
        'SELECT COUNT(*) FROM ' . $bindingTable
        . ' WHERE account_id = ? AND tenant_id = ? AND provider_app_id = ? '
        . "AND component_platform_id = ? AND connection_mode = 'component' AND enabled = 1",
        [$accountId, $tenantId, $authorizerAppId, $componentPlatformId],
    );
    if ($bindingCount !== 1) {
        throw new RuntimeException('Expected exactly one enabled component provider binding.');
    }

    $resourceKey = is_string($row['quota_resource_key'] ?? null) ? $row['quota_resource_key'] : null;
    $semanticKey = sprintf('openplatform-provision:%s:%s:%s', $componentPlatformId, $authorizerAppId, $tenantId);
    $semanticConsumeCount = $resourceKey === null || $resourceKey === ''
        ? 0
        : providerE2EScalarInt(
            $pdo,
            "SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = ? AND resource_key = ? "
            . "AND idempotency_key = ? AND entry_type = 'consume'",
            [$tenantId, $resourceKey, $semanticKey],
        );
    if ($semanticConsumeCount > 1) {
        throw new RuntimeException('Semantic quota consume count exceeded one.');
    }

    if ($status === 'provisioned') {
        $consumeEntryId = is_string($row['quota_consume_entry_id'] ?? null) ? $row['quota_consume_entry_id'] : '';
        if ($consumeEntryId === '' || $resourceKey === null || $resourceKey === '') {
            throw new RuntimeException('Newly provisioned Account must reference its quota consume entry.');
        }
        $consumeEntryCount = providerE2EScalarInt(
            $pdo,
            "SELECT COUNT(*) FROM quota_ledger_entries WHERE id = ? AND tenant_id = ? AND resource_key = ? AND entry_type = 'consume'",
            [$consumeEntryId, $tenantId, $resourceKey],
        );
        if ($consumeEntryCount !== 1 || $semanticConsumeCount !== 1) {
            throw new RuntimeException('Newly provisioned Account must have exactly one semantic quota consume.');
        }
    }

    if ($status === 'reconnected' && $row['quota_consume_entry_id'] !== null) {
        throw new RuntimeException('Reconnect provisioning must not carry a new quota consume reference.');
    }

    if ($row['quota_release_entry_id'] !== null) {
        throw new RuntimeException('Successful Provider E2E result must not contain a quota release entry.');
    }
}

/** @param list<mixed> $params */
function providerE2EScalarInt(PDO $pdo, string $sql, array $params): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function providerE2EVerify(
    string $root,
    string $baseUrl,
    string $token,
    string $tenantId,
    string $provisioningId,
): void {
    $maxAttemptsRaw = providerE2EOptionalProcessEnv('WEPLATFORM_PROVIDER_E2E_MAX_ATTEMPTS', '20');
    if (preg_match('/^[1-9][0-9]*$/', $maxAttemptsRaw) !== 1) {
        throw new RuntimeException('WEPLATFORM_PROVIDER_E2E_MAX_ATTEMPTS must be a positive integer.');
    }
    $maxAttempts = min(100, (int) $maxAttemptsRaw);
    $headers = providerE2EAdminHeaders($token, $tenantId);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $data = providerE2EHttpJson(
            'GET',
            $baseUrl . '/api/v1/openplatform/provisionings/' . rawurlencode($provisioningId),
            $headers,
        );
        $status = $data['status'] ?? null;
        if (!is_string($status) || $status === '') {
            throw new RuntimeException('Provisioning query returned no status.');
        }

        if (in_array($status, PROVIDER_E2E_SUCCESS_STATUSES, true)) {
            providerE2EAssertDatabaseConsistency($root, $tenantId, $provisioningId, $data);
            fwrite(STDOUT, sprintf(
                "[PASS] Real WeChat Provider E2E status=%s provisioning_id=%s account_id=%s authorizer_app_id=%s\n",
                $status,
                $provisioningId,
                (string) ($data['account_id'] ?? ''),
                (string) ($data['authorizer_app_id'] ?? ''),
            ));
            return;
        }

        if (in_array($status, PROVIDER_E2E_FAILURE_STATUSES, true)) {
            $errorCode = is_string($data['last_error_code'] ?? null) ? $data['last_error_code'] : 'none';
            $errorStage = is_string($data['last_error_stage'] ?? null) ? $data['last_error_stage'] : 'none';
            throw new RuntimeException(sprintf(
                'Provider E2E reached terminal failure status=%s error_code=%s error_stage=%s.',
                $status,
                $errorCode,
                $errorStage,
            ));
        }

        providerE2ERunWorkerOnce($root);
        if ($attempt < $maxAttempts) {
            sleep(1);
        }
    }

    throw new RuntimeException('Provider E2E did not reach a terminal status within the configured attempts.');
}

function providerE2EMain(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        providerE2EFail('Provider E2E runner is CLI-only.');
    }

    $providerE2EEnabled = getenv('WEPLATFORM_PROVIDER_E2E') === '1';
    if (!$providerE2EEnabled) {
        providerE2EFail('Refusing real provider traffic. Set WEPLATFORM_PROVIDER_E2E=1 explicitly.');
    }

    $root = dirname(__DIR__, 2);
    $baseUrl = rtrim(providerE2ERequiredProcessEnv('WEPLATFORM_PROVIDER_E2E_BASE_URL'), '/');
    if (!str_starts_with($baseUrl, 'https://')) {
        providerE2EFail('WEPLATFORM_PROVIDER_E2E_BASE_URL must use public HTTPS.');
    }
    $token = providerE2ERequiredProcessEnv('WEPLATFORM_PROVIDER_E2E_ADMIN_BEARER_TOKEN');
    $tenantId = providerE2ERequiredProcessEnv('WEPLATFORM_PROVIDER_E2E_TENANT_ID');
    $componentPlatformId = providerE2ERequiredProcessEnv('WEPLATFORM_PROVIDER_E2E_COMPONENT_PLATFORM_ID');

    $command = $argv[1] ?? '';
    try {
        match ($command) {
            'start' => providerE2EStart($baseUrl, $componentPlatformId, $token, $tenantId),
            'verify' => providerE2EVerify(
                $root,
                $baseUrl,
                $token,
                $tenantId,
                isset($argv[2]) && trim($argv[2]) !== ''
                    ? trim($argv[2])
                    : throw new RuntimeException('verify requires <provisioning_id>.'),
            ),
            default => throw new RuntimeException('Usage: php tests/ProviderE2E/run.php start | verify <provisioning_id>'),
        };
    } catch (Throwable $e) {
        providerE2EFail($e->getMessage());
    }
}

providerE2EMain($argv);
