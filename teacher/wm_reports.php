<?php
// teacher/wm_reports.php - Read-only WM results report for teacher portal

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_guard.php';

function wtr_get_int(string $key): int
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    return (int)$v;
}

function wtr_get_str(string $key, int $maxLen = 80): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function wtr_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function wtr_table_exists(PDO $pdo, string $table): bool
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

function wtr_column_exists(PDO $pdo, string $table, string $column): bool
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

$classCode = wtr_get_str('class_code', 30);
$studyYearId = wtr_get_int('study_year_id');
$examId = wtr_get_int('exam_id');
$subjectId = wtr_get_int('subject_id');
$q = wtr_get_str('q', 60);
$sort = wtr_get_str('sort', 20);
$page = max(1, wtr_get_int('page'));
$perPage = 50;

$sortMap = [
    'name_asc' => 'p.surname ASC, p.name ASC, p.id ASC',
    'name_desc' => 'p.surname DESC, p.name DESC, p.id DESC',
    'score_desc' => 'wr.score DESC, p.surname ASC, p.name ASC',
    'score_asc' => 'wr.score ASC, p.surname ASC, p.name ASC',
    'class_asc' => 'p.class_code ASC, p.surname ASC, p.name ASC',
    'class_desc' => 'p.class_code DESC, p.surname ASC, p.name ASC',
    'latest' => 'wr.id DESC',
];
if (!isset($sortMap[$sort])) {
    $sort = 'name_asc';
}
$orderBy = $sortMap[$sort];

$classOptions = [];
$yearOptions = [];
$examOptions = [];
$subjectOptions = [];
$selectedYear = null;
$selectedExam = null;
$selectedSubject = null;
$rows = [];
$totalRows = 0;
$totalPages = 1;
$summary = ['total' => 0, 'avg' => null, 'min' => null, 'max' => null];

$schemaReady = false;
$hasPupilTrack = false;
$hasResultCreated = false;

