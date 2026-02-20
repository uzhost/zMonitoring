<?php
// teachers/_tguard.php - Central guard for /teachers/* pages.
// Include this at the top of protected teachers pages.

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

session_start_secure();

if (!function_exists('tguard_safe_next_path')) {
    function tguard_safe_next_path(string $candidate, string $fallback = '/teachers/dashboard.php'): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return $fallback;
        }

        $parts = parse_url($candidate);
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';

        if ($path === '' || $path[0] !== '/') {
            return $fallback;
        }

        if (!str_starts_with($path, '/teachers/')) {
            return $fallback;
        }

        if (preg_match('#^/teachers/login\.php(?:$|[/?#])#', $path)) {
            return $fallback;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path . $query)) {
            return $fallback;
        }

        return $path . $query;
    }
}

if (!function_exists('tguard_deny')) {
    function tguard_deny(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}

if (!function_exists('tguard_method_guard')) {
    function tguard_method_guard(array $allowed): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $norm = [];
        foreach ($allowed as $m) {
            $mm = strtoupper(trim((string)$m));
            if ($mm !== '') {
                $norm[] = $mm;
            }
        }
        if (!$norm) {
            $norm = ['GET', 'HEAD'];
        }
        if (!in_array($method, $norm, true)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $norm));
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed.';
            exit;
        }
    }
}

if (!function_exists('tguard_read_level')) {
    function tguard_read_level(): int
    {
        if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && isset($_SESSION['admin']['level'])) {
            return (int)$_SESSION['admin']['level'];
        }
        if (isset($_SESSION['admin_level'])) {
            return (int)$_SESSION['admin_level'];
        }
        if (isset($_SESSION['level'])) {
            return (int)$_SESSION['level'];
        }
        if (function_exists('admin_level')) {
            return (int)admin_level();
        }
        return 0;
    }
}

if (!function_exists('tguard_teacher_id')) {
    function tguard_teacher_id(): int
    {
        if (isset($_SESSION['teacher']) && is_array($_SESSION['teacher']) && isset($_SESSION['teacher']['id'])) {
            return (int)$_SESSION['teacher']['id'];
        }
        if (function_exists('admin_id')) {
            return (int)admin_id();
        }
        return 0;
    }
}

// Optional page overrides before include:
// $tguard_allowed_methods = ['GET', 'HEAD'];
// $tguard_require_active = true;
// $tguard_login_path = '/teachers/login.php';
// $tguard_fallback_path = '/teachers/dashboard.php';
$tguard_allowed_methods = $tguard_allowed_methods ?? ['GET', 'HEAD'];
$tguard_require_active = $tguard_require_active ?? true;
$tguard_login_path = (string)($tguard_login_path ?? '/teachers/login.php');
$tguard_fallback_path = (string)($tguard_fallback_path ?? '/teachers/dashboard.php');

// Security / cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

tguard_method_guard((array)$tguard_allowed_methods);

$teacherId = tguard_teacher_id();
$level = tguard_read_level();

if ($teacherId <= 0) {
    $to = tguard_safe_next_path((string)($_SERVER['REQUEST_URI'] ?? ''), $tguard_fallback_path);
    header('Location: ' . $tguard_login_path . '?next=' . rawurlencode($to));
    exit;
}

if ($level !== 3) {
    // Keep strict level policy for teachers area.
    tguard_deny(403, 'Forbidden.');
}

if ($tguard_require_active) {
    try {
        $st = $pdo->prepare("
            SELECT id, login, full_name, short_name, class_id, is_active
            FROM teachers
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $teacherId]);
        $teacherRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!is_array($teacherRow) || (int)($teacherRow['is_active'] ?? 0) !== 1) {
            admin_logout_session();
            session_start_secure();
            header('Location: ' . $tguard_login_path . '?err=inactive');
            exit;
        }

        if (!isset($_SESSION['teacher']) || !is_array($_SESSION['teacher'])) {
            $_SESSION['teacher'] = [];
        }
        $_SESSION['teacher']['id'] = (int)$teacherRow['id'];
        $_SESSION['teacher']['login'] = (string)$teacherRow['login'];
        $_SESSION['teacher']['full_name'] = (string)($teacherRow['full_name'] ?? '');
        $_SESSION['teacher']['short_name'] = (string)($teacherRow['short_name'] ?? '');
        $_SESSION['teacher']['class_id'] = (string)($teacherRow['class_id'] ?? '');
    } catch (Throwable $e) {
        error_log('[TGUARD] Active-check failed: ' . $e->getMessage());
        tguard_deny(500, 'Guard validation failed.');
    }
}
