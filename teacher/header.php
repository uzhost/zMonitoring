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

// -------------------- Determine current admin/teacher level (tolerant) --------------------
/**
 * Robustly read admin level from various session shapes.
 * Expected meanings (per your project):
 *   Level 1 = superadmin/admin (full control)
 *   Level 2 = staff/admin (limited)
 *   Level 3 = viewer/teacher (read-only)
 */
$read_admin_level = static function (): int {
    // Prefer helper if it exists
    if (function_exists('admin_level')) {
        try {
            $lvl = (int)admin_level();
            if ($lvl > 0) return $lvl;
        } catch (\Throwable $e) {
            // ignore; fall back to session
        }
    }

    // Common session patterns
    if (isset($_SESSION['admin']) && is_array($_SESSION['admin']) && isset($_SESSION['admin']['level'])) {
        return (int)$_SESSION['admin']['level'];
    }
    if (isset($_SESSION['admin_level'])) return (int)$_SESSION['admin_level'];
    if (isset($_SESSION['level'])) return (int)$_SESSION['level'];

    return 0;
};

$adminLevel = $read_admin_level();

// -------------------- Guard only when not guest --------------------
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

        // Re-read in case session was set during require/auth
        $adminLevel = $read_admin_level();

        if (($adminLevel ?? 0) !== 3) {
            http_response_code(403);
            echo 'Forbidden.';
            exit;
        }
    }
}

// If we are not guest and logged in, normalize displayed level (should be 3 on teacher portal)
if (!$teacher_is_guest && $auth_required && $adminLevel <= 0) {
    $adminLevel = 3;
}

// -------------------- Page metadata --------------------
$pageTitle = (isset($page_title) && is_string($page_title) && $page_title !== '') ? $page_title : 'Teacher Portal';
$pageSubtitle = (isset($page_subtitle) && is_string($page_subtitle)) ? trim($page_subtitle) : '';
$showPageHeader = !isset($show_page_header) || (bool)$show_page_header;
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

// -------------------- Level label helpers --------------------
$levelLabel = match ((int)$adminLevel) {
    1 => 'Level 1',
    2 => 'Level 2',
    3 => 'Level 3',
    default => 'Level -',
};

$levelRoleLabel = match ((int)$adminLevel) {
    1 => 'Admin',
    2 => 'Staff',
    3 => 'Viewer',
    default => 'User',
};

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
    <style nonce="<?= h($cspNonce) ?>">
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
      body.bg-light{
        background:
          radial-gradient(1200px 520px at 12% -8%, rgba(59,130,246,.09), transparent 60%),
          radial-gradient(1000px 560px at 105% 110%, rgba(16,185,129,.08), transparent 65%),
          #f4f7fb !important;
      }
      /* Navbar polish */
      .teacher-nav{
        border-bottom: 1px solid rgba(125,211,252,.28);
        background:
          linear-gradient(100deg, #0b3a53 0%, #0f766e 52%, #1d4ed8 100%) !important;
        box-shadow: 0 8px 18px rgba(2,6,23,.24);
        position: sticky;
        top: 0;
        z-index: 1030;
        backdrop-filter: blur(6px);
      }
      .teacher-nav .nav-link{
        border-radius: .70rem;
        padding: .56rem .78rem;
        border: 1px solid transparent;
      }
      .teacher-nav .nav-link.active{
        background: rgba(186,230,253,.18);
        border-color: rgba(186,230,253,.35);
      }
      .teacher-nav .nav-link:hover{
        background: rgba(255,255,255,.14);
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
        background: rgba(255,255,255,.14);
      }
      .user-chip i{ opacity:.9; }
      .nav-level-chip{
        display:inline-flex;
        align-items:center;
        gap:.35rem;
        padding:.34rem .62rem;
        border-radius: 999px;
        border: 1px solid rgba(255,255,255,.36);
        background: rgba(255,255,255,.88);
        color: #0f172a;
        font-size: .78rem;
        font-weight: 700;
        line-height: 1;
      }
      .brand-mark{
        width:34px;
        height:34px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        border-radius:.6rem;
        background:rgba(13,110,253,.22);
        border:1px solid rgba(255,255,255,.12);
      }
      .page-head{
        background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.82));
        border: 1px solid rgba(15,23,42,.08);
        border-radius: .9rem;
        padding: .8rem .95rem;
      }
      .page-title{
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: .01em;
        color: #0f172a;
      }
      .page-subtitle{
        font-size: .86rem;
        color: rgba(51,65,85,.78);
      }
      .page-actions{
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
      }
      .flash-stack .alert{
        border-radius: .75rem;
        border: 1px solid rgba(148,163,184,.32);
        margin-bottom: .6rem;
      }
      .flash-icon{
        width: 1.25rem;
        text-align: center;
        margin-top: .1rem;
      }
    </style>
  <?php endif; ?>
