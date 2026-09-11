<?php

declare(strict_types=1);

use app\admin\support\AdminCookiePolicy;

$ttlSeconds = 3600;

$insecure = new AdminCookiePolicy(false);
expectSame('weplatform_admin_session', $insecure->sessionName(), 'session cookie name is stable');
expectSame('weplatform_admin_csrf', $insecure->csrfName(), 'csrf cookie name is stable');

$session = $insecure->sessionOptions($ttlSeconds);
expectSame($ttlSeconds, $session['expire'] ?? null, 'session cookie expiry follows TTL');
expectSame('/', $session['path'] ?? null, 'session cookie path is root');
expectSame(false, $session['secure'] ?? null, 'local session cookie may disable Secure');
expectSame(true, $session['httponly'] ?? null, 'session cookie is HttpOnly');
expectSame('lax', $session['samesite'] ?? null, 'session cookie SameSite is lax');

$csrf = $insecure->csrfOptions($ttlSeconds);
expectSame($ttlSeconds, $csrf['expire'] ?? null, 'csrf cookie expiry follows TTL');
expectSame('/', $csrf['path'] ?? null, 'csrf cookie path is root so /admin SPA can read it');
expectSame(false, $csrf['secure'] ?? null, 'local csrf cookie may disable Secure');
expectSame(false, $csrf['httponly'] ?? null, 'csrf cookie must be readable by the SPA');
expectSame('lax', $csrf['samesite'] ?? null, 'csrf cookie SameSite is lax');

$secure = new AdminCookiePolicy(true);
expectSame(true, $secure->sessionOptions($ttlSeconds)['secure'] ?? null, 'production session cookie is Secure');
expectSame(true, $secure->csrfOptions($ttlSeconds)['secure'] ?? null, 'production csrf cookie is Secure');
