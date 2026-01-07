<?php
// inc/auth.php — Admin auth helpers (admins table), hardened sessions + CSRF + RBAC(levels) + safe escaping
// DROP-IN ENHANCEMENT (FIXED):
// - Removes duplicate require_admin_levels() (previously declared twice)
// - Makes admin_level() tolerant to legacy session shapes
// - Lets admin_login_session() persist level (optional param, backwards compatible)
// - Keeps role-based helpers for backward compatibility
// - Keeps teacher-aware redirect helpers to avoid /teacher -> /admin/login.php loops

declare(strict_types=1);

// ------------------------------------------------------------
// Config knobs (safe defaults)
// ------------------------------------------------------------

const AUTH_IDLE_TIMEOUT    = 6 * 60 * 60; // 6 hours
const AUTH_ROTATE_INTERVAL = 30 * 60;     // 30 minutes
const AUTH_BIND_UA         = true;

const AUTH_TRUSTED_PROXIES = ['127.0.0.1', '::1'];

// Define level semantics (authoritative)
const AUTH_LEVEL_SUPERADMIN = 1; // full edit
const AUTH_LEVEL_ADMIN      = 2; // admin (view-only or limited, depending on page)
const AUTH_LEVEL_TEACHER    = 3; // viewer/teacher (read-only)

// ------------------------------------------------------------
// Session (hardened)
// ------------------------------------------------------------

function is_https_request(): bool
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($https) return true;

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $behindTrustedProxy = ($remote !== '' && in_array($remote, AUTH_TRUSTED_PROXIES, true));
    if (!$behindTrustedProxy) return false;

    $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($xfp === 'https') return true;

    $cfv = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    if ($cfv !== '' && str_contains($cfv, 'https')) return true;

    return false;
}

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        _auth_session_runtime_checks();
        return;
    }

    $secure = is_https_request();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');

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

    if (AUTH_BIND_UA) {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaHash = hash('sha256', $ua);
        if (empty($_SESSION['_ua_hash'])) {
            $_SESSION['_ua_hash'] = $uaHash;
        } elseif (is_string($_SESSION['_ua_hash']) && !hash_equals($_SESSION['_ua_hash'], $uaHash)) {
            admin_logout_session();
            http_response_code(401);
            echo 'Session invalid.';
            exit;
        }
    }

    if (empty($_SESSION['_created_at']) || !is_int($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    }
    if (empty($_SESSION['_last_seen']) || !is_int($_SESSION['_last_seen'])) {
        $_SESSION['_last_seen'] = time();
    }

    _auth_session_runtime_checks();
}