try {
    $schemaReady = wtr_table_exists($pdo, 'wm_results')
        && wtr_table_exists($pdo, 'wm_exams')
        && wtr_table_exists($pdo, 'wm_subjects')
        && wtr_table_exists($pdo, 'study_year')
        && wtr_column_exists($pdo, 'wm_exams', 'study_year_id');

    if (!$schemaReady) {
        wtr_flash('danger', 'Schema mismatch: wm_results/wm_exams/wm_subjects/study_year with wm_exams.study_year_id is required.');
    }

    $hasPupilTrack = wtr_column_exists($pdo, 'pupils', 'track');
    $hasResultCreated = wtr_column_exists($pdo, 'wm_results', 'created_at');

    $classOptions = $pdo->query("
        SELECT class_code, COUNT(*) AS cnt
        FROM pupils
        GROUP BY class_code
        ORDER BY class_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($schemaReady) {
        $yearOptions = $pdo->query("
            SELECT id, year_code, start_date, end_date, is_active
            FROM study_year
            ORDER BY start_date DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($yearOptions as $y) {
            if ((int)$y['id'] === $studyYearId) {
                $selectedYear = $y;
                break;
            }
        }
        if ($studyYearId > 0 && !$selectedYear) {
            wtr_flash('warning', 'Selected year was not found. Showing all years.');
            $studyYearId = 0;
        }

        $hasExamCycle = wtr_column_exists($pdo, 'wm_exams', 'cycle_no');
        $cycleExpr = $hasExamCycle ? 'e.cycle_no' : 'NULL AS cycle_no';
        $examSql = "
            SELECT e.id, e.study_year_id, sy.year_code AS study_year_code, $cycleExpr, e.exam_name, e.exam_date
            FROM wm_exams e
            INNER JOIN study_year sy ON sy.id = e.study_year_id
        ";
        if ($studyYearId > 0) {
            $examSql .= " WHERE e.study_year_id = :sy_id";
        }
        $examSql .= " ORDER BY e.exam_date DESC, e.id DESC";
        $stExamOpt = $pdo->prepare($examSql);
        if ($studyYearId > 0) {
            $stExamOpt->bindValue(':sy_id', $studyYearId, PDO::PARAM_INT);
        }
        $stExamOpt->execute();
        $examOptions = $stExamOpt->fetchAll(PDO::FETCH_ASSOC);

        $subjectOptions = $pdo->query("
            SELECT id, code, name, max_points
            FROM wm_subjects
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($examOptions as $e) {
        if ((int)$e['id'] === $examId) {
            $selectedExam = $e;
            break;
        }
    }

    if ($subjectId > 0) {
        $stSub = $pdo->prepare("
            SELECT id, code, name, max_points
            FROM wm_subjects
            WHERE id = :id
            LIMIT 1
        ");
        $stSub->execute([':id' => $subjectId]);
        $selectedSubject = $stSub->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$selectedSubject) {
            $subjectId = 0;
        }
    }

    if ($schemaReady) {
        $where = [];
        $params = [];

        if ($studyYearId > 0) {
            $where[] = 'e.study_year_id = :study_year_id';
            $params[':study_year_id'] = $studyYearId;
        }

        if ($examId > 0) {
            if ($selectedExam) {
                $where[] = 'wr.exam_id = :exam_id';
                $params[':exam_id'] = $examId;
            } else {
                wtr_flash('warning', 'Selected exam was not found. Showing all exams.');
                $examId = 0;
            }
        }
        if ($subjectId > 0 && $selectedSubject) {
            $where[] = 'wr.subject_id = :subject_id';
            $params[':subject_id'] = $subjectId;
        }

        if ($classCode !== '') {
            $where[] = 'p.class_code = :class_code';
            $params[':class_code'] = $classCode;
        }

        if ($q !== '') {
            $where[] = '(p.surname LIKE :q OR p.name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $trackExpr = $hasPupilTrack ? 'p.track' : "'' AS track";
        $createdExpr = $hasResultCreated ? 'wr.created_at' : 'NULL AS created_at';

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM wm_results wr
            INNER JOIN pupils p ON p.id = wr.pupil_id
            INNER JOIN wm_exams e ON e.id = wr.exam_id
            INNER JOIN study_year sy ON sy.id = e.study_year_id
            INNER JOIN wm_subjects ws ON ws.id = wr.subject_id
            $whereSql
        ");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sumStmt = $pdo->prepare("
            SELECT COUNT(*) AS total_rows, AVG(wr.score) AS avg_score, MIN(wr.score) AS min_score, MAX(wr.score) AS max_score
            FROM wm_results wr
            INNER JOIN pupils p ON p.id = wr.pupil_id
            INNER JOIN wm_exams e ON e.id = wr.exam_id
            INNER JOIN study_year sy ON sy.id = e.study_year_id
            INNER JOIN wm_subjects ws ON ws.id = wr.subject_id
            $whereSql
        ");
        $sumStmt->execute($params);
        $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary = [
            'total' => (int)($sumRow['total_rows'] ?? 0),
            'avg' => isset($sumRow['avg_score']) ? (float)$sumRow['avg_score'] : null,
            'min' => isset($sumRow['min_score']) ? (float)$sumRow['min_score'] : null,
            'max' => isset($sumRow['max_score']) ? (float)$sumRow['max_score'] : null,
        ];

        $listStmt = $pdo->prepare("
            SELECT
              wr.id,
              wr.score,
              $createdExpr,
              p.class_code,
              p.id AS pupil_id,
              p.surname,
              p.name,
              $trackExpr,
              e.exam_name,
              e.exam_date,
              e.cycle_no,
              sy.year_code AS study_year_code,
              ws.name AS subject_name,
              ws.code AS subject_code,
              ws.max_points
            FROM wm_results wr
            INNER JOIN pupils p ON p.id = wr.pupil_id
            INNER JOIN wm_exams e ON e.id = wr.exam_id
            INNER JOIN study_year sy ON sy.id = e.study_year_id
            INNER JOIN wm_subjects ws ON ws.id = wr.subject_id
            $whereSql
            ORDER BY $orderBy
            LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) {
            $listStmt->bindValue($k, $v);
        }
        $listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $listStmt->execute();
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    wtr_flash('danger', 'Failed to load WM reports.');
    error_log('[TEACHER_WM_REPORTS] load failed: ' . $e->getMessage());
}

$page_title = 'WM Reports';
$page_subtitle = 'Read-only weekly monitoring results';
require_once __DIR__ . '/header.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <div class="small text-uppercase text-muted fw-semibold">Filters</div>
          <div class="small text-muted">Loaded rows: <span class="fw-semibold"><?= h((string)$totalRows) ?></span></div>
        </div>
        <form method="get" action="/teacher/wm_reports.php" class="row g-3 align-items-end">
          <div class="col-12 col-lg-2">
            <label class="form-label small text-uppercase text-muted fw-semibold mb-1">Year</label>
            <select class="form-select shadow-sm" name="study_year_id">
              <option value="">All years</option>
              <?php foreach ($yearOptions as $yo): ?>
                <?php
                  $yid = (int)$yo['id'];
                  $yl = (string)$yo['year_code'];
                ?>
                <option value="<?= $yid ?>" <?= $studyYearId === $yid ? 'selected' : '' ?>>
                  <?= h($yl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-lg-1">
            <label class="form-label small text-uppercase text-muted fw-semibold mb-1">Class</label>
            <select class="form-select shadow-sm" name="class_code">
              <option value="">All classes</option>
              <?php foreach ($classOptions as $c): ?>
                <?php $cc = (string)$c['class_code']; ?>
                <option value="<?= h_attr($cc) ?>" <?= $classCode === $cc ? 'selected' : '' ?>>
                  <?= h($cc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-lg-2">
            <label class="form-label small text-uppercase text-muted fw-semibold mb-1">WM Exam</label>
            <select class="form-select shadow-sm" name="exam_id">
              <option value="">All exams</option>
              <?php foreach ($examOptions as $e): ?>
                <?php
                  $eid = (int)$e['id'];
                  $ey = (string)$e['study_year_code'];
                  $cy = $e['cycle_no'] !== null ? ('C' . (int)$e['cycle_no']) : 'C-';
                  $en = (string)$e['exam_name'];
                  $ed = (string)$e['exam_date'];
                ?>
                <option value="<?= $eid ?>" <?= $examId === $eid ? 'selected' : '' ?>>
                  <?= h($cy . ' | ' . $en . ' (' . $ed . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-lg-2">
            <label class="form-label small text-uppercase text-muted fw-semibold mb-1">WM Subject</label>
            <select class="form-select shadow-sm" name="subject_id">
              <option value="">All subjects</option>
              <?php foreach ($subjectOptions as $s): ?>
                <?php $sid = (int)$s['id']; ?>
                <option value="<?= $sid ?>" <?= $subjectId === $sid ? 'selected' : '' ?>>
                  <?= h((string)$s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-lg-2">
            <label class="form-label small text-uppercase text-muted fw-semibold mb-1">Search pupil</label>
            <input class="form-control shadow-sm" name="q" value="<?= h_attr($q) ?>" placeholder="surname or name">
          </div>

          <div class="col-6 col-lg-2">
            <label class="form-label small text-uppercase text-muted fw-semibold mb-1">Sort</label>
            <select class="form-select shadow-sm" name="sort">
              <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
              <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
              <option value="score_desc" <?= $sort === 'score_desc' ? 'selected' : '' ?>>Score desc</option>
              <option value="score_asc" <?= $sort === 'score_asc' ? 'selected' : '' ?>>Score asc</option>
              <option value="class_asc" <?= $sort === 'class_asc' ? 'selected' : '' ?>>Class asc</option>
              <option value="class_desc" <?= $sort === 'class_desc' ? 'selected' : '' ?>>Class desc</option>
              <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest</option>
            </select>
          </div>

          <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2 pt-1">
            <div class="small text-muted">Year only = all exams in year. Year + exam = selected exam only. Year + subject (no exam) = all exams for that subject in year.</div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit">
                <i class="bi bi-funnel me-1"></i>Load
              </button>
              <a href="/teacher/wm_reports.php" class="btn btn-outline-secondary">Reset</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($schemaReady && ($totalRows > 0 || $classCode !== '' || $studyYearId > 0 || $examId > 0 || $subjectId > 0 || $q !== '')): ?>
    <div class="col-12">
      <div class="card shadow-sm border-0">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="d-flex flex-wrap align-items-center gap-2 small">
            <span class="badge text-bg-light border">Year: <?= $selectedYear ? h((string)$selectedYear['year_code']) : 'All years' ?></span>
            <span class="badge text-bg-light border">Exam: <?= $selectedExam ? h((string)$selectedExam['exam_name']) : 'All exams' ?></span>
            <?php if ($selectedSubject): ?>
              <span class="badge text-bg-light border">Subject: <?= h((string)$selectedSubject['name']) ?></span>
            <?php endif; ?>
            <?php if ($classCode !== ''): ?>
              <span class="badge text-bg-light border">Class: <?= h($classCode) ?></span>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2 small">
            <span class="badge text-bg-primary">Total <?= h((string)$summary['total']) ?></span>
            <span class="badge text-bg-secondary">Avg <?= $summary['avg'] !== null ? h(number_format((float)$summary['avg'], 2, '.', '')) : '-' ?></span>
            <span class="badge text-bg-secondary">Min <?= $summary['min'] !== null ? h(number_format((float)$summary['min'], 2, '.', '')) : '-' ?></span>
            <span class="badge text-bg-secondary">Max <?= $summary['max'] !== null ? h(number_format((float)$summary['max'], 2, '.', '')) : '-' ?></span>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <?php if (!$schemaReady): ?>
          <div class="text-danger">Required WM report schema is not available.</div>
        <?php elseif (!$rows): ?>
          <div class="text-muted">No WM results found for selected filters.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width: 60px;">#</th>
                  <th style="width: 110px;">Class</th>
                  <th>Pupil</th>
                  <th style="width: 190px;">Subject</th>
                  <th style="width: 220px;">Exam</th>
                  <th style="width: 110px;">Track</th>
                  <th style="width: 130px;" class="text-end">Score</th>
                  <th style="width: 110px;" class="text-end">Percent</th>
                  <th style="width: 120px;">Saved at</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                  <?php
                    $full = trim((string)$r['surname'] . ' ' . (string)$r['name']);
                    $score = (float)$r['score'];
                    $max = (float)$r['max_points'];
                    $pct = $max > 0 ? ($score / $max) * 100.0 : 0.0;
                    $savedAt = isset($r['created_at']) && $r['created_at'] !== null ? (string)$r['created_at'] : '-';
                    $examLine = (string)$r['study_year_code'] . ' | ' . ($r['cycle_no'] !== null ? ('C' . (int)$r['cycle_no']) : 'C-')
                      . ' | ' . (string)$r['exam_name'] . ' (' . (string)$r['exam_date'] . ')';
                  ?>
                  <tr>
                    <td class="text-muted"><?= h((string)(($page - 1) * $perPage + $idx + 1)) ?></td>
                    <td><span class="badge text-bg-light border"><?= h((string)$r['class_code']) ?></span></td>
                    <td class="fw-semibold"><?= h($full !== '' ? $full : ('Pupil #' . (int)$r['pupil_id'])) ?></td>
                    <td><?= h((string)$r['subject_name']) ?></td>
                    <td><?= h($examLine) ?></td>
                    <td><?= h((string)$r['track']) ?></td>
                    <td class="text-end"><?= h(number_format($score, 2, '.', '')) ?> / <?= h((string)$r['max_points']) ?></td>
                    <td class="text-end"><?= h(number_format($pct, 1, '.', '')) ?>%</td>
                    <td><?= h($savedAt) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php
            $baseQ = [
              'class_code' => $classCode,
              'study_year_id' => $studyYearId,
              'exam_id' => $examId,
              'subject_id' => $subjectId,
              'q' => $q,
              'sort' => $sort,
            ];
            $mkUrl = static function (int $p) use ($baseQ): string {
                return '/teacher/wm_reports.php?' . http_build_query($baseQ + ['page' => $p]);
            };
          ?>

          <?php if ($totalPages > 1): ?>
            <nav class="mt-3" aria-label="WM reports pagination">
              <ul class="pagination pagination-sm mb-0 justify-content-end">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= h_attr($mkUrl(1)) ?>">First</a>
                </li>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= h_attr($mkUrl(max(1, $page - 1))) ?>">&laquo;</a>
                </li>
                <li class="page-item disabled"><span class="page-link"><?= h((string)$page) ?> / <?= h((string)$totalPages) ?></span></li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= h_attr($mkUrl(min($totalPages, $page + 1))) ?>">&raquo;</a>
                </li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= h_attr($mkUrl($totalPages)) ?>">Last</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
