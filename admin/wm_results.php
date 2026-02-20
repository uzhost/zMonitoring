<?php
// admin/wm_results.php - Enter WM results by class + exam + subject

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

// ----------------------- Helpers -----------------------
function wmr_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function wmr_redirect(array $q = []): void
{
    $base = '/admin/wm_results.php';
    $qs = $q ? ('?' . http_build_query($q)) : '';
    header('Location: ' . $base . $qs);
    exit;
}

function wmr_get_int(string $key): int
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    return (int)$v;
}

function wmr_get_str(string $key, int $maxLen = 80): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    return $v;
}

function wmr_norm_score(string $raw): ?string
{
    $raw = trim(str_replace(',', '.', $raw));
    if ($raw === '') return null;
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) return null;
    return $raw;
}

function wmr_table_exists(PDO $pdo, string $table): bool
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

function wmr_column_exists(PDO $pdo, string $table, string $column): bool
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

// ----------------------- State -----------------------
$classCode = wmr_get_str('class_code', 30);
$examId = wmr_get_int('exam_id');
$subjectId = wmr_get_int('subject_id');
$q = wmr_get_str('q', 60);

// ----------------------- Options -----------------------
$classOptions = [];
$examOptions = [];
$subjectOptions = [];
$hasPupilMiddle = false;
$hasPupilTrack = false;

