<?php
// teacher/p-header.php  Print-first report header (Bootstrap 5.3.8 CDN), no portal chrome
declare(strict_types=1);

require_once __DIR__ . '/../inc/tauth.php';

session_start_secure();

function uz_fix(string $text): string
{
    $text = str_replace(
        ["\u{2018}", "\u{2019}", "\u{02BB}", "\u{02BC}", "\u{0060}", "\u{00B4}", "\u{02B9}"],
        "'",
        $text
    );

    $rules = [
        'kok' => "ko'k",
        'alo' => "a'lo",
        'malumot' => "ma'lumot",
        'qollash' => "qo'llash",
        'korsatkich' => "ko'rsatkich",
        'korsatish' => "ko'rsatish",
        'ortacha' => "o'rtacha",
        'osish' => "o'sish",
        'ozgarish' => "o'zgarish",
        'boyicha' => "bo'yicha",
        'royxat' => "ro'yxat",
    ];

    foreach ($rules as $from => $to) {
        $text = preg_replace('/\b' . preg_quote($from, '/') . '\b/i', $to, $text) ?? $text;
    }

    return $text;
}

// Optional: report pages should still be protected unless explicitly disabled
$auth_required = $auth_required ?? true;
if ($auth_required) {
    // Teacher portal guard (levels 1/2/3)
    if (function_exists('require_teacher')) {
        require_teacher();
    } else {
        if (function_exists('teacher_id') && teacher_id() <= 0) {
            $to = (string)($_SERVER['REQUEST_URI'] ?? '/teachers/dashboard.php');
            header('Location: /teachers/login.php?next=' . rawurlencode($to));
            exit;
        }
    }
}

// Page metadata
$pageTitle      = (isset($page_title) && is_string($page_title) && $page_title !== '') ? $page_title : 'Hisobot';
$reportTitle    = (isset($report_title) && is_string($report_title)) ? trim($report_title) : '';
$reportSubtitle = (isset($report_subtitle) && is_string($report_subtitle)) ? trim($report_subtitle) : '';
$reportMeta     = (isset($report_meta) && is_array($report_meta)) ? $report_meta : [];

$pageTitle = uz_fix($pageTitle);
$reportTitle = uz_fix($reportTitle);
$reportSubtitle = uz_fix($reportSubtitle);

$reportMetaFixed = [];
foreach ($reportMeta as $k => $v) {
    $key = uz_fix((string)$k);
    if ($key === '') {
        continue;
    }
    $val = is_scalar($v) ? uz_fix((string)$v) : '';
    $reportMetaFixed[$key] = $val;
}
$reportMeta = $reportMetaFixed;

// Print stamp (can be overridden)
// Priority: explicit $print_stamp (server) -> ?stamp=... (URL) -> now
$stampFromUrl = isset($_GET['stamp']) ? trim((string)$_GET['stamp']) : '';
if (mb_strlen($stampFromUrl) > 60) {
    $stampFromUrl = mb_substr($stampFromUrl, 0, 60);
}

$printStamp = (isset($print_stamp) && is_string($print_stamp) && trim($print_stamp) !== '')
    ? trim($print_stamp)
    : (($stampFromUrl !== '') ? $stampFromUrl : date('Y-m-d H:i'));

