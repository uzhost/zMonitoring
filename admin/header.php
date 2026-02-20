<?php
// admin/header.php — Unified layout header (Bootstrap 5.3.8 CDN) + UX + CSP nonce
// Supports guest pages by setting: $auth_required = false; $show_admin_nav = false;

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// -------------------- Mode flags --------------------
$auth_required   = $auth_required   ?? true;
$show_admin_nav  = $show_admin_nav  ?? true;

if ($auth_required) {
    require_admin();
}

// -------------------- Page metadata --------------------
$pageTitle   = isset($page_title) && is_string($page_title) && $page_title !== '' ? $page_title : 'Admin';
$adminLogin  = (string)($_SESSION['admin_login'] ?? '');
$isGuest     = !$auth_required;

// -------------------- Active nav helper --------------------
$current = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$active = static function (string $file) use ($current): string {
    return $current === $file ? ' active' : '';
};

// -------------------- Nav groups (for dropdown active state) --------------------
$importPages = ['pupils_import.php', 'results_import.php', 'subjects.php'];
$isImportActive = in_array($current, $importPages, true);

$classPages = ['class_report.php', 'class_pupils.php', 'class_group.php', 'classes.php'];
$isClassActive = in_array($current, $classPages, true);

$pupilPages = ['pupils.php', 'pupils_result.php', 'certificates.php'];
$isPupilsActive = in_array($current, $pupilPages, true);

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
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <meta name="csp-nonce" content="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">

  <!-- Bootstrap 5.3.8 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- Custom admin styles -->
  <link href="/assets/admin.css" rel="stylesheet">

  <?php if ($isGuest): ?>
    <style>
      body{
        min-height:100vh;
        background:
          radial-gradient(1200px 600px at 20% 10%, rgba(13,110,253,.12), transparent 60%),
          radial-gradient(900px 500px at 90% 90%, rgba(25,135,84,.10), transparent 60%),
          #f8f9fa;
      }
      .login-shell{ min-height:100vh; }
      .brand-badge{
        width:52px;height:52px;display:inline-flex;align-items:center;justify-content:center;border-radius:16px;
      }
      .small-muted{ color: rgba(0,0,0,.55); }
      .card{ border-radius:16px; }
    </style>
  <?php endif; ?>

  <?php if (!$isGuest): ?>
    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      /* Navbar polish (scoped and safe) */
      .navbar{
        border-bottom: 1px solid rgba(255,255,255,.08);
      }
      .navbar .nav-link{
        border-radius: .65rem;
        padding: .55rem .75rem;
      }
      .navbar .nav-link.active{
        background: rgba(255,255,255,.12);
      }
      .navbar .nav-link:hover{
        background: rgba(255,255,255,.08);
      }
      .dropdown-menu{
        border-radius: .9rem;
        overflow: hidden;
      }
      .dropdown-menu-dark{
        border: 1px solid rgba(255,255,255,.08);
      }
      .dropdown-item{
        padding: .55rem .9rem;
      }
      .dropdown-item.active,
      .dropdown-item:active{
        background: rgba(13,110,253,.25);
      }
      .nav-divider{
        width: 1px;
        background: rgba(255,255,255,.12);
        margin: .55rem .6rem;
        align-self: stretch;
        display: none;
      }
      @media (min-width: 992px){
        .nav-divider{ display:block; }
      }
    </style>
  <?php endif; ?>
</head>
<body class="bg-light" id="top">

<a class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white border rounded-2 m-2" href="#mainContent">
  Skip to content
</a>

