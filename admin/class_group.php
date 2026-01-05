<?php
// admin/class_group.php — Manage pupils.class_group (1/2) by class_code
// Access policy:
// - Only admins.level in (1,2) can enter this page
// - Only level 1 can modify; level 2 is view-only

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';   // provides $pdo (PDO instance)
require_once __DIR__ . '/../inc/auth.php'; // session_start_secure(), require_admin(), verify_csrf(), csrf_field(), h(), admin_level()

session_start_secure();
require_admin();

$page_title = 'Class Group Management';

/* ----------------------- Page-local helpers (prefixed to avoid redeclare) ----------------------- */

function cg_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function cg_flash_out(): void
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    if (!is_array($items) || !$items) return;

    foreach ($items as $it) {
        $type = isset($it['type']) ? (string)$it['type'] : 'info';
        $msg  = isset($it['msg']) ? (string)$it['msg'] : '';
        if ($msg === '') continue;

        echo '<div class="alert alert-' . h($type) . ' alert-dismissible fade show" role="alert">';
        echo h($msg);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

function cg_redirect_self(array $keepQuery = []): void
{
    $base = '/admin/class_group.php';
    $q = $keepQuery ? ('?' . http_build_query($keepQuery)) : '';
    header('Location: ' . $base . $q);
    exit;
}

function cg_valid_group(int $g): bool
{
    return in_array($g, [1, 2], true);
}

/** Gate: only admin levels 1 or 2 may access this page. */
function cg_require_level_1_or_2(): void
{
    $lvl = (int)admin_level();
    if ($lvl !== 1 && $lvl !== 2) {
        cg_flash('warning', 'Access denied.');
        header('Location: /admin/dashboard.php');
        exit;
    }
}

/* ----------------------- Gate: only level 1/2 can enter ----------------------- */

cg_require_level_1_or_2();
$lvl = (int)admin_level();
$can_edit = ($lvl === 1);

/* ----------------------- Inputs (GET) ----------------------- */

$classCode   = trim((string)($_GET['class_code'] ?? ''));
$q           = trim((string)($_GET['q'] ?? ''));          // search
$track       = trim((string)($_GET['track'] ?? ''));      // '', 'Aniq', 'Tabiiy'
$groupFilter = trim((string)($_GET['group'] ?? ''));      // '', '1', '2'

/* ----------------------- Actions (POST) ----------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Only level 1 can modify (even though level 2 can view page)
    if (!$can_edit) {
        cg_flash('warning', 'View-only access: only level 1 admins can modify class groups.');
        cg_redirect_self([
            'class_code' => trim((string)($_POST['class_code'] ?? $classCode)),
            'q' => $q,
            'track' => $track,
            'group' => $groupFilter,
        ]);
    }

    $action        = (string)($_POST['action'] ?? '');
    $classFromPost = trim((string)($_POST['class_code'] ?? ''));

    $returnQuery = [
        'class_code' => $classFromPost !== '' ? $classFromPost : $classCode,
        'q'          => $q,
        'track'      => $track,
        'group'      => $groupFilter,
    ];

    // 1) Bulk entire class
    if ($action === 'bulk_class') {
        $g = (int)($_POST['class_group'] ?? 0);

        if ($classFromPost === '' || !cg_valid_group($g)) {
            cg_flash('danger', 'Invalid class or group.');
            cg_redirect_self($returnQuery);
        }

        $confirm = (string)($_POST['confirm_bulk'] ?? '');
        if ($confirm !== '1') {
            cg_flash('warning', 'Bulk class update was not confirmed.');
            cg_redirect_self($returnQuery);
        }

        $stmt = $pdo->prepare("
            UPDATE pupils
            SET class_group = :grp
            WHERE class_code = :class
        ");
        $stmt->execute([
            ':grp'   => $g,
            ':class' => $classFromPost,
        ]);

        cg_flash('success', 'Class updated: ' . $classFromPost . ' → Group ' . $g . '.');
        cg_redirect_self($returnQuery);
    }

    // 2) Bulk selected pupils
    if ($action === 'bulk_selected') {
        $g = (int)($_POST['class_group'] ?? 0);
        $ids = $_POST['pupil_ids'] ?? [];

        if ($classFromPost === '' || !cg_valid_group($g)) {
            cg_flash('danger', 'Invalid class or group.');
            cg_redirect_self($returnQuery);
        }

        if (!is_array($ids) || count($ids) === 0) {
            cg_flash('warning', 'No pupils selected.');
            cg_redirect_self($returnQuery);
        }

        // Normalize ids as unique positive ints
        $uniq = [];
        foreach ($ids as $id) {
            $v = (int)$id;
            if ($v > 0) $uniq[$v] = true;
        }
        $pupilIds = array_keys($uniq);

        if (!$pupilIds) {
            cg_flash('warning', 'No valid pupils selected.');
            cg_redirect_self($returnQuery);
        }

        $confirm = (string)($_POST['confirm_selected'] ?? '');
        if ($confirm !== '1') {
            cg_flash('warning', 'Bulk selected update was not confirmed.');
            cg_redirect_self($returnQuery);
        }

        // Ensure selected pupils belong to the class (security)
        $ph = [];
        $params = [':class' => $classFromPost];
        foreach ($pupilIds as $i => $pid) {
            $k = ':id' . $i;
            $ph[] = $k;
            $params[$k] = $pid;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS c
            FROM pupils
            WHERE class_code = :class
              AND id IN (" . implode(',', $ph) . ")
        ");
        $stmt->execute($params);
        $cnt = (int)($stmt->fetchColumn() ?: 0);

        if ($cnt !== count($pupilIds)) {
            cg_flash('danger', 'Security check failed: some selected pupils are not in the selected class.');
            cg_redirect_self($returnQuery);
        }

        $paramsUpd = $params;
        $paramsUpd[':grp'] = $g;

        $stmt = $pdo->prepare("
            UPDATE pupils
            SET class_group = :grp
            WHERE class_code = :class
              AND id IN (" . implode(',', $ph) . ")
        ");
        $stmt->execute($paramsUpd);

        cg_flash('success', 'Updated ' . count($pupilIds) . ' pupil(s) → Group ' . $g . '.');
        cg_redirect_self($returnQuery);
    }

    // 3) Single update
    if ($action === 'single') {
        $pupilId = (int)($_POST['pupil_id'] ?? 0);
        $g       = (int)($_POST['class_group'] ?? 0);

        if ($pupilId <= 0 || !cg_valid_group($g)) {
            cg_flash('danger', 'Invalid pupil or group.');
            cg_redirect_self($returnQuery);
        }

        $stmt = $pdo->prepare("SELECT class_code FROM pupils WHERE id = :id");
        $stmt->execute([':id' => $pupilId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            cg_flash('danger', 'Pupil not found.');
            cg_redirect_self($returnQuery);
        }
        if ($classFromPost !== '' && (string)$row['class_code'] !== $classFromPost) {
            cg_flash('danger', 'Security check failed: pupil not in selected class.');
            cg_redirect_self($returnQuery);
        }

        $stmt = $pdo->prepare("UPDATE pupils SET class_group = :grp WHERE id = :id");
        $stmt->execute([':grp' => $g, ':id' => $pupilId]);

        cg_flash('success', 'Pupil group updated.');
        cg_redirect_self($returnQuery);
    }

    cg_flash('danger', 'Unknown action.');
    cg_redirect_self($returnQuery);
}

/* ----------------------- Data: class list with counts ----------------------- */

$classRows = $pdo->query("
    SELECT class_code,
           COUNT(*) AS total,
           SUM(class_group = 1) AS g1,
           SUM(class_group = 2) AS g2
    FROM pupils
    GROUP BY class_code
    ORDER BY class_code
")->fetchAll(PDO::FETCH_ASSOC);

$trackChoices = [
    '' => 'All tracks',
    'Aniq' => 'Aniq',
    'Tabiiy' => 'Tabiiy',
];

/* ----------------------- Data: pupils in selected class ----------------------- */

$pupils = [];
$stats = null;
$visibleStats = ['total' => 0, 'g1' => 0, 'g2' => 0];

if ($classCode !== '') {
    $where = "p.class_code = :class";
    $params = [':class' => $classCode];

    if ($track === 'Aniq' || $track === 'Tabiiy') {
        $where .= " AND p.track = :track";
        $params[':track'] = $track;
    }

    if ($groupFilter === '1' || $groupFilter === '2') {
        $where .= " AND p.class_group = :grpfilter";
        $params[':grpfilter'] = (int)$groupFilter;
    }

    if ($q !== '') {
        $where .= " AND (p.surname LIKE :q OR p.name LIKE :q OR p.middle_name LIKE :q OR p.student_login LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.surname, p.name, p.middle_name, p.student_login, p.track, p.class_group
        FROM pupils p
        WHERE $where
        ORDER BY p.surname, p.name, p.id
    ");
    $stmt->execute($params);
    $pupils = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Visible stats (respect filters)
    foreach ($pupils as $p) {
        $visibleStats['total']++;
        if ((int)$p['class_group'] === 1) $visibleStats['g1']++;
        if ((int)$p['class_group'] === 2) $visibleStats['g2']++;
    }

    // Class stats (ignores filters)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(class_group = 1) AS g1,
               SUM(class_group = 2) AS g2,
               SUM(track = 'Aniq')  AS aniq,
               SUM(track = 'Tabiiy') AS tabiiy
        FROM pupils
        WHERE class_code = :class
    ");
    $stmt->execute([':class' => $classCode]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

require __DIR__ . '/header.php';
?>

<div class="container-fluid">

  <div class="d-flex align-items-start align-items-md-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h1 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>Class Group Management</h1>
      <div class="text-muted small">
        Access level:
        <span class="badge text-bg-dark">Level <?= h((string)$lvl) ?></span>
        <?php if ($can_edit): ?>
          <span class="badge text-bg-success ms-1"><i class="bi bi-pencil-square me-1"></i>Edit enabled</span>
        <?php else: ?>
          <span class="badge text-bg-warning ms-1"><i class="bi bi-eye me-1"></i>View-only</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($classCode !== ''): ?>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-secondary"
           href="class_group.php?<?= h(http_build_query(['class_code'=>$classCode])) ?>">
          <i class="bi bi-x-circle me-1"></i>Clear filters
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php cg_flash_out(); ?>

  <!-- Filters -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Class</label>
          <select name="class_code" class="form-select" onchange="this.form.submit()">
            <option value="">— Select class —</option>
            <?php foreach ($classRows as $cr): ?>
              <?php $cc = (string)$cr['class_code']; ?>
              <option value="<?= h($cc) ?>" <?= $cc === $classCode ? 'selected' : '' ?>>
                <?= h($cc) ?> (<?= (int)$cr['total'] ?> • G1 <?= (int)$cr['g1'] ?> • G2 <?= (int)$cr['g2'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Group</label>
          <select name="group" class="form-select" onchange="this.form.submit()">
            <option value=""  <?= $groupFilter === '' ? 'selected' : '' ?>>All</option>
            <option value="1" <?= $groupFilter === '1' ? 'selected' : '' ?>>Group 1</option>
            <option value="2" <?= $groupFilter === '2' ? 'selected' : '' ?>>Group 2</option>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Track</label>
          <select name="track" class="form-select" onchange="this.form.submit()">
            <?php foreach ($trackChoices as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $k === $track ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input name="q" class="form-control" value="<?= h($q) ?>" placeholder="Surname, name, login…">
        </div>

        <div class="col-md-2 d-grid">
          <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

<?php if ($classCode !== '' && $stats): ?>

  <!-- KPI cards -->
  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="text-muted small">Class</div><div class="fs-4 fw-semibold"><?= h($classCode) ?></div>
      <div class="text-muted small mt-2">Visible list</div>
      <div class="d-flex gap-2 flex-wrap">
        <span class="badge text-bg-dark">Total <?= (int)$visibleStats['total'] ?></span>
        <span class="badge text-bg-primary">G1 <?= (int)$visibleStats['g1'] ?></span>
        <span class="badge text-bg-success">G2 <?= (int)$visibleStats['g2'] ?></span>
      </div>
    </div></div></div>

    <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="text-muted small">Total pupils (class)</div><div class="fs-4 fw-semibold"><?= (int)$stats['total'] ?></div>
    </div></div></div>

    <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="text-muted small">Group 1 / Group 2 (class)</div>
      <div class="fs-5 fw-semibold">
        <span class="badge text-bg-primary me-1">G1 <?= (int)$stats['g1'] ?></span>
        <span class="badge text-bg-success">G2 <?= (int)$stats['g2'] ?></span>
      </div>
    </div></div></div>

    <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="text-muted small">Tracks (class)</div>
      <div class="fs-6 fw-semibold">
        <span class="badge text-bg-secondary me-1">Aniq <?= (int)$stats['aniq'] ?></span>
        <span class="badge text-bg-secondary">Tabiiy <?= (int)$stats['tabiiy'] ?></span>
      </div>
    </div></div></div>
  </div>

  <?php if ($can_edit): ?>
  <!-- Bulk ENTIRE class -->
  <div class="card shadow-sm mb-3">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="fw-semibold">
        <i class="bi bi-lightning-charge me-1"></i>Bulk: entire class
        <span class="text-muted small">(requires confirmation)</span>
      </div>

      <form method="post" class="d-flex gap-2 align-items-center">
        <?= csrf_field('csrf') ?>
        <input type="hidden" name="action" value="bulk_class">
        <input type="hidden" name="class_code" value="<?= h($classCode) ?>">
        <input type="hidden" name="confirm_bulk" value="0" id="confirm_bulk">

        <div class="btn-group" role="group">
          <button type="submit" name="class_group" value="1" class="btn btn-outline-primary" onclick="return cgBulkClassConfirm(1);">
            Set all to Group 1
          </button>
          <button type="submit" name="class_group" value="2" class="btn btn-outline-success" onclick="return cgBulkClassConfirm(2);">
            Set all to Group 2
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Pupils table + bulk selected -->
  <div class="card shadow-sm">
    <div class="card-body border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="fw-semibold">
        <i class="bi bi-people me-1"></i>Pupils
        <span class="text-muted small">Showing: <?= count($pupils) ?></span>
      </div>
      <div class="small text-muted">
        <?= $can_edit ? 'Select pupils, then use “Bulk selected” to move to Group 1/2.' : 'View-only mode (Level 2): editing disabled.' ?>
      </div>
    </div>

    <?php if ($can_edit): ?>
      <!-- Bulk selected toolbar -->
      <div class="p-2 border-bottom bg-body position-sticky" style="top: 0; z-index: 2;">
        <form id="bulkForm" method="post" class="d-flex align-items-center gap-2 flex-wrap mb-0" onsubmit="return cgBulkSelectedConfirm();">
          <?= csrf_field('csrf') ?>
          <input type="hidden" name="action" value="bulk_selected">
          <input type="hidden" name="class_code" value="<?= h($classCode) ?>">
          <input type="hidden" name="confirm_selected" value="0" id="confirm_selected">

          <span class="badge text-bg-dark" id="selCount">0 selected</span>

          <select name="class_group" class="form-select form-select-sm" style="max-width:180px;" required>
            <option value="">Move selected to…</option>
            <option value="1">Group 1</option>
            <option value="2">Group 2</option>
          </select>

          <button class="btn btn-sm btn-primary" id="btnBulkSelected" disabled>
            <i class="bi bi-arrow-right-circle me-1"></i>Apply
          </button>

          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cgClearSelection();">
            Clear
          </button>

          <div class="ms-auto d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cgSelectVisible();">
              Select visible
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cgInvertSelection();">
              Invert
            </button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:44px">
              <?php if ($can_edit): ?>
                <input class="form-check-input" type="checkbox" id="checkAll" aria-label="Select all visible">
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </th>
            <th style="width:60px">#</th>
            <th>Pupil</th>
            <th style="width:160px">Login</th>
            <th style="width:120px">Track</th>
            <th style="width:140px">Current</th>
            <th style="width:240px">Change</th>
          </tr>
        </thead>

        <tbody>
          <?php if (!$pupils): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No pupils found for the selected filters.</td></tr>
          <?php endif; ?>

          <?php foreach ($pupils as $i => $p): ?>
            <?php
              $full = trim($p['surname'] . ' ' . $p['name'] . ' ' . ((string)($p['middle_name'] ?? '')));
              $cg = (int)$p['class_group'];
              $badge = ($cg === 1) ? 'text-bg-primary' : 'text-bg-success';
              $pid = (int)$p['id'];
            ?>
            <tr>
              <td>
                <?php if ($can_edit): ?>
                  <input class="form-check-input rowcheck" type="checkbox" data-id="<?= $pid ?>" aria-label="Select pupil">
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= $i + 1 ?></td>
              <td class="fw-semibold"><?= h($full) ?></td>
              <td class="mono"><?= h((string)$p['student_login']) ?></td>
              <td><span class="badge text-bg-secondary"><?= h((string)$p['track']) ?></span></td>
              <td><span class="badge <?= h($badge) ?>">Group <?= $cg ?></span></td>
              <td>
                <?php if ($can_edit): ?>
                  <form method="post" class="d-flex gap-2">
                    <?= csrf_field('csrf') ?>
                    <input type="hidden" name="action" value="single">
                    <input type="hidden" name="pupil_id" value="<?= $pid ?>">
                    <input type="hidden" name="class_code" value="<?= h($classCode) ?>">

                    <select name="class_group" class="form-select form-select-sm" required>
                      <option value="1" <?= $cg === 1 ? 'selected' : '' ?>>Group 1</option>
                      <option value="2" <?= $cg === 2 ? 'selected' : '' ?>>Group 2</option>
                    </select>

                    <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Save</button>
                  </form>
                <?php else: ?>
                  <span class="text-muted small">View only</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>

      </table>
    </div>

  </div>

<?php elseif ($classCode !== ''): ?>
  <div class="alert alert-warning">No pupils found for this class.</div>
<?php endif; ?>

</div>

<script>
function cgBulkClassConfirm(group) {
  const msg = "This will set ALL pupils in this class to Group " + group + ".\n\nContinue?";
  const ok = window.confirm(msg);
  if (!ok) return false;
  const el = document.getElementById('confirm_bulk');
  if (el) el.value = "1";
  return true;
}

function cgSelectedIds() {
  const checks = document.querySelectorAll('.rowcheck:checked');
  const ids = [];
  checks.forEach(ch => ids.push(ch.getAttribute('data-id')));
  return ids.filter(Boolean);
}

function cgUpdateSelectionUI() {
  const ids = cgSelectedIds();
  const n = ids.length;

  const badge = document.getElementById('selCount');
  if (badge) badge.textContent = n + " selected";

  const btn = document.getElementById('btnBulkSelected');
  if (btn) btn.disabled = (n === 0);

  const all = document.querySelectorAll('.rowcheck');
  const checkAll = document.getElementById('checkAll');
  if (checkAll && all.length) {
    checkAll.checked = (n === all.length);
    checkAll.indeterminate = (n > 0 && n < all.length);
  }
}

function cgClearSelection() {
  document.querySelectorAll('.rowcheck').forEach(ch => ch.checked = false);
  const checkAll = document.getElementById('checkAll');
  if (checkAll) { checkAll.checked = false; checkAll.indeterminate = false; }
  cgUpdateSelectionUI();
}

function cgSelectVisible() {
  document.querySelectorAll('.rowcheck').forEach(ch => ch.checked = true);
  cgUpdateSelectionUI();
}

function cgInvertSelection() {
  document.querySelectorAll('.rowcheck').forEach(ch => ch.checked = !ch.checked);
  cgUpdateSelectionUI();
}

function cgBulkSelectedConfirm() {
  const ids = cgSelectedIds();
  const n = ids.length;
  if (!n) { alert('Select at least one pupil.'); return false; }

  const sel = document.querySelector('#bulkForm select[name="class_group"]');
  const g = sel ? sel.value : '';
  if (!g) { alert('Choose the target group.'); return false; }

  const msg = "Move " + n + " selected pupil(s) to Group " + g + "?\n\nContinue?";
  const ok = window.confirm(msg);
  if (!ok) return false;

  const el = document.getElementById('confirm_selected');
  if (el) el.value = "1";

  // Remove any previous hidden inputs
  const form = document.getElementById('bulkForm');
  form.querySelectorAll('input[name="pupil_ids[]"]').forEach(x => x.remove());

  // Add hidden inputs for selected ids
  ids.forEach(id => {
    const hid = document.createElement('input');
    hid.type = 'hidden';
    hid.name = 'pupil_ids[]';
    hid.value = id;
    form.appendChild(hid);
  });

  return true;
}

document.addEventListener('change', function(e) {
  if (e.target && e.target.classList && e.target.classList.contains('rowcheck')) {
    cgUpdateSelectionUI();
  }
  if (e.target && e.target.id === 'checkAll') {
    const v = e.target.checked;
    document.querySelectorAll('.rowcheck').forEach(ch => ch.checked = v);
    cgUpdateSelectionUI();
  }
});

document.addEventListener('DOMContentLoaded', cgUpdateSelectionUI);
</script>

<?php require __DIR__ . '/footer.php'; ?>
