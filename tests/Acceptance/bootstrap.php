<?php

declare(strict_types=1);

final readonly class AcceptanceConfig
{
    public function __construct(
        public string $root,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $pepper,
        public int $httpPort,
        public bool $keepDatabase,
    ) {
    }

    public static function load(string $root): self
    {
        $env = self::environment($root . '/.env');
        $read = static function (string $key, string $default = '') use ($env): string {
            $process = getenv($key);
            if (is_string($process) && $process !== '') {
                return $process;
            }
            return isset($env[$key]) ? (string) $env[$key] : $default;
        };

        if ($read('WEPLATFORM_ACCEPTANCE') !== '1') {
            throw new RuntimeException('Refusing to run: set WEPLATFORM_ACCEPTANCE=1 explicitly.');
        }

        $host = trim($read('DATABASE_HOSTNAME', '127.0.0.1'));
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Refusing to run: acceptance database must be local-only.');
        }

        $database = trim($read('DATABASE_DATABASE'));
        $databaseLower = strtolower($database);
        if (
            preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1
            || !(str_contains($databaseLower, 'acceptance') || str_ends_with($databaseLower, '_test'))
        ) {
            throw new RuntimeException('Refusing to run: acceptance database name must contain "acceptance" or end with "_test".');
        }

        $username = trim($read('DATABASE_USERNAME'));
        if ($username === '') {
            throw new RuntimeException('Refusing to run: DATABASE_USERNAME is required.');
        }

        $port = (int) $read('DATABASE_HOSTPORT', '3306');
        if ($port <= 0 || $port > 65535) {
            throw new RuntimeException('Refusing to run: DATABASE_HOSTPORT is invalid.');
        }

        $httpPort = (int) $read('WEPLATFORM_ACCEPTANCE_HTTP_PORT', '18080');
        if ($httpPort <= 0 || $httpPort > 65535) {
            throw new RuntimeException('Refusing to run: WEPLATFORM_ACCEPTANCE_HTTP_PORT is invalid.');
        }

        $pepper = trim($read('WEPLATFORM_ADMIN_SESSION_PEPPER'));
        if ($pepper === '') {
            $pepper = bin2hex(random_bytes(32));
        }

        return new self(
            root: $root,
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            password: $read('DATABASE_PASSWORD'),
            pepper: $pepper,
            httpPort: $httpPort,
            keepDatabase: $read('WEPLATFORM_ACCEPTANCE_KEEP_DB') === '1',
        );
    }

    /** @return array<string,string> */
    private static function environment(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $parsed = parse_ini_file($file, false, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            throw new RuntimeException('Unable to parse project .env file.');
        }
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $result[$key] = (string) $value;
            }
        }
        return $result;
    }

    /** @return array<string,string> */
    public function childEnvironment(): array
    {
        $current = getenv();
        $environment = is_array($current) ? array_map('strval', $current) : [];
        return array_merge($environment, [
            'WEPLATFORM_ADMIN_SESSION_PEPPER' => $this->pepper,
            'DATABASE_HOSTNAME' => $this->host,
            'DATABASE_DATABASE' => $this->database,
            'DATABASE_USERNAME' => $this->username,
            'DATABASE_PASSWORD' => $this->password,
            'DATABASE_HOSTPORT' => (string) $this->port,
            'DATABASE_CHARSET' => 'utf8mb4',
        ]);
    }
}

final class AcceptanceRuntime
{
    private ?PDO $database = null;
    /** @var resource|null */
    private $server = null;
    /** @var array<int,resource> */
    private array $serverPipes = [];

    public function __construct(public readonly AcceptanceConfig $config)
    {
    }

    public function preflight(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('pdo_mysql extension is required for acceptance tests.');
        }
        if (!is_file($this->config->root . '/vendor/autoload.php')) {
            throw new RuntimeException('vendor/autoload.php is required; run composer install locally first.');
        }
        if (!is_file($this->config->root . '/think')) {
            throw new RuntimeException('ThinkPHP console entrypoint is missing.');
        }
    }

    public function resetDatabase(): PDO
    {
        $server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config->host, $this->config->port),
            $this->config->username,
            $this->config->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ],
        );

        $identifier = '`' . $this->config->database . '`';
        $server->exec('DROP DATABASE IF EXISTS ' . $identifier);
        $server->exec('CREATE DATABASE ' . $identifier . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->database = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->host,
                $this->config->port,
                $this->config->database,
            ),
            $this->config->username,
            $this->config->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ],
        );
        return $this->database;
    }

    public function db(): PDO
    {
        if (!$this->database instanceof PDO) {
            throw new RuntimeException('Acceptance database is not initialized.');
        }
        return $this->database;
    }

    public function cleanupDatabase(): void
    {
        $this->database = null;
        if ($this->config->keepDatabase || !extension_loaded('pdo_mysql')) {
            return;
        }
        $server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config->host, $this->config->port),
            $this->config->username,
            $this->config->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $server->exec('DROP DATABASE IF EXISTS `' . $this->config->database . '`');
    }

    public function startServer(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $command = [
            PHP_BINARY,
            $this->config->root . '/think',
            'run',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $this->config->httpPort,
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->config->root,
            $this->config->childEnvironment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ThinkPHP acceptance server.');
        }
        $this->server = $process;
        $this->serverPipes = $pipes;
        foreach ($this->serverPipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        fclose($this->serverPipes[0]);
        unset($this->serverPipes[0]);

        $deadline = microtime(true) + 15.0;
        do {
            $status = proc_get_status($this->server);
            if (!$status['running']) {
                throw new RuntimeException('ThinkPHP acceptance server exited during startup.');
            }
            try {
                $response = $this->http('GET', '/api/v1/health');
                if ($response['status'] === 200) {
                    return;
                }
            } catch (Throwable) {
                // Server may still be booting.
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('ThinkPHP acceptance server health check did not become ready.');
    }

    public function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
        }
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->serverPipes = [];
        if (is_resource($this->server)) {
            proc_close($this->server);
        }
        $this->server = null;
    }

    /** @return array{status:int,body:string,json:array<string,mixed>|null} */
    public function http(string $method, string $path, array $headers = []): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $body = file_get_contents(
            'http://127.0.0.1:' . $this->config->httpPort . $path,
            false,
            $context,
        );
        if ($body === false) {
            throw new RuntimeException('HTTP acceptance request failed.');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }
        $json = json_decode($body, true);
        return [
            'status' => $status,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
        ];
    }
}

function acceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function acceptanceCode(array $response): ?string
{
    $json = $response['json'] ?? null;
    if (!is_array($json)) {
        return null;
    }
    $candidate = $json['code'] ?? ($json['error']['code'] ?? null);
    return is_string($candidate) ? $candidate : null;
}
