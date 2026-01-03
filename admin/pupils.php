<?php
// admin/pupils.php — Pupils CRUD (list/search/paginate + add/edit/delete)

declare(strict_types=1);

$page_title = 'Pupils';
require __DIR__ . '/header.php';

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Allow all logged-in admins/viewers to view; require admin+ for writes.
$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

// CSRF for state-changing requests
verify_csrf('csrf');

// Track enum values from SQL dump
const TRACK_OPTIONS = ['Aniq fanlar', 'Tabiiy fanlar'];

// ---- helpers (local) ----
function post_str(string $key, int $maxLen): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (is_int($v)) return $v;
    if (!is_string($v)) return $default;
    if (!preg_match('/^\d+$/', trim($v))) return $default;
    return (int)$v;
}

function set_flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

function valid_track(string $track): bool
{
    return in_array($track, TRACK_OPTIONS, true);
}

function normalize_class_code(string $s): string
{
    // keep as-entered but collapse internal whitespace
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    return $s;
}

function normalize_login(string $s): string
{
    // keep lowercase for consistency (unique index will enforce either way)
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    return $s;
}

// ---- handle POST actions ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    $action = $_POST['action'] ?? '';
    $action = is_string($action) ? $action : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $id          = post_int('id', 0);
            $surname     = post_str('surname', 40);
            $name        = post_str('name', 40);
            $middle_name = post_str('middle_name', 40);
            $class_code  = normalize_class_code(post_str('class_code', 30));
            $track       = post_str('track', 40);
            $student_login = normalize_login(post_str('student_login', 20));

            if ($action === 'update' && $id <= 0) {
                set_flash('danger', 'Invalid pupil ID.');
                header('Location: /admin/pupils.php');
                exit;
            }

            // Required
            if ($surname === '' || $name === '' || $class_code === '' || $track === '' || $student_login === '') {
                set_flash('danger', 'Surname, Name, Class, Track, and Student login are required.');
                header('Location: /admin/pupils.php');
                exit;
            }

            // Track enum validation
            if (!valid_track($track)) {
                set_flash('danger', 'Invalid track value.');
                header('Location: /admin/pupils.php');
                exit;
            }

            // middle_name nullable
            if ($middle_name === '') {
                $middle_name = null;
            }

            // Practical login constraint (schema is varchar(20) UNIQUE)
            // Adjust pattern if your school logins include other characters.
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,19}$/', $student_login)) {
                set_flash('danger', 'Student login must be 1–20 chars: a–z, 0–9, dot, underscore, dash (start with a–z or 0–9).');
                header('Location: /admin/pupils.php');
                exit;
            }

            if ($action === 'create') {
                $st = $pdo->prepare("
                    INSERT INTO pupils (surname, name, middle_name, class_code, track, student_login)
                    VALUES (:surname, :name, :middle_name, :class_code, :track, :student_login)
                ");
                $st->execute([
                    ':surname' => $surname,
                    ':name' => $name,
                    ':middle_name' => $middle_name,
                    ':class_code' => $class_code,
                    ':track' => $track,
                    ':student_login' => $student_login,
                ]);

                set_flash('success', 'Pupil created.');
                header('Location: /admin/pupils.php');
                exit;
            }

            if ($action === 'update') {
                $st = $pdo->prepare("
                    UPDATE pupils
                    SET surname = :surname,
                        name = :name,
                        middle_name = :middle_name,
                        class_code = :class_code,
                        track = :track,
                        student_login = :student_login
                    WHERE id = :id
                ");
                $st->execute([
                    ':id' => $id,
                    ':surname' => $surname,
                    ':name' => $name,
                    ':middle_name' => $middle_name,
                    ':class_code' => $class_code,
                    ':track' => $track,
                    ':student_login' => $student_login,
                ]);

                set_flash('success', 'Pupil updated.');
                header('Location: /admin/pupils.php');
                exit;
            }
        }

        if ($action === 'delete') {
            $id = post_int('id', 0);
            if ($id <= 0) {
                set_flash('danger', 'Invalid pupil ID.');
                header('Location: /admin/pupils.php');
                exit;
            }

            // FK results_pupil ON DELETE CASCADE (per dump): deleting pupil deletes their results too.
            $st = $pdo->prepare('DELETE FROM pupils WHERE id = :id');
            $st->execute([':id' => $id]);

            set_flash('success', 'Pupil deleted (and related results removed).');
            header('Location: /admin/pupils.php');
            exit;
        }

        set_flash('danger', 'Unknown action.');
        header('Location: /admin/pupils.php');
        exit;

    } catch (PDOException $e) {
        $sqlState = $e->getCode();
        if ($sqlState === '23000') {
            set_flash('danger', 'Duplicate pupil: Student login already exists (or constraint violation).');
        } else {
            set_flash('danger', 'Database error. Please check logs.');
        }
        header('Location: /admin/pupils.php');
        exit;
    }
}

