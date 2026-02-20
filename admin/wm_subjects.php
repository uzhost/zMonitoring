<?php
// admin/wm_subjects.php - WM Subjects CRUD (list/search/paginate + add/edit/delete)

declare(strict_types=1);

$page_title = 'WM Subjects';
require __DIR__ . '/header.php';

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Allow all logged-in admins/viewers to view; require admin+ for writes.
$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

// CSRF for state-changing requests
verify_csrf('csrf');

// ---- helpers (local) ----
function wm_post_str(string $key, int $maxLen): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function wm_post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (is_int($v)) return $v;
    if (!is_string($v)) return $default;
    if (!preg_match('/^-?\d+$/', trim($v))) return $default;
    return (int)$v;
}

function wm_set_flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function wm_take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
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
        if ($action === 'create') {
            $code = wm_post_str('code', 30);
            $name = wm_post_str('name', 120);
            $maxPoints = wm_post_int('max_points', 100);

            $code = strtoupper($code);

            if ($code === '' || $name === '') {
                wm_set_flash('danger', 'Code and Name are required.');
                header('Location: /admin/wm_subjects.php');
                exit;
            }
            if (!preg_match('/^[A-Z0-9][A-Z0-9_\\-\\.]{0,29}$/', $code)) {
                wm_set_flash('danger', 'Code must be 1-30 chars: A-Z, 0-9, underscore, dash, dot (start with A-Z or 0-9).');
                header('Location: /admin/wm_subjects.php');
                exit;
            }
            if ($maxPoints < 1 || $maxPoints > 100) {
                wm_set_flash('danger', 'Max points must be between 1 and 100.');
                header('Location: /admin/wm_subjects.php');
                exit;
            }

            $st = $pdo->prepare('INSERT INTO wm_subjects (code, name, max_points) VALUES (:code, :name, :max_points)');
            $st->execute([
                ':code' => $code,
                ':name' => $name,
                ':max_points' => $maxPoints,
            ]);

            wm_set_flash('success', 'WM subject created.');
            header('Location: /admin/wm_subjects.php');
            exit;
        }

        if ($action === 'update') {
            $id = wm_post_int('id', 0);
            $code = wm_post_str('code', 30);
            $name = wm_post_str('name', 120);
            $maxPoints = wm_post_int('max_points', 100);

            $code = strtoupper($code);

            if ($id <= 0) {
                wm_set_flash('danger', 'Invalid subject ID.');
                header('Location: /admin/wm_subjects.php');
                exit;
            }
            if ($code === '' || $name === '') {
                wm_set_flash('danger', 'Code and Name are required.');
                header('Location: /admin/wm_subjects.php');
                exit;
            }
            if (!preg_match('/^[A-Z0-9][A-Z0-9_\\-\\.]{0,29}$/', $code)) {
                wm_set_flash('danger', 'Code must be 1-30 chars: A-Z, 0-9, underscore, dash, dot (start with A-Z or 0-9).');
                header('Location: /admin/wm_subjects.php');
                exit;
            }
            if ($maxPoints < 1 || $maxPoints > 100) {
                wm_set_flash('danger', 'Max points must be between 1 and 100.');
                header('Location: /admin/wm_subjects.php');
                exit;
            }

            $st = $pdo->prepare('UPDATE wm_subjects SET code = :code, name = :name, max_points = :max_points WHERE id = :id');
            $st->execute([
                ':id' => $id,
                ':code' => $code,
                ':name' => $name,
                ':max_points' => $maxPoints,
            ]);

            wm_set_flash('success', 'WM subject updated.');
            header('Location: /admin/wm_subjects.php');
            exit;
        }

        if ($action === 'delete') {
            $id = wm_post_int('id', 0);
            if ($id <= 0) {
                wm_set_flash('danger', 'Invalid subject ID.');
                header('Location: /admin/wm_subjects.php');
                exit;
            }

            $st = $pdo->prepare('DELETE FROM wm_subjects WHERE id = :id');
            $st->execute([':id' => $id]);

            wm_set_flash('success', 'WM subject deleted.');
            header('Location: /admin/wm_subjects.php');
            exit;
        }

        wm_set_flash('danger', 'Unknown action.');
        header('Location: /admin/wm_subjects.php');
        exit;

    } catch (PDOException $e) {
        $sqlState = $e->getCode();
        if ($sqlState === '23000') {
            wm_set_flash('danger', 'Duplicate subject: Code or Name already exists, or row is referenced.');
        } else {
            wm_set_flash('danger', 'Database error. Please check logs.');
        }
        header('Location: /admin/wm_subjects.php');
        exit;
    }
}

// ---- GET: list subjects ----
$q = $_GET['q'] ?? '';
$q = is_string($q) ? trim($q) : '';
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];

if ($q !== '') {
    $where = 'WHERE code LIKE :q OR name LIKE :q';
    $params[':q'] = '%' . $q . '%';
}

$stCount = $pdo->prepare("SELECT COUNT(*) AS c FROM wm_subjects $where");
$stCount->execute($params);
$total = (int)($stCount->fetch()['c'] ?? 0);

