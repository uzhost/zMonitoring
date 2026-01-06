<?php
// admin/footer.php — Unified layout footer (Bootstrap 5.3.8) + UX polish + CSP nonce support

declare(strict_types=1);

$cspNonce = '';
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csp_nonce']) && is_string($_SESSION['csp_nonce'])) {
    $cspNonce = $_SESSION['csp_nonce'];
}

$appName = 'School Exam Analytics';
$year    = (int)date('Y');
$appVersion = defined('APP_VERSION') ? (string)APP_VERSION : '';
?>
</main>

<footer class="border-top bg-white mt-5">
  <div class="container-fluid py-3">
    <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-2 small text-muted">
      <div class="d-flex align-items-center gap-2">
        <span>© <?= htmlspecialchars((string)$year, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="d-none d-md-inline">•</span>
        <span class="text-muted">Internal Admin Panel</span>
        <?php if ($appVersion !== ''): ?>
          <span class="d-none d-md-inline">•</span>
          <span class="badge text-bg-light border">v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>

      <div class="d-flex align-items-center gap-3">
        <a class="link-secondary text-decoration-none" href="dashboard.php">
          <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>

        <a class="link-secondary text-decoration-none" href="#top" id="backToTopLink">
          <i class="bi bi-arrow-up-short me-1"></i> Back to top
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
  // Smooth "Back to top"
  var link = document.getElementById('backToTopLink');
  if (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // Enable tooltips globally if present
  try {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (el) { new bootstrap.Tooltip(el); });
  } catch (e) {}
})();
</script>

</body>
</html>