// ---- GET: list pupils ----
$q = $_GET['q'] ?? '';
$q = is_string($q) ? trim($q) : '';

$filterClass = $_GET['class_code'] ?? '';
$filterClass = is_string($filterClass) ? normalize_class_code(trim($filterClass)) : '';

$filterTrack = $_GET['track'] ?? '';
$filterTrack = is_string($filterTrack) ? trim($filterTrack) : '';
if ($filterTrack !== '' && !valid_track($filterTrack)) $filterTrack = '';

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build WHERE
$whereParts = [];
$params = [];

if ($q !== '') {
    $whereParts[] = "(p.student_login LIKE :q OR p.surname LIKE :q OR p.name LIKE :q OR p.middle_name LIKE :q OR p.class_code LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($filterClass !== '') {
    $whereParts[] = "p.class_code = :class_code";
    $params[':class_code'] = $filterClass;
}
if ($filterTrack !== '') {
    $whereParts[] = "p.track = :track";
    $params[':track'] = $filterTrack;
}

$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// Counts + page data
$stCount = $pdo->prepare("SELECT COUNT(*) AS c FROM pupils p $where");
foreach ($params as $k => $v) {
    $stCount->bindValue($k, $v, PDO::PARAM_STR);
}
$stCount->execute();
$total = (int)($stCount->fetch()['c'] ?? 0);

$st = $pdo->prepare("
    SELECT
      p.id, p.surname, p.name, p.middle_name, p.class_code, p.track, p.student_login,
      p.created_at, p.updated_at,
      (SELECT COUNT(*) FROM results r WHERE r.pupil_id = p.id) AS results_count
    FROM pupils p
    $where
    ORDER BY p.class_code ASC, p.surname ASC, p.name ASC, p.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $st->bindValue($k, $v, PDO::PARAM_STR);
}
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pages = (int)max(1, (int)ceil($total / $perPage));
$flash = take_flash();

