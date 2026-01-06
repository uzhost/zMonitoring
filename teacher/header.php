<?php
// teacher/header.php — Teacher layout header (Bootstrap 5.3.8 CDN) + polished navbar + CSP nonce
// Guest pages: set $teacher_is_guest = true; OR $auth_required = false;

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// -------------------- Mode flags --------------------
$auth_required     = $auth_required     ?? true;
$show_teacher_nav  = $show_teacher_nav  ?? true;
$teacher_is_guest  = $teacher_is_guest  ?? (!$auth_required);

// Guard only when not guest
if (!$teacher_is_guest && $auth_required) {
    if (function_exists('require_teacher')) {
        require_teacher(); // preferred (level 3, redirects to /teacher/login.php)
    } else {
        // Fallback (tolerant session shapes)
        if (admin_id() <= 0) {
            $to = (string)($_SERVER['REQUEST_URI'] ?? '/teacher/dashboard.php');
            header('Location: /teacher/login.php?next=' . rawurlencode($to));
            exit;
        }
        $lvl = null;
        if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && isset($_SESSION['admin']['level'])) {
            $lvl = (int)$_SESSION['admin']['level'];
        } elseif (isset($_SESSION['admin_level'])) {
            $lvl = (int)$_SESSION['admin_level'];
        } elseif (isset($_SESSION['level'])) {
            $lvl = (int)$_SESSION['level'];
        }
        if (($lvl ?? 0) !== 3) {
            http_response_code(403);
            echo 'Forbidden.';
            exit;
        }
    }
}

// -------------------- Page metadata --------------------
$pageTitle = (isset($page_title) && is_string($page_title) && $page_title !== '') ? $page_title : 'Teacher Portal';
$userLogin = admin_login();
$isGuest   = (bool)$teacher_is_guest;

// -------------------- Active nav helper --------------------
$currentPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$current     = basename($currentPath);

$active = static function (string $file) use ($current): string {
    return $current === $file ? ' active' : '';
};

// Dropdown active groups
$classPages     = ['class_report.php', 'class_pupils.php'];
$isClassActive  = in_array($current, $classPages, true);

// -------------------- Flash messages (optional) --------------------
$flashes = [];
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    foreach ($_SESSION['flash'] as $f) {
        if (!is_array($f)) continue;
        $type = in_array(($f['type'] ?? 'info'), ['success','danger','warning','info'], true) ? (string)$f['type'] : 'info';
        $msg  = (string)($f['msg'] ?? '');
        if ($msg !== '') $flashes[] = ['type' => $type, 'msg' => $msg];
    }
    unset($_SESSION['flash']);
}