</head>
<body class="bg-light" id="top">

<a class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white border rounded-2 m-2" href="#mainContent">
  Skip to content
</a>

<?php if ($show_teacher_nav && !$isGuest): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm teacher-nav">
  <div class="container-fluid">

    <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="/teacher/dashboard.php">
      <span class="brand-mark">
        <i class="bi bi-person-badge"></i>
      </span>
      <span>Teacher Portal</span>
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

        <!-- Visual divider -->
        <li class="nav-item d-none d-lg-flex align-items-stretch" aria-hidden="true">
          <span class="nav-divider"></span>
        </li>

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
          <a class="nav-link<?= $active('compare.php') ?>" href="/teacher/compare.php"
             aria-current="<?= $current === 'compare.php' ? 'page' : 'false' ?>">
            <i class="bi bi-bar-chart-line me-1"></i> Comparison
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= $active('reports.php') ?>" href="/teacher/reports.php"
             aria-current="<?= $current === 'reports.php' ? 'page' : 'false' ?>">
            <i class="bi bi-graph-up-arrow me-1"></i> Reports
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= $active('p-hisobot.php') ?>" href="/teacher/p-hisobot.php"
             aria-current="<?= $current === 'p-hisobot.php' ? 'page' : 'false' ?>">
            <i class="bi bi-printer me-1"></i> Print
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= $active('certificates.php') ?>" href="/teacher/certificates.php"
             aria-current="<?= $current === 'certificates.php' ? 'page' : 'false' ?>">
            <i class="bi bi-patch-check me-1"></i> Certificates
          </a>
        </li>

      </ul>

      <div class="d-flex align-items-center gap-2">
        <div class="user-chip text-light small d-none d-lg-inline-flex" title="Signed in user">
          <i class="bi bi-person-circle"></i>
          <span class="text-truncate" style="max-width: 220px;"><?= h($userLogin !== '' ? $userLogin : 'Teacher') ?></span>
        </div>
        <span class="nav-level-chip d-none d-lg-inline-flex" title="<?= h($levelRoleLabel) ?>">
          <i class="bi bi-shield-check"></i> <?= h($levelLabel) ?>
        </span>

        <a href="/teacher/logout.php" class="btn btn-outline-light btn-sm">
          <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>

<main id="mainContent" class="<?= $isGuest ? 'container' : 'container-fluid' ?> py-4">

<?php if (!$isGuest && $showPageHeader): ?>
  <div class="page-head d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
      <div class="page-title"><?= h($pageTitle) ?></div>
      <?php if ($pageSubtitle !== ''): ?>
        <div class="page-subtitle mt-1"><?= h($pageSubtitle) ?></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($page_actions) && is_string($page_actions)): ?>
      <div class="page-actions">
        <?= $page_actions /* trusted HTML */ ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($flashes): ?>
  <div class="mb-3 flash-stack">
    <?php foreach ($flashes as $f): ?>
      <?php
        $flashIcon = match ((string)$f['type']) {
            'success' => 'bi-check-circle-fill',
            'danger' => 'bi-x-octagon-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            default => 'bi-info-circle-fill',
        };
      ?>
      <div class="alert alert-<?= h($f['type']) ?> d-flex align-items-start gap-2" role="alert">
        <i class="bi <?= h($flashIcon) ?> flash-icon"></i>
        <div><?= h($f['msg']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
