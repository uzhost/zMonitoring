<?php
// teachers/index.php - entry point for Teacher portal
// Redirects:
//   - not logged in  -> /teachers/login.php
//   - logged in     -> /teachers/dashboard.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/tauth.php';

session_start_secure();

// Prevent caching of redirects (important for auth pages).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo 'Method Not Allowed.';
    exit;
}

$next = safe_next_path_teacher((string)($_GET['next'] ?? ''), '/teachers/dashboard.php');

// Logged in?
if (teacher_id() > 0) {
    // Enforce valid teacher levels.
    if (function_exists('teacher_level') && !in_array((int)teacher_level(), [1, 2, 3], true)) {
        teacher_logout_session();
        header('Location: /teachers/login.php?err=level');
        exit;
    }

    header('Location: ' . $next);
    exit;
}

// Not logged in -> login page
header('Location: /teachers/login.php?next=' . rawurlencode($next));
exit;
