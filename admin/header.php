<?php
// admin/header.php — Admin layout header (Bootstrap 5.3.8 CDN) + UX + CSP nonce

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin(); // blocks non-admins

// -------------------- Page metadata --------------------
$pageTitle = isset($page_title) && is_string($page_title) && $page_title !== '' ? $page_title : 'Admin';
$adminLogin = (string)($_SESSION['admin_login'] ?? '');

// -------------------- Active nav helper --------------------
$current = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$active = static function (string $file) use ($current): string {
    return $current === $file ? ' active' : '';
};

// Import dropdown active group
$importPages = ['pupils_import.php', 'results_import.php', 'subject_import.php'];
$isImportActive = in_array($current, $importPages, true);

// -------------------- Flash messages (optional) --------------------
// Convention: $_SESSION['flash'] = [['type'=>'success|danger|warning|info','msg'=>'...'], ...]
$flashes = [];
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    foreach ($_SESSION['flash'] as $f) {
        if (!is_array($f)) continue;
        $type = in_array(($f['type'] ?? 'info'), ['success','danger','warning','info'], true) ? $f['type'] : 'info';
        $msg  = (string)($f['msg'] ?? '');
        if ($msg !== '') $flashes[] = ['type' => $type, 'msg' => $msg];
    }
    unset($_SESSION['flash']);
}

// -------------------- CSP nonce (if your project supports CSP) --------------------
if (empty($_SESSION['csp_nonce']) || !is_string($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}
$cspNonce = $_SESSION['csp_nonce'];

// -------------------- Lightweight security headers (safe defaults) --------------------
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- Custom admin styles -->
  <link href="/assets/admin.css" rel="stylesheet">
</head>
<body class="bg-light">

<a class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white border rounded-2 m-2" href="#mainContent">
  Skip to content
</a>

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
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link<?= $active('dashboard.php') ?>" href="dashboard.php" aria-current="<?= $current === 'dashboard.php' ? 'page' : 'false' ?>">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
          </a>
        </li>

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
              <a class="dropdown-item<?= $active('results_import.php') ?>" href="results_import.php">
                <i class="bi bi-clipboard-data me-2"></i> Results import
              </a>
            </li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= $active('pupils.php') ?>" href="pupils.php" aria-current="<?= $current === 'pupils.php' ? 'page' : 'false' ?>">
            <i class="bi bi-people me-1"></i> Pupils
          </a>
        </li>

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

<main id="mainContent" class="container-fluid py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <h1 class="h4 mb-0"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
      <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
        <i class="bi bi-shield-lock me-1"></i> Admin
      </span>
    </div>

    <!-- Optional slot for page-level actions -->
    <?php if (!empty($page_actions) && is_string($page_actions)): ?>
      <div class="d-flex gap-2">
        <?= $page_actions /* trusted HTML you set per-page; do NOT pass user input here */ ?>
      </div>
    <?php endif; ?>
  </div>

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
