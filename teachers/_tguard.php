<?php
// teachers/_tguard.php - Central guard for /teachers/* pages.
// Include this at the top of protected teachers pages.

declare(strict_types=1);

require_once __DIR__ . '/../inc/tauth.php';
require_once __DIR__ . '/../inc/db.php';

session_start_secure();

if (!defined('TGUARD_LEVEL_SUPER_TEACHER')) {
    define('TGUARD_LEVEL_SUPER_TEACHER', 1);
}
if (!defined('TGUARD_LEVEL_MEDIUM_TEACHER')) {
    define('TGUARD_LEVEL_MEDIUM_TEACHER', 2);
}
if (!defined('TGUARD_LEVEL_TEACHER')) {
    define('TGUARD_LEVEL_TEACHER', 3);
}

if (!function_exists('tguard_truthy')) {
    function tguard_truthy(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}

if (!function_exists('tguard_safe_next_path')) {
    function tguard_safe_next_path(string $candidate, string $fallback = '/teachers/dashboard.php'): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') return $fallback;

        $parts = parse_url($candidate);
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';

        if ($path === '' || $path[0] !== '/') return $fallback;
        if (!str_starts_with($path, '/teachers/')) return $fallback;
        if (preg_match('#^/teachers/login\.php(?:$|[/?#])#', $path)) return $fallback;
        if (preg_match('/[\x00-\x1F\x7F]/', $path . $query)) return $fallback;

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
            if ($mm !== '') $norm[] = $mm;
        }
        if (!$norm) $norm = ['GET', 'HEAD'];

        if (!in_array($method, $norm, true)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $norm));
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed.';
            exit;
        }
    }
}

if (!function_exists('tguard_is_read_method')) {
    function tguard_is_read_method(string $method): bool
    {
        $m = strtoupper(trim($method));
        return in_array($m, ['GET', 'HEAD'], true);
    }
}

if (!function_exists('tguard_normalize_levels')) {
    function tguard_normalize_levels(array $levels): array
    {
        $ok = [];
        foreach ($levels as $level) {
            $lvl = (int)$level;
            if (in_array($lvl, [
                TGUARD_LEVEL_SUPER_TEACHER,
                TGUARD_LEVEL_MEDIUM_TEACHER,
                TGUARD_LEVEL_TEACHER,
            ], true)) {
                $ok[$lvl] = true;
            }
        }
        if (!$ok) {
            $ok = [
                TGUARD_LEVEL_SUPER_TEACHER => true,
                TGUARD_LEVEL_MEDIUM_TEACHER => true,
                TGUARD_LEVEL_TEACHER => true,
            ];
        }
        return array_keys($ok);
    }
}

if (!function_exists('tguard_teacher_id')) {
    function tguard_teacher_id(): int
    {
        if (isset($_SESSION['teacher']) && is_array($_SESSION['teacher']) && isset($_SESSION['teacher']['id'])) {
            return (int)$_SESSION['teacher']['id'];
        }
        if (isset($_SESSION['teacher_id'])) {
            return (int)$_SESSION['teacher_id'];
        }
        return 0;
    }
}

if (!function_exists('tguard_req_int')) {
    function tguard_req_int(string $key): ?int
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || !preg_match('/^\d+$/', $s)) return null;
        return (int)$s;
    }
}

if (!function_exists('tguard_req_str')) {
    function tguard_req_str(string $key, int $maxLen = 40): ?string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        if ($maxLen > 0 && mb_strlen($s, 'UTF-8') > $maxLen) {
            $s = mb_substr($s, 0, $maxLen, 'UTF-8');
        }
        return $s;
    }
}

