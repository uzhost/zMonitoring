<?php
// admin/otm_major.php - Assign OTM Major 1 / Major 2 per pupil from otm_subjects

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);
const OTMM_DRAFT_SESSION_KEY = 'otm_major_form_draft_v1';

function otmm_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function otmm_redirect(array $q = []): void
{
    $url = '/admin/otm_major.php';
    if ($q) $url .= '?' . http_build_query($q);
    header('Location: ' . $url);
    exit;
}

function otmm_get_str(string $key, int $maxLen = 120): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    return $v;
}

function otmm_get_int(string $key, int $min = 0, int $max = PHP_INT_MAX): int
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    $n = (int)$v;
    if ($n < $min || $n > $max) return 0;
    return $n;
}

function otmm_table_exists(PDO $pdo, string $table): bool
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

function otmm_column_exists(PDO $pdo, string $table, string $column): bool
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

function otmm_parse_subject_id(mixed $raw): array
{
    $s = is_string($raw) ? trim($raw) : (is_numeric($raw) ? (string)$raw : '');
    if ($s === '') return ['blank' => true, 'value' => null, 'error' => null];
    if (!preg_match('/^\d+$/', $s)) {
        return ['blank' => false, 'value' => null, 'error' => 'invalid subject id'];
    }
    $id = (int)$s;
    if ($id <= 0) {
        return ['blank' => false, 'value' => null, 'error' => 'invalid subject id'];
    }
    return ['blank' => false, 'value' => $id, 'error' => null];
}

function otmm_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}

function otmm_major_pair_code(?array $major1, ?array $major2): string
{
    $a = $major1 ? otmm_initial((string)($major1['name'] ?? '')) : '';
    $b = $major2 ? otmm_initial((string)($major2['name'] ?? '')) : '';
    $code = $a . $b;
    return $code !== '' ? $code : '—';
}

function otmm_store_draft_state(string $classCode, array $rowValues, array $rowErrors): void
{
    if ($classCode === '') return;
    if (!isset($_SESSION[OTMM_DRAFT_SESSION_KEY]) || !is_array($_SESSION[OTMM_DRAFT_SESSION_KEY])) {
        $_SESSION[OTMM_DRAFT_SESSION_KEY] = [];
    }
    $_SESSION[OTMM_DRAFT_SESSION_KEY][$classCode] = [
        'ts' => time(),
        'rows' => $rowValues,
        'errors' => $rowErrors,
    ];
}

function otmm_take_draft_state(string $classCode): array
{
    if ($classCode === '') return ['rows' => [], 'errors' => []];
    $all = $_SESSION[OTMM_DRAFT_SESSION_KEY] ?? null;
    if (!is_array($all) || !isset($all[$classCode]) || !is_array($all[$classCode])) {
        return ['rows' => [], 'errors' => []];
    }
    $state = $all[$classCode];
    unset($_SESSION[OTMM_DRAFT_SESSION_KEY][$classCode]);
    if (empty($_SESSION[OTMM_DRAFT_SESSION_KEY])) unset($_SESSION[OTMM_DRAFT_SESSION_KEY]);
    return [
        'rows' => (isset($state['rows']) && is_array($state['rows'])) ? $state['rows'] : [],
        'errors' => (isset($state['errors']) && is_array($state['errors'])) ? $state['errors'] : [],
    ];
}

function otmm_clear_draft_state(string $classCode): void
{
    if ($classCode === '') return;
    if (!isset($_SESSION[OTMM_DRAFT_SESSION_KEY]) || !is_array($_SESSION[OTMM_DRAFT_SESSION_KEY])) return;
    unset($_SESSION[OTMM_DRAFT_SESSION_KEY][$classCode]);
    if (empty($_SESSION[OTMM_DRAFT_SESSION_KEY])) unset($_SESSION[OTMM_DRAFT_SESSION_KEY]);
}

// ----------------------- State -----------------------
$classCode = otmm_get_str('class_code', 30);
$loadRequested = isset($_GET['load_grid']) && (string)$_GET['load_grid'] === '1';

// ----------------------- Options / Schema -----------------------
$classOptions = [];
$subjectOptions = [];
$subjectMap = [];
$rows = [];
$assignedCount = 0;
$draftRowsByPid = [];
$rowErrorsByPid = [];
$hasPupilMiddle = false;

