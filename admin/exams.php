<?php
// admin/exams.php — CRUD for exams

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

// ----------------------- Helpers -----------------------
function flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function redirect_self(array $keepQuery = []): void
{
    $base = '/admin/exams.php';
    $q = $keepQuery ? ('?' . http_build_query($keepQuery)) : '';
    header('Location: ' . $base . $q);
    exit;
}

function norm_year(string $s): string
{
    $s = trim($s);
    // Accept "2025-2026" or "2025/2026" and normalize to "2025-2026"
    $s = str_replace('/', '-', $s);
    return $s;
}

function valid_year(string $s): bool
{
    // academic_year like "2025-2026"
    return (bool)preg_match('/^\d{4}-\d{4}$/', $s);
}

function valid_term(?string $t): bool
{
    if ($t === null || $t === '') return true;
    return ctype_digit($t) && (int)$t >= 1 && (int)$t <= 6; // allow 1..6 (terms/semesters/quarters)
}

function valid_date(?string $d): bool
{
    if ($d === null || $d === '') return true;
    // Expect YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    $parts = explode('-', $d);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

// ----------------------- Inputs (GET) -----------------------
$q        = trim((string)($_GET['q'] ?? ''));
$year     = trim((string)($_GET['year'] ?? ''));
$term     = trim((string)($_GET['term'] ?? '')); // '' or '1'..'6'
$sort     = (string)($_GET['sort'] ?? 'date_desc');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;

// Sorting whitelist
$sortMap = [
    'date_desc' => 'e.exam_date DESC, e.id DESC',
    'date_asc'  => 'e.exam_date ASC, e.id ASC',
    'name_asc'  => 'e.exam_name ASC, e.id ASC',
    'name_desc' => 'e.exam_name DESC, e.id DESC',
    'year_desc' => 'e.academic_year DESC, e.term DESC, e.exam_date DESC, e.id DESC',
    'year_asc'  => 'e.academic_year ASC, e.term ASC, e.exam_date ASC, e.id ASC',
    'id_desc'   => 'e.id DESC',
    'id_asc'    => 'e.id ASC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['date_desc'];

// ----------------------- Actions (POST) -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

    // Preserve current filters after operations
    $returnQuery = [
        'q' => $q,
        'year' => $year,
        'term' => $term,
        'sort' => $sort,
        'page' => $page,
    ];

    if ($action === 'create' || $action === 'update') {
        $id        = (int)($_POST['id'] ?? 0);
        $ay        = norm_year((string)($_POST['academic_year'] ?? ''));
        $tRaw      = (string)($_POST['term'] ?? '');
        $tVal      = ($tRaw === '' ? null : (int)$tRaw);
        $name      = trim((string)($_POST['exam_name'] ?? ''));
        $dateRaw   = trim((string)($_POST['exam_date'] ?? ''));
        $dateVal   = ($dateRaw === '' ? null : $dateRaw);

        // Validate
        $errs = [];
        if ($ay === '' || !valid_year($ay)) $errs[] = 'Academic year must be in format YYYY-YYYY (e.g., 2025-2026).';
        if (!valid_term($tRaw)) $errs[] = 'Term must be empty or a number between 1 and 6.';
        if ($name === '' || mb_strlen($name) > 120) $errs[] = 'Exam name is required (max 120 characters).';
        if (!valid_date($dateVal)) $errs[] = 'Exam date must be empty or a valid date (YYYY-MM-DD).';

        if ($errs) {
            flash('danger', implode(' ', $errs));
            redirect_self($returnQuery);
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('
                INSERT INTO exams (academic_year, term, exam_name, exam_date)
                VALUES (:ay, :term, :name, :dt)
            ');
            $stmt->execute([
                'ay'   => $ay,
                'term' => $tVal,
                'name' => $name,
                'dt'   => $dateVal,
            ]);
            flash('success', 'Exam created successfully.');
            redirect_self($returnQuery);
        }

        // update
        if ($id <= 0) {
            flash('danger', 'Invalid exam ID.');
            redirect_self($returnQuery);
        }

        $stmt = $pdo->prepare('
            UPDATE exams
            SET academic_year = :ay,
                term = :term,
                exam_name = :name,
                exam_date = :dt
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'ay' => $ay,
            'term' => $tVal,
            'name' => $name,
            'dt' => $dateVal,
            'id' => $id,
        ]);

        flash('success', 'Exam updated successfully.');
        redirect_self($returnQuery);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('danger', 'Invalid exam ID.');
            redirect_self($returnQuery);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM exams WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            flash('success', 'Exam deleted.');
        } catch (Throwable $e) {
            // If FK restrict exists in your schema, deletion may fail
            flash('danger', 'Unable to delete exam. It may be referenced by results.');
        }

        redirect_self($returnQuery);
    }

    flash('danger', 'Unknown action.');
    redirect_self($returnQuery);
}