if (!function_exists('tguard_load_teacher_row')) {
    function tguard_load_teacher_row(PDO $pdo, int $teacherId): ?array
    {
        $st = $pdo->prepare('
            SELECT
              t.id,
              t.login,
              t.full_name,
              t.short_name,
              t.class_id,
              t.level,
              t.is_active,
              c.class_code
            FROM teachers t
            LEFT JOIN classes c ON c.id = t.class_id
            WHERE t.id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $teacherId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('tguard_sync_session_teacher')) {
    function tguard_sync_session_teacher(array $teacherRow): void
    {
        $level = (int)($teacherRow['level'] ?? 0);
        $login = (string)($teacherRow['login'] ?? '');
        $id = (int)($teacherRow['id'] ?? 0);

        if (!isset($_SESSION['teacher']) || !is_array($_SESSION['teacher'])) {
            $_SESSION['teacher'] = [];
        }

        $_SESSION['teacher']['id'] = $id;
        $_SESSION['teacher']['login'] = $login;
        $_SESSION['teacher']['full_name'] = (string)($teacherRow['full_name'] ?? '');
        $_SESSION['teacher']['short_name'] = (string)($teacherRow['short_name'] ?? '');
        $_SESSION['teacher']['class_id'] = (int)($teacherRow['class_id'] ?? 0);
        $_SESSION['teacher']['class_code'] = (string)($teacherRow['class_code'] ?? '');
        $_SESSION['teacher']['level'] = $level;
        $_SESSION['teacher']['is_active'] = (int)($teacherRow['is_active'] ?? 0);

        // Compatibility mirrors for older helpers/components that still read admin session keys.
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_login'] = $login;
        $_SESSION['admin_level'] = $level;
        $_SESSION['level'] = $level;

        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
            $_SESSION['admin'] = [];
        }
        $_SESSION['admin']['id'] = $id;
        $_SESSION['admin']['login'] = $login;
        $_SESSION['admin']['level'] = $level;

        $_SESSION['admin_role'] = match ($level) {
            TGUARD_LEVEL_SUPER_TEACHER => 'superadmin',
            TGUARD_LEVEL_MEDIUM_TEACHER => 'admin',
            default => 'viewer',
        };
    }
}

if (!function_exists('tguard_match_medium_scope')) {
    function tguard_match_medium_scope(PDO $pdo, array $teacherRow, ?int $forcedClassId, ?string $forcedClassCode): bool
    {
        $teacherClassId = (int)($teacherRow['class_id'] ?? 0);
        $teacherClassCode = trim((string)($teacherRow['class_code'] ?? ''));

        $targetClassId = $forcedClassId ?? tguard_req_int('class_id');
        $targetClassCode = $forcedClassCode ?? tguard_req_str('class_code', 30);

        if ($targetClassId !== null) {
            return ($teacherClassId > 0) && ($targetClassId === $teacherClassId);
        }

        if ($targetClassCode !== null && $targetClassCode !== '') {
            if ($teacherClassCode === '') return false;
            return mb_strtolower($targetClassCode, 'UTF-8') === mb_strtolower($teacherClassCode, 'UTF-8');
        }

        $pupilId = tguard_req_int('pupil_id');
        if ($pupilId !== null) {
            $st = $pdo->prepare('
                SELECT p.class_id, p.class_code
                FROM pupils p
                WHERE p.id = :id
                LIMIT 1
            ');
            $st->execute([':id' => $pupilId]);
            $pupil = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($pupil)) return false;

            $pupilClassId = (int)($pupil['class_id'] ?? 0);
            if ($pupilClassId > 0 && $teacherClassId > 0) {
                return $pupilClassId === $teacherClassId;
            }

            $pupilClassCode = trim((string)($pupil['class_code'] ?? ''));
            if ($pupilClassCode === '' || $teacherClassCode === '') return false;
            return mb_strtolower($pupilClassCode, 'UTF-8') === mb_strtolower($teacherClassCode, 'UTF-8');
        }

        return false;
    }
}

if (!function_exists('tguard_can_edit_any_class')) {
    function tguard_can_edit_any_class(): bool
    {
        $lvl = (int)($_SESSION['teacher']['level'] ?? 0);
        return $lvl === TGUARD_LEVEL_SUPER_TEACHER;
    }
}

if (!function_exists('tguard_can_edit_own_class')) {
    function tguard_can_edit_own_class(): bool
    {
        $lvl = (int)($_SESSION['teacher']['level'] ?? 0);
        if ($lvl !== TGUARD_LEVEL_MEDIUM_TEACHER) return false;

        $enabled = tguard_truthy($GLOBALS['tguard_allow_level2_own_class_edit'] ?? false)
            || tguard_truthy($_SESSION['teacher']['can_edit_own_class'] ?? false)
            || tguard_truthy($_SESSION['teacher']['allow_own_class_edit'] ?? false);

        if (!$enabled) return false;
        return ((int)($_SESSION['teacher']['class_id'] ?? 0) > 0);
    }
}

// Optional page overrides before include:
// $tguard_allowed_methods = ['GET', 'HEAD'];
// $tguard_allowed_levels = [1,2,3];
// $tguard_require_active = true;
// $tguard_login_path = '/teachers/login.php';
// $tguard_fallback_path = '/teachers/dashboard.php';
// $tguard_allow_level2_own_class_edit = false;
// $tguard_target_class_id = 123; // optional explicit scope for write checks
// $tguard_target_class_code = '10-A'; // optional explicit scope for write checks
$tguard_allowed_methods = $tguard_allowed_methods ?? ['GET', 'HEAD'];
$tguard_allowed_levels = tguard_normalize_levels((array)($tguard_allowed_levels ?? [1, 2, 3]));
$tguard_require_active = $tguard_require_active ?? true;
$tguard_login_path = (string)($tguard_login_path ?? '/teachers/login.php');
$tguard_fallback_path = (string)($tguard_fallback_path ?? '/teachers/dashboard.php');
$tguard_allow_level2_own_class_edit = tguard_truthy($tguard_allow_level2_own_class_edit ?? false);
$tguard_target_class_id = isset($tguard_target_class_id) ? (int)$tguard_target_class_id : null;
$tguard_target_class_code = isset($tguard_target_class_code) ? trim((string)$tguard_target_class_code) : null;
if ($tguard_target_class_code === '') $tguard_target_class_code = null;

// Security / cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

tguard_method_guard((array)$tguard_allowed_methods);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$teacherId = tguard_teacher_id();

if ($teacherId <= 0) {
    $to = tguard_safe_next_path((string)($_SERVER['REQUEST_URI'] ?? ''), $tguard_fallback_path);
    header('Location: ' . $tguard_login_path . '?next=' . rawurlencode($to));
    exit;
}

try {
    $teacherRow = tguard_load_teacher_row($pdo, $teacherId);
    if (!is_array($teacherRow)) {
        teacher_logout_session();
        session_start_secure();
        header('Location: ' . $tguard_login_path . '?err=invalid');
        exit;
    }

    if ($tguard_require_active && (int)($teacherRow['is_active'] ?? 0) !== 1) {
        teacher_logout_session();
        session_start_secure();
        header('Location: ' . $tguard_login_path . '?err=inactive');
        exit;
    }

    tguard_sync_session_teacher($teacherRow);

    $level = (int)($teacherRow['level'] ?? 0);
    if (!in_array($level, $tguard_allowed_levels, true)) {
        tguard_deny(403, 'Forbidden.');
    }

    if (!tguard_is_read_method($method)) {
        if ($level === TGUARD_LEVEL_TEACHER) {
            tguard_deny(403, 'Read-only access.');
        }

        if ($level === TGUARD_LEVEL_MEDIUM_TEACHER) {
            if (!tguard_can_edit_own_class()) {
                tguard_deny(403, 'Read-only access.');
            }
            $inScope = tguard_match_medium_scope($pdo, $teacherRow, $tguard_target_class_id, $tguard_target_class_code);
            if (!$inScope) {
                tguard_deny(403, 'You can edit only your own class data.');
            }
        }
    }

    // Header.php should not re-run legacy auth guard when this central guard is active.
    $auth_required = false;
    $teacher_is_guest = false;

    $GLOBALS['tguard_current_teacher'] = $_SESSION['teacher'];
    $GLOBALS['tguard_level'] = $level;
    $GLOBALS['tguard_can_edit_any_class'] = tguard_can_edit_any_class();
    $GLOBALS['tguard_can_edit_own_class'] = tguard_can_edit_own_class();
} catch (Throwable $e) {
    error_log('[TGUARD] validation failed: ' . $e->getMessage());
    tguard_deny(500, 'Guard validation failed.');
}
