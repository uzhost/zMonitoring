<?php
// admin/dashboard.php — Admin dashboard (filters + KPIs + rankings + distributions + trends) [enhanced]

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_role('admin');

$page_title = 'Dashboard';
require __DIR__ . '/header.php';

/**
 * -----------------------------
 * Input helpers (strict)
 * -----------------------------
 */
function gi(string $key, ?int $min = null, ?int $max = null): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') return null;
    if (!preg_match('/^\d+$/', (string)$_GET[$key])) return null;
    $v = (int)$_GET[$key];
    if ($min !== null && $v < $min) return null;
    if ($max !== null && $v > $max) return null;
    return $v;
}
function gs(string $key, int $maxLen = 120): ?string {
    if (!isset($_GET[$key])) return null;
    $v = trim((string)$_GET[$key]);
    if ($v === '') return null;
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}
function gdate(string $key): ?string {
    $v = gs($key, 20);
    if (!$v) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    [$y,$m,$d] = array_map('intval', explode('-', $v));
    if (!checkdate($m, $d, $y)) return null;
    return $v;
}

/**
 * Build WHERE clause for results-anchored queries.
 * Expects aliases: r=results, p=pupils, s=subjects, e=exams
 */
function build_filters(array $f): array {
    $where = [];
    $params = [];

    if (!empty($f['academic_year'])) {
        $where[] = 'e.academic_year = :academic_year';
        $params['academic_year'] = $f['academic_year'];
    }
    if (!empty($f['exam_id'])) {
        $where[] = 'e.id = :exam_id';
        $params['exam_id'] = (int)$f['exam_id'];
    }
    if (!empty($f['subject_id'])) {
        $where[] = 's.id = :subject_id';
        $params['subject_id'] = (int)$f['subject_id'];
    }
    if (!empty($f['class_code'])) {
        $where[] = 'p.class_code = :class_code';
        $params['class_code'] = $f['class_code'];
    }
    if (!empty($f['track'])) {
        $where[] = 'p.track = :track';
        $params['track'] = $f['track'];
    }
    if (!empty($f['date_from'])) {
        $where[] = 'e.exam_date >= :date_from';
        $params['date_from'] = $f['date_from'];
    }
    if (!empty($f['date_to'])) {
        $where[] = 'e.exam_date <= :date_to';
        $params['date_to'] = $f['date_to'];
    }

    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

function fetch_all(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $st->bindValue(':' . $k, $v, $type);
    }
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_one(PDO $pdo, string $sql, array $params = []): ?array {
    $rows = fetch_all($pdo, $sql, $params);
    return $rows[0] ?? null;
}

/** Preserve other filters in links */
function url_with(array $overrides): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'dashboard.php' . ($q ? ('?' . http_build_query($q)) : '');
}

/**
 * CSV exporter (safe: same filter slice)
 * IMPORTANT: this must run BEFORE any HTML is emitted.
 */
function export_csv_slice(PDO $pdo, string $where, array $params): void {
    $rows = fetch_all($pdo, "
        SELECT
          e.academic_year,
          e.term,
          e.exam_name,
          e.exam_date,
          p.class_code,
          p.track,
          p.student_login,
          p.surname,
          p.name,
          s.code AS subject_code,
          s.name AS subject_name,
          r.score
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $where
        ORDER BY COALESCE(e.exam_date,'9999-12-31') DESC, e.id DESC, p.class_code ASC, p.surname ASC, p.name ASC, s.name ASC
    ", $params);

    $filename = 'results_slice_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'academic_year','term','exam_name','exam_date',
        'class_code','track',
        'student_login','surname','name',
        'subject_code','subject_name',
        'score'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            (string)$r['academic_year'],
            (string)$r['term'],
            (string)$r['exam_name'],
            (string)$r['exam_date'],
            (string)$r['class_code'],
            (string)$r['track'],
            (string)$r['student_login'],
            (string)$r['surname'],
            (string)$r['name'],
            (string)$r['subject_code'],
            (string)$r['subject_name'],
            (string)$r['score'],
        ]);
    }
    fclose($out);
    exit;
}

/**
 * -----------------------------
 * Filters (GET)
 * -----------------------------
 */
$filters = [
    'academic_year' => gs('academic_year', 20),
    'exam_id'       => gi('exam_id', 1),
    'subject_id'    => gi('subject_id', 1),
    'class_code'    => gs('class_code', 30),
    'track'         => gs('track', 40),
    'date_from'     => gdate('date_from'),
    'date_to'       => gdate('date_to'),
];

