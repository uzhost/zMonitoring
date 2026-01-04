<?php
// admin/logout.php — Secure logout (hardened)

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

// Recommended: prevent caching of authenticated pages after logout
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Optional hardening headers (safe defaults)
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Start/ensure session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start_secure(); // your hardened session starter
}

// Clear session array
$_SESSION = [];

// If sessions use cookies, expire the cookie with matching params (including SameSite)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    // Try to respect SameSite if your session_start_secure() sets it.
    // session_get_cookie_params() may not always include 'samesite' depending on how it was set.
    $sameSite = $params['samesite'] ?? 'Lax';

    // PHP 7.3+ supports array options for setcookie (PHP 8.3+ in your stack)
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => (bool)($params['secure'] ?? false),
        'httponly' => (bool)($params['httponly'] ?? true),
        'samesite' => $sameSite,
    ]);
}

// Destroy session data on the server
// (session_destroy() marks it for deletion; cookie deletion above handles client side)
session_destroy();

// Ensure session data is not written back
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Redirect to login
header('Location: login.php', true, 303);
exit;
