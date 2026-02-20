<?php
// admin/wm_reports.php - Read-only WM results report

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

function wmrp_get_int(string $key): int
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    return (int)$v;
}

function wmrp_get_str(string $key, int $maxLen = 80): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function wmrp_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function wmrp_table_exists(PDO $pdo, string $table): bool
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

function wmrp_column_exists(PDO $pdo, string $table, string $column): bool
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
$classCode = wmrp_get_str('class_code', 30);
$examId = wmrp_get_int('exam_id');
$subjectId = wmrp_get_int('subject_id');
$q = wmrp_get_str('q', 60);
$sort = wmrp_get_str('sort', 20);
$page = max(1, wmrp_get_int('page'));
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

// ----------------------- Options -----------------------
$classOptions = [];
$examOptions = [];
$subjectOptions = [];
$selectedExam = null;
$selectedSubject = null;
$rows = [];
$totalRows = 0;
$totalPages = 1;
$summary = ['total' => 0, 'avg' => null, 'min' => null, 'max' => null];

$schemaReady = false;
$hasPupilMiddle = false;
$hasPupilTrack = false;
$hasResultCreated = false;

try {
    $schemaReady = wmrp_table_exists($pdo, 'wm_results')
        && wmrp_table_exists($pdo, 'wm_exams')
        && wmrp_table_exists($pdo, 'wm_subjects')
        && wmrp_table_exists($pdo, 'study_year')
        && wmrp_column_exists($pdo, 'wm_exams', 'study_year_id');

    if (!$schemaReady) {
        wmrp_flash('danger', 'Schema mismatch: wm_results/wm_exams/wm_subjects/study_year with wm_exams.study_year_id is required.');
    }

    $hasPupilMiddle = wmrp_column_exists($pdo, 'pupils', 'middle_name');
    $hasPupilTrack = wmrp_column_exists($pdo, 'pupils', 'track');
    $hasResultCreated = wmrp_column_exists($pdo, 'wm_results', 'created_at');

    $classOptions = $pdo->query("
        SELECT class_code, COUNT(*) AS cnt
        FROM pupils
        GROUP BY class_code
        ORDER BY class_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($schemaReady) {
        $hasExamCycle = wmrp_column_exists($pdo, 'wm_exams', 'cycle_no');
        $cycleExpr = $hasExamCycle ? 'e.cycle_no' : 'NULL AS cycle_no';

        $examOptions = $pdo->query("
            SELECT e.id, sy.year_code AS study_year_code, $cycleExpr, e.exam_name, e.exam_date
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

    foreach ($examOptions as $e) {
        if ((int)$e['id'] === $examId) {
            $selectedExam = $e;
            break;
        }
    }
    foreach ($subjectOptions as $s) {
        if ((int)$s['id'] === $subjectId) {
            $selectedSubject = $s;
            break;
        }
    }

    if ($schemaReady && $examId > 0 && $subjectId > 0 && $selectedExam && $selectedSubject) {
        $where = ['wr.exam_id = :exam_id', 'wr.subject_id = :subject_id'];
        $params = [
            ':exam_id' => $examId,
            ':subject_id' => $subjectId,
        ];

        if ($classCode !== '') {
            $where[] = 'p.class_code = :class_code';
            $params[':class_code'] = $classCode;
        }

        if ($q !== '') {
            $where[] = '(p.surname LIKE :q OR p.name LIKE :q OR p.student_login LIKE :q'
                . ($hasPupilMiddle ? ' OR p.middle_name LIKE :q' : '')
                . ')';
            $params[':q'] = '%' . $q . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $middleExpr = $hasPupilMiddle ? 'p.middle_name' : "'' AS middle_name";
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
              $middleExpr,
              p.student_login,
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
    wmrp_flash('danger', 'Failed to load WM reports.');
    error_log('[WM_REPORTS] load failed: ' . $e->getMessage());
}

$page_title = 'WM Reports';
$page_actions = '<a class="btn btn-outline-primary btn-sm" href="wm_results.php"><i class="bi bi-table me-1"></i>Open WM Results Entry</a>';
require_once __DIR__ . '/header.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <form method="get" action="/admin/wm_reports.php" class="row g-2 align-items-end">
          <div class="col-12 col-md-2">
            <label class="form-label">Class</label>
            <select class="form-select" name="class_code">
              <option value="">All classes</option>
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

          <div class="col-6 col-md-2">
            <label class="form-label">Search pupil</label>
            <input class="form-control" name="q" value="<?= h_attr($q) ?>" placeholder="name/login">
          </div>

          <div class="col-6 col-md-1">
            <label class="form-label">Sort</label>
            <select class="form-select" name="sort">
              <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
              <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
              <option value="score_desc" <?= $sort === 'score_desc' ? 'selected' : '' ?>>Score desc</option>
              <option value="score_asc" <?= $sort === 'score_asc' ? 'selected' : '' ?>>Score asc</option>
              <option value="class_asc" <?= $sort === 'class_asc' ? 'selected' : '' ?>>Class asc</option>
              <option value="class_desc" <?= $sort === 'class_desc' ? 'selected' : '' ?>>Class desc</option>
              <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest</option>
            </select>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-outline-secondary" type="submit">
              <i class="bi bi-funnel me-1"></i>Load
            </button>
            <a href="/admin/wm_reports.php" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($examId > 0 && $subjectId > 0 && $selectedExam && $selectedSubject): ?>
    <div class="col-12">
      <div class="card shadow-sm border-0">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="small text-muted">
            Exam: <span class="fw-semibold"><?= h((string)$selectedExam['exam_name']) ?></span> |
            Subject: <span class="fw-semibold"><?= h((string)$selectedSubject['name']) ?></span>
            <?php if ($classCode !== ''): ?>
              | Class: <span class="fw-semibold"><?= h($classCode) ?></span>
            <?php endif; ?>
          </div>
          <div class="small text-muted">
            Total: <span class="fw-semibold"><?= h((string)$summary['total']) ?></span> |
            Avg: <span class="fw-semibold"><?= $summary['avg'] !== null ? h(number_format((float)$summary['avg'], 2, '.', '')) : '-' ?></span> |
            Min: <span class="fw-semibold"><?= $summary['min'] !== null ? h(number_format((float)$summary['min'], 2, '.', '')) : '-' ?></span> |
            Max: <span class="fw-semibold"><?= $summary['max'] !== null ? h(number_format((float)$summary['max'], 2, '.', '')) : '-' ?></span>
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
        <?php elseif ($examId <= 0 || $subjectId <= 0): ?>
          <div class="text-muted">Choose exam and subject to view WM results report.</div>
        <?php elseif (!$rows): ?>
          <div class="text-muted">No WM results found for selected filters.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width: 60px;">#</th>
                  <th style="width: 110px;">Class</th>
                  <th>Pupil</th>
                  <th style="width: 130px;">Login</th>
                  <th style="width: 110px;">Track</th>
                  <th style="width: 130px;" class="text-end">Score</th>
                  <th style="width: 110px;" class="text-end">Percent</th>
                  <th style="width: 120px;">Saved at</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                  <?php
                    $full = trim((string)$r['surname'] . ' ' . (string)$r['name'] . ' ' . (string)($r['middle_name'] ?? ''));
                    $score = (float)$r['score'];
                    $max = (float)$r['max_points'];
                    $pct = $max > 0 ? ($score / $max) * 100.0 : 0.0;
                    $savedAt = isset($r['created_at']) && $r['created_at'] !== null ? (string)$r['created_at'] : '-';
                  ?>
                  <tr>
                    <td class="text-muted"><?= h((string)(($page - 1) * $perPage + $idx + 1)) ?></td>
                    <td><span class="badge text-bg-light border"><?= h((string)$r['class_code']) ?></span></td>
                    <td class="fw-semibold"><?= h($full !== '' ? $full : ('Pupil #' . (int)$r['pupil_id'])) ?></td>
                    <td><code><?= h((string)$r['student_login']) ?></code></td>
                    <td><?= h((string)$r['track']) ?></td>
                    <td class="text-end">
                      <?= h(number_format($score, 2, '.', '')) ?> / <?= h((string)$r['max_points']) ?>
                    </td>
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
              'exam_id' => $examId,
              'subject_id' => $subjectId,
              'q' => $q,
              'sort' => $sort,
            ];
            $mkUrl = static function (int $p) use ($baseQ): string {
                return '/admin/wm_reports.php?' . http_build_query($baseQ + ['page' => $p]);
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
