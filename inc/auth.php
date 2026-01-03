<?php
// inc/auth.php — Admin auth helpers (admins table), sessions + CSRF

declare(strict_types=1);

/**
 * This file is intentionally standalone:
 * - session_start_secure()
 * - csrf_token(), verify_csrf()
 * - require_admin(), require_role()
 * - admin_id(), admin_role(), admin_login()
 *
 * Assumes admin session keys:
 *   $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_login']
 */

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Harden session cookies
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    // Prevent cache issues for sensitive pages
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    session_start();
}

/** CSRF: token generator (per session) */
function csrf_token(): string
{
    session_start_secure();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** CSRF: verify on POST */
function verify_csrf(?string $field = 'csrf'): void
{
    session_start_secure();

    // Only enforce for state-changing methods
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $sent = (string)($_POST[$field] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');

    if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        exit;
    }
}

/** Auth getters */
function admin_id(): int
{
    session_start_secure();
    return (int)($_SESSION['admin_id'] ?? 0);
}

function admin_role(): string
{
    session_start_secure();
    $r = $_SESSION['admin_role'] ?? '';
    return is_string($r) ? $r : '';
}

function admin_login(): string
{
    session_start_secure();
    $l = $_SESSION['admin_login'] ?? '';
    return is_string($l) ? $l : '';
}

/** Require login for admin area */
function require_admin(): void
{
    session_start_secure();

    if (empty($_SESSION['admin_id'])) {
        $to = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php';
        header('Location: /admin/login.php?next=' . rawurlencode($to));
        exit;
    }
}

/**
 * Require role (simple RBAC)
 * Order of privilege:
 *   superadmin > admin > viewer
 */
function require_role(string $minRole): void
{
    require_admin();

    $hier = [
        'viewer'     => 1,
        'admin'      => 2,
        'superadmin' => 3,
    ];

    $current = admin_role();
    $cur = $hier[$current] ?? 0;
    $min = $hier[$minRole] ?? 999;

    if ($cur < $min) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
}

/** Convenience: safe HTML escape */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
