<?php
// admin/login.php — Admin authentication (admins table) — unified header/footer mode
// DROP-IN: Enforces admin level access. Only level 1 and 2 may sign in.

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Use unified header in guest mode
$auth_required  = false;
$show_admin_nav = false;
$page_title     = 'Admin Login';

// Safe "next"
$nextRaw = (string)($_GET['next'] ?? 'dashboard.php');
if ($nextRaw !== '' && $nextRaw[0] !== '/') {
    $nextRaw = '/admin/' . ltrim($nextRaw, '/');
}
$next = safe_next_url($nextRaw, '/admin/dashboard.php');

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . $next);
    exit;
}

// Brute-force throttling
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SEC   = 10 * 60;
const LOGIN_LOCK_SEC     = 10 * 60;

// Admin level access (ONLY these can log in here)
const ADMIN_ALLOWED_LEVELS = [1, 2];

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$apcuOk = function_exists('apcu_fetch') && (bool)ini_get('apc.enabled');
$ipKey  = 'admin_login_ip:' . hash('sha256', $ip);

function _now(): int { return time(); }

function _purge_old_attempts(array $attempts, int $windowSec): array
{
    $cut = _now() - $windowSec;
    $out = [];
    foreach ($attempts as $t) {
        if (is_int($t) && $t >= $cut) $out[] = $t;
    }
    return $out;
}

function _is_locked(array $attempts, int $maxAttempts, int $windowSec, int $lockSec): bool
{
    $attempts = _purge_old_attempts($attempts, $windowSec);
    if (count($attempts) < $maxAttempts) return false;
    $last = end($attempts);
    return is_int($last) && (_now() - $last) < $lockSec;
}

if (!isset($_SESSION['_login_attempts']) || !is_array($_SESSION['_login_attempts'])) {
    $_SESSION['_login_attempts'] = [];
}
$_SESSION['_login_attempts'] = _purge_old_attempts($_SESSION['_login_attempts'], LOGIN_WINDOW_SEC);

$ipAttempts = [];
if ($apcuOk) {
    $tmp = apcu_fetch($ipKey);
    if (is_array($tmp)) $ipAttempts = $tmp;
    $ipAttempts = _purge_old_attempts($ipAttempts, LOGIN_WINDOW_SEC);
}

$locked = _is_locked($_SESSION['_login_attempts'], LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_SEC, LOGIN_LOCK_SEC)
       || ($apcuOk && _is_locked($ipAttempts, LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_SEC, LOGIN_LOCK_SEC));

$error = null;
$loginValue = '';
// Dummy hash (timing mitigation)
$dummyHash = '$2y$10$wH5XwC8b7l8f4yq4oD6e5eYc1o9g8i7h6j5k4l3m2n1o0p9q8r7s6';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($locked) {
        usleep(200_000);
        $error = 'Too many attempts. Please wait and try again.';
    } else {
        $loginRaw   = (string)($_POST['login'] ?? '');
        $login      = mb_strtolower(trim($loginRaw));
        $loginValue = $loginRaw;
        $password   = (string)($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $error = 'Please enter login and password.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, login, password_hash, role, level, is_active
                 FROM admins
                 WHERE login = :login
                 LIMIT 1'
            );
            $stmt->execute(['login' => $login]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            $hash     = $dummyHash;
            $isActive = false;
            $level    = 0;

            if (is_array($admin)) {
                $isActive = ((int)($admin['is_active'] ?? 0) === 1);
                $level    = (int)($admin['level'] ?? 0);

                if (!empty($admin['password_hash']) && is_string($admin['password_hash'])) {
                    $hash = $admin['password_hash'];
                }
            }

            $passOk = password_verify($password, $hash);

            $ok = is_array($admin)
                && $isActive
                && $passOk
                && in_array($level, ADMIN_ALLOWED_LEVELS, true)
                && isset($admin['id'], $admin['role'], $admin['login']);

            if (!$ok) {
                $_SESSION['_login_attempts'][] = _now();
                if ($apcuOk) {
                    $ipAttempts[] = _now();
                    apcu_store($ipKey, $ipAttempts, LOGIN_WINDOW_SEC + LOGIN_LOCK_SEC);
                }
                usleep(200_000);
                // Do not leak whether it's credentials, inactive, or insufficient level
                $error = 'Invalid credentials or insufficient permissions.';
            } else {
                $_SESSION['_login_attempts'] = [];
                if ($apcuOk) apcu_delete($ipKey);

                // Keep compatibility with your existing auth.php signature.
                // Store level in tolerant shapes so all pages can read it consistently.
                admin_login_session((int)$admin['id'], (string)$admin['role'], (string)$admin['login']);

                $_SESSION['admin_level'] = $level;
                $_SESSION['level'] = $level;
                if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
                    $_SESSION['admin'] = [];
                }
                $_SESSION['admin']['level'] = $level;
                $_SESSION['admin']['role']  = (string)$admin['role'];
                $_SESSION['admin']['login'] = (string)$admin['login'];
                $_SESSION['admin']['id']    = (int)$admin['id'];

                // Optional: audit update if columns exist (ignore failures)
                try {
                    $pdo->prepare('UPDATE admins SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id LIMIT 1')
                        ->execute(['ip' => $ip, 'id' => (int)$admin['id']]);
                } catch (Throwable $e) {}

                header('Location: ' . $next);
                exit;
            }
        }
    }
}

