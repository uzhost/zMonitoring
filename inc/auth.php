<?php
// inc/auth.php — Admin auth helpers (admins table), hardened sessions + CSRF + RBAC + safe escaping

declare(strict_types=1);

/**
 * Standalone helpers:
 * - session_start_secure()
 * - csrf_token(), csrf_field(), verify_csrf()
 * - require_admin(), require_role()
 * - admin_id(), admin_role(), admin_login()
 * - admin_login_session(), admin_logout_session()
 * - h() (NULL-safe), h_attr()
 * - safe_next_url()   (optional, for login redirect safety)
 *
 * Assumes admin session keys:
 *   $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_login']
 */

// ------------------------------------------------------------
// Config knobs (safe defaults)
// ------------------------------------------------------------

// Idle timeout in seconds (0 disables). Recommend 2–8 hours for admin panels.
const AUTH_IDLE_TIMEOUT = 6 * 60 * 60; // 6 hours

// Regenerate session id every N seconds while logged-in (0 disables).
const AUTH_ROTATE_INTERVAL = 30 * 60; // 30 minutes

// Bind session to user agent hash (lightweight anti-hijack). Set false to disable.
const AUTH_BIND_UA = true;

// Trust proxy HTTPS headers only from these proxy IPs (add your reverse proxy IPs if used).
// If you are not behind a proxy, leave as localhost only.
const AUTH_TRUSTED_PROXIES = ['127.0.0.1', '::1'];

// ------------------------------------------------------------
// Session (hardened)
// ------------------------------------------------------------

/** Determine if request is HTTPS, proxy-aware (only if proxy is trusted) */
function is_https_request(): bool
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($https) {
        return true;
    }

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $behindTrustedProxy = ($remote !== '' && in_array($remote, AUTH_TRUSTED_PROXIES, true));
    if (!$behindTrustedProxy) {
        return false;
    }

    // Common proxy headers
    $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($xfp === 'https') {
        return true;
    }

    $cfv = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    if ($cfv !== '' && str_contains($cfv, 'https')) {
        return true;
    }

    return false;
}

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Still enforce rolling checks if already active
        _auth_session_runtime_checks();
        return;
    }

    $secure = is_https_request();

    // Harden session behavior
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');

    // Cookie params (set before session_start)
    $params = session_get_cookie_params();
    $cookie = [
        'lifetime' => 0,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookie);

    session_start();

    // Basic anti-hijack signals
    if (AUTH_BIND_UA) {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaHash = hash('sha256', $ua);
        if (empty($_SESSION['_ua_hash'])) {
            $_SESSION['_ua_hash'] = $uaHash;
        } elseif (is_string($_SESSION['_ua_hash']) && !hash_equals($_SESSION['_ua_hash'], $uaHash)) {
            // UA changed -> invalidate session
            admin_logout_session();
            http_response_code(401);
            echo 'Session invalid.';
            exit;
        }
    }

    // Initialize timestamps
    if (empty($_SESSION['_created_at']) || !is_int($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    }
    if (empty($_SESSION['_last_seen']) || !is_int($_SESSION['_last_seen'])) {
        $_SESSION['_last_seen'] = time();
    }

    _auth_session_runtime_checks();
}

/** Internal: enforce idle timeout + periodic rotation */
function _auth_session_runtime_checks(): void
{
    // Idle timeout (only meaningful after login; still safe to apply broadly)
    if (AUTH_IDLE_TIMEOUT > 0 && isset($_SESSION['_last_seen']) && is_int($_SESSION['_last_seen'])) {
        $idle = time() - $_SESSION['_last_seen'];
        if ($idle > AUTH_IDLE_TIMEOUT) {
            admin_logout_session();
            http_response_code(401);
            echo 'Session expired.';
            exit;
        }
    }
    $_SESSION['_last_seen'] = time();

    // Rotate session id periodically *after authentication exists*
    if (AUTH_ROTATE_INTERVAL > 0 && !empty($_SESSION['admin_id'])) {
        $rot = (int)($_SESSION['_last_rot'] ?? 0);
        if ($rot <= 0) {
            $_SESSION['_last_rot'] = time();
        } elseif ((time() - $rot) >= AUTH_ROTATE_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['_last_rot'] = time();
        }
    }
}

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------

/** CSRF: token generator (per session) */
function csrf_token(): string
{
    session_start_secure();

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        strlen($_SESSION['csrf_token']) < 32
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Convenience: hidden input field */
function csrf_field(string $name = 'csrf'): string
{
    $t = csrf_token();
    return '<input type="hidden" name="' . h_attr($name) . '" value="' . h_attr($t) . '">';
}

/**
 * CSRF: verify on state-changing methods
 * Supports:
 * - POST field (default name "csrf")
 * - AJAX header: X-CSRF-Token
 * - JSON body: { "csrf": "..." } when Content-Type: application/json
 */
function verify_csrf(string $field = 'csrf'): void
{
    session_start_secure();

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $sent = '';

    // Form field
    if (isset($_POST[$field])) {
        $sent = is_string($_POST[$field]) ? $_POST[$field] : '';
    }

    // AJAX header support
    if ($sent === '') {
        $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sent = is_string($hdr) ? $hdr : '';
    }

    // JSON body support (if still empty)
    if ($sent === '') {
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (str_contains($ct, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data[$field]) && is_string($data[$field])) {
                    $sent = $data[$field];
                }
            }
        }
    }

    $sess = $_SESSION['csrf_token'] ?? '';
    $sess = is_string($sess) ? $sess : '';

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
        $to = (string)($_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php');
        // Only allow local paths in "next"
        $to = safe_next_url($to, '/admin/dashboard.php');

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
// Optional: safe "next" URL helper (avoid open redirects)
// ------------------------------------------------------------

/**
 * Ensures a redirect target is a local path beginning with "/".
 * Rejects absolute URLs and protocol-relative URLs ("//evil.com").
 */
function safe_next_url(string $candidate, string $fallback = '/admin/dashboard.php'): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return $fallback;
    }

    // Must start with a single "/" and not with "//"
    if ($candidate[0] !== '/' || (isset($candidate[1]) && $candidate[0] === '/' && $candidate[1] === '/')) {
        return $fallback;
    }

    // Optionally: prevent control chars
    if (preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
        return $fallback;
    }

    return $candidate;
}

// ------------------------------------------------------------
// Login/Logout helpers
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

    // Ensure CSRF exists early
    csrf_token();

    // Prevent session fixation
    session_regenerate_id(true);

    // Reset timers
    $_SESSION['_last_seen'] = time();
    $_SESSION['_last_rot']  = time();
}

/** Logout helper */
function admin_logout_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Start session to properly clear cookie (safe even if not logged in)
        session_start_secure();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        // Expire cookie with same parameters to ensure browser removes it
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?? '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool)($p['secure'] ?? false),
            'httponly' => true,
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
