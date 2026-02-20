<?php
// admin/wm_exams.php - CRUD for wm_exams

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();
$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

// ----------------------- Helpers -----------------------
function wmex_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function wmex_redirect_self(array $keepQuery = []): void
{
    $base = '/admin/wm_exams.php';
    $q = $keepQuery ? ('?' . http_build_query($keepQuery)) : '';
    header('Location: ' . $base . $q);
    exit;
}

function wmex_norm_year(string $s): string
{
    $s = trim($s);
    // Accept "2025-2026" or "2025/2026", normalize to "2025/2026"
    $s = str_replace('-', '/', $s);
    return $s;
}

function wmex_norm_exam_year(string $s): string
{
    $s = trim($s);
    // DB constraint for wm_exams expects "YYYY-YYYY"
    $s = str_replace('/', '-', $s);
    return $s;
}

function wmex_valid_year(string $s): bool
{
    // Accept either "2025/2026" or "2025-2026"
    return (bool)preg_match('/^\d{4}[\/-]\d{4}$/', $s);
}

function wmex_valid_year_span(string $s): bool
{
    if (!wmex_valid_year($s)) return false;
    $a = (int)substr($s, 0, 4);
    $b = (int)substr($s, 5, 4);
    return $b === ($a + 1);
}

function wmex_valid_cycle(?string $c): bool
{
    if ($c === null || $c === '') return true;
    return ctype_digit($c) && (int)$c >= 1 && (int)$c <= 60;
}