// CSP nonce (optional, consistent with your existing header approach)
if (empty($_SESSION['csp_nonce']) || !is_string($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}
$cspNonce = $_SESSION['csp_nonce'];

// Minimal security headers
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

?>
<!doctype html>
<html lang="uz">
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

  <!-- Optional: keep your global UI tokens -->
  <link href="/assets/admin.css" rel="stylesheet">

  <style nonce="<?= h($cspNonce) ?>">
    :root{
      --report-border: rgba(0,0,0,.22);
      --report-border-strong: rgba(0,0,0,.35);
      --report-muted: rgba(0,0,0,.62);
    }

    body{ background:#f8f9fa; color:#0f172a; }
    .report-shell{ max-width: 1080px; margin: 0 auto; }
    .report-head{
      background:#fff;
      border: 1px solid var(--report-border-strong);
      border-radius: 14px;
      padding: 14px 16px;
    }
    .report-title{ font-weight: 800; letter-spacing: .2px; }
    .report-subtitle{ color: var(--report-muted); font-size: 12.5px; }
    .report-meta{
      margin-top: 8px;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 6px 12px;
      font-size: 12px;
      color: var(--report-muted);
    }
    .report-meta .k{ color: rgba(0,0,0,.78); font-weight: 700; }
    .report-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .print-mode-group .btn{
      min-width: 92px;
    }
    .print-mode-group .btn.active{
      background: #0f172a;
      color: #fff;
      border-color: #0f172a;
    }

    .stamp-editor{ display:flex; gap:8px; align-items:center; justify-content:flex-end; }
    .stamp-editor .form-control{ max-width: 210px; }
    .stamp-display{ font-weight: 600; }

    /* Screen: keep a clean header + print button */
    @media screen {
      .only-print{ display:none !important; }
    }
    @media (max-width: 992px) {
      .report-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 576px) {
      .report-meta { grid-template-columns: 1fr; }
    }

    /* Print: official, compact, repeatable */
    @media print{
      @page{ margin: 10mm; }
      body{
        background:#fff !important;
        color:#111827 !important;
        font-size: 11px !important;
        line-height: 1.25 !important;
      }
      .no-print{ display:none !important; }
      .only-print{ display:block !important; }

      .report-shell{ max-width: none !important; margin: 0 !important; }
      #mainContent{
        padding-top: 0 !important;
        padding-bottom: 16mm !important;
      }
      .report-head{
        border-radius: 10px !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        margin-bottom: 4mm !important;
      }
      .report-title{
        font-size: 1.05rem !important;
        line-height: 1.2 !important;
      }
      .report-subtitle{
        font-size: 11px !important;
      }
      .report-meta{
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 4px 8px !important;
        font-size: 11px !important;
      }
      .print-footer{
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        padding: 4mm 10mm;
        border-top: 1px solid var(--report-border);
        font-size: 11px;
        color: rgba(0,0,0,.65);
      }
      .print-footer .page::after{ content: counter(page); }
      .stamp-editor{ display:none !important; }
      *{ -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    }
  </style>
</head>

<body>
  <a class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white border rounded-2 m-2" href="#mainContent">
    Mazmunga o'tish
  </a>

  <main id="mainContent" class="container-fluid py-3">
    <div class="report-shell">

      <!-- Report header (screen + print) -->
      <div class="report-head mb-3">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
          <div class="pe-2">
            <?php if ($reportTitle !== ''): ?>
              <div class="report-title h5 mb-1"><?= h($reportTitle) ?></div>
            <?php else: ?>
              <div class="report-title h5 mb-1"><?= h($pageTitle) ?></div>
            <?php endif; ?>

            <?php if ($reportSubtitle !== ''): ?>
              <div class="report-subtitle"><?= h($reportSubtitle) ?></div>
            <?php endif; ?>
          </div>

          <div class="report-actions no-print">
            <div class="btn-group btn-group-sm print-mode-group" role="group" aria-label="Print mode">
              <button type="button" class="btn btn-outline-dark" id="printModeColor">Colour</button>
              <button type="button" class="btn btn-outline-dark" id="printModeGray">Greyscale</button>
            </div>
            <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
              <i class="bi bi-printer me-1"></i>Chop etish
            </button>
          </div>
        </div>

        <!-- Editable print timestamp (screen only) -->
        <div class="stamp-editor no-print mt-2">
          <label class="form-label mb-0 small text-muted" for="reportStampInput">Hisobot vaqti:</label>
          <input id="reportStampInput" class="form-control form-control-sm" type="text"
                 value="<?= h($printStamp) ?>" placeholder="Masalan: 6-yanvar yoki 2026-01-06 09:00"
                 autocomplete="off" inputmode="text" maxlength="60">
          <button id="reportStampNow" type="button" class="btn btn-sm btn-outline-secondary" title="Hozirgi vaqt">
            <i class="bi bi-clock"></i>
          </button>
          <button id="reportStampReset" type="button" class="btn btn-sm btn-outline-secondary" title="Asl holat">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
        </div>

        <div class="report-meta mt-2">
          <?php
            // Always include timestamp (unless overridden by $report_meta)
            $meta = $reportMeta;
            $meta['Hisobot vaqti'] = $meta['Hisobot vaqti'] ?? $printStamp;

            foreach ($meta as $k => $v):
              if (!is_string($k) || $k === '') continue;
              $val = is_scalar($v) ? (string)$v : '';
              if ($val === '') continue;
          ?>
            <div>
              <span class="k"><?= h($k) ?>:</span>
              <?php if ($k === 'Hisobot vaqti'): ?>
                <span id="reportStampText" class="stamp-display" data-stamp-default="<?= h($val) ?>"><?= h($val) ?></span>
              <?php else: ?>
                <span><?= h($val) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
