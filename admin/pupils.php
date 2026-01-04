<?php
// admin/pupils.php — Pupils CRUD (list/search/paginate + grade(parallels) + sorting)

declare(strict_types=1);

$page_title = 'Pupils';
require __DIR__ . '/header.php';

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Allow all logged-in admins/viewers to view; require admin+ for writes.
$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

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

function get_str(string $key, int $maxLen = 200): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
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

function is_valid_sort(string $s): bool
{
    return in_array($s, ['id', 'name'], true);
}

function is_valid_dir(string $s): bool
{
    return in_array($s, ['asc', 'desc'], true);
}

function grade_from_class_code(string $classCode): ?int
{
    $classCode = trim($classCode);
    // expected formats like "10-A", "9-B", etc. (also handles "10 A" loosely)
    if (preg_match('/^(\d{1,2})\s*[-\s]/u', $classCode, $m)) return (int)$m[1];
    if (preg_match('/^(\d{1,2})$/u', $classCode, $m)) return (int)$m[1];
    return null;
}

// ---- handle POST actions ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    // CSRF only for POST (fixes GET search/filter issues)
    verify_csrf('csrf');

    $action = $_POST['action'] ?? '';
    $action = is_string($action) ? $action : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $id            = post_int('id', 0);
            $surname       = post_str('surname', 40);
            $name          = post_str('name', 40);
            $middle_name   = post_str('middle_name', 40);
            $class_code    = normalize_class_code(post_str('class_code', 30));
            $track         = post_str('track', 40);
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
$q = get_str('q', 120);

$filterGradeRaw = get_str('grade', 4);
$filterGrade = ($filterGradeRaw !== '' && preg_match('/^\d{1,2}$/', $filterGradeRaw)) ? (int)$filterGradeRaw : null;

$filterClass = normalize_class_code(get_str('class_code', 30));

$filterTrack = get_str('track', 40);
if ($filterTrack !== '' && !valid_track($filterTrack)) $filterTrack = '';

$sort = get_str('sort', 10);
if (!is_valid_sort($sort)) $sort = 'name';

$dir = strtolower(get_str('dir', 4));
if (!is_valid_dir($dir)) $dir = 'asc';

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

// Grade (parallels) logic:
// - If grade selected and class_code not selected: include all parallels in that grade (e.g., 10-A, 10-B, 10-V)
// - If track selected, it further restricts within that grade (requirement)
if ($filterGrade !== null && $filterClass === '') {
    // Matches "10-" prefix. If your data uses "10A" without dash, adjust here.
    $whereParts[] = "p.class_code LIKE :grade_like";
    $params[':grade_like'] = $filterGrade . "-%";
}

// Specific class filter (overrides grade grouping if chosen)
if ($filterClass !== '') {
    $whereParts[] = "p.class_code = :class_code";
    $params[':class_code'] = $filterClass;
}

if ($filterTrack !== '') {
    $whereParts[] = "p.track = :track";
    $params[':track'] = $filterTrack;
}

$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// Sorting
$orderBy = "p.surname ASC, p.name ASC, p.middle_name ASC, p.id ASC";
if ($sort === 'id') {
    $orderBy = "p.id " . strtoupper($dir);
} elseif ($sort === 'name') {
    $orderBy = "p.surname " . strtoupper($dir) . ", p.name " . strtoupper($dir) . ", p.middle_name " . strtoupper($dir) . ", p.id DESC";
}

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
    ORDER BY $orderBy
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

