<?php
// admin/classes.php - Classes CRUD + pupil linkage helpers

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

// Allow all logged-in users to view; admin/superadmin can modify.
$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

$page_title = 'Classes';

const CLASSES_FLASH_KEY = 'classes_flash';

function cl_set_flash(string $type, string $msg): void
{
    $_SESSION[CLASSES_FLASH_KEY] = ['type' => $type, 'msg' => $msg];
}

function cl_take_flash(): ?array
{
    $f = $_SESSION[CLASSES_FLASH_KEY] ?? null;
    unset($_SESSION[CLASSES_FLASH_KEY]);
    return is_array($f) ? $f : null;
}

function cl_post_str(string $key, int $maxLen): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function cl_post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (is_int($v)) return $v;
    if (!is_string($v) || !preg_match('/^\d+$/', trim($v))) return $default;
    return (int)$v;
}

function cl_get_str(string $key, int $maxLen = 120): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function cl_is_checked(string $key): bool
{
    return isset($_POST[$key]) && (string)$_POST[$key] === '1';
}

function cl_normalize_class_code(string $raw): string
{
    $v = strtoupper(trim($raw));
    $v = preg_replace('/\s+/', '', $v) ?? '';
    return $v;
}

function cl_normalize_section(string $raw): ?string
{
    $v = strtoupper(trim($raw));
    if ($v === '') return null;
    return $v;
}

function cl_extract_grade_from_code(string $classCode): ?int
{
    if (preg_match('/^\s*(\d{1,2})\b/', $classCode, $m)) {
        return (int)$m[1];
    }
    return null;
}

function cl_extract_section_from_code(string $classCode): ?string
{
    if (preg_match('/^\s*\d{1,2}[-_]?([A-Z0-9]{1,5})\s*$/', $classCode, $m)) {
        return strtoupper((string)$m[1]);
    }
    return null;
}

function cl_validate_class_code(string $classCode): bool
{
    if ($classCode === '') return false;
    return (bool)preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/', $classCode);
}

function cl_redirect(string $url = '/admin/classes.php'): void
{
    header('Location: ' . $url);
    exit;
}

function cl_sql_state(Throwable $e): string
{
    if ($e instanceof PDOException && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
        return $e->errorInfo[0];
    }
    return (string)$e->getCode();
}