<?php if ($show_admin_nav && !$isGuest): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="dashboard.php">
      <i class="bi bi-mortarboard"></i>
      <span>Exam Analytics</span>
    </a>

    <button
      class="navbar-toggler"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#adminNav"
      aria-controls="adminNav"
      aria-expanded="false"
      aria-label="Toggle navigation"
    >
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="adminNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">

        <li class="nav-item">
          <a class="nav-link<?= $active('dashboard.php') ?>" href="dashboard.php" aria-current="<?= $current === 'dashboard.php' ? 'page' : 'false' ?>">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
          </a>
        </li>

        <div class="nav-divider"></div>

        <!-- Import dropdown -->
        <li class="nav-item dropdown">
          <a
            class="nav-link dropdown-toggle<?= $isImportActive ? ' active' : '' ?>"
            href="#"
            role="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            aria-current="<?= $isImportActive ? 'page' : 'false' ?>"
          >
            <i class="bi bi-upload me-1"></i> Import
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow-sm">
            <li>
              <a class="dropdown-item<?= $active('pupils_import.php') ?>" href="pupils_import.php">
                <i class="bi bi-people me-2"></i> Pupils import
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('results_import.php') ?>" href="results_import.php">
                <i class="bi bi-journal-text me-2"></i> Results import
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item<?= $active('subjects.php') ?>" href="subjects.php">
                <i class="bi bi-book me-2"></i> Subjects
              </a>
            </li>
          </ul>
        </li>

        <!-- Class dropdown (dark + consistent) -->
        <li class="nav-item dropdown">
          <a
            class="nav-link dropdown-toggle<?= $isClassActive ? ' active' : '' ?>"
            href="#"
            role="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            aria-current="<?= $isClassActive ? 'page' : 'false' ?>"
          >
            <i class="bi bi-diagram-3 me-1"></i> Class
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow-sm">
            <li>
              <a class="dropdown-item<?= $active('class_report.php') ?>" href="class_report.php">
                <i class="bi bi-clipboard-data me-2"></i> Class Reports
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('class_pupils.php') ?>" href="class_pupils.php">
                <i class="bi bi-table me-2"></i> Class Pupils
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('class_group.php') ?>" href="class_group.php">
                <i class="bi bi-table me-2"></i> Class Groups
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('classes.php') ?>" href="classes.php">
                <i class="bi bi-collection me-2"></i> Classes
              </a>
            </li>
          </ul>
        </li>

        <!-- Pupils dropdown -->
        <li class="nav-item dropdown">
          <a
            class="nav-link dropdown-toggle<?= $isPupilsActive ? ' active' : '' ?>"
            href="#"
            role="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            aria-current="<?= $isPupilsActive ? 'page' : 'false' ?>"
          >
            <i class="bi bi-people me-1"></i> Pupils
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow-sm">
            <li>
              <a class="dropdown-item<?= $active('pupils.php') ?>" href="pupils.php">
                <i class="bi bi-people me-2"></i> Pupils list
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('pupils_result.php') ?>" href="pupils_result.php">
                <i class="bi bi-graph-up-arrow me-2"></i> Pupils results
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active('certificates.php') ?>" href="certificates.php">
                <i class="bi bi-patch-check me-2"></i> Certificates
              </a>
            </li>
          </ul>
        </li>

        <div class="nav-divider"></div>

        <li class="nav-item">
          <a class="nav-link<?= $active('reports.php') ?>" href="reports.php" aria-current="<?= $current === 'reports.php' ? 'page' : 'false' ?>">
            <i class="bi bi-bar-chart-line me-1"></i> Reports
          </a>
        </li>

      </ul>

      <div class="d-flex align-items-center gap-2">
        <div class="d-none d-lg-flex align-items-center text-light small">
          <i class="bi bi-person-circle me-2"></i>
          <span class="text-truncate" style="max-width: 220px;">
            <?= htmlspecialchars($adminLogin !== '' ? $adminLogin : 'Admin', ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>

        <a href="logout.php" class="btn btn-outline-light btn-sm">
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
      <h1 class="h4 mb-0"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
      <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
        <i class="bi bi-shield-lock me-1"></i> Admin
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
      <div class="alert alert-<?= htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8') ?> d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-info-circle"></i>
        <div><?= htmlspecialchars($f['msg'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
