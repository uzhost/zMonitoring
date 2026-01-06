<?php
// teacher/login.php — Teacher/Viewer login (admins table)
// DROP-IN: Only admins.level = 3 (viewer/teacher) may sign in here.
// Security: session hardening + basic rate-limit + CSRF + safe redirects.

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// If already logged in, go to teacher dashboard
if (admin_id() > 0) {
  header('Location: /teacher/dashboard.php');
  exit;
}

/**
 * Normalize and validate next (same-origin, site-relative only)
 */
function safe_next_path(string $candidate, string $fallback = '/teacher/dashboard.php'): string
{
  $candidate = trim($candidate);
  if ($candidate === '') return $fallback;

  $parts = parse_url($candidate);
  $path  = $parts['path']  ?? '';
  $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

  if ($path === '' || $path[0] !== '/') return $fallback;

  // avoid redirecting to login/logout
  if (preg_match('#^/teacher/(login|logout)\.php(?:$|[/?#])#', $path)) {
    return $fallback;
  }

  return $path . $query;
}

// Accept next from GET and POST (POST takes precedence)
$next = safe_next_path(
  (string)($_POST['next'] ?? ($_GET['next'] ?? '')),
  '/teacher/dashboard.php'
);

// Basic in-session rate limiting (per browser session)
$now = time();
if (!isset($_SESSION['t_login_fail'])) $_SESSION['t_login_fail'] = 0;
if (!isset($_SESSION['t_login_lock_until'])) $_SESSION['t_login_lock_until'] = 0;

$lockedUntil = (int)$_SESSION['t_login_lock_until'];
$isLocked = $lockedUntil > $now;

$error = '';
$info  = '';

// Dummy hash for timing mitigation (must be a valid bcrypt hash)
$dummyHash = '$2y$10$wH5XwC8b7l8f4yq4oD6e5eYc1o9g8i7h6j5k4l3m2n1o0p9q8r7s6';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  verify_csrf('csrf');

  if ($isLocked) {
    $remain = $lockedUntil - $now;
    $error = 'Too many attempts. Try again in ' . (int)ceil($remain / 60) . ' minute(s).';
  } else {
    $loginRaw = (string)($_POST['login'] ?? '');
    $login    = mb_strtolower(trim($loginRaw));
    $pass     = (string)($_POST['password'] ?? '');

    if ($login === '' || $pass === '') {
      $error = 'Please enter login and password.';
    } else {
      $stmt = $pdo->prepare("
        SELECT id, login, password_hash, role, level, is_active
        FROM admins
        WHERE login = :login
        LIMIT 1
      ");
      $stmt->execute([':login' => $login]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      // timing-safe verification path
      $hash     = $dummyHash;
      $isActive = false;
      $level    = 0;

      if (is_array($row)) {
        $isActive = ((int)($row['is_active'] ?? 0) === 1);
        $level    = (int)($row['level'] ?? 0);
        if (!empty($row['password_hash']) && is_string($row['password_hash'])) {
          $hash = $row['password_hash'];
        }
      }

      $passOk = password_verify($pass, $hash);

      // Allow only level 3 (viewer/teacher)
      $ok = is_array($row)
        && $isActive
        && $passOk
        && ($level === 3)
        && isset($row['id'], $row['login'], $row['role']);

      if ($ok) {
        // Create admin session (viewer) — compatible with existing auth helpers
        admin_login_session((int)$row['id'], (string)$row['role'], (string)$row['login']);

        // Store level in tolerant shapes (matches your admin login approach)
        $_SESSION['admin_level'] = 3;
        $_SESSION['level'] = 3;
        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
          $_SESSION['admin'] = [];
        }
        $_SESSION['admin']['level'] = 3;
        $_SESSION['admin']['role']  = (string)$row['role'];
        $_SESSION['admin']['login'] = (string)$row['login'];
        $_SESSION['admin']['id']    = (int)$row['id'];

        // Update last login (best effort)
        try {
          $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
          $ipBin = null;

          // If your admins.last_login_ip is VARCHAR, storing string is fine.
          // If it's VARBINARY(16), store packed bytes.
          if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $packed = @inet_pton($ip);
            if (is_string($packed)) $ipBin = $packed;
          }

          // Attempt VARBINARY write first, fall back to plain string if needed.
          try {
            $u = $pdo->prepare("UPDATE admins SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id");
            $u->execute([':ip' => $ipBin, ':id' => (int)$row['id']]);
          } catch (Throwable) {
            $u = $pdo->prepare("UPDATE admins SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id");
            $u->execute([':ip' => $ip, ':id' => (int)$row['id']]);
          }
        } catch (Throwable) {
          // ignore
        }

        $_SESSION['t_login_fail'] = 0;
        $_SESSION['t_login_lock_until'] = 0;

        header('Location: ' . $next);
        exit;
      }

      // Fail path (do not leak whether user exists, is inactive, wrong level, etc.)
      $_SESSION['t_login_fail'] = (int)$_SESSION['t_login_fail'] + 1;

      // Lock after 6 failed attempts for 10 minutes
      if ((int)$_SESSION['t_login_fail'] >= 6) {
        $_SESSION['t_login_lock_until'] = time() + 600;
        $error = 'Too many attempts. Try again in 10 minutes.';
      } else {
        $error = 'Invalid credentials or insufficient permissions.';
      }

      usleep(200_000);
    }
  }
}

if ($isLocked && $error === '') {
  $remain = $lockedUntil - $now;
  $info = 'Login is temporarily locked for ' . (int)ceil($remain / 60) . ' minute(s).';
}

// ---- Layout (header/footer) ----
$page_title = 'Teacher login';
$teacher_is_guest = true; // critical: do NOT enforce teacher/_guard.php here
require __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-md-8 col-lg-5">
    <div class="text-center mb-4">
      <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-4 bg-white shadow-sm border">
        <i class="bi bi-person-badge fs-4 text-primary"></i>
        <div class="text-start">
          <div class="fw-semibold">Teacher / Viewer Portal</div>
          <div class="text-muted small">Exam analytics (read-only)</div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h1 class="h5 mb-0">Sign in</h1>
          <span class="badge text-bg-primary-subtle border text-primary-emphasis">
            <i class="bi bi-shield-lock me-1"></i> Level 3
          </span>
        </div>

        <?php if ($error !== ''): ?>
          <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle"></i>
            <div><?= h($error) ?></div>
          </div>
        <?php elseif ($info !== ''): ?>
          <div class="alert alert-info d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-info-circle"></i>
            <div><?= h($info) ?></div>
          </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="next" value="<?= h($next) ?>">

          <div class="mb-3">
            <label class="form-label">Login</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input class="form-control"
                     name="login"
                     required
                     maxlength="64"
                     autofocus
                     <?= $isLocked ? 'disabled' : '' ?>>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-key"></i></span>
              <input class="form-control"
                     type="password"
                     name="password"
                     required
                     maxlength="200"
                     <?= $isLocked ? 'disabled' : '' ?>>
            </div>
          </div>

          <div class="d-grid gap-2">
            <button class="btn btn-primary" type="submit" <?= $isLocked ? 'disabled' : '' ?>>
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign in
            </button>
          </div>

          <p class="text-muted small mt-3 mb-0">
            Access is limited to accounts with <code>admins.level = 3</code>.
            If you need access, ask the school administrator to enable a teacher/viewer account.
          </p>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
