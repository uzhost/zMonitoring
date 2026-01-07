<?php
// teacher/index.php — entry point for Teacher portal
// Redirects:
//   - not logged in  -> /teacher/login.php
//   - logged in     -> /teacher/dashboard.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Prevent caching of redirects (important for auth pages)
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// -------------------- Auth check --------------------

// Logged in?
if (admin_id() > 0) {
    // Optional: enforce teacher/viewer only
    if (function_exists('admin_level') && (int)admin_level() !== 3) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    header('Location: /teacher/dashboard.php');
    exit;
}

// Not logged in → login page
header('Location: /teacher/login.php');
exit;