function _auth_session_runtime_checks(): void
{
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

function csrf_field(string $name = 'csrf'): string
{
    $t = csrf_token();
    return '<input type="hidden" name="' . h_attr($name) . '" value="' . h_attr($t) . '">';
}

function verify_csrf(string $field = 'csrf'): void
{
    session_start_secure();

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $sent = '';

    if (isset($_POST[$field])) {
        $sent = is_string($_POST[$field]) ? $_POST[$field] : '';
    }

    if ($sent === '') {
        $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sent = is_string($hdr) ? $hdr : '';
    }

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
// Safe output helpers (NULL-safe)
// ------------------------------------------------------------

function h(mixed $s): string
{
    if ($s === null) return '';
    if (is_bool($s)) return $s ? '1' : '0';
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function h_attr(mixed $s): string
{
    return h($s);
}

// ------------------------------------------------------------
// Auth getters (tolerant shapes)
// ------------------------------------------------------------

function admin_id(): int
{
    session_start_secure();
    return (int)($_SESSION['admin_id'] ?? ($_SESSION['admin']['id'] ?? 0));
}

function admin_role(): string
{
    session_start_secure();
    $r = $_SESSION['admin_role'] ?? ($_SESSION['admin']['role'] ?? '');
    return is_string($r) ? $r : '';
}

function admin_login(): string
{
    session_start_secure();
    $l = $_SESSION['admin_login'] ?? ($_SESSION['admin']['login'] ?? '');
    return is_string($l) ? $l : '';
}

/**
 * Return admin access level (tolerant).
 * 1 = Superadmin
 * 2 = Admin
 * 3 = Teacher/Viewer
 */
function admin_level(): int
{
    session_start_secure();

    // Preferred (new)
    if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && isset($_SESSION['admin']['level'])) {
        return (int)$_SESSION['admin']['level'];
    }

    // Legacy mirrors / tolerant
    if (isset($_SESSION['admin_level'])) return (int)$_SESSION['admin_level'];
    if (isset($_SESSION['level'])) return (int)$_SESSION['level'];

    return 0;
}

// ------------------------------------------------------------
// Safe "next" URL helper (avoid open redirects)
// ------------------------------------------------------------

function safe_next_url(string $candidate, string $fallback = '/admin/dashboard.php'): string
{
    $candidate = trim($candidate);
    if ($candidate === '') return $fallback;

    if ($candidate[0] !== '/' || (isset($candidate[1]) && $candidate[0] === '/' && $candidate[1] === '/')) {
        return $fallback;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
        return $fallback;
    }

    return $candidate;
}

/**
 * Teacher-safe next helper (defaults to teacher dashboard)
 */
function safe_next_path_teacher(string $candidate, string $fallback = '/teacher/dashboard.php'): string
{
    $candidate = trim($candidate);
    if ($candidate === '') return $fallback;

    $parts = parse_url($candidate);
    $path  = $parts['path']  ?? '';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

    if ($path === '' || $path[0] !== '/') return $fallback;

    if (preg_match('#^/teacher/login\.php(?:$|[/?#])#', $path)) {
        return $fallback;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $path . $query)) {
        return $fallback;
    }

    return $path . $query;
}

// ------------------------------------------------------------
// Redirect helpers (area-aware)
// ------------------------------------------------------------

function require_admin_login(): void
{
    $to = (string)($_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php');
    $to = safe_next_url($to, '/admin/dashboard.php');
    header('Location: /admin/login.php?next=' . rawurlencode($to));
    exit;
}

function require_teacher_login(): void
{
    $to = (string)($_SERVER['REQUEST_URI'] ?? '/teacher/dashboard.php');
    $to = safe_next_path_teacher($to, '/teacher/dashboard.php');
    header('Location: /teacher/login.php?next=' . rawurlencode($to));
    exit;
}

// ------------------------------------------------------------
// RBAC (Role-based kept; Level-based added)
// ------------------------------------------------------------

/**
 * Require login for admin area.
 * Backwards compatible: redirects to /admin/login.php.
 */
function require_admin(): void
{
    session_start_secure();

    if (admin_id() <= 0) {
        require_admin_login();
    }
}

/**
 * Require login and specific admin levels.
 * Example: require_admin_levels([1,2]) for admin pages.
 */
function require_admin_levels(array $levels): void
{
    require_admin();

    $cur = admin_level();
    foreach ($levels as $lvl) {
        if ((int)$lvl === $cur) {
            return;
        }
    }

    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

/**
 * Editing permission (only level 1).
 * Use in pages: $can_edit = can_edit();
 */
function can_edit(): bool
{
    return admin_level() === AUTH_LEVEL_SUPERADMIN;
}

/**
 * Teacher area guard (Level 3 only). Redirects to /teacher/login.php.
 * Use on teacher pages (except teacher/login.php).
 */
function require_teacher(): void
{
    session_start_secure();

    if (admin_id() <= 0) {
        require_teacher_login();
    }

    if (admin_level() !== AUTH_LEVEL_TEACHER) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
}

/**
 * Require role (legacy, string RBAC).
 * Order of privilege: superadmin > admin > viewer
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
// Login/Logout helpers
// ------------------------------------------------------------

/**
 * Call immediately after successful authentication.
 * Backwards compatible signature.
 *
 * New optional parameter: $level (admins.level).
 */
function admin_login_session(int $id, string $role, string $login, ?int $level = null): void
{
    session_start_secure();

    $_SESSION['admin_id']    = $id;
    $_SESSION['admin_role']  = $role;
    $_SESSION['admin_login'] = $login;

    // Mirror into tolerant structure
    if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
        $_SESSION['admin'] = [];
    }
    $_SESSION['admin']['id']    = $id;
    $_SESSION['admin']['role']  = $role;
    $_SESSION['admin']['login'] = $login;

    // Persist level consistently (new + legacy mirrors)
    if ($level !== null) {
        $lvl = (int)$level;
        $_SESSION['admin']['level'] = $lvl;
        $_SESSION['admin_level']    = $lvl; // legacy mirror
        $_SESSION['level']          = $lvl; // legacy mirror
    }

    csrf_token();
    session_regenerate_id(true);

    $_SESSION['_last_seen'] = time();
    $_SESSION['_last_rot']  = time();
}

function admin_logout_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start_secure();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
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
