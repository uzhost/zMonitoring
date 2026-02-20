<?php
// teachers/login.php - Teacher login via teachers table.
// Uses /teachers/* routes and keeps session compatibility with existing auth guards.

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/tauth.php';

session_start_secure();

function teachers_safe_next_path(string $candidate, string $fallback = '/teachers/dashboard.php'): string
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

    if (preg_match('#^/teachers/(login|logout)\.php(?:$|[/?#])#', $path)) {
        return $fallback;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $path . $query)) {
        return $fallback;
    }

    return $path . $query;
}

function teachers_ref_code(string $prefix = 'TLOGIN'): string
{
    try {
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable) {
        $rand = strtoupper(substr(sha1((string)microtime(true)), 0, 8));
    }
    return $prefix . '-' . gmdate('YmdHis') . '-' . $rand;
}

$next = teachers_safe_next_path((string)($_POST['next'] ?? ($_GET['next'] ?? '')), '/teachers/dashboard.php');

if (teacher_id() > 0 || admin_id() > 0) {
    header('Location: ' . $next);
    exit;
}

$now = time();
if (!isset($_SESSION['teachers_login_fail'])) {
    $_SESSION['teachers_login_fail'] = 0;
}
if (!isset($_SESSION['teachers_login_lock_until'])) {
    $_SESSION['teachers_login_lock_until'] = 0;
}

$lockUntil = (int)$_SESSION['teachers_login_lock_until'];
$isSessionLocked = $lockUntil > $now;
$error = '';
$info = '';

