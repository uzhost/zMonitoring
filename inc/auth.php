<?php
// inc/auth.php - Shared auth helpers with separated admin and teacher session APIs.
// Backward compatible with legacy admin_* usage while providing teacher_* first-class helpers.

declare(strict_types=1);

// ------------------------------------------------------------
// Config knobs (safe defaults)
// ------------------------------------------------------------

const AUTH_IDLE_TIMEOUT    = 6 * 60 * 60; // 6 hours
const AUTH_ROTATE_INTERVAL = 30 * 60;     // 30 minutes
const AUTH_BIND_UA         = true;

const AUTH_TRUSTED_PROXIES = ['127.0.0.1', '::1'];

const AUTH_ADMIN_BASE          = '/admin';
const AUTH_TEACHER_BASE        = '/teachers';
const AUTH_TEACHER_BASE_LEGACY = '/teacher';

// Level semantics
const AUTH_LEVEL_SUPERADMIN = 1; // Super Teacher / Super Admin
const AUTH_LEVEL_ADMIN      = 2; // Medium Teacher / Admin
const AUTH_LEVEL_TEACHER    = 3; // Read-only Teacher / Viewer

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
            auth_logout_session_all();
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

function _auth_has_identity(): bool
{
    if (!empty($_SESSION['admin_id'])) return true;
    if (!empty($_SESSION['teacher_id'])) return true;
    if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && !empty($_SESSION['admin']['id'])) return true;
    if (isset($_SESSION['teacher']) && is_array($_SESSION['teacher']) && !empty($_SESSION['teacher']['id'])) return true;
    return false;
}

