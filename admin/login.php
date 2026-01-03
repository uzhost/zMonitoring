<?php
// admin/login.php — Admin authentication (admins table)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// If already logged in
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Normalize login (case-insensitive by convention)
    $loginRaw = (string)($_POST['login'] ?? '');
    $login    = mb_strtolower(trim($loginRaw));
    $password = (string)($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Please enter login and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, login, password_hash, role, is_active
             FROM admins
             WHERE login = :login
             LIMIT 1'
        );
        $stmt->execute(['login' => $login]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        $ok = $admin
            && (int)$admin['is_active'] === 1
            && is_string($admin['password_hash'])
            && password_verify($password, $admin['password_hash']);

        if (!$ok) {
            // constant-time delay to reduce brute force signal
            usleep(200_000);
            $error = 'Invalid credentials.';
        } else {
            session_regenerate_id(true);

            $_SESSION['admin_id']    = (int)$admin['id'];
            $_SESSION['admin_role']  = (string)$admin['role'];
            $_SESSION['admin_login'] = (string)$admin['login'];

            // Optional: track last login
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $upd = $pdo->prepare('
                    UPDATE admins
                    SET last_login_at = NOW(),
                        last_login_ip = INET6_ATON(:ip)
                    WHERE id = :id
                    LIMIT 1
                ');
                $upd->execute([
                    'ip' => $ip,
                    'id' => (int)$admin['id'],
                ]);
            } catch (Throwable $e) {
                // Do not block login if logging fails
            }

            header('Location: dashboard.php');
            exit;
        }
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">

<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h4 class="mb-3 text-center">Admin Login</h4>

          <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <form method="post" novalidate autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
              <label class="form-label">Login</label>
              <input
                type="text"
                name="login"
                class="form-control"
                required
                autofocus
                inputmode="text"
                autocomplete="username"
              >
            </div>

            <div class="mb-3">
              <label class="form-label">Password</label>
              <input
                type="password"
                name="password"
                class="form-control"
                required
                autocomplete="current-password"
              >
            </div>

            <button class="btn btn-primary w-100" type="submit">Sign in</button>
          </form>
        </div>
      </div>

      <p class="text-center text-muted mt-3 small">
        © <?= date('Y') ?> Exam Analytics
      </p>
    </div>
  </div>
</div>

</body>
</html>
