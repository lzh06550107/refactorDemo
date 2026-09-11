<?php

declare(strict_types=1);

/**
 * @param array<string,string> $headers
 * @return array{status:int,body:string,json:array<string,mixed>|null,headers:list<string>}
 */
function acceptanceAdminBrowserHttp(
    AcceptanceRuntime $runtime,
    string $method,
    string $path,
    array $headers = [],
    ?string $body = null,
): array {
    $headerLines = ['Accept: application/json'];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'ignore_errors' => true,
        'timeout' => 5,
    ];
    if ($body !== null) {
        $options['content'] = $body;
    }

    $context = stream_context_create(['http' => $options]);
    $responseBody = file_get_contents(
        'http://127.0.0.1:' . $runtime->config->httpPort . $path,
        false,
        $context,
    );
    if ($responseBody === false) {
        throw new RuntimeException('Admin browser HTTP acceptance request failed.');
    }

    /** @var list<string> $responseHeaders */
    $responseHeaders = isset($http_response_header) && is_array($http_response_header)
        ? array_values(array_map('strval', $http_response_header))
        : [];

    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    $json = json_decode($responseBody, true);
    return [
        'status' => $status,
        'body' => $responseBody,
        'json' => is_array($json) ? $json : null,
        'headers' => $responseHeaders,
    ];
}

/**
 * @param list<string> $headers
 * @return array{value:string,header:string}|null
 */
function acceptanceAdminBrowserCookie(array $headers, string $name): ?array
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            continue;
        }

        $cookie = trim(substr($header, strlen('Set-Cookie:')));
        $pair = explode(';', $cookie, 2)[0] ?? '';
        $parts = explode('=', $pair, 2);
        if (count($parts) !== 2 || strcasecmp(trim($parts[0]), $name) !== 0) {
            continue;
        }

        return [
            'value' => rawurldecode(trim($parts[1])),
            'header' => $cookie,
        ];
    }

    return null;
}

/** @param array<string,mixed>|null $json */
function acceptanceAdminBrowserData(?array $json): ?array
{
    if (!is_array($json)) {
        return null;
    }
    $data = $json['data'] ?? null;
    return is_array($data) ? $data : null;
}

function acceptanceAdminBrowserAuthRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $adminId = 'accept-browser-admin';
    $username = 'accept-browser-admin';
    $password = 'acceptance-password';

    $insert = $db->prepare(
        'INSERT INTO admin_users (id, username, password_hash, status, expires_at) VALUES (?, ?, ?, ?, NULL)',
    );
    $insert->execute([
        $adminId,
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        'active',
    ]);

    $runtime->startServer();
    try {
        $csrfResponse = acceptanceAdminBrowserHttp(
            $runtime,
            'GET',
            '/admin-api/v1/auth/csrf',
        );
        acceptanceAssert($csrfResponse['status'] === 200, 'Admin CSRF bootstrap must return 200.');
        $csrfCookie = acceptanceAdminBrowserCookie($csrfResponse['headers'], 'weplatform_admin_csrf');
        acceptanceAssert($csrfCookie !== null && $csrfCookie['value'] !== '', 'Admin CSRF bootstrap must set a readable CSRF cookie.');
        acceptanceAssert(
            stripos($csrfCookie['header'], 'httponly') === false,
            'Admin CSRF cookie must remain readable by the browser SPA.',
        );
        $csrfToken = $csrfCookie['value'];

        $loginPayload = json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR);
        $loginResponse = acceptanceAdminBrowserHttp(
            $runtime,
            'POST',
            '/admin-api/v1/auth/login',
            [
                'Content-Type' => 'application/json',
                'X-CSRF-Token' => $csrfToken,
                'Cookie' => 'weplatform_admin_csrf=' . rawurlencode($csrfToken),
            ],
            $loginPayload,
        );
        acceptanceAssert($loginResponse['status'] === 200, 'Admin browser login must return 200.');
        acceptanceAssert(
            acceptanceAdminBrowserData($loginResponse['json']) === [
                'id' => $adminId,
                'username' => $username,
            ],
            'Admin browser login must return the authenticated administrator profile.',
        );

        $sessionCookie = acceptanceAdminBrowserCookie($loginResponse['headers'], 'weplatform_admin_session');
        acceptanceAssert($sessionCookie !== null && $sessionCookie['value'] !== '', 'Admin browser login must set a session cookie.');
        acceptanceAssert(
            stripos($sessionCookie['header'], 'httponly') !== false,
            'Admin browser session cookie must be HttpOnly.',
        );
        $rawSessionToken = $sessionCookie['value'];

        $sessionQuery = $db->prepare(
            'SELECT token_hash FROM admin_sessions WHERE admin_user_id = ? ORDER BY created_at ASC',
        );
        $sessionQuery->execute([$adminId]);
        $storedTokenHashes = $sessionQuery->fetchAll(PDO::FETCH_COLUMN);
        acceptanceAssert(count($storedTokenHashes) === 1, 'Admin browser login must persist exactly one database session.');
        $storedTokenHash = is_string($storedTokenHashes[0] ?? null) ? $storedTokenHashes[0] : '';
        acceptanceAssert($storedTokenHash !== '', 'Admin browser database session must contain a token hash.');
        acceptanceAssert($storedTokenHash !== $rawSessionToken, 'Admin browser database session must never store the raw cookie token.');
        acceptanceAssert(
            hash_equals(hash_hmac('sha256', $rawSessionToken, $runtime->config->pepper), $storedTokenHash),
            'Admin browser database session must store the expected one-way token hash.',
        );

        $sessionHeader = 'weplatform_admin_session=' . rawurlencode($rawSessionToken);
        $meResponse = acceptanceAdminBrowserHttp(
            $runtime,
            'GET',
            '/admin-api/v1/auth/me',
            ['Cookie' => $sessionHeader],
        );
        acceptanceAssert($meResponse['status'] === 200, 'Authenticated Admin me must return 200.');
        acceptanceAssert(
            acceptanceAdminBrowserData($meResponse['json']) === [
                'id' => $adminId,
                'username' => $username,
            ],
            'Authenticated Admin me must restore the browser session principal.',
        );

        $dashboardResponse = acceptanceAdminBrowserHttp(
            $runtime,
            'GET',
            '/admin-api/v1/dashboard',
            ['Cookie' => $sessionHeader],
        );
        acceptanceAssert($dashboardResponse['status'] === 200, 'Authenticated Admin dashboard must return 200.');
        acceptanceAssert(
            acceptanceAdminBrowserData($dashboardResponse['json']) === [
                'application' => 'admin',
                'status' => 'ready',
                'admin_user_id' => $adminId,
            ],
            'Authenticated Admin dashboard must expose the restored administrator principal.',
        );

        $logoutResponse = acceptanceAdminBrowserHttp(
            $runtime,
            'POST',
            '/admin-api/v1/auth/logout',
            [
                'Content-Type' => 'application/json',
                'X-CSRF-Token' => $csrfToken,
                'Cookie' => $sessionHeader . '; weplatform_admin_csrf=' . rawurlencode($csrfToken),
            ],
            '{}',
        );
        acceptanceAssert($logoutResponse['status'] === 200, 'Admin browser logout must return 200.');
        acceptanceAssert(
            acceptanceAdminBrowserData($logoutResponse['json']) === ['logged_out' => true],
            'Admin browser logout must confirm logout without exposing credentials.',
        );

        $oldSessionResponse = acceptanceAdminBrowserHttp(
            $runtime,
            'GET',
            '/admin-api/v1/auth/me',
            ['Cookie' => $sessionHeader],
        );
        acceptanceAssert($oldSessionResponse['status'] === 401, 'Logged-out Admin browser session must be rejected with 401.');
        acceptanceAssert(
            acceptanceCode($oldSessionResponse) === 'UNAUTHORIZED',
            'Logged-out Admin browser session must return UNAUTHORIZED.',
        );

        $remainingSessions = $db->prepare('SELECT COUNT(*) FROM admin_sessions WHERE admin_user_id = ?');
        $remainingSessions->execute([$adminId]);
        acceptanceAssert((int) $remainingSessions->fetchColumn() === 0, 'Admin browser logout must remove the database session.');
    } finally {
        $runtime->stopServer();
        $delete = $db->prepare('DELETE FROM admin_users WHERE id = ?');
        $delete->execute([$adminId]);
    }
}