$st = $pdo->prepare("
    SELECT id, code, name, max_points
    FROM wm_subjects
    $where
    ORDER BY name ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $st->bindValue($k, $v, PDO::PARAM_STR);
}
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$pages = (int)max(1, (int)ceil($total / $perPage));
$flash = wm_take_flash();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">WM Subjects</h1>
    <div class="text-muted small">
      Manage World Math subject master data (code, name, max points).
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($canWrite): ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">
        <i class="bi bi-plus-lg me-1"></i> Add WM Subject
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
    <form class="row g-2 align-items-center" method="get" action="/admin/wm_subjects.php">
      <div class="col-12 col-md-6 col-lg-4">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input
            type="text"
            class="form-control"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Search by code or name..."
          >
        </div>
      </div>
      <div class="col-12 col-md-auto">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
        <a class="btn btn-outline-link" href="/admin/wm_subjects.php">Reset</a>
      </div>

      <div class="col-12 col-md-auto ms-md-auto text-muted small">
        Total: <span class="fw-semibold"><?= (int)$total ?></span>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:90px;">ID</th>
            <th style="width:220px;">Code</th>
            <th>Name</th>
            <th style="width:140px;" class="text-end">Max points</th>
            <th style="width:180px;" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="5" class="text-center text-muted py-4">
              No WM subjects found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $sid = (int)$r['id'];
              $code = (string)$r['code'];
              $name = (string)$r['name'];
              $mp = (int)$r['max_points'];
              $editModalId = 'modalEdit' . $sid;
              $delModalId  = 'modalDelete' . $sid;
            ?>
            <tr>
              <td class="text-muted"><?= $sid ?></td>
              <td>
                <span class="badge text-bg-light border">
                  <?= h($code) ?>
                </span>
              </td>
              <td class="fw-semibold"><?= h($name) ?></td>
              <td class="text-end"><?= $mp ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm" role="group">
                  <?php if ($canWrite): ?>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= h($editModalId) ?>">
                      <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#<?= h($delModalId) ?>">
                      <i class="bi bi-trash me-1"></i> Delete
                    </button>
                  <?php else: ?>
                    <span class="text-muted small">Read-only</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>

            <?php if ($canWrite): ?>
              <div class="modal fade" id="<?= h($editModalId) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post" action="/admin/wm_subjects.php">
                      <div class="modal-header">
                        <h5 class="modal-title">Edit WM Subject</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $sid ?>">

                        <div class="mb-3">
                          <label class="form-label">Code</label>
                          <input class="form-control" name="code" maxlength="30" required value="<?= h($code) ?>">
                          <div class="form-text">Example: ALGEBRA, GEOMETRY (A-Z/0-9/_/./-)</div>
                        </div>

                        <div class="mb-3">
                          <label class="form-label">Name</label>
                          <input class="form-control" name="name" maxlength="120" required value="<?= h($name) ?>">
                        </div>

                        <div class="mb-0">
                          <label class="form-label">Max points</label>
                          <input class="form-control" type="number" name="max_points" min="1" max="100" required value="<?= $mp ?>">
                          <div class="form-text">Recommended: 100</div>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-check2-circle me-1"></i> Save
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <div class="modal fade" id="<?= h($delModalId) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post" action="/admin/wm_subjects.php">
                      <div class="modal-header">
                        <h5 class="modal-title text-danger">Delete WM Subject</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $sid ?>">

                        <p class="mb-2">
                          You are about to delete:
                          <span class="fw-semibold"><?= h($name) ?></span>
                          <span class="badge text-bg-light border ms-1"><?= h($code) ?></span>
                        </p>
                        <div class="alert alert-warning mb-0">
                          If this subject is referenced by WM results, deletion may fail due to constraints.
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
            <?php endif; ?>

          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
      <nav aria-label="WM subjects pagination">
        <ul class="pagination mb-0 flex-wrap">
          <?php
            $base = '/admin/wm_subjects.php?q=' . rawurlencode($q) . '&page=';
            $prev = max(1, $page - 1);
            $next = min($pages, $page + 1);
          ?>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($base . $prev) ?>">Prev</a>
          </li>

          <?php
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            if ($start > 1) {
              echo '<li class="page-item"><a class="page-link" href="' . h($base . '1') . '">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            for ($p = $start; $p <= $end; $p++) {
              $activePage = $p === $page ? 'active' : '';
              echo '<li class="page-item ' . $activePage . '"><a class="page-link" href="' . h($base . (string)$p) . '">' . $p . '</a></li>';
            }
            if ($end < $pages) {
              if ($end < $pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
              echo '<li class="page-item"><a class="page-link" href="' . h($base . (string)$pages) . '">' . $pages . '</a></li>';
            }
          ?>

          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($base . $next) ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php if ($canWrite): ?>
  <div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post" action="/admin/wm_subjects.php">
          <div class="modal-header">
            <h5 class="modal-title">Add WM Subject</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">

            <div class="mb-3">
              <label class="form-label">Code</label>
              <input class="form-control" name="code" maxlength="30" required placeholder="ALGEBRA">
              <div class="form-text">Unique. Used for imports/mapping. Stored uppercase.</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Name</label>
              <input class="form-control" name="name" maxlength="120" required placeholder="Algebra">
              <div class="form-text">Unique display name.</div>
            </div>

            <div class="mb-0">
              <label class="form-label">Max points</label>
              <input class="form-control" type="number" name="max_points" min="1" max="100" required value="100">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-plus-circle me-1"></i> Create
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