function _auth_session_runtime_checks(): void
{
    if (AUTH_IDLE_TIMEOUT > 0 && isset($_SESSION['_last_seen']) && is_int($_SESSION['_last_seen'])) {
        $idle = time() - $_SESSION['_last_seen'];
        if ($idle > AUTH_IDLE_TIMEOUT) {
            auth_logout_session_all();
            http_response_code(401);
            echo 'Session expired.';
            exit;
        }
    }
    $_SESSION['_last_seen'] = time();

    if (AUTH_ROTATE_INTERVAL > 0 && _auth_has_identity()) {
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
// Admin session getters
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

function admin_level(): int
{
    session_start_secure();

    if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && isset($_SESSION['admin']['level'])) {
        return (int)$_SESSION['admin']['level'];
    }
    if (isset($_SESSION['admin_level'])) return (int)$_SESSION['admin_level'];
    if (isset($_SESSION['level'])) return (int)$_SESSION['level'];

    return 0;
}

// ------------------------------------------------------------
// Teacher session getters
// ------------------------------------------------------------

function teacher_id(): int
{
    session_start_secure();
    return (int)($_SESSION['teacher_id'] ?? ($_SESSION['teacher']['id'] ?? 0));
}

function teacher_login(): string
{
    session_start_secure();
    $l = $_SESSION['teacher_login'] ?? ($_SESSION['teacher']['login'] ?? '');
    return is_string($l) ? $l : '';
}

function teacher_level(): int
{
    session_start_secure();

    if (isset($_SESSION['teacher']) && is_array($_SESSION['teacher']) && isset($_SESSION['teacher']['level'])) {
        return (int)$_SESSION['teacher']['level'];
    }
    if (isset($_SESSION['teacher_level'])) return (int)$_SESSION['teacher_level'];

    return 0;
}

function teacher_class_id(): int
{
    session_start_secure();

    if (isset($_SESSION['teacher']) && is_array($_SESSION['teacher']) && isset($_SESSION['teacher']['class_id'])) {
        return (int)$_SESSION['teacher']['class_id'];
    }
    if (isset($_SESSION['teacher_class_id'])) return (int)$_SESSION['teacher_class_id'];

    return 0;
}

// ------------------------------------------------------------
// Safe "next" URL helpers (avoid open redirects)
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

function safe_next_path_teacher(string $candidate, string $fallback = '/teachers/dashboard.php'): string
{
    $candidate = trim($candidate);
    if ($candidate === '') return $fallback;

    $parts = parse_url($candidate);
    $path  = (string)($parts['path'] ?? '');
    $query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';

    if ($path === '' || $path[0] !== '/') return $fallback;

    $inTeachersArea = str_starts_with($path, AUTH_TEACHER_BASE . '/')
        || $path === AUTH_TEACHER_BASE
        || str_starts_with($path, AUTH_TEACHER_BASE_LEGACY . '/')
        || $path === AUTH_TEACHER_BASE_LEGACY;

    if (!$inTeachersArea) return $fallback;

    if (preg_match('#^/(teachers|teacher)/(login|logout)\.php(?:$|[/?#])#', $path)) {
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
    $to = (string)($_SERVER['REQUEST_URI'] ?? '/teachers/dashboard.php');
    $to = safe_next_path_teacher($to, '/teachers/dashboard.php');
    header('Location: /teachers/login.php?next=' . rawurlencode($to));
    exit;
}

// ------------------------------------------------------------
// RBAC
// ------------------------------------------------------------

function require_admin(): void
{
    session_start_secure();

    if (admin_id() <= 0) {
        require_admin_login();
    }
}

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

function can_edit(): bool
{
    return admin_level() === AUTH_LEVEL_SUPERADMIN;
}

function require_teacher(): void
{
    require_teacher_levels([AUTH_LEVEL_SUPERADMIN, AUTH_LEVEL_ADMIN, AUTH_LEVEL_TEACHER]);
}

function require_teacher_levels(array $levels): void
{
    session_start_secure();

    if (teacher_id() <= 0) {
        require_teacher_login();
    }

    $cur = teacher_level();
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
 * Legacy role guard for admin area.
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

function _auth_role_for_level(int $level): string
{
    return match ($level) {
        AUTH_LEVEL_SUPERADMIN => 'superadmin',
        AUTH_LEVEL_ADMIN => 'admin',
        default => 'viewer',
    };
}

/**
 * Admin login session bootstrap.
 */
function admin_login_session(int $id, string $role, string $login, ?int $level = null): void
{
    session_start_secure();

    $_SESSION['admin_id']    = $id;
    $_SESSION['admin_role']  = $role;
    $_SESSION['admin_login'] = $login;

    if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
        $_SESSION['admin'] = [];
    }
    $_SESSION['admin']['id']    = $id;
    $_SESSION['admin']['role']  = $role;
    $_SESSION['admin']['login'] = $login;

    if ($level !== null) {
        $lvl = (int)$level;
        $_SESSION['admin']['level'] = $lvl;
        $_SESSION['admin_level']    = $lvl;
        $_SESSION['level']          = $lvl;
    }

    csrf_token();
    session_regenerate_id(true);

    $_SESSION['_last_seen'] = time();
    $_SESSION['_last_rot']  = time();
}

/**
 * Teacher login session bootstrap.
 *
 * By default mirrors admin_* keys for backward compatibility with old teacher pages.
 */
function teacher_login_session(
    int $id,
    string $login,
    int $level = AUTH_LEVEL_TEACHER,
    ?int $classId = null,
    string $fullName = '',
    string $shortName = '',
    bool $mirrorAdmin = true
): void {
    session_start_secure();

    $lvl = (int)$level;
    if (!in_array($lvl, [AUTH_LEVEL_SUPERADMIN, AUTH_LEVEL_ADMIN, AUTH_LEVEL_TEACHER], true)) {
        $lvl = AUTH_LEVEL_TEACHER;
    }

    $_SESSION['teacher_id'] = $id;
    $_SESSION['teacher_login'] = $login;
    $_SESSION['teacher_level'] = $lvl;
    $_SESSION['teacher_class_id'] = $classId ?? 0;

    if (!isset($_SESSION['teacher']) || !is_array($_SESSION['teacher'])) {
        $_SESSION['teacher'] = [];
    }
    $_SESSION['teacher']['id'] = $id;
    $_SESSION['teacher']['login'] = $login;
    $_SESSION['teacher']['level'] = $lvl;
    $_SESSION['teacher']['class_id'] = $classId ?? 0;
    $_SESSION['teacher']['full_name'] = $fullName;
    $_SESSION['teacher']['short_name'] = $shortName;

    if ($mirrorAdmin) {
        $role = _auth_role_for_level($lvl);
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_login'] = $login;
        $_SESSION['admin_role'] = $role;
        $_SESSION['admin_level'] = $lvl;
        $_SESSION['level'] = $lvl;

        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
            $_SESSION['admin'] = [];
        }
        $_SESSION['admin']['id'] = $id;
        $_SESSION['admin']['login'] = $login;
        $_SESSION['admin']['role'] = $role;
        $_SESSION['admin']['level'] = $lvl;
    }

    csrf_token();
    session_regenerate_id(true);

    $_SESSION['_last_seen'] = time();
    $_SESSION['_last_rot']  = time();
}

function teacher_logout_session(bool $clearLegacyMirrors = true): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start_secure();
    }

    unset($_SESSION['teacher']);
    unset($_SESSION['teacher_id'], $_SESSION['teacher_login'], $_SESSION['teacher_level'], $_SESSION['teacher_class_id']);

    if ($clearLegacyMirrors) {
        unset($_SESSION['admin']);
        unset($_SESSION['admin_id'], $_SESSION['admin_login'], $_SESSION['admin_role'], $_SESSION['admin_level'], $_SESSION['level']);
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

function auth_logout_session_all(): void
{
    admin_logout_session();
}