// thresholds (configurable later; defaults per spec)
$passThreshold      = gi('pass', 0, 40) ?? 24;
$goodThreshold      = gi('good', 0, 40) ?? 30;
$excellentThreshold = gi('excellent', 0, 40) ?? 35;

// Enforce ordering (no surprising UI)
$passThreshold = min($passThreshold, 40);
$goodThreshold = min(max($goodThreshold, $passThreshold), 40);
$excellentThreshold = min(max($excellentThreshold, $goodThreshold), 40);

// Dropdown sources
$years    = fetch_all($pdo, "SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC");
$subjects = fetch_all($pdo, "SELECT id, code, name FROM subjects ORDER BY name ASC");
$classes  = fetch_all($pdo, "SELECT DISTINCT class_code FROM pupils WHERE class_code <> '' ORDER BY class_code ASC");

// Tracks (keep your Uzbek labels; these can be table-driven later)
$tracks = [
    ['v' => 'Aniq fanlar',   't' => 'Aniq fanlar'],
    ['v' => 'Tabiiy fanlar', 't' => 'Tabiiy fanlar'],
];

// Exams list (optionally filtered by academic_year)
$examParams = [];
$examWhere = '';
if (!empty($filters['academic_year'])) {
    $examWhere = 'WHERE academic_year = :ay';
    $examParams['ay'] = $filters['academic_year'];
}
$exams = fetch_all(
    $pdo,
    "SELECT id, academic_year, term, exam_name, exam_date
     FROM exams
     $examWhere
     ORDER BY COALESCE(exam_date,'9999-12-31') DESC, id DESC",
    $examParams
);

// Build main slice filter clause
[$where, $params] = build_filters($filters);

// Handle export BEFORE output
if (!empty($_GET['export']) && (string)$_GET['export'] === '1') {
    export_csv_slice($pdo, $where, $params);
}

/**
 * -----------------------------
 * KPI queries
 * -----------------------------
 */