try {
    $hasPupilMiddle = wmr_column_exists($pdo, 'pupils', 'middle_name');
    $hasPupilTrack = wmr_column_exists($pdo, 'pupils', 'track');

    $classOptions = $pdo->query("
        SELECT class_code, COUNT(*) AS cnt
        FROM pupils
        GROUP BY class_code
        ORDER BY class_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!wmr_table_exists($pdo, 'study_year') || !wmr_column_exists($pdo, 'wm_exams', 'study_year_id')) {
        wmr_flash('danger', 'Schema mismatch: wm_exams.study_year_id + study_year table are required.');
    } else {
        $hasExamCycle = wmr_column_exists($pdo, 'wm_exams', 'cycle_no');
        $cycleExpr = $hasExamCycle ? 'e.cycle_no' : 'NULL AS cycle_no';

        $examOptions = $pdo->query("
            SELECT e.id, e.study_year_id, sy.year_code AS study_year_code, $cycleExpr, e.exam_name, e.exam_date
            FROM wm_exams e
            INNER JOIN study_year sy ON sy.id = e.study_year_id
            ORDER BY e.exam_date DESC, e.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $subjectOptions = $pdo->query("
        SELECT id, code, name, max_points
        FROM wm_subjects
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    wmr_flash('danger', 'Failed to load WM results options.');
    error_log('[WM_RESULTS] options load failed: ' . $e->getMessage());
}

// Selected exam/subject metadata
$selectedExam = null;
$selectedSubject = null;
foreach ($examOptions as $e) {
    if ((int)$e['id'] === $examId) { $selectedExam = $e; break; }
}
foreach ($subjectOptions as $s) {
    if ((int)$s['id'] === $subjectId) { $selectedSubject = $s; break; }
}

$maxPoints = $selectedSubject ? (float)$selectedSubject['max_points'] : 100.0;

// ----------------------- Save (POST) -----------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');

    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'save_scores') {
        wmr_flash('danger', 'Unknown action.');
        wmr_redirect();
    }

    $classCode = trim((string)($_POST['class_code'] ?? ''));
    $examId = (int)($_POST['exam_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $q = trim((string)($_POST['q'] ?? ''));
    $clearEmpty = ((string)($_POST['clear_empty'] ?? '1') === '1');

    $returnQ = [
        'class_code' => $classCode,
        'exam_id' => $examId,
        'subject_id' => $subjectId,
        'q' => $q,
    ];

    if ($classCode === '' || $examId <= 0 || $subjectId <= 0) {
        wmr_flash('danger', 'Choose class, exam, and subject first.');
        wmr_redirect($returnQ);
    }

    $stExam = $pdo->prepare('SELECT id FROM wm_exams WHERE id = :id LIMIT 1');
    $stExam->execute([':id' => $examId]);
    if (!(int)$stExam->fetchColumn()) {
        wmr_flash('danger', 'Selected exam not found.');
        wmr_redirect($returnQ);
    }

    $stSub = $pdo->prepare('SELECT id, max_points FROM wm_subjects WHERE id = :id LIMIT 1');
    $stSub->execute([':id' => $subjectId]);
    $sub = $stSub->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        wmr_flash('danger', 'Selected subject not found.');
        wmr_redirect($returnQ);
    }
    $maxPoints = (float)$sub['max_points'];

    $pupilSql = "
        SELECT id
        FROM pupils
        WHERE class_code = :class_code
    ";
    $pupilParams = [':class_code' => $classCode];
    if ($q !== '') {
        $pupilSql .= " AND (surname LIKE :q OR name LIKE :q";
        if ($hasPupilMiddle) {
            $pupilSql .= " OR middle_name LIKE :q";
        }
        $pupilSql .= " OR student_login LIKE :q)";
        $pupilParams[':q'] = '%' . $q . '%';
    }
    $pupilSql .= ' ORDER BY surname, name, id';

    $stP = $pdo->prepare($pupilSql);
    $stP->execute($pupilParams);
    $pupilIds = array_map(static fn(array $r): int => (int)$r['id'], $stP->fetchAll(PDO::FETCH_ASSOC));

    if (!$pupilIds) {
        wmr_flash('warning', 'No pupils found for selected class/filter.');
        wmr_redirect($returnQ);
    }

    $scores = $_POST['scores'] ?? [];
    if (!is_array($scores)) $scores = [];

    $errors = [];
    $saved = 0;
    $deleted = 0;

    $up = $pdo->prepare("
        INSERT INTO wm_results (pupil_id, subject_id, exam_id, score)
        VALUES (:pupil_id, :subject_id, :exam_id, :score)
        AS new
        ON DUPLICATE KEY UPDATE
          score = new.score
    ");
    $del = $pdo->prepare("
        DELETE FROM wm_results
        WHERE pupil_id = :pupil_id AND subject_id = :subject_id AND exam_id = :exam_id
    ");

    try {
        $pdo->beginTransaction();

        foreach ($pupilIds as $pid) {
            $raw = $scores[(string)$pid] ?? ($scores[$pid] ?? '');
            $raw = is_string($raw) ? $raw : '';
            $norm = wmr_norm_score($raw);

            if ($norm === null) {
                if ($clearEmpty) {
                    $del->execute([
                        ':pupil_id' => $pid,
                        ':subject_id' => $subjectId,
                        ':exam_id' => $examId,
                    ]);
                    if ($del->rowCount() > 0) $deleted++;
                }
                continue;
            }

            $scoreVal = (float)$norm;
            if ($scoreVal < 0 || $scoreVal > $maxPoints) {
                $errors[] = "Pupil #{$pid}: score must be between 0 and {$maxPoints}.";
                if (count($errors) >= 10) break;
                continue;
            }

            $scoreDb = number_format($scoreVal, 2, '.', '');
            $up->execute([
                ':pupil_id' => $pid,
                ':subject_id' => $subjectId,
                ':exam_id' => $examId,
                ':score' => $scoreDb,
            ]);
            $saved++;
        }

        if ($errors) {
            $pdo->rollBack();
            wmr_flash('danger', 'Validation failed: ' . implode(' ', $errors));
            wmr_redirect($returnQ);
        }

        $pdo->commit();
        wmr_flash('success', "Saved scores: {$saved}. Cleared: {$deleted}.");
        wmr_redirect($returnQ);

    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        wmr_flash('danger', 'Failed to save scores due to a server error.');
        wmr_redirect($returnQ);
    }
}

// ----------------------- Load rows for entry grid -----------------------
$rows = [];
$selectedCount = 0;
$enteredCount = 0;

if ($classCode !== '' && $examId > 0 && $subjectId > 0 && $selectedExam && $selectedSubject) {
    $middleExpr = $hasPupilMiddle ? 'p.middle_name' : "'' AS middle_name";
    $trackExpr = $hasPupilTrack ? 'p.track' : "'' AS track";
    $sql = "
        SELECT
          p.id,
          p.surname,
          p.name,
          $middleExpr,
          p.student_login,
          $trackExpr,
          wr.score
        FROM pupils p
        LEFT JOIN wm_results wr
          ON wr.pupil_id = p.id
         AND wr.exam_id = :exam_id
         AND wr.subject_id = :subject_id
        WHERE p.class_code = :class_code
    ";
    $params = [
        ':exam_id' => $examId,
        ':subject_id' => $subjectId,
        ':class_code' => $classCode,
    ];

    if ($q !== '') {
        $sql .= " AND (p.surname LIKE :q OR p.name LIKE :q";
        if ($hasPupilMiddle) {
            $sql .= " OR p.middle_name LIKE :q";
        }
        $sql .= " OR p.student_login LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= " ORDER BY p.surname ASC, p.name ASC, p.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $selectedCount = count($rows);
    foreach ($rows as $r) {
        if ($r['score'] !== null) $enteredCount++;
    }
}

$page_title = 'WM Results Entry';
require_once __DIR__ . '/header.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <form method="get" action="/admin/wm_results.php" class="row g-2 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Class</label>
            <select class="form-select" name="class_code" required>
              <option value="">Choose class</option>
              <?php foreach ($classOptions as $c): ?>
                <?php $cc = (string)$c['class_code']; ?>
                <option value="<?= h_attr($cc) ?>" <?= $classCode === $cc ? 'selected' : '' ?>>
                  <?= h($cc) ?> (<?= (int)$c['cnt'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">WM Exam</label>
            <select class="form-select" name="exam_id" required>
              <option value="">Choose exam</option>
              <?php foreach ($examOptions as $e): ?>
                <?php
                  $eid = (int)$e['id'];
                  $ey = (string)$e['study_year_code'];
                  $cy = $e['cycle_no'] !== null ? ('C' . (int)$e['cycle_no']) : 'C-';
                  $en = (string)$e['exam_name'];
                  $ed = (string)$e['exam_date'];
                ?>
                <option value="<?= $eid ?>" <?= $examId === $eid ? 'selected' : '' ?>>
                  <?= h($ey . ' | ' . $cy . ' | ' . $en . ' (' . $ed . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">WM Subject</label>
            <select class="form-select" name="subject_id" required>
              <option value="">Choose subject</option>
              <?php foreach ($subjectOptions as $s): ?>
                <?php $sid = (int)$s['id']; ?>
                <option value="<?= $sid ?>" <?= $subjectId === $sid ? 'selected' : '' ?>>
                  <?= h((string)$s['name'] . ' [' . (string)$s['code'] . ']') ?> / <?= (int)$s['max_points'] ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-2">
            <label class="form-label">Search pupil</label>
            <input class="form-control" name="q" value="<?= h_attr($q) ?>" placeholder="name/login">
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-outline-secondary" type="submit">
              <i class="bi bi-funnel me-1"></i> Load
            </button>
            <a href="/admin/wm_results.php" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($classCode !== '' && $examId > 0 && $subjectId > 0): ?>
    <div class="col-12">
      <div class="card shadow-sm border-0">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="small text-muted">
            Class: <span class="fw-semibold"><?= h($classCode) ?></span> |
            Exam: <span class="fw-semibold"><?= h($selectedExam ? (string)$selectedExam['exam_name'] : '-') ?></span> |
            Subject: <span class="fw-semibold"><?= h($selectedSubject ? (string)$selectedSubject['name'] : '-') ?></span>
            <?php if ($selectedSubject): ?>
              | Max: <span class="fw-semibold"><?= (int)$selectedSubject['max_points'] ?></span>
            <?php endif; ?>
          </div>
          <div class="small text-muted">
            Pupils: <span class="fw-semibold"><?= $selectedCount ?></span> |
            Entered: <span class="fw-semibold"><?= $enteredCount ?></span>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <?php if ($classCode === '' || $examId <= 0 || $subjectId <= 0): ?>
          <div class="text-muted">Choose class, exam, and subject to start entering WM scores.</div>
        <?php elseif (!$rows): ?>
          <div class="text-muted">No pupils found for selected filters.</div>
        <?php else: ?>
          <form method="post" action="/admin/wm_results.php">
            <input type="hidden" name="csrf" value="<?= h_attr(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_scores">
            <input type="hidden" name="class_code" value="<?= h_attr($classCode) ?>">
            <input type="hidden" name="exam_id" value="<?= $examId ?>">
            <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
            <input type="hidden" name="q" value="<?= h_attr($q) ?>">

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="clearEmpty" name="clear_empty">
                <label class="form-check-label" for="clearEmpty">Clear existing scores when input is empty</label>
              </div>
              <?php if ($canWrite): ?>
                <button class="btn btn-primary" type="submit">
                  <i class="bi bi-save me-1"></i> Save Scores
                </button>
              <?php else: ?>
                <span class="text-muted small">Read-only</span>
              <?php endif; ?>
            </div>

            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="width:70px;">#</th>
                    <th>Pupil</th>
                    <th style="width:140px;">Login</th>
                    <th style="width:140px;">Track</th>
                    <th style="width:170px;" class="text-end">Score (0-<?= h((string)$maxPoints) ?>)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $i => $r): ?>
                    <?php
                      $pid = (int)$r['id'];
                      $full = trim((string)$r['surname'] . ' ' . (string)$r['name'] . ' ' . (string)($r['middle_name'] ?? ''));
                      $scoreVal = $r['score'] !== null ? number_format((float)$r['score'], 2, '.', '') : '';
                    ?>
                    <tr>
                      <td class="text-muted"><?= $i + 1 ?></td>
                      <td class="fw-semibold"><?= h($full !== '' ? $full : ('Pupil #' . $pid)) ?></td>
                      <td><code><?= h((string)$r['student_login']) ?></code></td>
                      <td><?= h((string)$r['track']) ?></td>
                      <td class="text-end">
                        <input
                          class="form-control form-control-sm text-end"
                          name="scores[<?= $pid ?>]"
                          value="<?= h_attr($scoreVal) ?>"
                          placeholder="0.00"
                          <?= $canWrite ? '' : 'disabled' ?>
                          inputmode="decimal"
                        >
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if ($canWrite): ?>
              <div class="mt-3 d-flex justify-content-end">
                <button class="btn btn-primary" type="submit">
                  <i class="bi bi-save me-1"></i> Save Scores
                </button>
              </div>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