verify_csrf('csrf');

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
            $classCode = cl_normalize_class_code(cl_post_str('class_code', 20));
            $gradeRaw = cl_post_str('grade', 3);
            $section = cl_normalize_section(cl_post_str('section', 5));
            $isActive = cl_is_checked('is_active') ? 1 : 0;
            $linkExisting = cl_is_checked('link_existing');

            if (!cl_validate_class_code($classCode)) {
                cl_set_flash('danger', 'Class code is invalid. Use up to 20 chars: A-Z, 0-9, dot, underscore, dash.');
                cl_redirect();
            }

            $grade = null;
            if ($gradeRaw !== '') {
                if (!preg_match('/^\d{1,2}$/', $gradeRaw)) {
                    cl_set_flash('danger', 'Grade must be a number between 1 and 12.');
                    cl_redirect();
                }
                $grade = (int)$gradeRaw;
            } else {
                $grade = cl_extract_grade_from_code($classCode);
            }

            if ($grade !== null && ($grade < 1 || $grade > 12)) {
                cl_set_flash('danger', 'Grade must be between 1 and 12.');
                cl_redirect();
            }

            if ($section === null) {
                $section = cl_extract_section_from_code($classCode);
            }
            if ($section !== null && !preg_match('/^[A-Z0-9]{1,5}$/', $section)) {
                cl_set_flash('danger', 'Section must be 1-5 chars: A-Z or 0-9.');
                cl_redirect();
            }

            $pdo->beginTransaction();

            $st = $pdo->prepare("
                INSERT INTO classes (class_code, grade, section, is_active)
                VALUES (:class_code, :grade, :section, :is_active)
            ");
            $st->execute([
                ':class_code' => $classCode,
                ':grade' => $grade,
                ':section' => $section,
                ':is_active' => $isActive,
            ]);

            $classId = (int)$pdo->lastInsertId();
            $linkedCount = 0;

            if ($linkExisting && $classId > 0) {
                $up = $pdo->prepare("
                    UPDATE pupils
                    SET class_id = :class_id
                    WHERE class_code = :class_code
                      AND (class_id IS NULL OR class_id <> :class_id2)
                ");
                $up->execute([
                    ':class_id' => $classId,
                    ':class_id2' => $classId,
                    ':class_code' => $classCode,
                ]);
                $linkedCount = $up->rowCount();
            }

            $pdo->commit();

            $msg = 'Class created.';
            if ($linkedCount > 0) {
                $msg .= ' Linked pupils: ' . $linkedCount . '.';
            }
            cl_set_flash('success', $msg);
            cl_redirect();
        }

        if ($action === 'update') {
            $id = cl_post_int('id', 0);
            $classCode = cl_normalize_class_code(cl_post_str('class_code', 20));
            $gradeRaw = cl_post_str('grade', 3);
            $section = cl_normalize_section(cl_post_str('section', 5));
            $isActive = cl_is_checked('is_active') ? 1 : 0;
            $propagateCode = cl_is_checked('propagate_code');
            $relinkByCode = cl_is_checked('relink_by_code');

            if ($id <= 0) {
                cl_set_flash('danger', 'Invalid class ID.');
                cl_redirect();
            }
            if (!cl_validate_class_code($classCode)) {
                cl_set_flash('danger', 'Class code is invalid. Use up to 20 chars: A-Z, 0-9, dot, underscore, dash.');
                cl_redirect();
            }

            $grade = null;
            if ($gradeRaw !== '') {
                if (!preg_match('/^\d{1,2}$/', $gradeRaw)) {
                    cl_set_flash('danger', 'Grade must be a number between 1 and 12.');
                    cl_redirect();
                }
                $grade = (int)$gradeRaw;
            } else {
                $grade = cl_extract_grade_from_code($classCode);
            }
            if ($grade !== null && ($grade < 1 || $grade > 12)) {
                cl_set_flash('danger', 'Grade must be between 1 and 12.');
                cl_redirect();
            }

            if ($section === null) {
                $section = cl_extract_section_from_code($classCode);
            }
            if ($section !== null && !preg_match('/^[A-Z0-9]{1,5}$/', $section)) {
                cl_set_flash('danger', 'Section must be 1-5 chars: A-Z or 0-9.');
                cl_redirect();
            }

            $stOld = $pdo->prepare('SELECT class_code FROM classes WHERE id = :id LIMIT 1');
            $stOld->execute([':id' => $id]);
            $old = $stOld->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                cl_set_flash('danger', 'Class not found.');
                cl_redirect();
            }
            $oldCode = (string)$old['class_code'];

            $pdo->beginTransaction();

            $st = $pdo->prepare("
                UPDATE classes
                SET class_code = :class_code,
                    grade = :grade,
                    section = :section,
                    is_active = :is_active
                WHERE id = :id
            ");
            $st->execute([
                ':id' => $id,
                ':class_code' => $classCode,
                ':grade' => $grade,
                ':section' => $section,
                ':is_active' => $isActive,
            ]);

            $propagated = 0;
            $relinked = 0;

            if ($propagateCode && $oldCode !== $classCode) {
                $up = $pdo->prepare('UPDATE pupils SET class_code = :new_code WHERE class_id = :class_id');
                $up->execute([
                    ':new_code' => $classCode,
                    ':class_id' => $id,
                ]);
                $propagated = $up->rowCount();
            }

            if ($relinkByCode) {
                $up2 = $pdo->prepare("
                    UPDATE pupils
                    SET class_id = :class_id
                    WHERE class_code = :class_code
                      AND (class_id IS NULL OR class_id <> :class_id2)
                ");
                $up2->execute([
                    ':class_id' => $id,
                    ':class_id2' => $id,
                    ':class_code' => $classCode,
                ]);
                $relinked = $up2->rowCount();
            }

            $pdo->commit();

            $msg = 'Class updated.';
            if ($propagated > 0) $msg .= ' Updated pupil class_code: ' . $propagated . '.';
            if ($relinked > 0) $msg .= ' Linked pupils by code: ' . $relinked . '.';
            cl_set_flash('success', $msg);
            cl_redirect();
        }

        if ($action === 'toggle_active') {
            $id = cl_post_int('id', 0);
            $to = cl_post_int('to', 1);
            if ($id <= 0 || ($to !== 0 && $to !== 1)) {
                cl_set_flash('danger', 'Invalid toggle request.');
                cl_redirect();
            }

            $st = $pdo->prepare('UPDATE classes SET is_active = :is_active WHERE id = :id');
            $st->execute([
                ':id' => $id,
                ':is_active' => $to,
            ]);

            cl_set_flash('success', $to === 1 ? 'Class activated.' : 'Class deactivated.');
            cl_redirect();
        }

        if ($action === 'sync_links') {
            $up = $pdo->prepare("
                UPDATE pupils p
                INNER JOIN classes c ON c.class_code = p.class_code
                SET p.class_id = c.id
                WHERE p.class_id IS NULL OR p.class_id <> c.id
            ");
            $up->execute();
            cl_set_flash('success', 'Sync complete. Linked pupils: ' . $up->rowCount() . '.');
            cl_redirect();
        }

        if ($action === 'delete') {
            $id = cl_post_int('id', 0);
            if ($id <= 0) {
                cl_set_flash('danger', 'Invalid class ID.');
                cl_redirect();
            }

            $st = $pdo->prepare('DELETE FROM classes WHERE id = :id');
            $st->execute([':id' => $id]);
            cl_set_flash('success', 'Class deleted.');
            cl_redirect();
        }

        cl_set_flash('danger', 'Unknown action.');
        cl_redirect();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $state = cl_sql_state($e);
        if ($state === '23000') {
            cl_set_flash('danger', 'Constraint error. Class code might already exist or row is referenced.');
        } else {
            cl_set_flash('danger', 'Database error. Check server logs.');
        }
        cl_redirect();
    }
}

$q = cl_get_str('q', 100);
$active = cl_get_str('active', 8);
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

if (!in_array($active, ['', '1', '0'], true)) {
    $active = '';
}

$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(c.class_code LIKE :q OR c.section LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($active !== '') {
    $where[] = 'c.is_active = :is_active';
    $params[':is_active'] = (int)$active;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stCount = $pdo->prepare("SELECT COUNT(*) AS c FROM classes c $whereSql");
foreach ($params as $k => $v) {
    $type = ($k === ':is_active') ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stCount->bindValue($k, $v, $type);
}
$stCount->execute();
$total = (int)($stCount->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

$st = $pdo->prepare("
    SELECT
      c.id,
      c.class_code,
      c.grade,
      c.section,
      c.is_active,
      c.created_at,
      c.updated_at,
      COALESCE(pl.cnt, 0) AS linked_pupils,
      COALESCE(pc.cnt, 0) AS code_pupils
    FROM classes c
    LEFT JOIN (
      SELECT class_id, COUNT(*) AS cnt
      FROM pupils
      WHERE class_id IS NOT NULL
      GROUP BY class_id
    ) pl ON pl.class_id = c.id
    LEFT JOIN (
      SELECT class_code, COUNT(*) AS cnt
      FROM pupils
      GROUP BY class_code
    ) pc ON pc.class_code = c.class_code
    $whereSql
    ORDER BY c.grade ASC, c.class_code ASC, c.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $type = ($k === ':is_active') ? PDO::PARAM_INT : PDO::PARAM_STR;
    $st->bindValue($k, $v, $type);
}
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pages = (int)max(1, (int)ceil($total / $perPage));
$flash = cl_take_flash();

$stats = [
    'total_classes' => 0,
    'active_classes' => 0,
    'linked_pupils' => 0,
    'linkable_pupils' => 0,
];

$stats['total_classes'] = (int)($pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn() ?: 0);
$stats['active_classes'] = (int)($pdo->query('SELECT COUNT(*) FROM classes WHERE is_active = 1')->fetchColumn() ?: 0);
$stats['linked_pupils'] = (int)($pdo->query('SELECT COUNT(*) FROM pupils WHERE class_id IS NOT NULL')->fetchColumn() ?: 0);
$stats['linkable_pupils'] = (int)($pdo->query("
    SELECT COUNT(*)
    FROM pupils p
    INNER JOIN classes c ON c.class_code = p.class_code
    WHERE p.class_id IS NULL OR p.class_id <> c.id
")->fetchColumn() ?: 0);

require __DIR__ . '/header.php';
?>

<style nonce="<?= h((string)($cspNonce ?? '')) ?>">
  .classes-hero {
    border: 1px solid rgba(15, 23, 42, .08);
    border-radius: 1rem;
    background:
      radial-gradient(900px 260px at 0% 0%, rgba(13,110,253,.08), transparent 65%),
      radial-gradient(900px 260px at 100% 100%, rgba(25,135,84,.06), transparent 65%),
      #fff;
  }
  .classes-hero .title {
    font-size: 1.12rem;
    font-weight: 700;
    letter-spacing: .01em;
    margin-bottom: .2rem;
  }
  .classes-hero .subtitle {
    color: #64748b;
    margin-bottom: 0;
  }
  .kpi-card {
    border: 1px solid rgba(15, 23, 42, .08);
    border-radius: .95rem;
    box-shadow: 0 8px 18px rgba(2, 6, 23, .06);
  }
  .kpi-card .kpi-label {
    color: #64748b;
    font-size: .88rem;
  }
  .kpi-card .kpi-value {
    font-size: 2rem;
    line-height: 1.1;
    font-weight: 700;
    color: #0f172a;
  }
  .kpi-icon {
    width: 2.2rem;
    height: 2.2rem;
    border-radius: .65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(13,110,253,.12);
    color: #0d6efd;
  }
  .classes-table .code-pill {
    font-weight: 600;
    letter-spacing: .01em;
  }
  .classes-table thead th {
    font-weight: 700;
  }
</style>

<div class="classes-hero p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <div class="title">Class Registry and Mapping</div>
      <p class="subtitle">Manage `classes` records and keep pupils linked by `class_id`.</p>
    </div>
    <div class="d-flex gap-2">
      <?php if ($canWrite): ?>
        <form method="post" action="/admin/classes.php" class="d-inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="sync_links">
          <button class="btn btn-outline-secondary" type="submit">
            <i class="bi bi-arrow-repeat me-1"></i> Sync Pupil Links
          </button>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">
          <i class="bi bi-plus-lg me-1"></i> Add Class
        </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <span class="badge text-bg-light border">Master Data</span>
    <span class="badge text-bg-light border">Class Linking</span>
  </div>
  <div class="text-muted small">Use filters below to find classes quickly.</div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= h((string)($flash['type'] ?? 'info')) ?> alert-dismissible fade show" role="alert">
    <?= h((string)($flash['msg'] ?? '')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card kpi-card h-100">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="kpi-label">Total classes</div>
          <div class="kpi-value"><?= (int)$stats['total_classes'] ?></div>
        </div>
        <span class="kpi-icon"><i class="bi bi-collection"></i></span>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card kpi-card h-100">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="kpi-label">Active classes</div>
          <div class="kpi-value"><?= (int)$stats['active_classes'] ?></div>
        </div>
        <span class="kpi-icon"><i class="bi bi-check2-circle"></i></span>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card kpi-card h-100">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="kpi-label">Pupils with class_id</div>
          <div class="kpi-value"><?= (int)$stats['linked_pupils'] ?></div>
        </div>
        <span class="kpi-icon"><i class="bi bi-link-45deg"></i></span>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card kpi-card h-100">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="kpi-label">Linkable by class_code</div>
          <div class="kpi-value"><?= (int)$stats['linkable_pupils'] ?></div>
        </div>
        <span class="kpi-icon"><i class="bi bi-arrow-left-right"></i></span>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form method="get" action="/admin/classes.php" class="row g-2 align-items-center">
      <div class="col-12 col-md-6 col-lg-4">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input
            class="form-control"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Search by class code or section"
          >
        </div>
      </div>
      <div class="col-12 col-md-3 col-lg-2">
        <select class="form-select" name="active">
          <option value="" <?= $active === '' ? 'selected' : '' ?>>All statuses</option>
          <option value="1" <?= $active === '1' ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= $active === '0' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-12 col-md-auto">
        <button class="btn btn-outline-secondary" type="submit">Filter</button>
        <a class="btn btn-outline-link" href="/admin/classes.php">Reset</a>
      </div>
      <div class="col-12 col-md-auto ms-md-auto text-muted small">
        Total: <span class="fw-semibold"><?= (int)$total ?></span>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm classes-table">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:90px;">ID</th>
            <th style="width:180px;">Class code</th>
            <th style="width:100px;">Grade</th>
            <th style="width:100px;">Section</th>
            <th style="width:120px;">Status</th>
            <th style="width:140px;" class="text-end">Linked</th>
            <th style="width:160px;" class="text-end">By code</th>
            <th style="width:220px;" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No classes found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $id = (int)$r['id'];
              $classCode = (string)$r['class_code'];
              $grade = $r['grade'] !== null ? (int)$r['grade'] : null;
              $section = $r['section'] !== null ? (string)$r['section'] : '';
              $isActiveRow = (int)$r['is_active'] === 1;
              $linkedPupils = (int)$r['linked_pupils'];
              $codePupils = (int)$r['code_pupils'];
              $editModalId = 'modalEdit' . $id;
              $deleteModalId = 'modalDelete' . $id;
            ?>
            <tr>
              <td class="text-muted"><?= $id ?></td>
              <td><span class="badge text-bg-light border code-pill"><?= h($classCode) ?></span></td>
              <td><?= $grade !== null ? $grade : '<span class="text-muted">-</span>' ?></td>
              <td><?= $section !== '' ? h($section) : '<span class="text-muted">-</span>' ?></td>
              <td>
                <?php if ($isActiveRow): ?>
                  <span class="badge text-bg-success">Active</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-end fw-semibold"><?= $linkedPupils ?></td>
              <td class="text-end">
                <?= $codePupils ?>
                <?php if ($codePupils !== $linkedPupils): ?>
                  <span class="badge text-bg-warning ms-1">Mismatch</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if ($canWrite): ?>
                  <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= h($editModalId) ?>">
                      <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#<?= h($deleteModalId) ?>">
                      <i class="bi bi-trash me-1"></i> Delete
                    </button>
                  </div>
                <?php else: ?>
                  <span class="text-muted small">Read-only</span>
                <?php endif; ?>
              </td>
            </tr>

            <?php if ($canWrite): ?>
              <div class="modal fade" id="<?= h($editModalId) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post" action="/admin/classes.php">
                      <div class="modal-header">
                        <h5 class="modal-title">Edit Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $id ?>">

                        <div class="mb-3">
                          <label class="form-label">Class code</label>
                          <input class="form-control" name="class_code" maxlength="20" required value="<?= h($classCode) ?>">
                          <div class="form-text">Example: 10-A, 11B, 9-G1</div>
                        </div>

                        <div class="row g-2">
                          <div class="col-md-6">
                            <label class="form-label">Grade</label>
                            <input class="form-control" type="number" name="grade" min="1" max="12" value="<?= $grade !== null ? $grade : '' ?>">
                            <div class="form-text">Optional. Auto-derived from class code if empty.</div>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label">Section</label>
                            <input class="form-control" name="section" maxlength="5" value="<?= h($section) ?>">
                            <div class="form-text">Optional. Example: A, B1.</div>
                          </div>
                        </div>

                        <div class="mt-3">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active<?= $id ?>" <?= $isActiveRow ? 'checked' : '' ?>>
                            <label class="form-check-label" for="active<?= $id ?>">Active</label>
                          </div>
                          <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="propagate_code" value="1" id="propagate<?= $id ?>" checked>
                            <label class="form-check-label" for="propagate<?= $id ?>">Update linked pupils.class_code when code changes</label>
                          </div>
                          <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="relink_by_code" value="1" id="relink<?= $id ?>" checked>
                            <label class="form-check-label" for="relink<?= $id ?>">Link pupils to this class by matching class_code</label>
                          </div>
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

              <div class="modal fade" id="<?= h($deleteModalId) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form method="post" action="/admin/classes.php">
                      <div class="modal-header">
                        <h5 class="modal-title text-danger">Delete Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $id ?>">

                        <p class="mb-2">
                          Delete class <span class="fw-semibold"><?= h($classCode) ?></span>?
                        </p>
                        <div class="alert alert-warning mb-0">
                          Linked pupils (`class_id`) will be set to NULL by the foreign key rule.
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
      <nav aria-label="Classes pagination">
        <ul class="pagination mb-0 flex-wrap">
          <?php
            $base = '/admin/classes.php?q=' . rawurlencode($q) . '&active=' . rawurlencode($active) . '&page=';
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
                $activeCls = ($p === $page) ? 'active' : '';
                echo '<li class="page-item ' . $activeCls . '"><a class="page-link" href="' . h($base . (string)$p) . '">' . $p . '</a></li>';
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
        <form method="post" action="/admin/classes.php">
          <div class="modal-header">
            <h5 class="modal-title">Add Class</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">

            <div class="mb-3">
              <label class="form-label">Class code</label>
              <input class="form-control" name="class_code" maxlength="20" required placeholder="10-A">
              <div class="form-text">Unique code for this class.</div>
            </div>

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Grade</label>
                <input class="form-control" type="number" name="grade" min="1" max="12" placeholder="10">
                <div class="form-text">Optional. Auto-derived if empty.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Section</label>
                <input class="form-control" name="section" maxlength="5" placeholder="A">
                <div class="form-text">Optional.</div>
              </div>
            </div>

            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" name="is_active" value="1" id="create_active" checked>
              <label class="form-check-label" for="create_active">Active</label>
            </div>
            <div class="form-check mt-1">
              <input class="form-check-input" type="checkbox" name="link_existing" value="1" id="create_link_existing" checked>
              <label class="form-check-label" for="create_link_existing">Link existing pupils by class_code</label>
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