function wmex_valid_date(?string $d): bool
{
    if ($d === null || $d === '') return false; // required for wm_exams
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    $parts = explode('-', $d);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

function wmex_default_study_dates(string $yearCode): array
{
    $startY = (int)substr($yearCode, 0, 4);
    $endY   = (int)substr($yearCode, 5, 4);
    return [
        sprintf('%04d-09-01', $startY),
        sprintf('%04d-08-31', $endY),
    ];
}

function wmex_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = :t
        LIMIT 1
    ");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function wmex_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :t
          AND column_name = :c
        LIMIT 1
    ");
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function wmex_get_study_year(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare("
        SELECT id, year_code, start_date, end_date, is_active
        FROM study_year
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function wmex_debug_enabled(): bool
{
    $v = strtolower(trim((string)getenv('APP_DEBUG')));
    return in_array($v, ['1', 'true', 'on', 'yes'], true);
}

function wmex_error_ref(string $scope): string
{
    try {
        $rand = strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable) {
        $rand = strtoupper(substr(md5((string)microtime(true)), 0, 6));
    }
    return $scope . '-' . date('YmdHis') . '-' . $rand;
}

function wmex_pdo_context(PDOException $e): array
{
    $state = (string)($e->errorInfo[0] ?? $e->getCode() ?? '00000');
    $driver = isset($e->errorInfo[1]) ? (string)$e->errorInfo[1] : 'NA';
    $detail = isset($e->errorInfo[2]) ? (string)$e->errorInfo[2] : $e->getMessage();
    return [$state, $driver, $detail];
}

function wmex_year_date_window(string $academicYear): array
{
    if (!wmex_valid_year_span($academicYear)) {
        return ['', ''];
    }
    $startY = (int)substr($academicYear, 0, 4);
    $endY   = (int)substr($academicYear, 5, 4);
    return [
        sprintf('%04d-09-01', $startY),
        sprintf('%04d-08-31', $endY),
    ];
}

function wmex_extract_constraint_name(string $detail): string
{
    if (preg_match("/constraint '([^']+)'/i", $detail, $m)) {
        return (string)$m[1];
    }
    return '';
}

function wmex_study_year_code_from_window(string $startDate, string $endDate): ?string
{
    if (!wmex_valid_date($startDate) || !wmex_valid_date($endDate)) {
        return null;
    }

    $startY = (int)substr($startDate, 0, 4);
    $endY = (int)substr($endDate, 0, 4);
    $startMd = substr($startDate, 5, 5);
    $endMd = substr($endDate, 5, 5);

    if ($startMd !== '09-01' || $endMd !== '08-31' || $endY !== ($startY + 1)) {
        return null;
    }

    return sprintf('%04d/%04d', $startY, $endY);
}

// ----------------------- Inputs (GET) -----------------------
$q        = trim((string)($_GET['q'] ?? ''));
$year     = trim((string)($_GET['year'] ?? ''));
$cycle    = trim((string)($_GET['cycle'] ?? '')); // '' or '1'..'60'
$sort     = (string)($_GET['sort'] ?? 'date_desc');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;

$sortMap = [
    'date_desc'  => 'e.exam_date DESC, e.id DESC',
    'date_asc'   => 'e.exam_date ASC, e.id ASC',
    'name_asc'   => 'e.exam_name ASC, e.id ASC',
    'name_desc'  => 'e.exam_name DESC, e.id DESC',
    'year_desc'  => 'sy.year_code DESC, e.cycle_no DESC, e.exam_date DESC, e.id DESC',
    'year_asc'   => 'sy.year_code ASC, e.cycle_no ASC, e.exam_date ASC, e.id ASC',
    'cycle_desc' => 'e.cycle_no DESC, e.exam_date DESC, e.id DESC',
    'cycle_asc'  => 'e.cycle_no ASC, e.exam_date ASC, e.id ASC',
    'id_desc'    => 'e.id DESC',
    'id_asc'     => 'e.id ASC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['date_desc'];

// ----------------------- Actions (POST) -----------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    $returnQuery = [
        'q' => $q,
        'year' => $year,
        'cycle' => $cycle,
        'sort' => $sort,
        'page' => $page,
    ];

    if ($action === 'create' || $action === 'update') {
        if (!wmex_table_exists($pdo, 'study_year') || !wmex_column_exists($pdo, 'wm_exams', 'study_year_id')) {
            wmex_flash('danger', 'Schema mismatch: wm_exams.study_year_id with FK to study_year is required.');
            wmex_redirect_self($returnQuery);
        }

        $id          = (int)($_POST['id'] ?? 0);
        $studyYearId = (int)($_POST['study_year_id'] ?? 0);
        $cRaw        = trim((string)($_POST['cycle_no'] ?? ''));
        $cVal        = ($cRaw === '' ? null : (int)$cRaw);
        $name        = trim((string)($_POST['exam_name'] ?? ''));
        $dateRaw     = trim((string)($_POST['exam_date'] ?? ''));
        $dateVal     = ($dateRaw === '' ? null : $dateRaw);
        $studyYearRow = null;

        $errs = [];
        if ($studyYearId <= 0) {
            $errs[] = 'Study year is required.';
        } else {
            $studyYearRow = wmex_get_study_year($pdo, $studyYearId);
            if (!$studyYearRow) {
                $errs[] = 'Invalid study year selected.';
            }
        }
        if (!wmex_valid_cycle($cRaw)) $errs[] = 'Cycle must be empty or a number between 1 and 60.';
        if ($name === '' || mb_strlen($name) > 120) $errs[] = 'Exam name is required (max 120 characters).';
        if (!wmex_valid_date($dateVal)) $errs[] = 'Exam date is required and must be valid (YYYY-MM-DD).';
        if (is_array($studyYearRow) && wmex_valid_date($dateVal)) {
            $wStart = (string)$studyYearRow['start_date'];
            $wEnd = (string)$studyYearRow['end_date'];
            if ($wStart !== '' && $wEnd !== '' && ($dateVal < $wStart || $dateVal > $wEnd)) {
                $errs[] = 'Exam date must be within selected study year window (' . $wStart . ' to ' . $wEnd . ').';
            }
        }

        if ($errs) {
            wmex_flash('danger', implode(' ', $errs));
            wmex_redirect_self($returnQuery);
        }

        try {
            if ($action === 'create') {
                $dup = $pdo->prepare('
                    SELECT id
                    FROM wm_exams
                    WHERE study_year_id = :sy_id AND exam_date = :dt AND exam_name = :name
                    LIMIT 1
                ');
                $dup->execute([
                    'sy_id' => $studyYearId,
                    'dt' => $dateVal,
                    'name' => $name,
                ]);
                if ((int)$dup->fetchColumn() > 0) {
                    wmex_flash('warning', 'This WM exam already exists for the selected study year/date/name.');
                    wmex_redirect_self($returnQuery);
                }

                $stmt = $pdo->prepare('
                    INSERT INTO wm_exams (study_year_id, cycle_no, exam_name, exam_date)
                    VALUES (:sy_id, :cycle_no, :name, :dt)
                ');
                $stmt->execute([
                    'sy_id'    => $studyYearId,
                    'cycle_no' => $cVal,
                    'name'     => $name,
                    'dt'       => $dateVal,
                ]);
                wmex_flash('success', 'WM exam created successfully.');
                wmex_redirect_self($returnQuery);
            }

            if ($id <= 0) {
                wmex_flash('danger', 'Invalid exam ID.');
                wmex_redirect_self($returnQuery);
            }

            $dup = $pdo->prepare('
                SELECT id
                FROM wm_exams
                WHERE study_year_id = :sy_id AND exam_date = :dt AND exam_name = :name AND id <> :id
                LIMIT 1
            ');
            $dup->execute([
                'sy_id' => $studyYearId,
                'dt' => $dateVal,
                'name' => $name,
                'id' => $id,
            ]);
            if ((int)$dup->fetchColumn() > 0) {
                wmex_flash('warning', 'Another WM exam already has the same study year/date/name.');
                wmex_redirect_self($returnQuery);
            }

            $stmt = $pdo->prepare('
                UPDATE wm_exams
                SET study_year_id = :sy_id,
                    cycle_no = :cycle_no,
                    exam_name = :name,
                    exam_date = :dt
                WHERE id = :id
                LIMIT 1
            ');
            $stmt->execute([
                'sy_id'    => $studyYearId,
                'cycle_no' => $cVal,
                'name'     => $name,
                'dt'       => $dateVal,
                'id'       => $id,
            ]);

            wmex_flash('success', 'WM exam updated successfully.');
            wmex_redirect_self($returnQuery);
        } catch (PDOException $e) {
            [$state, $driverCode, $dbDetail] = wmex_pdo_context($e);
            $ref = wmex_error_ref('WMEX-SAVE');
            if ($state === 'HY000' && $driverCode === '3819') {
                $constraint = wmex_extract_constraint_name($dbDetail);
                $cText = $constraint !== '' ? ' [' . $constraint . ']' : '';
                wmex_flash('danger', 'WM exam failed a database check constraint' . $cText . '. Ref: ' . $ref . ' [SQLSTATE ' . $state . ', DRIVER ' . $driverCode . ']');
                wmex_flash('warning', 'Verify selected study year, cycle range, and exam date window.');
            } elseif ($state === '23000') {
                wmex_flash('danger', 'Duplicate WM exam or integrity constraint violation. Ref: ' . $ref . ' [SQLSTATE ' . $state . ', DRIVER ' . $driverCode . ']');
            } elseif ($state === '42S22' || $state === '42S02') {
                wmex_flash('danger', 'Database schema mismatch for WM exams (missing table/column). Ref: ' . $ref . ' [SQLSTATE ' . $state . ', DRIVER ' . $driverCode . ']');
            } else {
                wmex_flash('danger', 'Database error while saving WM exam. Ref: ' . $ref . ' [SQLSTATE ' . $state . ', DRIVER ' . $driverCode . ']');
            }
            if (wmex_debug_enabled()) {
                wmex_flash('warning', 'Debug [' . $ref . '] ' . $dbDetail);
            }
            error_log('[WM_EXAMS][' . $ref . '] save failed SQLSTATE=' . $state . ' DRIVER=' . $driverCode . ' MSG=' . $dbDetail);
            wmex_redirect_self($returnQuery);
        } catch (Throwable $e) {
            $ref = wmex_error_ref('WMEX-SAVE');
            wmex_flash('danger', 'Unexpected error while saving WM exam. Ref: ' . $ref . '.');
            if (wmex_debug_enabled()) {
                wmex_flash('warning', 'Debug [' . $ref . '] ' . $e->getMessage());
            }
            error_log('[WM_EXAMS][' . $ref . '] unexpected save error: ' . $e->getMessage());
            wmex_redirect_self($returnQuery);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            wmex_flash('danger', 'Invalid exam ID.');
            wmex_redirect_self($returnQuery);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM wm_exams WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            wmex_flash('success', 'WM exam deleted.');
        } catch (Throwable) {
            wmex_flash('danger', 'Unable to delete WM exam. It may be referenced by WM results.');
        }

        wmex_redirect_self($returnQuery);
    }

    if ($action === 'sy_create' || $action === 'sy_update') {
        if (!wmex_table_exists($pdo, 'study_year')) {
            wmex_flash('danger', 'study_year table not found. Run study_year.sql first.');
            wmex_redirect_self($returnQuery);
        }

        $syId = (int)($_POST['sy_id'] ?? 0);
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $isActive = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;

        $errs = [];
        if (!wmex_valid_date($startDate)) $errs[] = 'Start date is required and must be valid (YYYY-MM-DD).';
        if (!wmex_valid_date($endDate)) $errs[] = 'End date is required and must be valid (YYYY-MM-DD).';
        if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
            $errs[] = 'End date must be greater than or equal to start date.';
        }
        if (!$errs) {
            if (wmex_study_year_code_from_window($startDate, $endDate) === null) {
                $errs[] = 'Study year range must be exactly Sep 1 to Aug 31 of consecutive years.';
            }
        }

        if ($errs) {
            wmex_flash('danger', implode(' ', $errs));
            wmex_redirect_self($returnQuery);
        }

        try {
            if ($action === 'sy_create') {
                $stmt = $pdo->prepare("
                    INSERT INTO study_year (start_date, end_date, is_active)
                    VALUES (:start_date, :end_date, :is_active)
                ");
                $stmt->execute([
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':is_active' => $isActive,
                ]);
                wmex_flash('success', 'Study year created.');
                wmex_redirect_self($returnQuery);
            }

            if ($syId <= 0) {
                wmex_flash('danger', 'Invalid study year ID.');
                wmex_redirect_self($returnQuery);
            }

            $stmt = $pdo->prepare("
                UPDATE study_year
                SET start_date = :start_date,
                    end_date = :end_date,
                    is_active = :is_active
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':is_active' => $isActive,
                ':id' => $syId,
            ]);
            wmex_flash('success', 'Study year updated.');
            wmex_redirect_self($returnQuery);
        } catch (PDOException $e) {
            $state = $e->errorInfo[0] ?? $e->getCode();
            if ($state === '23000') {
                wmex_flash('danger', 'Duplicate study year or integrity constraint violation.');
            } else {
                wmex_flash('danger', 'Database error while saving study year.');
            }
            error_log('[WM_EXAMS] study_year save failed: ' . $e->getMessage());
            wmex_redirect_self($returnQuery);
        }
    }

    if ($action === 'sy_toggle') {
        if (!wmex_table_exists($pdo, 'study_year')) {
            wmex_flash('danger', 'study_year table not found. Run study_year.sql first.');
            wmex_redirect_self($returnQuery);
        }

        $syId = (int)($_POST['sy_id'] ?? 0);
        $to = (int)($_POST['to'] ?? 1);
        if ($syId <= 0 || ($to !== 0 && $to !== 1)) {
            wmex_flash('danger', 'Invalid study year toggle request.');
            wmex_redirect_self($returnQuery);
        }

        $stmt = $pdo->prepare('UPDATE study_year SET is_active = :a WHERE id = :id');
        $stmt->execute([':a' => $to, ':id' => $syId]);
        wmex_flash('success', $to ? 'Study year activated.' : 'Study year deactivated.');
        wmex_redirect_self($returnQuery);
    }

    if ($action === 'sy_delete') {
        if (!wmex_table_exists($pdo, 'study_year')) {
            wmex_flash('danger', 'study_year table not found. Run study_year.sql first.');
            wmex_redirect_self($returnQuery);
        }

        $syId = (int)($_POST['sy_id'] ?? 0);
        if ($syId <= 0) {
            wmex_flash('danger', 'Invalid study year ID.');
            wmex_redirect_self($returnQuery);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM study_year WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $syId]);
            wmex_flash('success', 'Study year deleted.');
        } catch (Throwable $e) {
            wmex_flash('danger', 'Unable to delete study year.');
            error_log('[WM_EXAMS] study_year delete failed: ' . $e->getMessage());
        }
        wmex_redirect_self($returnQuery);
    }

    wmex_flash('danger', 'Unknown action.');
    wmex_redirect_self($returnQuery);
}

// ----------------------- Query: filters/pagination -----------------------
$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(e.exam_name LIKE :q OR sy.year_code LIKE :q OR CAST(e.id AS CHAR) = :qExact)';
    $params['q'] = '%' . $q . '%';
    $params['qExact'] = $q;
}
if ($year !== '' && ctype_digit($year)) {
    $where[] = 'e.study_year_id = :sy_id';
    $params['sy_id'] = (int)$year;
}
if ($cycle !== '' && ctype_digit($cycle)) {
    $where[] = 'e.cycle_no = :cycle_no';
    $params['cycle_no'] = (int)$cycle;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totalRows = 0;
$totalPages = 1;
$rows = [];
$yearOptions = [];
$cycleOptions = [];
$studyYearReady = false;
$studyYearRows = [];

try {
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM wm_exams e
        INNER JOIN study_year sy ON sy.id = e.study_year_id
        $whereSql
    ");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare("
        SELECT e.id, e.study_year_id, sy.year_code AS study_year_code, e.cycle_no, e.exam_name, e.exam_date
        FROM wm_exams e
        INNER JOIN study_year sy ON sy.id = e.study_year_id
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

    $studyYearReady = wmex_table_exists($pdo, 'study_year');
    if ($studyYearReady) {
        $syStmt = $pdo->query("
            SELECT id, year_code, start_date, end_date, is_active
            FROM study_year
            ORDER BY start_date DESC, id DESC
        ");
        $studyYearRows = $syStmt ? $syStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($studyYearRows as $sy) {
            $yearOptions[] = [
                'id' => (int)$sy['id'],
                'label' => (string)$sy['year_code'],
            ];
        }
    }

    $cycleStmt = $pdo->query("SELECT DISTINCT cycle_no FROM wm_exams WHERE cycle_no IS NOT NULL ORDER BY cycle_no ASC");
    if ($cycleStmt) {
        $cycleOptions = $cycleStmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $state = $e->errorInfo[0] ?? $e->getCode();
    if ($state === '42S22' || $state === '42S02') {
        wmex_flash('danger', 'WM exams schema is missing required fields/tables. Please check database migration.');
    } else {
        wmex_flash('danger', 'Could not load WM exams data.');
    }
    error_log('[WM_EXAMS] load failed: ' . $e->getMessage());
}

$page_title = 'WM Exams';
$page_actions = '<div class="d-flex gap-2">'
              . '<button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#createModal">'
              . '<i class="bi bi-plus-lg me-1"></i>New WM exam</button>';
if ($studyYearReady && $canWrite) {
    $page_actions .= '<button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#syCreateModal">'
                  . '<i class="bi bi-calendar-range me-1"></i>Study years</button>';
}
$page_actions .= '</div>';

require_once __DIR__ . '/header.php';
?>
<?php $csrf = csrf_token(); ?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body">

        <form class="row g-2 align-items-end" method="get" action="wm_exams.php">
          <div class="col-12 col-md-4">
            <label class="form-label mb-1">Search</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control" name="q" value="<?= h_attr($q) ?>" placeholder="Name, year, or ID">
            </div>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Study year</label>
            <select class="form-select" name="year">
              <option value="">All</option>
              <?php foreach ($yearOptions as $yo): ?>
                <option value="<?= h_attr((string)$yo['id']) ?>" <?= ((string)$yo['id'] === $year) ? 'selected' : '' ?>>
                  <?= h((string)$yo['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Cycle</label>
            <select class="form-select" name="cycle">
              <option value="">All</option>
              <?php foreach ($cycleOptions as $co): ?>
                <?php $coStr = (string)$co; ?>
                <option value="<?= h_attr($coStr) ?>" <?= ($cycle !== '' && (int)$cycle === (int)$co) ? 'selected' : '' ?>>
                  <?= h($coStr) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label mb-1">Sort</label>
            <select class="form-select" name="sort">
              <option value="date_desc"  <?= $sort==='date_desc'?'selected':'' ?>>Date ↓</option>
              <option value="date_asc"   <?= $sort==='date_asc'?'selected':'' ?>>Date ↑</option>
              <option value="name_asc"   <?= $sort==='name_asc'?'selected':'' ?>>Name A-Z</option>
              <option value="name_desc"  <?= $sort==='name_desc'?'selected':'' ?>>Name Z-A</option>
              <option value="year_desc"  <?= $sort==='year_desc'?'selected':'' ?>>Year ↓</option>
              <option value="year_asc"   <?= $sort==='year_asc'?'selected':'' ?>>Year ↑</option>
              <option value="cycle_desc" <?= $sort==='cycle_desc'?'selected':'' ?>>Cycle ↓</option>
              <option value="cycle_asc"  <?= $sort==='cycle_asc'?'selected':'' ?>>Cycle ↑</option>
              <option value="id_desc"    <?= $sort==='id_desc'?'selected':'' ?>>ID ↓</option>
              <option value="id_asc"     <?= $sort==='id_asc'?'selected':'' ?>>ID ↑</option>
            </select>
          </div>

          <div class="col-12 col-md-1 d-grid">
            <button class="btn btn-outline-secondary" type="submit">
              <i class="bi bi-funnel me-1"></i>Apply
            </button>
          </div>

          <?php if ($q !== '' || $year !== '' || $cycle !== '' || $sort !== 'date_desc' || $page !== 1): ?>
            <div class="col-12">
              <a class="small text-decoration-none" href="wm_exams.php">
                <i class="bi bi-x-circle me-1"></i>Reset filters
              </a>
            </div>
          <?php endif; ?>
        </form>

        <hr class="my-3">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="text-muted small">
            Showing <span class="fw-semibold"><?= h((string)count($rows)) ?></span> of
            <span class="fw-semibold"><?= h((string)$totalRows) ?></span> WM exam(s)
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
                <th style="width: 140px;">Study year</th>
                <th style="width: 100px;">Cycle</th>
                <th style="width: 140px;">Date</th>
                <th class="text-end" style="width: 170px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No WM exams found.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $id = (int)$r['id'];
                    $syId = (int)$r['study_year_id'];
                    $ay = (string)$r['study_year_code'];
                    $cy = $r['cycle_no'] === null ? '' : (string)$r['cycle_no'];
                    $nm = (string)$r['exam_name'];
                    $dt = (string)$r['exam_date'];
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= h((string)$id) ?></td>
                    <td>
                      <div class="fw-semibold"><?= h($nm) ?></div>
                      <div class="small text-muted">
                        <i class="bi bi-calendar2-week me-1"></i><?= h($dt) ?>
                      </div>
                    </td>
                    <td><span class="badge text-bg-light border"><?= h($ay) ?></span></td>
                    <td>
                      <?php if ($cy === ''): ?>
                        <span class="text-muted">—</span>
                      <?php else: ?>
                        <span class="badge text-bg-primary">C<?= h($cy) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= h($dt) ?></td>
                    <td class="text-end">
                      <?php if ($canWrite): ?>
                        <button
                          class="btn btn-sm btn-outline-primary"
                          type="button"
                          data-bs-toggle="modal"
                          data-bs-target="#editModal"
                          data-id="<?= h_attr((string)$id) ?>"
                          data-sy-id="<?= h_attr((string)$syId) ?>"
                          data-cycle="<?= h_attr($cy) ?>"
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
                      <?php else: ?>
                        <span class="badge text-bg-light border">Read only</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php
          $baseQ = [
            'q' => $q,
            'year' => $year,
            'cycle' => $cycle,
            'sort' => $sort,
          ];
          $mkUrl = static function (int $p) use ($baseQ): string {
              return 'wm_exams.php?' . http_build_query($baseQ + ['page' => $p]);
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

<div class="card shadow-sm border-0 mt-3">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div>
        <h5 class="mb-1">Study Year Settings</h5>
        <div class="small text-muted">Manage study year windows used by WM Exams and filters.</div>
      </div>
      <?php if ($studyYearReady && $canWrite): ?>
        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#syCreateModal">
          <i class="bi bi-plus-lg me-1"></i>Add study year
        </button>
      <?php endif; ?>
    </div>

    <?php if (!$studyYearReady): ?>
      <div class="alert alert-warning mb-0">
        <div class="fw-semibold">study_year table not found.</div>
        <div class="small mt-1">Run <code>study_year.sql</code> first, then reload this page to manage study years here.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 140px;">Year code</th>
              <th style="width: 140px;">Start date</th>
              <th style="width: 140px;">End date</th>
              <th style="width: 120px;">Status</th>
              <th class="text-end" style="width: 260px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$studyYearRows): ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-3">No study years found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($studyYearRows as $sy): ?>
                <?php
                  $syId = (int)$sy['id'];
                  $syCode = (string)$sy['year_code'];
                  $syStart = (string)$sy['start_date'];
                  $syEnd = (string)$sy['end_date'];
                  $syActive = ((int)$sy['is_active'] === 1);
                ?>
                <tr>
                  <td><span class="badge text-bg-light border"><?= h($syCode) ?></span></td>
                  <td><?= h($syStart) ?></td>
                  <td><?= h($syEnd) ?></td>
                  <td>
                    <span class="badge <?= $syActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                      <?= $syActive ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <?php if ($canWrite): ?>
                      <button
                        class="btn btn-sm btn-outline-primary"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#syEditModal"
                        data-sy-id="<?= h_attr((string)$syId) ?>"
                        data-start-date="<?= h_attr($syStart) ?>"
                        data-end-date="<?= h_attr($syEnd) ?>"
                        data-is-active="<?= $syActive ? '1' : '0' ?>"
                      >
                        <i class="bi bi-pencil-square me-1"></i>Edit
                      </button>

                      <form class="d-inline" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>">
                        <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
                        <input type="hidden" name="action" value="sy_toggle">
                        <input type="hidden" name="sy_id" value="<?= h_attr((string)$syId) ?>">
                        <input type="hidden" name="to" value="<?= $syActive ? '0' : '1' ?>">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                          <?= $syActive ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </form>

                      <form class="d-inline" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>" onsubmit="return confirm('Delete study year <?= h_attr($syCode) ?>?');">
                        <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
                        <input type="hidden" name="action" value="sy_delete">
                        <input type="hidden" name="sy_id" value="<?= h_attr((string)$syId) ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">
                          <i class="bi bi-trash3 me-1"></i>Delete
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="badge text-bg-light border">Read only</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-lg me-1"></i>New WM exam</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label">Study year</label>
          <select class="form-select" name="study_year_id" required>
            <option value="">Select study year</option>
            <?php foreach ($studyYearRows as $sy): ?>
              <?php
                $syCode = (string)$sy['year_code'];
                $syStart = (string)$sy['start_date'];
                $syEnd = (string)$sy['end_date'];
                $syActive = ((int)$sy['is_active'] === 1);
              ?>
              <option value="<?= h_attr((string)$sy['id']) ?>">
                <?= h($syCode . ' (' . $syStart . ' to ' . $syEnd . ')' . ($syActive ? '' : ' [Inactive]')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Stored from <code>study_year.id</code>.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Cycle (optional)</label>
          <input class="form-control" type="number" name="cycle_no" min="1" max="60" placeholder="1">
          <div class="form-text">Optional weekly cycle number (1..60).</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Exam name</label>
          <input class="form-control" name="exam_name" required maxlength="120" placeholder="Week 1 Assessment">
        </div>

        <div class="mb-0">
          <label class="form-label">Exam date</label>
          <input class="form-control" type="date" name="exam_date" required>
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
    <form class="modal-content" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>Edit WM exam</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label">Study year</label>
          <select class="form-select" name="study_year_id" id="edit_sy_id" required>
            <option value="">Select study year</option>
            <?php foreach ($studyYearRows as $sy): ?>
              <?php
                $syCode = (string)$sy['year_code'];
                $syStart = (string)$sy['start_date'];
                $syEnd = (string)$sy['end_date'];
                $syActive = ((int)$sy['is_active'] === 1);
              ?>
              <option value="<?= h_attr((string)$sy['id']) ?>">
                <?= h($syCode . ' (' . $syStart . ' to ' . $syEnd . ')' . ($syActive ? '' : ' [Inactive]')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Cycle (optional)</label>
          <input class="form-control" type="number" name="cycle_no" id="edit_cycle" min="1" max="60">
        </div>

        <div class="mb-3">
          <label class="form-label">Exam name</label>
          <input class="form-control" name="exam_name" id="edit_name" required maxlength="120">
        </div>

        <div class="mb-0">
          <label class="form-label">Exam date</label>
          <input class="form-control" type="date" name="exam_date" id="edit_date" required>
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
    <form class="modal-content" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="del_id" value="">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-1"></i>Delete WM exam</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
          <i class="bi bi-exclamation-triangle-fill mt-1"></i>
          <div>
            You are about to delete: <span class="fw-semibold" id="del_name">—</span>
            <div class="small mt-1">
              Related WM results may be removed depending on foreign key rules.
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

<!-- Study Year Create Modal -->
<div class="modal fade" id="syCreateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="sy_create">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-calendar-plus me-1"></i>New study year</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Start date</label>
            <input class="form-control" type="date" name="start_date" required>
          </div>
          <div class="col-6">
            <label class="form-label">End date</label>
            <input class="form-control" type="date" name="end_date" required>
          </div>
        </div>
        <div class="form-text mt-2">Year code is auto-generated when range is Sep 1 to Aug 31.</div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="sy_create_active" name="is_active" value="1" checked>
          <label class="form-check-label" for="sy_create_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Study Year Edit Modal -->
<div class="modal fade" id="syEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="wm_exams.php?<?= h_attr(http_build_query(['q'=>$q,'year'=>$year,'cycle'=>$cycle,'sort'=>$sort,'page'=>$page])) ?>">
      <input type="hidden" name="csrf" value="<?= h_attr($csrf) ?>">
      <input type="hidden" name="action" value="sy_update">
      <input type="hidden" name="sy_id" id="sy_edit_id" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>Edit study year</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Start date</label>
            <input class="form-control" type="date" name="start_date" id="sy_edit_start_date" required>
          </div>
          <div class="col-6">
            <label class="form-label">End date</label>
            <input class="form-control" type="date" name="end_date" id="sy_edit_end_date" required>
          </div>
        </div>
        <div class="form-text mt-2">Year code is auto-generated when range is Sep 1 to Aug 31.</div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="sy_edit_is_active" name="is_active" value="1">
          <label class="form-check-label" for="sy_edit_is_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save changes</button>
      </div>
    </form>
  </div>
</div>

<?php
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

      document.getElementById('edit_id').value    = btn.getAttribute('data-id') || '';
      document.getElementById('edit_cycle').value = btn.getAttribute('data-cycle') || '';
      document.getElementById('edit_name').value  = btn.getAttribute('data-name') || '';
      document.getElementById('edit_date').value  = btn.getAttribute('data-date') || '';
      const editSy = document.getElementById('edit_sy_id');
      if (editSy) {
        editSy.value = btn.getAttribute('data-sy-id') || '';
      }
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

  const syEditModal = document.getElementById('syEditModal');
  if (syEditModal) {
    syEditModal.addEventListener('show.bs.modal', function (ev) {
      const btn = ev.relatedTarget;
      if (!btn) return;

      document.getElementById('sy_edit_id').value = btn.getAttribute('data-sy-id') || '';
      document.getElementById('sy_edit_start_date').value = btn.getAttribute('data-start-date') || '';
      document.getElementById('sy_edit_end_date').value = btn.getAttribute('data-end-date') || '';
      document.getElementById('sy_edit_is_active').checked = (btn.getAttribute('data-is-active') || '0') === '1';
    });
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