$csrf = csrf_token();

$remaining = null;
if ($locked) {
    $all = $_SESSION['_login_attempts'];
    if ($apcuOk && count($ipAttempts) > count($all)) $all = $ipAttempts;
    $last = end($all);
    if (is_int($last)) $remaining = max(0, LOGIN_LOCK_SEC - (_now() - $last));
}

require_once __DIR__ . '/header.php';
?>

<div class="container login-shell d-flex align-items-center py-4">
  <div class="row justify-content-center w-100">
    <div class="col-md-7 col-lg-4">

      <div class="text-center mb-3">
        <span class="brand-badge bg-primary text-white shadow-sm">
          <i class="bi bi-shield-lock-fill fs-4"></i>
        </span>
        <div class="mt-2 fw-semibold">Exam Analytics</div>
        <div class="small small-muted">Administrator access</div>
      </div>

      <div class="card shadow-sm border-0">
        <div class="card-body p-4">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0">Sign in</h5>
            <span class="badge text-bg-light border">
              <i class="bi bi-lock-fill me-1"></i>Secure
            </span>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
              <i class="bi bi-exclamation-triangle-fill mt-1"></i>
              <div>
                <div class="fw-semibold"><?= h($error) ?></div>
                <?php if ($remaining !== null && $remaining > 0): ?>
                  <div class="small mt-1">
                    Try again in <?= h((string)ceil($remaining / 60)) ?> minute(s).
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <form method="post" novalidate autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">

            <div class="mb-3">
              <label class="form-label">Login</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                <input type="text"
                       name="login"
                       class="form-control"
                       required
                       autofocus
                       autocomplete="username"
                       value="<?= h_attr($loginValue) ?>"
                       <?= $locked ? 'disabled' : '' ?>>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                <input id="pwd"
                       type="password"
                       name="password"
                       class="form-control"
                       required
                       autocomplete="current-password"
                       <?= $locked ? 'disabled' : '' ?>>
                <button class="btn btn-outline-secondary" type="button" id="togglePwd"
                        <?= $locked ? 'disabled' : '' ?> aria-label="Show password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="form-text small-muted">
                Authorized users only. Access attempts may be logged.
                <span class="d-block mt-1">Allowed admin levels: <span class="mono">1–2</span>.</span>
              </div>
            </div>

            <button class="btn btn-primary w-100 mt-3" type="submit" <?= $locked ? 'disabled' : '' ?>>
              <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
            </button>

            <?php if ($next !== '/admin/dashboard.php'): ?>
              <div class="text-center small small-muted mt-2">
                You will be redirected after login.
              </div>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <p class="text-center text-muted mt-3 small mb-0">
        © <?= h((string)date('Y')) ?> Exam Analytics
      </p>

    </div>
  </div>
</div>

<script<?= !empty($_SESSION['csp_nonce']) ? ' nonce="' . h_attr($_SESSION['csp_nonce']) . '"' : '' ?>>
(function () {
  const btn = document.getElementById('togglePwd');
  const pwd = document.getElementById('pwd');
  if (!btn || !pwd) return;

  btn.addEventListener('click', function () {
    const isPwd = pwd.type === 'password';
    pwd.type = isPwd ? 'text' : 'password';
    btn.setAttribute('aria-label', isPwd ? 'Hide password' : 'Show password');
    btn.innerHTML = isPwd ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
  });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