// Valid bcrypt hash; used to make timing consistent for missing logins.
$dummyHash = '$2y$10$f9sJBy95W6rA0GJf7fq9S.qMNqcyC9t5gTQvWz6eb4W2Z0P6bBjaS';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');

    if ($isSessionLocked) {
        $remain = max(1, $lockUntil - $now);
        $error = 'Too many attempts. Try again in ' . (int)ceil($remain / 60) . ' minute(s).';
    } else {
        $loginRaw = (string)($_POST['login'] ?? '');
        $login = mb_strtolower(trim($loginRaw), 'UTF-8');
        $pass = (string)($_POST['password'] ?? '');

        if ($login === '' || $pass === '') {
            $error = 'Please enter login and password.';
        } else {
            try {
                $st = $pdo->prepare("
                    SELECT id, login, password_hash, is_active, level, full_name, short_name, class_id, failed_attempts, last_failed_at
                    FROM teachers
                    WHERE login = :login
                    LIMIT 1
                ");
                $st->execute([':login' => $login]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

                $hash = $dummyHash;
                $isActive = false;
                $isDbLocked = false;
                $dbLockRemain = 0;

                if (is_array($row)) {
                    $hash = is_string($row['password_hash'] ?? null) && $row['password_hash'] !== ''
                        ? (string)$row['password_hash']
                        : $dummyHash;
                    $isActive = ((int)($row['is_active'] ?? 0) === 1);

                    $failed = (int)($row['failed_attempts'] ?? 0);
                    $lastFailedAt = (string)($row['last_failed_at'] ?? '');
                    if ($failed >= 6 && $lastFailedAt !== '') {
                        $failedTs = strtotime($lastFailedAt);
                        if ($failedTs !== false) {
                            $dbLockUntil = $failedTs + 600;
                            if ($dbLockUntil > $now) {
                                $isDbLocked = true;
                                $dbLockRemain = $dbLockUntil - $now;
                            }
                        }
                    }
                }

                $passOk = password_verify($pass, $hash);
                $ok = is_array($row) && $isActive && !$isDbLocked && $passOk;

                if ($ok) {
                    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
                    $u = $pdo->prepare("
                        UPDATE teachers
                        SET failed_attempts = 0,
                            last_failed_at = NULL,
                            last_login_at = NOW(),
                            last_login_ip = :ip
                        WHERE id = :id
                    ");
                    $u->execute([
                        ':ip' => $ip,
                        ':id' => (int)$row['id'],
                    ]);

                    // Teacher-native session bootstrap (with optional admin mirror for compatibility).
                    $teacherLevel = (int)($row['level'] ?? AUTH_LEVEL_TEACHER);
                    teacher_login_session(
                        (int)$row['id'],
                        (string)$row['login'],
                        $teacherLevel,
                        isset($row['class_id']) ? (int)$row['class_id'] : null,
                        (string)($row['full_name'] ?? ''),
                        (string)($row['short_name'] ?? ''),
                        true
                    );

                    $_SESSION['teachers_login_fail'] = 0;
                    $_SESSION['teachers_login_lock_until'] = 0;

                    header('Location: ' . $next);
                    exit;
                }

                $_SESSION['teachers_login_fail'] = (int)$_SESSION['teachers_login_fail'] + 1;
                if ((int)$_SESSION['teachers_login_fail'] >= 8) {
                    $_SESSION['teachers_login_lock_until'] = time() + 600;
                }

                if (is_array($row)) {
                    try {
                        $upFail = $pdo->prepare("
                            UPDATE teachers
                            SET failed_attempts = LEAST(COALESCE(failed_attempts, 0) + 1, 4294967295),
                                last_failed_at = NOW()
                            WHERE id = :id
                        ");
                        $upFail->execute([':id' => (int)$row['id']]);
                    } catch (Throwable) {
                        // Keep generic user response below.
                    }
                }

                if ($isDbLocked) {
                    $error = 'Too many attempts. Try again in ' . (int)ceil(max(1, $dbLockRemain) / 60) . ' minute(s).';
                } else {
                    $error = 'Invalid credentials or inactive account.';
                }

                usleep(220000);
            } catch (Throwable $e) {
                $ref = teachers_ref_code();
                error_log('[TEACHERS_LOGIN][' . $ref . '] ' . $e->getMessage());
                $error = 'Database error during sign-in. Ref: ' . $ref;
            }
        }
    }
}

if ($isSessionLocked && $error === '') {
    $remain = max(1, $lockUntil - $now);
    $info = 'Login is temporarily locked for ' . (int)ceil($remain / 60) . ' minute(s).';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Teachers Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous">
  <style>
    :root {
      --page-a: #f7fafc;
      --page-b: #edf4ff;
      --ink: #0f172a;
      --muted: #64748b;
      --brand: #1d4ed8;
    }
    body {
      min-height: 100vh;
      background:
        radial-gradient(1200px 500px at 10% -10%, rgba(29, 78, 216, 0.12), transparent 65%),
        radial-gradient(1000px 500px at 90% -10%, rgba(14, 116, 144, 0.10), transparent 65%),
        linear-gradient(180deg, var(--page-a), var(--page-b));
      color: var(--ink);
    }
    .login-wrap {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 1rem;
    }
    .login-card {
      width: 100%;
      max-width: 540px;
      border: 0;
      border-radius: 1rem;
      box-shadow: 0 16px 38px rgba(15, 23, 42, 0.10);
      overflow: hidden;
    }
    .login-head {
      background: linear-gradient(135deg, #eff6ff, #e0f2fe);
      border-bottom: 1px solid #dbeafe;
    }
    .title-sub {
      color: var(--muted);
      font-size: 0.92rem;
    }
    .input-group-text {
      background: #f8fafc;
    }
  </style>
</head>
<body>
  <main class="login-wrap">
    <div class="card login-card">
      <div class="card-body p-4 p-lg-5 login-head">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-circle bg-white border d-inline-flex align-items-center justify-content-center" style="width:52px;height:52px;">
            <i class="bi bi-mortarboard text-primary fs-4"></i>
          </div>
          <div>
            <h1 class="h4 mb-1">Teachers Portal</h1>
            <div class="title-sub">Secure sign-in for teacher accounts</div>
          </div>
        </div>
      </div>
      <div class="card-body p-4 p-lg-5">
        <?php if ($error !== ''): ?>
          <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div><?= h($error) ?></div>
          </div>
        <?php elseif ($info !== ''): ?>
          <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div><?= h($info) ?></div>
          </div>
        <?php endif; ?>

        <form method="post" action="/teachers/login.php" autocomplete="off" novalidate>
          <input type="hidden" name="csrf" value="<?= h_attr(csrf_token()) ?>">
          <input type="hidden" name="next" value="<?= h_attr($next) ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold">Login</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input
                class="form-control"
                name="login"
                maxlength="100"
                required
                autofocus
                value="<?= h_attr((string)($_POST['login'] ?? '')) ?>"
                <?= $isSessionLocked ? 'disabled' : '' ?>
              >
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-key"></i></span>
              <input
                class="form-control"
                type="password"
                name="password"
                maxlength="255"
                required
                <?= $isSessionLocked ? 'disabled' : '' ?>
              >
            </div>
          </div>

          <div class="d-grid">
            <button class="btn btn-primary btn-lg" type="submit" <?= $isSessionLocked ? 'disabled' : '' ?>>
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
          </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