// --- Filter dropdown sources ---
// Grades (derived from class_code prefix)
$gradeRows = $pdo->query("
    SELECT DISTINCT CAST(SUBSTRING_INDEX(class_code, '-', 1) AS UNSIGNED) AS grade
    FROM pupils
    WHERE class_code REGEXP '^[0-9]{1,2}-'
    ORDER BY grade ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Classes dropdown should respect selected grade and track (so UX matches "parallels")
$classSql = "SELECT DISTINCT class_code FROM pupils WHERE class_code <> ''";
$classParams = [];
if ($filterGrade !== null) {
    $classSql .= " AND class_code LIKE :g";
    $classParams[':g'] = $filterGrade . "-%";
}
if ($filterTrack !== '') {
    $classSql .= " AND track = :t";
    $classParams[':t'] = $filterTrack;
}
$classSql .= " ORDER BY class_code ASC";
$stClass = $pdo->prepare($classSql);
foreach ($classParams as $k => $v) $stClass->bindValue($k, $v, PDO::PARAM_STR);
$stClass->execute();
$classRows = $stClass->fetchAll(PDO::FETCH_ASSOC);

// URL builder that preserves filters
$base = $_GET;
$mkUrl = function (array $overrides = []) use ($base): string {
    $q = $base;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'pupils.php?' . http_build_query($q);
};

// Table header sort link helper
$sortLink = function (string $col) use ($sort, $dir, $mkUrl): array {
    $isActive = ($sort === $col);
    $nextDir = 'asc';
    if ($isActive) $nextDir = ($dir === 'asc') ? 'desc' : 'asc';
    $url = $mkUrl(['sort' => $col, 'dir' => $nextDir, 'page' => 1]);
    $icon = '';
    if ($isActive) {
        $icon = $dir === 'asc' ? 'bi bi-sort-up' : 'bi bi-sort-down';
    } else {
        $icon = 'bi bi-arrow-down-up';
    }
    return [$url, $icon, $isActive];
};
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
      <div class="col-12 col-lg-4">
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

      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label small text-muted mb-1">Grade (parallels)</label>
        <select class="form-select" name="grade">
          <option value="">All grades</option>
          <?php foreach ($gradeRows as $gr): ?>
            <?php $g = (int)($gr['grade'] ?? 0); if ($g <= 0) continue; ?>
            <option value="<?= h((string)$g) ?>" <?= ($filterGrade === $g) ? 'selected' : '' ?>><?= h((string)$g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label small text-muted mb-1">Track</label>
        <select class="form-select" name="track">
          <option value="">All tracks</option>
          <?php foreach (TRACK_OPTIONS as $t): ?>
            <option value="<?= h($t) ?>" <?= $filterTrack === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label small text-muted mb-1">Class (optional)</label>
        <select class="form-select" name="class_code">
          <option value="">All classes (or all parallels if Grade selected)</option>
          <?php foreach ($classRows as $cr): ?>
            <?php $cc = (string)($cr['class_code'] ?? ''); ?>
            <?php if ($cc === '') continue; ?>
            <option value="<?= h($cc) ?>" <?= $filterClass === $cc ? 'selected' : '' ?>><?= h($cc) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">
          Tip: choose Grade to get 10-A/10-B/10-V together. Choose Class to narrow to one parallel.
        </div>
      </div>

      <div class="col-12 col-lg-1 d-grid">
        <button class="btn btn-outline-primary" type="submit">
          <i class="bi bi-funnel me-1"></i> Filter
        </button>
      </div>

      <div class="col-12">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="small text-muted">
            Showing <span class="fw-semibold"><?= h(count($rows)) ?></span> of <span class="fw-semibold"><?= h($total) ?></span> pupils.
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($q !== ''): ?><span class="badge text-bg-light border"><i class="bi bi-search me-1"></i><?= h($q) ?></span><?php endif; ?>
            <?php if ($filterGrade !== null): ?><span class="badge text-bg-light border"><i class="bi bi-layers me-1"></i>Grade <?= h((string)$filterGrade) ?></span><?php endif; ?>
            <?php if ($filterTrack !== ''): ?><span class="badge text-bg-light border"><i class="bi bi-diagram-3 me-1"></i><?= h($filterTrack) ?></span><?php endif; ?>
            <?php if ($filterClass !== ''): ?><span class="badge text-bg-light border"><i class="bi bi-grid-3x3-gap me-1"></i><?= h($filterClass) ?></span><?php endif; ?>
            <span class="badge text-bg-secondary">
              <i class="bi bi-sort-alpha-down me-1"></i>Sort: <?= h($sort) ?> (<?= h($dir) ?>)
            </span>

            <a class="btn btn-sm btn-outline-secondary" href="pupils.php" title="Clear all filters">
              <i class="bi bi-x-circle me-1"></i> Clear
            </a>
          </div>
        </div>
      </div>

      <!-- preserve sorting on filter submit -->
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="dir" value="<?= h($dir) ?>">
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <?php [$idUrl, $idIcon, $idActive] = $sortLink('id'); ?>
            <?php [$nameUrl, $nameIcon, $nameActive] = $sortLink('name'); ?>

            <th style="width:70px">
              <a class="text-decoration-none text-dark" href="<?= h($idUrl) ?>">
                ID <i class="<?= h($idIcon) ?> ms-1<?= $idActive ? '' : ' text-muted' ?>"></i>
              </a>
            </th>

            <th>
              <a class="text-decoration-none text-dark" href="<?= h($nameUrl) ?>">
                Pupil <i class="<?= h($nameIcon) ?> ms-1<?= $nameActive ? '' : ' text-muted' ?>"></i>
              </a>
            </th>

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

              $g = grade_from_class_code($classCode);
              $gradeBadge = ($g !== null) ? ('<span class="badge text-bg-light border me-1">G' . h((string)$g) . '</span>') : '';

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
              <td>
                <?= $gradeBadge ?>
                <span class="badge text-bg-light border"><?= h($classCode) ?></span>
              </td>
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
                    title="Edit"
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
                    title="Delete"
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
            $mkPageUrl = function (int $p) use ($mkUrl): string {
              return $mkUrl(['page' => $p]);
            };
            $start = max(1, $page - 3);
            $end = min($pages, $page + 3);
          ?>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($mkPageUrl(max(1, $page - 1))) ?>" tabindex="-1" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Prev</a>
          </li>

          <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= h($mkPageUrl(1)) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <?php endif; ?>

          <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h($mkPageUrl($p)) ?>"><?= h($p) ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= h($mkPageUrl($pages)) ?>"><?= h($pages) ?></a></li>
          <?php endif; ?>

          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($mkPageUrl(min($pages, $page + 1))) ?>" aria-disabled="<?= $page >= $pages ? 'true' : 'false' ?>">Next</a>
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
                <input class="form-control" name="class_code" maxlength="30" required placeholder="e.g., 10-A">
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
