<?php
// teacher/_guard.php — Level-3-only guard for teacher area (read-only pages)
// DROP-IN: avoids function-name collisions; enforces admin level=3 (not role string)

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

/**
 * Build a safe, same-origin relative "next" path for redirects.
 * NOTE: Function name is prefixed to avoid collisions with other pages.
 */
function teacher_safe_next_path(string $candidate, string $fallback = '/teacher/dashboard.php'): string
{
    $candidate = trim($candidate);

    if ($candidate === '') return $fallback;

    $parts = parse_url($candidate);
    $path  = $parts['path']  ?? '';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

    if ($path === '' || $path[0] !== '/') return $fallback;

    // loop prevention
    if (preg_match('#^/teacher/login\.php(?:$|[/?#])#', $path)) {
        return $fallback;
    }

    return $path . $query;
}

function teacher_deny_403(string $message = 'Forbidden.'): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function teacher_deny_405(array $allowed): void
{
    http_response_code(405);
    header('Allow: ' . implode(', ', $allowed));
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed.';
    exit;
}

// Security / caching headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Authentication: must be logged in
if (admin_id() <= 0) {
    $reqUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $to = teacher_safe_next_path($reqUri, '/teacher/dashboard.php');
    header('Location: /teacher/login.php?next=' . rawurlencode($to));
    exit;
}

// Authorization: level 3 only (tolerant session shapes)
$level = null;
if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && isset($_SESSION['admin']['level'])) {
    $level = (int)$_SESSION['admin']['level'];
} elseif (isset($_SESSION['admin_level'])) {
    $level = (int)$_SESSION['admin_level'];
} elseif (isset($_SESSION['level'])) {
    $level = (int)$_SESSION['level'];
}
if (($level ?? 0) !== 3) {
    teacher_deny_403('Forbidden.');
}

// Method policy: read-only
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    teacher_deny_405(['GET', 'HEAD']);
}