// Filter dropdown sources
$classRows = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Pupils</h1>
    <div class="text-muted small">
      Manage pupils master data (name, class, track, student login).
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($canWrite): ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">
        <i class="bi bi-plus-lg me-1"></i> Add Pupil
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= h((string)($flash['type'] ?? 'info')) ?> alert-dismissible fade show" role="alert">
    <?= h((string)($flash['msg'] ?? '')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="pupils.php">
      <div class="col-12 col-lg-5">
        <label class="form-label small text-muted mb-1">Search</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input
            type="text"
            class="form-control"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Surname, name, login, class..."
          >
        </div>
      </div>

      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label small text-muted mb-1">Class</label>
        <select class="form-select" name="class_code">
          <option value="">All classes</option>
          <?php foreach ($classRows as $cr): ?>
            <?php $cc = (string)($cr['class_code'] ?? ''); ?>
            <?php if ($cc === '') continue; ?>
            <option value="<?= h($cc) ?>" <?= $filterClass === $cc ? 'selected' : '' ?>><?= h($cc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label small text-muted mb-1">Track</label>
        <select class="form-select" name="track">
          <option value="">All tracks</option>
          <?php foreach (TRACK_OPTIONS as $t): ?>
            <option value="<?= h($t) ?>" <?= $filterTrack === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4 col-lg-1 d-grid">
        <button class="btn btn-outline-primary" type="submit">
          <i class="bi bi-funnel me-1"></i> Filter
        </button>
      </div>

      <div class="col-12">
        <div class="small text-muted">
          Showing <span class="fw-semibold"><?= h(count($rows)) ?></span> of <span class="fw-semibold"><?= h($total) ?></span> pupils.
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:70px">ID</th>
            <th>Pupil</th>
            <th>Class</th>
            <th>Track</th>
            <th>Login</th>
            <th class="text-end">Results</th>
            <th class="text-muted">Updated</th>
            <?php if ($canWrite): ?>
              <th class="text-end" style="width:140px">Actions</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="<?= $canWrite ? '8' : '7' ?>" class="text-center text-muted py-4">
                No pupils found.
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($rows as $r): ?>
            <?php
              $id = (int)$r['id'];
              $full = trim((string)$r['surname'] . ' ' . (string)$r['name']);
              $mid = (string)($r['middle_name'] ?? '');
              if ($mid !== '') $full .= ' ' . $mid;

              $track = (string)$r['track'];
              $trackBadge = $track === 'Aniq fanlar' ? 'text-bg-primary' : 'text-bg-success';

              $resultsCount = (int)($r['results_count'] ?? 0);
              $updatedAt = (string)($r['updated_at'] ?? '');
              $classCode = (string)($r['class_code'] ?? '');
              $login = (string)($r['student_login'] ?? '');

              $editPayload = [
                'id' => $id,
                'surname' => (string)$r['surname'],
                'name' => (string)$r['name'],
                'middle_name' => (string)($r['middle_name'] ?? ''),
                'class_code' => $classCode,
                'track' => $track,
                'student_login' => $login,
              ];
            ?>
            <tr>
              <td class="text-muted"><?= h($id) ?></td>
              <td class="fw-semibold"><?= h($full) ?></td>
              <td><span class="badge text-bg-light border"><?= h($classCode) ?></span></td>
              <td><span class="badge <?= h($trackBadge) ?>"><?= h($track) ?></span></td>
              <td><code><?= h($login) ?></code></td>
              <td class="text-end">
                <span class="badge <?= $resultsCount > 0 ? 'text-bg-secondary' : 'text-bg-light border text-dark' ?>">
                  <?= h($resultsCount) ?>
                </span>
              </td>
              <td class="text-muted small"><?= h($updatedAt) ?></td>
              <?php if ($canWrite): ?>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#modalEdit"
                    data-pupil='<?= h(json_encode($editPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                  >
                    <i class="bi bi-pencil-square"></i>
                  </button>

                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal"
                    data-bs-target="#modalDelete"
                    data-id="<?= h($id) ?>"
                    data-name="<?= h($full) ?>"
                    data-results="<?= h($resultsCount) ?>"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
      <nav aria-label="Pupils pagination">
        <ul class="pagination pagination-sm mb-0 flex-wrap">
          <?php
            $base = $_GET;
            $mkUrl = function (int $p) use ($base): string {
              $q = $base;
              $q['page'] = $p;
              return 'pupils.php?' . http_build_query($q);
            };
          ?>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($mkUrl(max(1, $page - 1))) ?>" tabindex="-1" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Prev</a>
          </li>

          <?php
            $start = max(1, $page - 3);
            $end = min($pages, $page + 3);
          ?>
          <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= h($mkUrl(1)) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <?php endif; ?>

          <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h($mkUrl($p)) ?>"><?= h($p) ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= h($mkUrl($pages)) ?>"><?= h($pages) ?></a></li>
          <?php endif; ?>

          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($mkUrl(min($pages, $page + 1))) ?>" aria-disabled="<?= $page >= $pages ? 'true' : 'false' ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php if ($canWrite): ?>
  <!-- Create modal -->
  <div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <form method="post" action="pupils.php">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Pupil</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Surname *</label>
                <input class="form-control" name="surname" maxlength="40" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Name *</label>
                <input class="form-control" name="name" maxlength="40" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Middle name</label>
                <input class="form-control" name="middle_name" maxlength="40">
              </div>

              <div class="col-md-4">
                <label class="form-label">Class *</label>
                <input class="form-control" name="class_code" maxlength="30" required placeholder="e.g., 9-A">
              </div>

              <div class="col-md-4">
                <label class="form-label">Track *</label>
                <select class="form-select" name="track" required>
                  <option value="" selected disabled>Select track</option>
                  <?php foreach (TRACK_OPTIONS as $t): ?>
                    <option value="<?= h($t) ?>"><?= h($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Student login *</label>
                <input class="form-control" name="student_login" maxlength="20" required placeholder="e.g., ab1234">
                <div class="form-text">Lowercase recommended. Must be unique.</div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2-circle me-1"></i> Create
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit modal -->
  <div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <form method="post" action="pupils.php" id="editForm">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="edit_id" value="">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Pupil</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Surname *</label>
                <input class="form-control" name="surname" id="edit_surname" maxlength="40" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Name *</label>
                <input class="form-control" name="name" id="edit_name" maxlength="40" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Middle name</label>
                <input class="form-control" name="middle_name" id="edit_middle_name" maxlength="40">
              </div>

              <div class="col-md-4">
                <label class="form-label">Class *</label>
                <input class="form-control" name="class_code" id="edit_class_code" maxlength="30" required>
              </div>

              <div class="col-md-4">
                <label class="form-label">Track *</label>
                <select class="form-select" name="track" id="edit_track" required>
                  <?php foreach (TRACK_OPTIONS as $t): ?>
                    <option value="<?= h($t) ?>"><?= h($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Student login *</label>
                <input class="form-control" name="student_login" id="edit_student_login" maxlength="20" required>
                <div class="form-text">Must be unique (case-insensitive depending on collation).</div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save2 me-1"></i> Save
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete modal -->
  <div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post" action="pupils.php">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" id="del_id" value="">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Pupil</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <div class="alert alert-warning mb-3">
              This will delete the pupil and (due to foreign keys) also remove all their results.
            </div>
            <div class="mb-1">
              <span class="text-muted">Pupil:</span>
              <span class="fw-semibold" id="del_name">—</span>
            </div>
            <div>
              <span class="text-muted">Results to be removed:</span>
              <span class="badge text-bg-secondary" id="del_results">0</span>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">
              <i class="bi bi-trash me-1"></i> Delete
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script nonce="<?= h($_SESSION['csp_nonce'] ?? '') ?>">
  (function () {
    // Edit modal populate
    var modalEdit = document.getElementById('modalEdit');
    if (modalEdit) {
      modalEdit.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var raw = btn.getAttribute('data-pupil') || '';
        var data = null;
        try { data = JSON.parse(raw); } catch (e) { data = null; }
        if (!data) return;

        document.getElementById('edit_id').value = data.id || '';
        document.getElementById('edit_surname').value = data.surname || '';
        document.getElementById('edit_name').value = data.name || '';
        document.getElementById('edit_middle_name').value = data.middle_name || '';
        document.getElementById('edit_class_code').value = data.class_code || '';
        document.getElementById('edit_student_login').value = data.student_login || '';

        var trackSel = document.getElementById('edit_track');
        if (trackSel && data.track) trackSel.value = data.track;
      });
    }

    // Delete modal populate
    var modalDelete = document.getElementById('modalDelete');
    if (modalDelete) {
      modalDelete.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;

        document.getElementById('del_id').value = btn.getAttribute('data-id') || '';
        document.getElementById('del_name').textContent = btn.getAttribute('data-name') || '—';
        document.getElementById('del_results').textContent = btn.getAttribute('data-results') || '0';
      });
    }
  })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
