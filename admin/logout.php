<?php
// admin/logout.php — Secure logout

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Unset all session data
$_SESSION = [];

// Delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
