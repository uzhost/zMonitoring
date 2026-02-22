<?php
// admin/footer.php - Unified admin footer (Bootstrap 5.3.8) + CSP nonce support

declare(strict_types=1);

$cspNonce = '';
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csp_nonce']) && is_string($_SESSION['csp_nonce'])) {
    $cspNonce = $_SESSION['csp_nonce'];
}

$appName = 'School Analytics';
$year = (int)date('Y');
$appVersion = defined('APP_VERSION') ? (string)APP_VERSION : '';
?>
</main>

<style<?= $cspNonce !== '' ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
  .admin-footer {
    margin-top: 1rem;
    border-top: 1px solid rgba(15,23,42,.10);
    background:
      radial-gradient(950px 260px at 100% 0%, rgba(37,99,235,.10), transparent 66%),
      radial-gradient(950px 260px at 0% 100%, rgba(13,148,136,.10), transparent 66%),
      linear-gradient(180deg, #ffffff, #f8fbff);
  }
  .admin-footer .footer-wrap {
    min-height: 0;
  }
  .admin-footer .footer-title {
    font-weight: 700;
    color: #0f172a;
    letter-spacing: .01em;
  }
  .admin-footer .footer-muted {
    color: #64748b;
  }
  .admin-footer .footer-chip {
    border-radius: 999px;
    border: 1px solid rgba(37,99,235,.28);
    background: rgba(37,99,235,.10);
    color: #1d4ed8;
    padding: .2rem .56rem;
    font-size: .72rem;
    font-weight: 700;
  }
  .admin-footer .footer-link {
    color: #334155;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .28rem;
    padding: .22rem .1rem;
    border-bottom: 2px solid transparent;
    transition: color .15s ease, border-color .15s ease;
  }
  .admin-footer .footer-link:hover {
    color: #1d4ed8;
    border-color: rgba(29,78,216,.35);
    text-decoration: none;
  }
</style>

<footer class="admin-footer">
  <div class="container-fluid py-2">
    <div class="footer-wrap d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-2">
      <div class="d-flex flex-wrap align-items-center gap-2 small">
        <span class="footer-title">&copy; <?= htmlspecialchars((string)$year, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="footer-muted">Admin Panel</span>
        <?php if ($appVersion !== ''): ?>
          <span class="footer-chip">v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-3 small">
        <a class="footer-link" href="/admin/dashboard.php">
          <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="footer-link" href="/admin/classes.php">
          <i class="bi bi-collection"></i> Classes
        </a>
        <a class="footer-link" href="/admin/wm_subjects.php">
          <i class="bi bi-calendar-week"></i> Weekly
        </a>
        <a class="footer-link" href="/admin/reports.php">
          <i class="bi bi-graph-up-arrow"></i> Reports
        </a>
        <a class="footer-link" href="#top" id="backToTopLink">
          <i class="bi bi-arrow-up-short"></i> Back to top
        </a>
      </div>
    </div>
  </div>
</footer>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

<script<?= $cspNonce !== '' ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
(function () {
  var link = document.getElementById('backToTopLink');
  if (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  try {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (el) { new bootstrap.Tooltip(el); });
  } catch (e) {}
})();
</script>

</body>
</html>