$schemaOtmMajor = false;
$schemaOtmSubjects = false;
$schemaVersionOk = false;

try {
    $schemaOtmMajor = otmm_table_exists($pdo, 'otm_major');
    $schemaOtmSubjects = otmm_table_exists($pdo, 'otm_subjects');
    $hasPupilMiddle = otmm_column_exists($pdo, 'pupils', 'middle_name');

    if ($schemaOtmMajor) {
        $schemaVersionOk =
            otmm_column_exists($pdo, 'otm_major', 'pupil_id') &&
            otmm_column_exists($pdo, 'otm_major', 'major1_subject_id') &&
            otmm_column_exists($pdo, 'otm_major', 'major2_subject_id');
    }

    $classOptions = $pdo->query("
        SELECT class_code, COUNT(*) AS cnt
        FROM pupils
        WHERE class_code IS NOT NULL AND class_code <> ''
        GROUP BY class_code
        ORDER BY class_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($schemaOtmSubjects) {
        $subjectOptions = $pdo->query("
            SELECT id, code, name, is_active, sort_order
            FROM otm_subjects
            ORDER BY is_active DESC, sort_order ASC, name ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($subjectOptions as $s) {
            $subjectMap[(int)$s['id']] = $s;
        }
    }
} catch (Throwable $e) {
    otmm_flash('danger', 'Failed to load OTM major page data.');
    error_log('[OTM_MAJOR] options load failed: ' . $e->getMessage());
}

// ----------------------- Save (POST) -----------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');

    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    if (!$schemaOtmSubjects) {
        otmm_flash('danger', 'Table otm_subjects does not exist. Run otm_subjects.sql first.');
        otmm_redirect();
    }
    if (!$schemaOtmMajor) {
        otmm_flash('danger', 'Table otm_major does not exist. Run otm_major.sql first.');
        otmm_redirect();
    }
    if (!$schemaVersionOk) {
        otmm_flash('danger', 'Table otm_major exists but does not have the expected columns.');
        otmm_redirect();
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'save_majors') {
        otmm_flash('danger', 'Unknown action.');
        otmm_redirect();
    }

    $classCode = trim((string)($_POST['class_code'] ?? ''));
    $returnQ = ['class_code' => $classCode, 'load_grid' => '1'];

    if ($classCode === '') {
        otmm_flash('danger', 'Class is required.');
        otmm_redirect($returnQ);
    }

    $pupilSql = "
        SELECT id
        FROM pupils
        WHERE class_code = :class_code
    ";
    $pupilParams = [':class_code' => $classCode];
    $pupilSql .= " ORDER BY surname ASC, name ASC, id ASC";

    $stP = $pdo->prepare($pupilSql);
    $stP->execute($pupilParams);
    $pupilIds = array_map(static fn(array $r): int => (int)$r['id'], $stP->fetchAll(PDO::FETCH_ASSOC));

    if (!$pupilIds) {
        otmm_flash('warning', 'No pupils found for the selected class/filter.');
        otmm_redirect($returnQ);
    }

    // Valid OTM subjects (allow active/inactive existing IDs)
    $validSubjectIds = [];
    $stValid = $pdo->query("SELECT id FROM otm_subjects");
    foreach (($stValid ? $stValid->fetchAll(PDO::FETCH_COLUMN) : []) as $sid) {
        $validSubjectIds[(int)$sid] = true;
    }

    $postedRows = $_POST['rows'] ?? [];
    if (!is_array($postedRows)) $postedRows = [];

    $up = $pdo->prepare("
        INSERT INTO otm_major (
            pupil_id, major1_subject_id, major2_subject_id, is_active, created_by, updated_by
        )
        VALUES (
            :pupil_id, :major1_subject_id, :major2_subject_id, 1, :created_by, :updated_by
        ) AS new
        ON DUPLICATE KEY UPDATE
            major1_subject_id = new.major1_subject_id,
            major2_subject_id = new.major2_subject_id,
            is_active = 1,
            updated_by = new.updated_by
    ");

    $saved = 0;
    $skipped = 0;
    $errorRows = 0;
    $rowFieldErrors = [];
    $draftRows = [];
    $adminId = admin_id();
    foreach ($pupilIds as $pid) {
        $r = $postedRows[(string)$pid] ?? ($postedRows[$pid] ?? []);
        if (!is_array($r)) $r = [];

        $rawM1 = isset($r['major1_subject_id']) ? (string)$r['major1_subject_id'] : '';
        $rawM2 = isset($r['major2_subject_id']) ? (string)$r['major2_subject_id'] : '';
        $m1 = otmm_parse_subject_id($rawM1);
        $m2 = otmm_parse_subject_id($rawM2);

        $fieldErrs = [];
        if ($m1['error'] !== null) $fieldErrs['major1_subject_id'] = 'Invalid major 1.';
        if ($m2['error'] !== null) $fieldErrs['major2_subject_id'] = 'Invalid major 2.';

        if (!$fieldErrs) {
            if ($m1['blank'] && $m2['blank']) {
                $skipped++;
                continue;
            }

            if ($m1['blank'] xor $m2['blank']) {
                $msg = 'Select both majors or leave both empty.';
                $fieldErrs['major1_subject_id'] = $msg;
                $fieldErrs['major2_subject_id'] = $msg;
            } else {
                $m1Id = (int)$m1['value'];
                $m2Id = (int)$m2['value'];

                if (!isset($validSubjectIds[$m1Id])) {
                    $fieldErrs['major1_subject_id'] = 'Major 1 subject not found.';
                }
                if (!isset($validSubjectIds[$m2Id])) {
                    $fieldErrs['major2_subject_id'] = 'Major 2 subject not found.';
                }
                if ($m1Id === $m2Id) {
                    $msg = 'Major 1 and Major 2 must be different.';
                    $fieldErrs['major1_subject_id'] = $msg;
                    $fieldErrs['major2_subject_id'] = $msg;
                }

                if (!$fieldErrs) {
                    try {
                        $up->execute([
                            ':pupil_id' => $pid,
                            ':major1_subject_id' => $m1Id,
                            ':major2_subject_id' => $m2Id,
                            ':created_by' => ($adminId > 0 ? $adminId : null),
                            ':updated_by' => ($adminId > 0 ? $adminId : null),
                        ]);
                        $saved++;
                    } catch (Throwable $e) {
                        $msg = 'Save failed. Try again.';
                        $fieldErrs['major1_subject_id'] = $msg;
                        $fieldErrs['major2_subject_id'] = $msg;
                        error_log('[OTM_MAJOR] row save failed for pupil ' . $pid . ': ' . $e->getMessage());
                    }
                }
            }
        }

        if ($fieldErrs) {
            $errorRows++;
            $rowFieldErrors[$pid] = $fieldErrs;
            $draftRows[$pid] = [
                'major1_subject_id' => $rawM1,
                'major2_subject_id' => $rawM2,
            ];
        }
    }

    if ($errorRows > 0) {
        otmm_store_draft_state($classCode, $draftRows, $rowFieldErrors);
        $type = $saved > 0 ? 'warning' : 'danger';
        otmm_flash($type, "Saved: {$saved}. Skipped (blank): {$skipped}. Rows with errors: {$errorRows}. Fix highlighted selectors and save again.");
        otmm_redirect($returnQ);
    }

    otmm_clear_draft_state($classCode);
    otmm_flash('success', "Saved major selections: {$saved}. Skipped (blank): {$skipped}.");
    otmm_redirect($returnQ);
}

// ----------------------- Load grid -----------------------
$loadContextReady = (
    $schemaOtmSubjects &&
    $schemaOtmMajor &&
    $schemaVersionOk &&
    $classCode !== ''
);

$loadContextIssues = [];
if ($loadRequested && !$loadContextReady) {
    if (!$schemaOtmSubjects) $loadContextIssues[] = 'otm_subjects table is missing';
    if (!$schemaOtmMajor) $loadContextIssues[] = 'otm_major table is missing';
    elseif (!$schemaVersionOk) $loadContextIssues[] = 'otm_major schema is outdated';
    if ($classCode === '') $loadContextIssues[] = 'Class';
}

if ($loadContextReady) {
    $draftState = otmm_take_draft_state($classCode);
    $draftRowsByPid = is_array($draftState['rows'] ?? null) ? $draftState['rows'] : [];
    $rowErrorsByPid = is_array($draftState['errors'] ?? null) ? $draftState['errors'] : [];
}

if ($loadContextReady) {
    $middleExpr = $hasPupilMiddle ? 'p.middle_name' : "'' AS middle_name";

    $sql = "
        SELECT
          p.id,
          p.surname,
          p.name,
          {$middleExpr},
          om.major1_subject_id,
          om.major2_subject_id,
          om.id AS otm_major_row_id,
          om.is_active
        FROM pupils p
        LEFT JOIN otm_major om
          ON om.pupil_id = p.id
        WHERE p.class_code = :class_code
    ";
    $params = [':class_code' => $classCode];

    $sql .= " ORDER BY p.surname ASC, p.name ASC, p.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        if (!empty($r['otm_major_row_id'])) $assignedCount++;
    }
}

$page_title = 'OTM Major';
require __DIR__ . '/header.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-diagram-3 me-2"></i>OTM Major Assignment</div>
            <div class="small text-muted">Assign Major 1 and Major 2 for each pupil from the OTM subject list.</div>
          </div>
        </div>

        <?php if (!$schemaOtmSubjects || !$schemaOtmMajor || !$schemaVersionOk): ?>
          <div class="alert alert-warning mb-3">
            <?php if (!$schemaOtmSubjects): ?>
              <div class="fw-semibold">Table `otm_subjects` was not found.</div>
              <div class="small">Run <code>otm_subjects.sql</code> first.</div>
            <?php elseif (!$schemaOtmMajor): ?>
              <div class="fw-semibold">Table `otm_major` was not found.</div>
              <div class="small">Run <code>otm_major.sql</code> after creating <code>otm_subjects</code>.</div>
            <?php else: ?>
              <div class="fw-semibold">`otm_major` schema is outdated.</div>
              <div class="small">Recreate or migrate the table to include <code>major1_subject_id</code> and <code>major2_subject_id</code>.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($loadContextIssues): ?>
          <div class="alert alert-warning mb-3">
            <div class="fw-semibold">Grid not loaded yet.</div>
            <div class="small">Please complete/fix: <?= h(implode(', ', $loadContextIssues)) ?>.</div>
          </div>
        <?php endif; ?>

        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label">Class <span class="text-danger">*</span></label>
            <select name="class_code" class="form-select" required>
              <option value="">Select class</option>
              <?php foreach ($classOptions as $c): $cc = (string)$c['class_code']; ?>
                <option value="<?= h_attr($cc) ?>" <?= $classCode === $cc ? 'selected' : '' ?>>
                  <?= h($cc) ?> (<?= (int)$c['cnt'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary" name="load_grid" value="1">
              <i class="bi bi-search me-1"></i>Load Grid
            </button>
            <a class="btn btn-outline-secondary" href="otm_major.php">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-table me-2"></i>Entry Grid</div>
            <div class="small text-muted">
              <?php if ($rows): ?>
                <?= count($rows) ?> pupils loaded, <?= (int)$assignedCount ?> already assigned.
              <?php else: ?>
                Choose a class and click "Load Grid".
              <?php endif; ?>
            </div>
          </div>
          <div class="small text-muted">
            Available OTM subjects: <span class="fw-semibold"><?= count($subjectOptions) ?></span>
          </div>
        </div>

        <?php if (!$schemaOtmSubjects || !$schemaOtmMajor || !$schemaVersionOk): ?>
          <div class="text-muted">Schema not ready yet.</div>
        <?php elseif (!$loadContextReady): ?>
          <div class="alert alert-info mb-0">
            Select <strong>Class</strong> and click <strong>Load Grid</strong> to assign majors.
          </div>
        <?php elseif (!$rows): ?>
          <div class="alert alert-warning mb-0">No pupils found for the selected class/filter.</div>
        <?php elseif (!$subjectOptions): ?>
          <div class="alert alert-warning mb-0">No OTM subjects found. Insert rows into <code>otm_subjects</code> first.</div>
        <?php else: ?>
          <form method="post">
            <?= csrf_field('csrf') ?>
            <input type="hidden" name="action" value="save_majors">
            <input type="hidden" name="class_code" value="<?= h_attr($classCode) ?>">

            <div class="d-flex flex-wrap gap-3 mb-2">
              <div class="small">
                <span class="text-muted">Class:</span> <span class="fw-semibold"><?= h($classCode) ?></span>
              </div>
            </div>

            <div class="alert alert-light border small">
              Select both majors for each pupil. Major 1 and Major 2 must be different. If both selectors are empty, that pupil is skipped (no update).
            </div>

            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width:70px">ID</th>
                    <th>Pupil</th>
                    <th style="width:110px">Current</th>
                    <th style="width:300px">Major 1</th>
                    <th style="width:300px">Major 2</th>
                    <th style="width:80px">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $pid = (int)$r['id'];
                      $fullName = trim((string)$r['surname'] . ' ' . (string)$r['name']);
                      $draftRow = $draftRowsByPid[$pid] ?? [];
                      $rowErr = $rowErrorsByPid[$pid] ?? [];
                      $m1Raw = array_key_exists('major1_subject_id', $draftRow) ? (string)$draftRow['major1_subject_id'] : (string)((int)($r['major1_subject_id'] ?? 0));
                      $m2Raw = array_key_exists('major2_subject_id', $draftRow) ? (string)$draftRow['major2_subject_id'] : (string)((int)($r['major2_subject_id'] ?? 0));
                      $m1Id = (preg_match('/^\d+$/', $m1Raw) && (int)$m1Raw > 0) ? (int)$m1Raw : 0;
                      $m2Id = (preg_match('/^\d+$/', $m2Raw) && (int)$m2Raw > 0) ? (int)$m2Raw : 0;
                      $m1 = $subjectMap[$m1Id] ?? null;
                      $m2 = $subjectMap[$m2Id] ?? null;
                      $pairCode = otmm_major_pair_code($m1, $m2);
                      $pairTitle = trim((string)($m1['name'] ?? '') . (($m1 && $m2) ? ' / ' : '') . (string)($m2['name'] ?? ''));
                      $m1Invalid = isset($rowErr['major1_subject_id']);
                      $m2Invalid = isset($rowErr['major2_subject_id']);
                      $rowClass = ($m1Invalid || $m2Invalid) ? 'table-warning' : '';
                    ?>
                    <tr class="<?= $rowClass ?>">
                      <td class="text-muted"><?= $pid ?></td>
                      <td><div class="fw-semibold"><?= h($fullName) ?></div></td>
                      <td>
                        <span class="badge text-bg-light border text-dark" title="<?= h_attr($pairTitle !== '' ? $pairTitle : 'No majors assigned') ?>">
                          <?= h($pairCode) ?>
                        </span>
                      </td>
                      <td>
                        <select name="rows[<?= $pid ?>][major1_subject_id]" class="form-select form-select-sm<?= $m1Invalid ? ' is-invalid' : '' ?>">
                          <option value="">Select major 1</option>
                          <?php foreach ($subjectOptions as $s): $sid = (int)$s['id']; ?>
                            <?php
                              $label = (string)$s['name'];
                              if ((int)$s['is_active'] !== 1) $label .= ' [inactive]';
                            ?>
                            <option value="<?= $sid ?>" <?= ($m1Id === $sid) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php if ($m1Invalid): ?>
                          <div class="invalid-feedback d-block"><?= h($rowErr['major1_subject_id']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <select name="rows[<?= $pid ?>][major2_subject_id]" class="form-select form-select-sm<?= $m2Invalid ? ' is-invalid' : '' ?>">
                          <option value="">Select major 2</option>
                          <?php foreach ($subjectOptions as $s): $sid = (int)$s['id']; ?>
                            <?php
                              $label = (string)$s['name'];
                              if ((int)$s['is_active'] !== 1) $label .= ' [inactive]';
                            ?>
                            <option value="<?= $sid ?>" <?= ($m2Id === $sid) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php if ($m2Invalid): ?>
                          <div class="invalid-feedback d-block"><?= h($rowErr['major2_subject_id']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($r['otm_major_row_id'])): ?>
                          <span class="badge <?= ((int)($r['is_active'] ?? 1) === 1) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= ((int)($r['is_active'] ?? 1) === 1) ? 'Saved' : 'Off' ?>
                          </span>
                        <?php else: ?>
                          <span class="badge text-bg-light border text-dark">New</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary"<?= !$canWrite ? ' disabled' : '' ?>>
                <i class="bi bi-save me-1"></i>Save Major Assignments
              </button>
              <a class="btn btn-outline-secondary" href="<?= h_attr('otm_major.php?' . http_build_query(['class_code' => $classCode, 'load_grid' => '1'])) ?>">
                <i class="bi bi-arrow-repeat me-1"></i>Reload
              </a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
