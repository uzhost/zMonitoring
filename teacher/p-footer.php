<?php
// teacher/p-footer.php  Print-first report footer
declare(strict_types=1);

// Note: this file assumes it is included after p-header.php in the same request,
// so helpers like h() and $cspNonce are already available.

?>

      <!-- Print-only footer (page number + optional signature line) -->
      <footer class="print-footer only-print">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-truncate pe-2">
            <?= h((string)date('Y')) ?> &middot; <?= h((string)($pageTitle ?? 'Hisobot')) ?>
          </div>
          <div class="small text-nowrap me-2">Vaqt: <span id="printFooterStamp"><?= h((string)($printStamp ?? '')) ?></span></div>
          <div class="mono text-nowrap">Sahifa: <span class="page"></span></div>
        </div>
      </footer>

    </div><!-- /.report-shell -->
  </main>

  <script nonce="<?= h($cspNonce ?? '') ?>">
  (function () {
    var modeColorBtn = document.getElementById('printModeColor');
    var modeGrayBtn = document.getElementById('printModeGray');
    var modeKey = 'ztim_print_mode:' + location.pathname;

    function setPrintMode(mode, persist) {
      var isGray = mode === 'greyscale';
      document.body.classList.toggle('print-greyscale', isGray);
      document.body.classList.toggle('print-colour', !isGray);
      if (modeColorBtn) modeColorBtn.classList.toggle('active', !isGray);
      if (modeGrayBtn) modeGrayBtn.classList.toggle('active', isGray);
      if (persist) {
        try { localStorage.setItem(modeKey, isGray ? 'greyscale' : 'colour'); } catch (e) {}
      }
    }

    var initialMode = 'colour';
    try {
      var urlMode = (new URL(window.location.href)).searchParams.get('print_mode');
      if (urlMode === 'greyscale' || urlMode === 'gray') {
        initialMode = 'greyscale';
      } else {
        var savedMode = localStorage.getItem(modeKey);
        if (savedMode === 'greyscale' || savedMode === 'colour') initialMode = savedMode;
      }
    } catch (e) {}

    setPrintMode(initialMode, false);
    if (modeColorBtn) modeColorBtn.addEventListener('click', function () { setPrintMode('colour', true); });
    if (modeGrayBtn) modeGrayBtn.addEventListener('click', function () { setPrintMode('greyscale', true); });

    var input = document.getElementById('reportStampInput');
    var text  = document.getElementById('reportStampText');
    var footerStamp = document.getElementById('printFooterStamp');
    if (!input || !text) return;

    var key = 'ztim_print_stamp:' + location.pathname;
    var defaultStamp = (text.dataset && text.dataset.stampDefault) ? String(text.dataset.stampDefault) : String(input.value || '');
    defaultStamp = defaultStamp.trim();

    function clamp(v) {
      v = String(v || '').trim();
      if (!v) v = defaultStamp;
      if (v.length > 60) v = v.slice(0, 60);
      return v;
    }

    function setStamp(v, persist) {
      v = clamp(v);
      input.value = v;
      text.textContent = v;
      if (footerStamp) footerStamp.textContent = v;
      if (persist) {
        try { localStorage.setItem(key, v); } catch (e) {}
      }
    }

    // 1) URL override (?stamp=6-yanvar) wins, and is persisted for this page.
    try {
      var url = new URL(window.location.href);
      var urlStamp = url.searchParams.get('stamp');
      if (urlStamp && urlStamp.trim()) {
        setStamp(urlStamp, true);
      } else {
        // 2) Local storage (per page) if present
        var saved = localStorage.getItem(key);
        if (saved && saved.trim()) setStamp(saved, false);
      }
    } catch (e) {}

    // Live edit
    input.addEventListener('input', function () {
      setStamp(input.value, true);
    });

    // Keep printed value consistent
    window.addEventListener('beforeprint', function () {
      var isGray = document.body.classList.contains('print-greyscale');
      setPrintMode(isGray ? 'greyscale' : 'colour', false);
      setStamp(input.value, false);
    });

    var btnNow = document.getElementById('reportStampNow');
    if (btnNow) {
      btnNow.addEventListener('click', function () {
        var d = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var v = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        setStamp(v, true);
      });
    }

    var btnReset = document.getElementById('reportStampReset');
    if (btnReset) {
      btnReset.addEventListener('click', function () {
        try { localStorage.removeItem(key); } catch (e) {}
        setStamp(defaultStamp, false);
      });
    }
  })();
  </script>

</body>
</html>