// ----------------------- Query: filters/pagination -----------------------
$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(e.exam_name LIKE :q OR e.academic_year LIKE :q OR CAST(e.id AS CHAR) = :qExact)';
    $params['q'] = '%' . $q . '%';
    $params['qExact'] = $q;
}
if ($year !== '') {
    $yearN = norm_year($year);
    $where[] = 'e.academic_year = :ay';
    $params['ay'] = $yearN;
}
if ($term !== '') {
    if (ctype_digit($term)) {
        $where[] = 'e.term = :term';
        $params['term'] = (int)$term;
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM exams e $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("
    SELECT e.id, e.academic_year, e.term, e.exam_name, e.exam_date
    FROM exams e
    $whereSql
    ORDER BY $orderBy
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// For filter dropdown: distinct years
$yearsStmt = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC");
$yearOptions = $yearsStmt ? $yearsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

// Page chrome
$page_title = 'Exams';
$page_actions = '<button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#createModal">'
              . '<i class="bi bi-plus-lg me-1"></i>New exam</button>';

require_once __DIR__ . '/header.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body">

        <form class="row g-2 align-items-end" method="get" action="exams.php">
          <div class="col-12 col-md-5">
            <label class="form-label mb-1">Search</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control" name="q" value="<?= h_attr($q) ?>" placeholder="Name, year, or ID">
            </div>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Academic year</label>
            <select class="form-select" name="year">
              <option value="">All</option>
              <?php foreach ($yearOptions as $yo): ?>
                <option value="<?= h_attr((string)$yo) ?>" <?= ((string)$yo === norm_year($year)) ? 'selected' : '' ?>>
                  <?= h((string)$yo) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Term</label>
            <select class="form-select" name="term">
              <option value="">All</option>
              <?php for ($i=1; $i<=6; $i++): ?>
                <option value="<?= $i ?>" <?= ($term !== '' && (int)$term === $i) ? 'selected' : '' ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="col-12 col-md-2">
            <label class="form-label mb-1">Sort</label>
            <select class="form-select" name="sort">
              <option value="date_desc" <?= $sort==='date_desc'?'selected':'' ?>>Date ↓</option>
              <option value="date_asc"  <?= $sort==='date_asc'?'selected':'' ?>>Date ↑</option>
              <option value="name_asc"  <?= $sort==='name_asc'?'selected':'' ?>>Name A–Z</option>
              <option value="name_desc" <?= $sort==='name_desc'?'selected':'' ?>>Name Z–A</option>
              <option value="year_desc" <?= $sort==='year_desc'?'selected':'' ?>>Year ↓</option>
              <option value="year_asc"  <?= $sort==='year_asc'?'selected':'' ?>>Year ↑</option>
              <option value="id_desc"   <?= $sort==='id_desc'?'selected':'' ?>>ID ↓</option>
              <option value="id_asc"    <?= $sort==='id_asc'?'selected':'' ?>>ID ↑</option>
            </select>
          </div>

          <div class="col-12 col-md-1 d-grid">
            <button class="btn btn-outline-secondary" type="submit">
              <i class="bi bi-funnel me-1"></i>Apply
            </button>
          </div>

          <?php if ($q !== '' || $year !== '' || $term !== '' || $sort !== 'date_desc' || $page !== 1): ?>
            <div class="col-12">
              <a class="small text-decoration-none" href="exams.php">
                <i class="bi bi-x-circle me-1"></i>Reset filters
              </a>
            </div>
          <?php endif; ?>
        </form>

        <hr class="my-3">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="text-muted small">
            Showing <span class="fw-semibold"><?= h((string)count($rows)) ?></span> of
            <span class="fw-semibold"><?= h((string)$totalRows) ?></span> exam(s)
          </div>
          <div class="text-muted small">
            Page <span class="fw-semibold"><?= h((string)$page) ?></span> / <?= h((string)$totalPages) ?>
          </div>
        </div>

        <div class="table-responsive mt-3">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width: 70px;">ID</th>
                <th>Exam</th>
                <th style="width: 140px;">Academic year</th>
                <th style="width: 90px;">Term</th>
                <th style="width: 140px;">Date</th>
                <th class="text-end" style="width: 170px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No exams found.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $id = (int)$r['id'];
                    $ay = (string)$r['academic_year'];
                    $tm = $r['term'] === null ? '' : (string)$r['term'];
                    $nm = (string)$r['exam_name'];
                    $dt = $r['exam_date'] === null ? '' : (string)$r['exam_date'];

                    $badge = 'text-bg-secondary';
                    if ($tm !== '') {
                        $badge = ((int)$tm <= 2) ? 'text-bg-primary' : (((int)$tm <= 4) ? 'text-bg-success' : 'text-bg-dark');
                    }
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= h((string)$id) ?></td>
                    <td>
                      <div class="fw-semibold"><?= h($nm) ?></div>
                      <div class="small text-muted">
                        <i class="bi bi-calendar2-week me-1"></i><?= $dt !== '' ? h($dt) : '—' ?>
                      </div>
                    </td>
                    <td><span class="badge text-bg-light border"><?= h($ay) ?></span></td>
                    <td>
                      <?php if ($tm === ''): ?>
                        <span class="text-muted">—</span>
                      <?php else: ?>
                        <span class="badge <?= h_attr($badge) ?>">T<?= h($tm) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= $dt !== '' ? h($dt) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end">
                      <button
                        class="btn btn-sm btn-outline-primary"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#editModal"
                        data-id="<?= h_attr((string)$id) ?>"
                        data-ay="<?= h_attr($ay) ?>"
                        data-term="<?= h_attr($tm) ?>"
                        data-name="<?= h_attr($nm) ?>"
                        data-date="<?= h_attr($dt) ?>"
                      >
                        <i class="bi bi-pencil-square me-1"></i>Edit
                      </button>

                      <button
                        class="btn btn-sm btn-outline-danger"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#deleteModal"
                        data-id="<?= h_attr((string)$id) ?>"
                        data-name="<?= h_attr($nm) ?>"
                      >
                        <i class="bi bi-trash3 me-1"></i>Delete
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php
          // Pagination links
          $baseQ = [
            'q' => $q,
            'year' => $year,
            'term' => $term,
            'sort' => $sort,
          ];
          $mkUrl = static function (int $p) use ($baseQ): string {
              return 'exams.php?' . http_build_query($baseQ + ['page' => $p]);
          };
        ?>

        <?php if ($totalPages > 1): ?>
          <nav class="mt-3" aria-label="Pagination">
            <ul class="pagination pagination-sm mb-0 justify-content-end">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h_attr($mkUrl(1)) ?>">First</a>
              </li>
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h_attr($mkUrl(max(1, $page-1))) ?>">&laquo;</a>
              </li>

              <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                for ($p = $start; $p <= $end; $p++):
              ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                  <a class="page-link" href="<?= h_attr($mkUrl($p)) ?>"><?= h((string)$p) ?></a>
                </li>
              <?php endfor; ?>

              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h_attr($mkUrl(min($totalPages, $page+1))) ?>">&raquo;</a>
              </li>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h_attr($mkUrl($totalPages)) ?>">Last</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?php $csrf = csrf_token(); ?>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'term'=>$term,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-lg me-1"></i>New exam</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label">Academic year</label>
          <input class="form-control" name="academic_year" placeholder="2025-2026" required maxlength="9">
          <div class="form-text">Format: YYYY-YYYY</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Term (optional)</label>
          <select class="form-select" name="term">
            <option value="">—</option>
            <?php for ($i=1; $i<=6; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Exam name</label>
          <input class="form-control" name="exam_name" required maxlength="120" placeholder="Midterm / Final / Diagnostic...">
        </div>

        <div class="mb-0">
          <label class="form-label">Exam date (optional)</label>
          <input class="form-control" type="date" name="exam_date">
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'term'=>$term,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>Edit exam</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label">Academic year</label>
          <input class="form-control" name="academic_year" id="edit_ay" required maxlength="9">
        </div>

        <div class="mb-3">
          <label class="form-label">Term (optional)</label>
          <select class="form-select" name="term" id="edit_term">
            <option value="">—</option>
            <?php for ($i=1; $i<=6; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Exam name</label>
          <input class="form-control" name="exam_name" id="edit_name" required maxlength="120">
        </div>

        <div class="mb-0">
          <label class="form-label">Exam date (optional)</label>
          <input class="form-control" type="date" name="exam_date" id="edit_date">
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'term'=>$term,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="del_id" value="">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-1"></i>Delete exam</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
          <i class="bi bi-exclamation-triangle-fill mt-1"></i>
          <div>
            You are about to delete: <span class="fw-semibold" id="del_name">—</span>
            <div class="small mt-1">
              If results reference this exam, deletion may fail (or cascade depending on your FK settings).
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Delete</button>
      </div>
    </form>
  </div>
</div>

<?php
// CSP nonce from header (if set)
$nonce = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csp_nonce']) && is_string($_SESSION['csp_nonce']))
  ? $_SESSION['csp_nonce'] : '';
?>
<script<?= $nonce ? ' nonce="'.h_attr($nonce).'"' : '' ?>>
(function () {
  const editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (ev) {
      const btn = ev.relatedTarget;
      if (!btn) return;

      document.getElementById('edit_id').value   = btn.getAttribute('data-id') || '';
      document.getElementById('edit_ay').value   = btn.getAttribute('data-ay') || '';
      document.getElementById('edit_term').value = btn.getAttribute('data-term') || '';
      document.getElementById('edit_name').value = btn.getAttribute('data-name') || '';
      document.getElementById('edit_date').value = btn.getAttribute('data-date') || '';
    });
  }

  const delModal = document.getElementById('deleteModal');
  if (delModal) {
    delModal.addEventListener('show.bs.modal', function (ev) {
      const btn = ev.relatedTarget;
      if (!btn) return;

      const id = btn.getAttribute('data-id') || '';
      const name = btn.getAttribute('data-name') || '—';

      document.getElementById('del_id').value = id;
      document.getElementById('del_name').textContent = name;
    });
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