// -------------------- CSP nonce --------------------
if (empty($_SESSION['csp_nonce']) || !is_string($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}
$cspNonce = $_SESSION['csp_nonce'];

// -------------------- Lightweight security headers --------------------
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csp-nonce" content="<?= h($cspNonce) ?>">

  <!-- Bootstrap 5.3.8 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- Site styles (reuse admin.css if that’s your global UI) -->
  <link href="/assets/admin.css" rel="stylesheet">

  <?php if ($isGuest): ?>
    <style>
      body{
        min-height:100vh;
        background:
          radial-gradient(1200px 600px at 18% 10%, rgba(13,110,253,.14), transparent 60%),
          radial-gradient(900px 500px at 90% 90%, rgba(25,135,84,.12), transparent 60%),
          #f8f9fa;
      }
      .login-shell{ min-height:100vh; }
      .brand-badge{
        width:54px;height:54px;display:inline-flex;align-items:center;justify-content:center;border-radius:16px;
      }
      .small-muted{ color: rgba(0,0,0,.55); }
      .card{ border-radius: 18px; }
    </style>
  <?php else: ?>
    <style nonce="<?= h($cspNonce) ?>">
      /* Navbar polish */
      .navbar{
        border-bottom: 1px solid rgba(255,255,255,.08);
      }
      .navbar .nav-link{
        border-radius: .70rem;
        padding: .56rem .78rem;
      }
      .navbar .nav-link.active{
        background: rgba(255,255,255,.12);
      }
      .navbar .nav-link:hover{
        background: rgba(255,255,255,.08);
      }
      .dropdown-menu{
        border-radius: .95rem;
        overflow: hidden;
      }
      .dropdown-menu-dark{
        border: 1px solid rgba(255,255,255,.08);
      }
      .dropdown-item{
        padding: .56rem .92rem;
      }
      .dropdown-item.active,
      .dropdown-item:active{
        background: rgba(13,110,253,.25);
      }
      .nav-divider{
        width: 1px;
        background: rgba(255,255,255,.12);
        margin: .55rem .7rem;
        align-self: stretch;
        display: none;
      }
      @media (min-width: 992px){
        .nav-divider{ display:block; }
      }
      .user-chip{
        display:inline-flex;
        align-items:center;
        gap:.5rem;
        padding:.35rem .6rem;
        border-radius: 999px;
        border: 1px solid rgba(255,255,255,.18);
        background: rgba(255,255,255,.08);
      }
      .user-chip i{ opacity:.9; }
    </style>
  <?php endif; ?>
</head>
<body class="bg-light" id="top">

<a class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white border rounded-2 m-2" href="#mainContent">
  Skip to content
</a>

<?php if ($show_teacher_nav && !$isGuest): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container-fluid">

    <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="/teacher/dashboard.php">
      <span class="d-inline-flex align-items-center justify-content-center rounded-3"
            style="width:34px;height:34px;background:rgba(13,110,253,.22);border:1px solid rgba(255,255,255,.12);">
        <i class="bi bi-person-badge"></i>
      </span>
      <span>Teacher Portal</span>
      <span class="badge text-bg-secondary-subtle border text-secondary-emphasis ms-1 d-none d-md-inline">
        Level 3
      </span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#teacherNav"
            aria-controls="teacherNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="teacherNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">

        <li class="nav-item">
          <a class="nav-link<?= $active('dashboard.php') ?>" href="/teacher/dashboard.php"
             aria-current="<?= $current === 'dashboard.php' ? 'page' : 'false' ?>">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
          </a>
        </li>

        <div class="nav-divider"></div>

        <!-- Class dropdown -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $isClassActive ? ' active' : '' ?>" href="#"
             role="button" data-bs-toggle="dropdown" aria-expanded="false"
             aria-current="<?= $isClassActive ? 'page' : 'false' ?>">
            <i class="bi bi-diagram-3 me-1"></i> Class
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow-sm">
            <li>
              <a class="dropdown-item<?= $active('class_report.php') ?>" href="/teacher/class_report.php">
                <i class="bi bi-clipboard-data me-2"></i> Class reports
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('class_pupils.php') ?>" href="/teacher/class_pupils.php">
                <i class="bi bi-table me-2"></i> Class pupils
              </a>
            </li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= $active('reports.php') ?>" href="/teacher/reports.php"
             aria-current="<?= $current === 'reports.php' ? 'page' : 'false' ?>">
            <i class="bi bi-bar-chart-line me-1"></i> Reports
          </a>
        </li>

      </ul>

      <div class="d-flex align-items-center gap-2">
        <div class="user-chip text-light small d-none d-lg-inline-flex" title="Signed in user">
          <i class="bi bi-person-circle"></i>
          <span class="text-truncate" style="max-width: 220px;"><?= h($userLogin !== '' ? $userLogin : 'Teacher') ?></span>
        </div>

        <a href="/teacher/logout.php" class="btn btn-outline-light btn-sm">
          <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>

<main id="mainContent" class="<?= $isGuest ? 'container' : 'container-fluid' ?> py-4">

<?php if (!$isGuest): ?>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <h1 class="h4 mb-0"><?= h($pageTitle) ?></h1>
      <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
        <i class="bi bi-eye me-1"></i> Teacher
      </span>
    </div>

    <?php if (!empty($page_actions) && is_string($page_actions)): ?>
      <div class="d-flex gap-2">
        <?= $page_actions /* trusted HTML */ ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($flashes): ?>
  <div class="mb-3">
    <?php foreach ($flashes as $f): ?>
      <div class="alert alert-<?= h($f['type']) ?> d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-info-circle"></i>
        <div><?= h($f['msg']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