$kpi = fetch_one($pdo, "
    SELECT
        COUNT(*) AS n,
        AVG(r.score) AS avg_score,
        MIN(r.score) AS min_score,
        MAX(r.score) AS max_score
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $where
", $params) ?? ['n' => 0, 'avg_score' => null, 'min_score' => null, 'max_score' => null];

$nRecords = (int)($kpi['n'] ?? 0);

$median = null;
$pass = ['passed' => 0, 'total' => 0];
$bands = ['needs_support'=>0,'pass_only'=>0,'good'=>0,'excellent'=>0,'total'=>0];

if ($nRecords > 0) {
    $median = fetch_one($pdo, "
        WITH x AS (
          SELECT
            r.score AS score,
            ROW_NUMBER() OVER (ORDER BY r.score) AS rn,
            COUNT(*) OVER () AS cnt
          FROM results r
          JOIN pupils p   ON p.id = r.pupil_id
          JOIN subjects s ON s.id = r.subject_id
          JOIN exams e    ON e.id = r.exam_id
          $where
        )
        SELECT AVG(score) AS median_score
        FROM x
        WHERE rn IN (FLOOR((cnt + 1)/2), FLOOR((cnt + 2)/2))
    ", $params);

    $pass = fetch_one($pdo, "
        SELECT
          SUM(r.score >= :pass) AS passed,
          COUNT(*) AS total
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $where
    ", $params + ['pass' => $passThreshold]) ?? $pass;

    $bands = fetch_one($pdo, "
        SELECT
          SUM(r.score < :pass) AS needs_support,
          SUM(r.score >= :pass AND r.score < :good) AS pass_only,
          SUM(r.score >= :good AND r.score < :excellent) AS good,
          SUM(r.score >= :excellent) AS excellent,
          COUNT(*) AS total
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $where
    ", $params + ['pass' => $passThreshold, 'good' => $goodThreshold, 'excellent' => $excellentThreshold]) ?? $bands;
}

$passRate = 0.0;
if (!empty($pass['total'])) {
    $passRate = ((float)$pass['passed'] / (float)$pass['total']) * 100.0;
}

// Top / Bottom pupils by average score
$topPupils = $nRecords > 0 ? fetch_all($pdo, "
    SELECT
      p.id,
      p.surname,
      p.name,
      p.class_code,
      p.student_login,
      AVG(r.score) AS avg_score,
      COUNT(*) AS n
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $where
    GROUP BY p.id
    ORDER BY avg_score DESC, n DESC
    LIMIT 10
", $params) : [];

$bottomPupils = $nRecords > 0 ? fetch_all($pdo, "
    SELECT
      p.id,
      p.surname,
      p.name,
      p.class_code,
      p.student_login,
      AVG(r.score) AS avg_score,
      COUNT(*) AS n
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $where
    GROUP BY p.id
    ORDER BY avg_score ASC, n DESC
    LIMIT 10
", $params) : [];

// Subject difficulty
$subjectDifficulty = $nRecords > 0 ? fetch_all($pdo, "
    SELECT
      s.id,
      s.code,
      s.name,
      COUNT(*) AS n,
      AVG(r.score) AS mean_score,
      STDDEV_POP(r.score) AS stdev_score
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $where
    GROUP BY s.id
    HAVING COUNT(*) >= 5
    ORDER BY mean_score ASC, stdev_score DESC
    LIMIT 12
", $params) : [];

// Histogram 0..40
$histMap = array_fill(0, 41, 0);
if ($nRecords > 0) {
    $hist = fetch_all($pdo, "
        SELECT r.score, COUNT(*) AS n
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $where
        GROUP BY r.score
        ORDER BY r.score ASC
    ", $params);

    foreach ($hist as $r) {
        $sc = (int)$r['score'];
        if ($sc >= 0 && $sc <= 40) $histMap[$sc] = (int)$r['n'];
    }
}
$histMax = max($histMap) ?: 1;

// Trend
$trend = $nRecords > 0 ? fetch_all($pdo, "
    SELECT
      e.id AS exam_id,
      e.exam_name,
      e.exam_date,
      e.academic_year,
      AVG(r.score) AS avg_score,
      COUNT(*) AS n
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $where
    GROUP BY e.id
    HAVING COUNT(*) >= 5
    ORDER BY COALESCE(e.exam_date,'9999-12-31') ASC, e.id ASC
    LIMIT 12
", $params) : [];

/**
 * Active filter chips
 */
$chips = [];
if ($filters['academic_year']) $chips[] = ['k'=>'academic_year','t'=>'Year: ' . $filters['academic_year']];
if ($filters['exam_id'])       $chips[] = ['k'=>'exam_id','t'=>'Exam ID: ' . (int)$filters['exam_id']];
if ($filters['subject_id'])    $chips[] = ['k'=>'subject_id','t'=>'Subject ID: ' . (int)$filters['subject_id']];
if ($filters['class_code'])    $chips[] = ['k'=>'class_code','t'=>'Class: ' . $filters['class_code']];
if ($filters['track'])         $chips[] = ['k'=>'track','t'=>'Track: ' . $filters['track']];
if ($filters['date_from'])     $chips[] = ['k'=>'date_from','t'=>'From: ' . $filters['date_from']];
if ($filters['date_to'])       $chips[] = ['k'=>'date_to','t'=>'To: ' . $filters['date_to']];

function score_badge_class(int $score, int $pass, int $good, int $excellent): string {
    if ($score < $pass) return 'text-bg-danger';
    if ($score < $good) return 'text-bg-warning text-dark';
    if ($score < $excellent) return 'text-bg-primary';
    return 'text-bg-success';
}

?>
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="d-flex align-items-center gap-2">
            <div class="fw-semibold"><i class="bi bi-funnel me-2"></i>Filters</div>
            <?php if ($chips): ?>
              <span class="badge text-bg-light border">
                <i class="bi bi-check2-circle me-1"></i><?= count($chips) ?> active
              </span>
            <?php endif; ?>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="dashboard.php">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </a>
            <a class="btn btn-sm btn-outline-primary" href="results_import.php">
              <i class="bi bi-upload me-1"></i>Import Results
            </a>
            <a class="btn btn-sm btn-primary" href="<?= h(url_with(['export' => 1])) ?>">
              <i class="bi bi-download me-1"></i>Export CSV
            </a>
          </div>
        </div>

        <?php if ($chips): ?>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <?php foreach ($chips as $c): ?>
              <a class="badge rounded-pill text-bg-secondary-subtle border text-secondary-emphasis text-decoration-none"
                 href="<?= h(url_with([$c['k'] => null])) ?>"
                 title="Remove filter">
                <i class="bi bi-x-circle me-1"></i><?= h($c['t']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="get" class="row g-2 mt-2">
          <div class="col-md-2">
            <label class="form-label">Academic Year</label>
            <select name="academic_year" class="form-select">
              <option value="">All</option>
              <?php foreach ($years as $y): ?>
                <?php $ay = (string)$y['academic_year']; ?>
                <option value="<?= h($ay) ?>" <?= ($filters['academic_year'] === $ay) ? 'selected' : '' ?>>
                  <?= h($ay) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Exam</label>
            <select name="exam_id" class="form-select">
              <option value="">All</option>
              <?php foreach ($exams as $e): ?>
                <?php
                  $eid = (int)$e['id'];
                  $label = trim((string)$e['exam_name']) . ' — ' . (string)$e['academic_year'];
                  if (!empty($e['term'])) $label .= ' (Term ' . (int)$e['term'] . ')';
                  if (!empty($e['exam_date'])) $label .= ' • ' . (string)$e['exam_date'];
                ?>
                <option value="<?= $eid ?>" <?= ($filters['exam_id'] === $eid) ? 'selected' : '' ?>>
                  <?= h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Subject</label>
            <select name="subject_id" class="form-select">
              <option value="">All</option>
              <?php foreach ($subjects as $s): ?>
                <?php $sid = (int)$s['id']; ?>
                <option value="<?= $sid ?>" <?= ($filters['subject_id'] === $sid) ? 'selected' : '' ?>>
                  <?= h((string)$s['name']) ?><?= $s['code'] ? ' (' . h((string)$s['code']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-1">
            <label class="form-label">Class</label>
            <select name="class_code" class="form-select">
              <option value="">All</option>
              <?php foreach ($classes as $c): ?>
                <?php $cc = (string)$c['class_code']; ?>
                <option value="<?= h($cc) ?>" <?= ($filters['class_code'] === $cc) ? 'selected' : '' ?>>
                  <?= h($cc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Track</label>
            <select name="track" class="form-select">
              <option value="">All</option>
              <?php foreach ($tracks as $t): ?>
                <option value="<?= h($t['v']) ?>" <?= ($filters['track'] === $t['v']) ? 'selected' : '' ?>>
                  <?= h($t['t']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Date from</label>
            <input type="date" name="date_from" class="form-control" value="<?= h($filters['date_from']) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Date to</label>
            <input type="date" name="date_to" class="form-control" value="<?= h($filters['date_to']) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Pass</label>
            <input type="number" name="pass" min="0" max="40" class="form-control" value="<?= (int)$passThreshold ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Good</label>
            <input type="number" name="good" min="0" max="40" class="form-control" value="<?= (int)$goodThreshold ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Excellent</label>
            <input type="number" name="excellent" min="0" max="40" class="form-control" value="<?= (int)$excellentThreshold ?>">
          </div>

          <div class="col-12 d-flex flex-wrap gap-2 align-items-end mt-2">
            <button class="btn btn-primary">
              <i class="bi bi-search me-1"></i>Apply
            </button>
            <a class="btn btn-outline-secondary" href="<?= h(url_with(['export' => 1])) ?>">
              <i class="bi bi-download me-1"></i>Export CSV (slice)
            </a>
          </div>
        </form>

        <?php if ($nRecords === 0): ?>
          <div class="alert alert-info mt-3 mb-0">
            <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i>No results for the selected filters.</div>
            <div class="small">Try removing filters, selecting another exam, or importing results.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Records</div>
            <div class="fs-4 fw-semibold"><?= $nRecords ?></div>
          </div>
          <div class="text-muted fs-3"><i class="bi bi-database"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Average</div>
            <div class="fs-4 fw-semibold">
              <?= $kpi['avg_score'] !== null ? number_format((float)$kpi['avg_score'], 2) : '—' ?>
            </div>
            <div class="small text-muted">
              Min <?= h((string)($kpi['min_score'] ?? '—')) ?> • Max <?= h((string)($kpi['max_score'] ?? '—')) ?>
            </div>
          </div>
          <div class="text-muted fs-3"><i class="bi bi-graph-up"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Median</div>
            <div class="fs-4 fw-semibold">
              <?= !empty($median['median_score']) ? number_format((float)$median['median_score'], 2) : '—' ?>
            </div>
            <div class="small text-muted">Robust central tendency</div>
          </div>
          <div class="text-muted fs-3"><i class="bi bi-distribute-vertical"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Pass rate (≥ <?= (int)$passThreshold ?>)</div>
            <div class="fs-4 fw-semibold"><?= number_format($passRate, 1) ?>%</div>
            <div class="small text-muted"><?= (int)($pass['passed'] ?? 0) ?> / <?= (int)($pass['total'] ?? 0) ?></div>
          </div>
          <div class="text-muted fs-3"><i class="bi bi-check2-circle"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold"><i class="bi bi-bar-chart-steps me-2"></i>Score distribution (0–40)</div>
          <div class="small text-muted">Relative frequency</div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:72px">Score</th>
                <th>Distribution</th>
                <th class="text-end" style="width:90px">Count</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($s = 0; $s <= 40; $s++): ?>
                <?php
                  $n = (int)$histMap[$s];
                  $pct = ($n / $histMax) * 100.0;
                  $badgeClass = score_badge_class($s, $passThreshold, $goodThreshold, $excellentThreshold);
                ?>
                <tr>
                  <td><span class="badge <?= h($badgeClass) ?>"><?= $s ?></span></td>
                  <td>
                    <div class="progress" style="height: 10px;">
                      <div class="progress-bar" role="progressbar" style="width: <?= (float)$pct ?>%"></div>
                    </div>
                  </td>
                  <td class="text-end"><?= $n ?></td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>

        <hr class="my-3">

        <div class="d-flex flex-wrap gap-2">
          <span class="badge text-bg-danger">Needs Support: <?= (int)($bands['needs_support'] ?? 0) ?></span>
          <span class="badge text-bg-warning text-dark">Pass: <?= (int)($bands['pass_only'] ?? 0) ?></span>
          <span class="badge text-bg-primary">Good: <?= (int)($bands['good'] ?? 0) ?></span>
          <span class="badge text-bg-success">Excellent: <?= (int)($bands['excellent'] ?? 0) ?></span>
          <span class="badge text-bg-secondary">Total: <?= (int)($bands['total'] ?? 0) ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-graph-down me-2"></i>Subject difficulty (Mean / StdDev)</div>

        <?php if (!$subjectDifficulty): ?>
          <div class="text-muted">Not enough data for difficulty metrics in this slice.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Subject</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">StdDev</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($subjectDifficulty as $r): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= h((string)$r['name']) ?></div>
                      <div class="small text-muted"><?= h((string)$r['code']) ?></div>
                    </td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end"><?= number_format((float)$r['mean_score'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['stdev_score'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">
            Lower mean indicates higher difficulty; higher StdDev indicates more dispersion.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-trophy me-2"></i>Top pupils (by average)</div>
        <?php if (!$topPupils): ?>
          <div class="text-muted">No data in this slice.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Pupil</th>
                  <th>Class</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Avg</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topPupils as $r): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= h((string)$r['surname']) ?> <?= h((string)$r['name']) ?></div>
                      <div class="small text-muted"><code><?= h((string)$r['student_login']) ?></code></div>
                    </td>
                    <td><?= h((string)$r['class_code']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$r['avg_score'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-2"></i>Bottom pupils (by average)</div>
        <?php if (!$bottomPupils): ?>
          <div class="text-muted">No data in this slice.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Pupil</th>
                  <th>Class</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Avg</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bottomPupils as $r): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= h((string)$r['surname']) ?> <?= h((string)$r['name']) ?></div>
                      <div class="small text-muted"><code><?= h((string)$r['student_login']) ?></code></div>
                    </td>
                    <td><?= h((string)$r['class_code']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$r['avg_score'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-activity me-2"></i>Trend (average by exam)</div>
        <?php if (!$trend): ?>
          <div class="text-muted">Not enough data for trend lines in this slice.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Exam</th>
                  <th>Year</th>
                  <th>Date</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Avg</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($trend as $t): ?>
                  <tr>
                    <td class="fw-semibold"><?= h((string)$t['exam_name']) ?></td>
                    <td><?= h((string)$t['academic_year']) ?></td>
                    <td><?= h((string)($t['exam_date'] ?? '')) ?></td>
                    <td class="text-end"><?= (int)$t['n'] ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$t['avg_score'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">
            For clearer trend diagnostics, apply Subject and/or Class filter to reduce mixing effects.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
