<?php
// inc/auth.php — Admin auth helpers (admins table), hardened sessions + CSRF + RBAC + safe escaping

declare(strict_types=1);

/**
 * Standalone helpers:
 * - session_start_secure()
 * - csrf_token(), verify_csrf()
 * - require_admin(), require_role()
 * - admin_id(), admin_role(), admin_login()
 * - h() (NULL-safe), h_attr() (attribute-safe)
 *
 * Assumes admin session keys:
 *   $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_login']
 */

// ------------------------------------------------------------
// Session (hardened)
// ------------------------------------------------------------
function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Detect HTTPS (basic). If you're behind a reverse proxy, handle that in a dedicated security.php.
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Harden session cookies
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    // Lax is generally best for typical admin panels; if you never need cross-site POSTs, Strict is OK.
    ini_set('session.cookie_samesite', 'Lax');

    // Optional: mitigate very long-lived sessions (tune to your needs)
    // ini_set('session.gc_maxlifetime', '21600'); // 6 hours

    // NOTE: Do NOT send headers here (library file). Pages can set Cache-Control themselves.

    session_start();
}

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------

/** CSRF: token generator (per session) */
function csrf_token(): string
{
    session_start_secure();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF: verify on state-changing methods
 * Supports:
 * - POST field (default name "csrf")
 * - AJAX header: X-CSRF-Token
 */
function verify_csrf(string $field = 'csrf'): void
{
    session_start_secure();

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $sent = null;

    // Form field
    if (isset($_POST[$field])) {
        $sent = $_POST[$field];
    }

    // AJAX header support
    if ($sent === null || $sent === '') {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    $sess = $_SESSION['csrf_token'] ?? '';

    if (!is_string($sent)) {
        $sent = '';
    }
    if (!is_string($sess)) {
        $sess = '';
    }

    if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        exit;
    }
}

// ------------------------------------------------------------
// Auth getters
// ------------------------------------------------------------
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

// ------------------------------------------------------------
// RBAC
// ------------------------------------------------------------

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

// ------------------------------------------------------------
// Safe output helpers (NULL-safe)
// ------------------------------------------------------------

/**
 * Safe HTML escape (NULL-safe).
 * Use for normal text nodes: <?= h($value) ?>
 */
function h(mixed $s): string
{
    if ($s === null) {
        return '';
    }
    if (is_bool($s)) {
        return $s ? '1' : '0';
    }
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safe attribute escape helper (same as h(), explicit naming).
 * Use for attributes: <div data-x="<?= h_attr($value) ?>">
 */
function h_attr(mixed $s): string
{
    return h($s);
}

// ------------------------------------------------------------
// Optional: login helper to harden sessions after auth
// (Use this in /admin/login.php after verifying password.)
// ------------------------------------------------------------

/**
 * Call immediately after successful authentication.
 * Example:
 *   admin_login_session((int)$row['id'], (string)$row['role'], (string)$row['login']);
 */
function admin_login_session(int $id, string $role, string $login): void
{
    session_start_secure();

    $_SESSION['admin_id'] = $id;
    $_SESSION['admin_role'] = $role;
    $_SESSION['admin_login'] = $login;

    // Prevent session fixation
    session_regenerate_id(true);
}

/** Logout helper */
function admin_logout_session(): void
{
    session_start_secure();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), true);
    }

    session_destroy();
}
