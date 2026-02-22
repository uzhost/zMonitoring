<?php
// teachers/dashboard.php - Analytics-first dashboard (Teacher/Viewer portal)
// The "full reports" remain in reports.php; this page is a decision-friendly overview.

declare(strict_types=1);

// Centralized teachers guard
$tguard_allowed_methods = ['GET', 'HEAD'];
$tguard_login_path = '/teachers/login.php';
$tguard_fallback_path = '/teachers/dashboard.php';
$tguard_require_active = true;
require_once __DIR__ . '/_tguard.php';

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/teachers/dashboard.php');
$teacherBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
$teacherBase = ($teacherBase === '') ? '/teachers' : $teacherBase;
$dashboardUrl = $teacherBase . '/dashboard.php';
$reportsBaseUrl = $teacherBase . '/reports.php';
$pupilBaseUrl = $teacherBase . '/pupil.php';

// CSP nonce
if (empty($_SESSION['csp_nonce']) || !is_string($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}
$cspNonce = $_SESSION['csp_nonce'];

// Security headers
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

$pageTitle = 'Dashboard';
const PASS_SCORE = 18.4;
const GOOD_SCORE = 26.4;
const EXCELLENT_SCORE = 34.4;

// -------------------- Helpers --------------------
function qs_merge(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return http_build_query($q);
}

function get_query_string(string $key, int $maxLen = 40): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return ($maxLen > 0) ? mb_substr($value, 0, $maxLen, 'UTF-8') : $value;
}

function get_query_int(string $key): int {
    return (int)($_GET[$key] ?? 0);
}

function exam_label(array $exam): string {
    $ay = (string)($exam['academic_year'] ?? '');
    $termValue = $exam['term'] ?? null;
    $t = ($termValue === null || $termValue === '') ? '-' : (string)$termValue;
    $nm = (string)($exam['exam_name'] ?? '');
    $d = (string)($exam['exam_date'] ?? '');
    return trim($ay . ' T' . $t . ' - ' . $nm . ($d !== '' ? ' (' . $d . ')' : ''));
}

function score_band(float $s): string {
    if ($s < PASS_SCORE) return 'danger';       // Needs support
    if ($s < GOOD_SCORE) return 'warning';      // Pass
    if ($s < EXCELLENT_SCORE) return 'primary'; // Good
    return 'success';                           // Excellent
}

function fmt_delta(?float $d, int $dec = 2): string {
    if ($d === null) return '-';
    $sign = ($d > 0) ? '+' : '';
    return $sign . number_format($d, $dec);
}


function badge_delta(?float $d): string {
    if ($d === null) return 'secondary';
    if ($d > 0.00001) return 'success';
    if ($d < -0.00001) return 'danger';
    return 'secondary';
}

// -------------------- Filters --------------------
$year      = get_query_string('year', 16);
$examIdIn  = max(0, get_query_int('exam_id'));
$classCode = get_query_string('class_code', 20);
$track     = get_query_string('track', 20);
$subjectId = max(0, get_query_int('subject_id'));

$validTracks = ['Aniq', 'Tabiiy'];
if ($track !== '' && !in_array($track, $validTracks, true)) {
    $track = '';
}

// Performance thresholds (0-40 scale)
// 46% = Pass, 66% = Good, 86% = Excellent

// -------------------- Load filter options --------------------
$years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll();

$examsStmt = $pdo->prepare("
  SELECT id, academic_year, term, exam_name, exam_date
  FROM exams
  WHERE (:y1 = '' OR academic_year = :y2)
  ORDER BY
    academic_year DESC,
    COALESCE(exam_date,'0000-00-00') DESC,
    id DESC
");
$examsStmt->execute([':y1' => $year, ':y2' => $year]);
$exams = $examsStmt->fetchAll();

$classes  = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code")->fetchAll();
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();
$subjectNameById = [];
foreach ($subjects as $s) {
    $sid = (int)($s['id'] ?? 0);
    if ($sid > 0) $subjectNameById[$sid] = (string)($s['name'] ?? '');
}

// -------------------- Determine effective exam + previous exam --------------------
$effectiveExamId = $examIdIn;
if ($effectiveExamId <= 0 && !empty($exams)) {
    $effectiveExamId = (int)$exams[0]['id'];
}

$prevExamId = 0;
if ($effectiveExamId > 0 && !empty($exams)) {
    $idx = null;
    foreach ($exams as $i => $e) {
        if ((int)$e['id'] === $effectiveExamId) { $idx = $i; break; }
    }
    if ($idx !== null && isset($exams[$idx + 1])) {
        $prevExamId = (int)$exams[$idx + 1]['id'];
    }
}

// Label helpers
$examLabel = 'All exams';
$prevLabel = null;

if ($effectiveExamId > 0) {
    foreach ($exams as $e) {
        if ((int)$e['id'] === $effectiveExamId) {
            $examLabel = exam_label($e);
            break;
        }
    }
}

if ($prevExamId > 0) {
    foreach ($exams as $e) {
        if ((int)$e['id'] === $prevExamId) {
            $prevLabel = exam_label($e);
            break;
        }
    }
}
// -------------------- Build WHERE fragments --------------------
$whereBase = ["1=1"];
$paramsBase = [];

if ($year !== '') {
    $whereBase[] = "e.academic_year = :year";
    $paramsBase[':year'] = $year;
}
if ($classCode !== '') {
    $whereBase[] = "p.class_code = :class_code";
    $paramsBase[':class_code'] = $classCode;
}
if ($track !== '') {
    $whereBase[] = "p.track = :track";
    $paramsBase[':track'] = $track;
}
if ($subjectId > 0) {
    $whereBase[] = "r.subject_id = :subject_id";
    $paramsBase[':subject_id'] = $subjectId;
}

$whereBaseSql = implode(' AND ', $whereBase);

$whereNowSql = $whereBaseSql;
$paramsNow = $paramsBase;
if ($effectiveExamId > 0) {
    $whereNowSql .= " AND r.exam_id = :exam_id_now";
    $paramsNow[':exam_id_now'] = $effectiveExamId;
}

$wherePrevSql = $whereBaseSql;
$paramsPrev = $paramsBase;
if ($prevExamId > 0) {
    $wherePrevSql .= " AND r.exam_id = :exam_id_prev";
    $paramsPrev[':exam_id_prev'] = $prevExamId;
}

// -------------------- KPI --------------------
$kpiQuery = "
  SELECT
    COUNT(*) AS n_results,
    COUNT(DISTINCT r.pupil_id) AS n_pupils,
    AVG(r.score) AS avg_score
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE %s
";

$kpiNowStmt = $pdo->prepare(sprintf($kpiQuery, $whereNowSql));
$kpiNowStmt->execute($paramsNow);
$kpiNow = $kpiNowStmt->fetch() ?: ['n_results' => 0, 'n_pupils' => 0, 'avg_score' => null];

$kpiPrev = ['n_results' => 0, 'n_pupils' => 0, 'avg_score' => null];
if ($prevExamId > 0) {
    $kpiPrevStmt = $pdo->prepare(sprintf($kpiQuery, $wherePrevSql));
    $kpiPrevStmt->execute($paramsPrev);
    $kpiPrev = $kpiPrevStmt->fetch() ?: $kpiPrev;
}

// Median
$medianOf = static function (array $scores): ?float {
    $n = count($scores);
    if ($n === 0) return null;
    sort($scores, SORT_NUMERIC);
    $mid = intdiv($n, 2);
    return ($n % 2 === 1) ? (float)$scores[$mid] : ((($scores[$mid - 1] + $scores[$mid]) / 2.0));
};

$scoresNowStmt = $pdo->prepare("
  SELECT r.score
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereNowSql
");
$scoresNowStmt->execute($paramsNow);
$scoresNow = [];
while ($row = $scoresNowStmt->fetch()) $scoresNow[] = (float)$row['score'];
$medianNow = $medianOf($scoresNow);

$medianPrev = null;
if ($prevExamId > 0) {
    $scoresPrevStmt = $pdo->prepare("
      SELECT r.score
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      WHERE $wherePrevSql
    ");
    $scoresPrevStmt->execute($paramsPrev);
    $scoresPrev = [];
    while ($row = $scoresPrevStmt->fetch()) $scoresPrev[] = (float)$row['score'];
    $medianPrev = $medianOf($scoresPrev);
}

// Pass rate
$passRateNow = null;
if ((int)$kpiNow['n_results'] > 0) {
    $prStmt = $pdo->prepare("
      SELECT AVG(CASE WHEN r.score >= :pass THEN 1 ELSE 0 END) AS pass_rate
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      WHERE $whereNowSql
    ");
    $prStmt->execute($paramsNow + [':pass' => PASS_SCORE]);
    $passRateNow = (float)($prStmt->fetch()['pass_rate'] ?? 0.0);
}

$passRatePrev = null;
if ($prevExamId > 0 && (int)$kpiPrev['n_results'] > 0) {
    $prStmt = $pdo->prepare("
      SELECT AVG(CASE WHEN r.score >= :pass THEN 1 ELSE 0 END) AS pass_rate
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      WHERE $wherePrevSql
    ");
    $prStmt->execute($paramsPrev + [':pass' => PASS_SCORE]);
    $passRatePrev = (float)($prStmt->fetch()['pass_rate'] ?? 0.0);
}

// Deltas
$deltaAvg    = ($prevExamId > 0 && $kpiNow['avg_score'] !== null && $kpiPrev['avg_score'] !== null) ? ((float)$kpiNow['avg_score'] - (float)$kpiPrev['avg_score']) : null;
$deltaMedian = ($prevExamId > 0 && $medianNow !== null && $medianPrev !== null) ? ($medianNow - $medianPrev) : null;
$deltaPass   = ($prevExamId > 0 && $passRateNow !== null && $passRatePrev !== null) ? (($passRateNow - $passRatePrev) * 100.0) : null;

// -------------------- Subject movers --------------------
$subjectRows = [];
$moversBest = [];
$moversWorst = [];

if ($effectiveExamId > 0 && $subjectId === 0) {
    $subNowStmt = $pdo->prepare("
      SELECT r.subject_id, s.name, AVG(r.score) AS avg_score, COUNT(*) AS n
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      JOIN subjects s ON s.id = r.subject_id
      WHERE $whereNowSql
      GROUP BY r.subject_id, s.name
      HAVING COUNT(*) > 0
      ORDER BY avg_score DESC
    ");
    $subNowStmt->execute($paramsNow);
    $nowMap = [];
    while ($r = $subNowStmt->fetch()) {
        $sid = (int)$r['subject_id'];
        $nowMap[$sid] = [
            'subject_id' => $sid,
            'name' => (string)$r['name'],
            'avg_now' => (float)$r['avg_score'],
            'n' => (int)$r['n'],
        ];
    }

    $prevMap = [];
    if ($prevExamId > 0) {
        $subPrevStmt = $pdo->prepare("
          SELECT r.subject_id, AVG(r.score) AS avg_score
          FROM results r
          JOIN pupils p ON p.id = r.pupil_id
          JOIN exams  e ON e.id = r.exam_id
          WHERE $wherePrevSql
          GROUP BY r.subject_id
        ");
        $subPrevStmt->execute($paramsPrev);
        while ($r = $subPrevStmt->fetch()) {
            $prevMap[(int)$r['subject_id']] = (float)$r['avg_score'];
        }
    }

    foreach ($nowMap as $sid => $row) {
        $avgPrev = $prevMap[$sid] ?? null;
        $delta = ($avgPrev === null) ? null : ($row['avg_now'] - $avgPrev);

        $subjectRows[] = [
            'subject_id' => $sid,
            'name' => $row['name'],
            'avg_now' => $row['avg_now'],
            'avg_prev' => $avgPrev,
            'delta' => $delta,
            'n' => $row['n'],
        ];
    }

    // Movers (requires prev)  FIXED: sign-aware, no overlap
    if ($prevExamId > 0) {
        $withDelta = array_values(array_filter($subjectRows, static fn($x) => $x['delta'] !== null));

        $improvements = array_values(array_filter($withDelta, static fn($x) => (float)$x['delta'] > 0.00001));
        $declines     = array_values(array_filter($withDelta, static fn($x) => (float)$x['delta'] < -0.00001));

        usort($improvements, static fn($a, $b) => $b['delta'] <=> $a['delta']); // biggest gain first
        usort($declines,     static fn($a, $b) => $a['delta'] <=> $b['delta']); // most negative first

        $moversBest  = array_slice($improvements, 0, 5);
        $moversWorst = array_slice($declines, 0, 5);
    }

    // Compact table on dashboard (top 12 by now avg)
    usort($subjectRows, static fn($a, $b) => $b['avg_now'] <=> $a['avg_now']);
    $subjectRows = array_slice($subjectRows, 0, 12);
}

// -------------------- Top/Bottom pupils --------------------
$topPupils = [];
$bottomPupils = [];

if ($effectiveExamId > 0) {
    $pupilAggSql = "
      SELECT
        p.id,
        p.class_code,
        p.track,
        CONCAT(p.surname, ' ', p.name) AS short_name,
        SUM(r.score) AS total_score,
        AVG(r.score) AS avg_score,
        COUNT(*) AS n_subjects
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      WHERE $whereNowSql
      GROUP BY p.id, p.class_code, p.track, short_name
      HAVING COUNT(*) > 0
    ";

    $st = $pdo->prepare($pupilAggSql . " ORDER BY total_score DESC, avg_score DESC LIMIT 8");
    $st->execute($paramsNow);
    $topPupils = $st->fetchAll();

    $sb = $pdo->prepare($pupilAggSql . " ORDER BY total_score ASC, avg_score ASC LIMIT 8");
    $sb->execute($paramsNow);
    $bottomPupils = $sb->fetchAll();
}

// -------------------- At-risk snapshot (now vs prev) --------------------
$atRisk = [];
if ($effectiveExamId > 0) {
    if ($prevExamId > 0) {
        // IMPORTANT: do NOT reuse named placeholders in native prepares.
        $riskSql = "
          SELECT
            p.id,
            p.class_code,
            p.track,
            CONCAT(p.surname, ' ', p.name) AS short_name,
            AVG(CASE WHEN r.exam_id = :now_exam_a  THEN r.score END)  AS avg_now,
            AVG(CASE WHEN r.exam_id = :prev_exam_a THEN r.score END)  AS avg_prev
          FROM results r
          JOIN pupils p ON p.id = r.pupil_id
          JOIN exams  e ON e.id = r.exam_id
          WHERE
            ($whereBaseSql)
            AND (r.exam_id = :now_exam_b OR r.exam_id = :prev_exam_b)
          GROUP BY p.id, p.class_code, p.track, short_name
          HAVING avg_now IS NOT NULL AND avg_prev IS NOT NULL
          ORDER BY (avg_now - avg_prev) ASC, avg_now ASC
          LIMIT 10
        ";

        $riskStmt = $pdo->prepare($riskSql);
        $riskStmt->execute($paramsBase + [
            ':now_exam_a'  => $effectiveExamId,
            ':now_exam_b'  => $effectiveExamId,
            ':prev_exam_a' => $prevExamId,
            ':prev_exam_b' => $prevExamId,
        ]);
        $atRisk = $riskStmt->fetchAll();
    } else {
        $riskStmt = $pdo->prepare("
          SELECT
            p.id,
            p.class_code,
            p.track,
            CONCAT(p.surname, ' ', p.name) AS short_name,
            AVG(r.score) AS avg_now
          FROM results r
          JOIN pupils p ON p.id = r.pupil_id
          JOIN exams  e ON e.id = r.exam_id
          WHERE $whereNowSql
          GROUP BY p.id, p.class_code, p.track, short_name
          HAVING COUNT(*) > 0
          ORDER BY avg_now ASC
          LIMIT 10
        ");
        $riskStmt->execute($paramsNow);
        $atRisk = $riskStmt->fetchAll();
    }
}

// -------------------- Distribution --------------------
$distLabels = [];
$distMap = array_fill(0, 9, 0);

for ($i = 0; $i <= 8; $i++) {
    $start = $i * 5;
    $end = $start + 4;
    $distLabels[] = ($start === 40) ? "40" : "{$start}-{$end}";
}

if ($effectiveExamId > 0) {
    $distStmt = $pdo->prepare("
      SELECT
        FLOOR(LEAST(GREATEST(r.score,0),40) / 5) * 5 AS bucket,
        COUNT(*) AS cnt
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      WHERE $whereNowSql
      GROUP BY bucket
      ORDER BY bucket
    ");
    $distStmt->execute($paramsNow);
    while ($row = $distStmt->fetch()) {
        $b = (int)$row['bucket'];
        $idx = (int)($b / 5);
        if ($idx >= 0 && $idx <= 8) $distMap[$idx] = (int)$row['cnt'];
    }
}

// -------------------- Trend --------------------
$trendSql = "
  SELECT
    e.id AS exam_id,
    CONCAT(e.academic_year, ' T', COALESCE(e.term,'-'), ' - ', e.exam_name) AS label,
    COALESCE(e.exam_date, '0000-00-00') AS d,
    AVG(r.score) AS avg_score
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereBaseSql
  GROUP BY e.id, label, d
  ORDER BY d ASC, e.id ASC
";
$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute($paramsBase);

$trend = [];
while ($row = $trendStmt->fetch()) {
    $trend[] = [
        'exam_id' => (int)$row['exam_id'],
        'label'   => (string)$row['label'],
        'avg'     => (float)$row['avg_score'],
    ];
}

$jsDistLabels  = json_encode($distLabels, JSON_UNESCAPED_UNICODE);
$jsDistData    = json_encode(array_values($distMap), JSON_UNESCAPED_UNICODE);
$jsTrendLabels = json_encode(array_map(static fn($t) => $t['label'], $trend), JSON_UNESCAPED_UNICODE);
$jsTrendData   = json_encode(array_map(static fn($t) => $t['avg'], $trend), JSON_UNESCAPED_UNICODE);

$selectedTrendIndex = null;
foreach ($trend as $i => $t) {
    if ((int)$t['exam_id'] === (int)$effectiveExamId) { $selectedTrendIndex = $i; break; }
}
$jsSelectedTrendIndex = json_encode($selectedTrendIndex, JSON_UNESCAPED_UNICODE);

// -------------------- Layout --------------------
require_once __DIR__ . '/header.php';

$fullReportsUrl = $reportsBaseUrl . (($_GET) ? ('?' . qs_merge()) : '');

$hasPrev = ($prevExamId > 0);
$subTitle = $hasPrev ? ('Compared to: ' . $prevLabel) : 'No previous exam available for comparison';

$activeFilters = [];
if ($year !== '') {
    $activeFilters[] = ['label' => 'Year: ' . $year, 'href' => $dashboardUrl . '?' . qs_merge(['year' => null])];
}
if ($examIdIn > 0) {
    $activeFilters[] = ['label' => 'Exam: ' . $examLabel, 'href' => $dashboardUrl . '?' . qs_merge(['exam_id' => null])];
}
if ($classCode !== '') {
    $activeFilters[] = ['label' => 'Class: ' . $classCode, 'href' => $dashboardUrl . '?' . qs_merge(['class_code' => null])];
}
if ($track !== '') {
    $activeFilters[] = ['label' => 'Track: ' . $track, 'href' => $dashboardUrl . '?' . qs_merge(['track' => null])];
}
if ($subjectId > 0) {
    $subjectName = $subjectNameById[$subjectId] ?? ('ID ' . $subjectId);
    $activeFilters[] = ['label' => 'Subject: ' . $subjectName, 'href' => $dashboardUrl . '?' . qs_merge(['subject_id' => null])];
}
$activeFilterCount = count($activeFilters);
?>

<style nonce="<?= h($cspNonce) ?>">
  .dashboard-shell .card{ border-radius:.9rem; }
  .dashboard-shell .card-body{ padding:1rem 1.05rem; }
  .dashboard-shell .form-label{
    font-size:.78rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    color:#5f6b7a;
    margin-bottom:.35rem;
    font-weight:600;
  }
  .dashboard-shell .display-6{
    font-size:2rem;
    line-height:1.05;
    font-weight:700;
    letter-spacing:-.02em;
    color:#0f172a;
  }
  .dashboard-shell .table thead th{
    font-size:.78rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    color:#5c6675;
  }
  /* Subject movers UI */
  .mover-row{ padding:.52rem .25rem; border-bottom:1px solid rgba(0,0,0,.08); }
  .mover-row:last-child{ border-bottom:0; }
  .mover-meta{ font-size:.78rem; color:rgba(0,0,0,.55); }
  .mover-name a{ text-decoration:none; }
  .mover-name a:hover{ text-decoration:underline; }
  .mover-icon{ width:1.25rem; text-align:center; opacity:.85; }
  .mover-count{ font-size:.78rem; }
  .filter-chip{ text-decoration:none; border-radius:999px; }
  .filter-chip:hover{ background:rgba(13,110,253,.08); }
</style>

<div class="container-fluid py-3 dashboard-shell">

  <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h1 class="h4 mb-0">Analytics dashboard</h1>
        <span class="badge text-bg-primary-subtle border text-primary-emphasis">
          <i class="bi bi-eye me-1"></i> Viewer mode
        </span>
        <?php if ($effectiveExamId > 0): ?>
          <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
            <i class="bi bi-calendar-event me-1"></i> <?= h($examLabel) ?>
          </span>
        <?php else: ?>
          <span class="badge text-bg-warning-subtle border text-warning-emphasis">
            <i class="bi bi-exclamation-triangle me-1"></i> No exam data in scope
          </span>
        <?php endif; ?>
      </div>
      <div class="text-muted small mt-1">
        <?= h($subTitle) ?>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-secondary" href="<?= h($dashboardUrl) ?>">
        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
      </a>
      <a class="btn btn-primary" href="<?= h($fullReportsUrl) ?>">
        <i class="bi bi-graph-up-arrow me-1"></i> Open full reports
      </a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label">Academic year</label>
          <select class="form-select" name="year">
            <option value="">All</option>
            <?php foreach ($years as $yrow): $y = (string)($yrow['academic_year'] ?? ''); ?>
              <option value="<?= h($y) ?>" <?= ($y !== '' && $y === $year) ? 'selected' : '' ?>><?= h($y) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
          <label class="form-label">Exam (defaults to latest)</label>
          <select class="form-select" name="exam_id">
            <option value="0">Latest (auto)</option>
            <?php foreach ($exams as $e): ?>
              <?php
                $id = (int)$e['id'];
                $label = exam_label($e);
              ?>
              <option value="<?= $id ?>" <?= ($id === $examIdIn) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label">Class</label>
          <select class="form-select" name="class_code">
            <option value="">All</option>
            <?php foreach ($classes as $c): $cc = (string)($c['class_code'] ?? ''); ?>
              <option value="<?= h($cc) ?>" <?= ($cc !== '' && $cc === $classCode) ? 'selected' : '' ?>><?= h($cc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label">Track</label>
          <select class="form-select" name="track">
            <option value="">All</option>
            <?php foreach ($validTracks as $tr): ?>
              <option value="<?= h($tr) ?>" <?= ($tr === $track) ? 'selected' : '' ?>><?= h($tr) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label">Subject</label>
          <select class="form-select" name="subject_id">
            <option value="0">All</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $subjectId) ? 'selected' : '' ?>><?= h($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-auto ms-md-auto">
          <button class="btn btn-primary">
            <i class="bi bi-funnel me-1"></i> Apply
          </button>
        </div>
      </form>

      <?php if ($activeFilterCount > 0): ?>
        <div class="mt-3 d-flex flex-wrap align-items-center gap-2">
          <span class="text-muted small">Active filters:</span>
          <?php foreach ($activeFilters as $filter): ?>
            <a class="badge text-bg-light border text-dark-emphasis filter-chip"
               href="<?= h($filter['href']) ?>">
              <?= h($filter['label']) ?> <i class="bi bi-x-circle ms-1"></i>
            </a>
          <?php endforeach; ?>
          <a class="btn btn-link btn-sm p-0 ms-1" href="<?= h($dashboardUrl) ?>">Clear all</a>
        </div>
      <?php else: ?>
        <div class="text-muted small mt-3">
          Tip: apply class or subject filters to make the trend and movers more actionable.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="text-muted small">Average score</div>
              <div class="display-6 mb-0">
                <?= ($kpiNow['avg_score'] === null) ? '-' : h(number_format((float)$kpiNow['avg_score'], 2)) ?>
              </div>
            </div>
            <span class="badge text-bg-<?= h(badge_delta($deltaAvg)) ?>">
              Delta <?= h(fmt_delta($deltaAvg, 2)) ?>
            </span>
          </div>
          <div class="text-muted small mt-2">
            Band:
            <?php if ($kpiNow['avg_score'] === null): ?>
              -
            <?php else: ?>
              <span class="badge text-bg-<?= h(score_band((float)$kpiNow['avg_score'])) ?>">
                <?= h(score_band((float)$kpiNow['avg_score'])) ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="text-muted small">Results: <?= h((string)($kpiNow['n_results'] ?? 0)) ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="text-muted small">Median score</div>
              <div class="display-6 mb-0"><?= ($medianNow === null) ? '-' : h(number_format((float)$medianNow, 2)) ?></div>
            </div>
            <span class="badge text-bg-<?= h(badge_delta($deltaMedian)) ?>">
              Delta <?= h(fmt_delta($deltaMedian, 2)) ?>
            </span>
          </div>
          <div class="text-muted small mt-2">
            Pupils in scope: <?= h((string)($kpiNow['n_pupils'] ?? 0)) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="text-muted small">Pass rate (>= <?= h(number_format(PASS_SCORE, 0)) ?>)</div>
              <div class="display-6 mb-0">
                <?= ($passRateNow === null) ? '-' : h(number_format($passRateNow * 100, 1)) . '%' ?>
              </div>
            </div>
            <span class="badge text-bg-<?= h(badge_delta($deltaPass)) ?>">
              Delta <?= ($deltaPass === null) ? '-' : h(($deltaPass > 0 ? '+' : '') . number_format($deltaPass, 1)) ?> pp
            </span>
          </div>
          <div class="text-muted small mt-2">
            Targets: Pass <?= h((string)PASS_SCORE) ?> | Good <?= h((string)GOOD_SCORE) ?> | Excellent <?= h((string)EXCELLENT_SCORE) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="text-muted small">Scope summary</div>
          <div class="h5 mb-1"><?= ($classCode !== '') ? h($classCode) : 'All classes' ?></div>
          <div class="text-muted small">
            <?= ($track !== '') ? h($track) : 'All tracks' ?>
            <?= ($subjectId > 0) ? ' | One subject' : ' | All subjects' ?>
          </div>
          <div class="text-muted small mt-1">
            Active filters: <?= h((string)$activeFilterCount) ?>
          </div>
          <div class="mt-3">
            <a class="btn btn-outline-primary btn-sm" href="<?= h($fullReportsUrl) ?>">
              <i class="bi bi-list-check me-1"></i> Drill down in Reports
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Movers + Charts -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
              <div class="fw-semibold">Subject movers</div>
              <div class="text-muted small">Best / worst change vs previous exam</div>
            </div>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
              <i class="bi bi-arrows-move me-1"></i> Delta
            </span>
          </div>

          <?php if (!$hasPrev || $subjectId !== 0 || $effectiveExamId <= 0): ?>
            <div class="text-muted small">
              <?= $subjectId !== 0 ? 'Movers are shown only when Subject = All.' : '' ?>
              <?= !$hasPrev ? 'No previous exam in scope to compute delta.' : '' ?>
              <?= $effectiveExamId <= 0 ? 'No exam data available.' : '' ?>
            </div>
          <?php else: ?>
            <div class="row g-2">
              <div class="col-12">
                <div class="d-flex align-items-center justify-content-between">
                  <div class="text-muted small mb-1">Top improvements</div>
                  <span class="badge text-bg-success-subtle border text-success-emphasis mover-count">
                    <?= count($moversBest) ?> subjects
                  </span>
                </div>

                <?php if (!$moversBest): ?>
                  <div class="text-muted small">No improvements in this scope.</div>
                <?php else: ?>
                  <?php foreach ($moversBest as $m): ?>
                    <?php
                      $now  = (float)$m['avg_now'];
                      $prev = (float)$m['avg_prev'];
                      $d    = (float)$m['delta']; // positive
                    ?>
                    <div class="d-flex justify-content-between align-items-center mover-row">
                      <div class="d-flex align-items-start gap-2">
                        <div class="mover-icon text-success"><i class="bi bi-arrow-up-right"></i></div>
                        <div class="mover-name">
                          <a href="<?= h($dashboardUrl) ?>?<?= h(qs_merge(['subject_id' => (int)$m['subject_id']])) ?>">
                            <?= h($m['name']) ?>
                          </a>
                          <div class="mover-meta">
                            Prev <?= h(number_format($prev, 2)) ?> -> Now <?= h(number_format($now, 2)) ?>
                            | n=<?= h((string)$m['n']) ?>
                          </div>
                        </div>
                      </div>
                      <span class="badge text-bg-success"><?= h(fmt_delta($d, 2)) ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <div class="col-12 mt-2">
                <div class="d-flex align-items-center justify-content-between">
                  <div class="text-muted small mb-1">Top declines</div>
                  <span class="badge text-bg-danger-subtle border text-danger-emphasis mover-count">
                    <?= count($moversWorst) ?> subjects
                  </span>
                </div>

                <?php if (!$moversWorst): ?>
                  <div class="text-muted small">No declines in this scope.</div>
                <?php else: ?>
                  <?php foreach ($moversWorst as $m): ?>
                    <?php
                      $now  = (float)$m['avg_now'];
                      $prev = (float)$m['avg_prev'];
                      $d    = (float)$m['delta']; // negative
                    ?>
                    <div class="d-flex justify-content-between align-items-center mover-row">
                      <div class="d-flex align-items-start gap-2">
                        <div class="mover-icon text-danger"><i class="bi bi-arrow-down-right"></i></div>
                        <div class="mover-name">
                          <a href="<?= h($dashboardUrl) ?>?<?= h(qs_merge(['subject_id' => (int)$m['subject_id']])) ?>">
                            <?= h($m['name']) ?>
                          </a>
                          <div class="mover-meta">
                            Prev <?= h(number_format($prev, 2)) ?> -> Now <?= h(number_format($now, 2)) ?>
                            | n=<?= h((string)$m['n']) ?>
                          </div>
                        </div>
                      </div>
                      <span class="badge text-bg-danger"><?= h(fmt_delta($d, 2)) ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
              <div class="fw-semibold">Score distribution</div>
              <div class="text-muted small">Selected exam only (0-40 in 5-point bands)</div>
            </div>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
              <i class="bi bi-bar-chart me-1"></i> Histogram
            </span>
          </div>
          <canvas id="distChart" height="150" aria-label="Score distribution chart"></canvas>
          <div id="distNoData" class="text-muted small mt-2 d-none">
            No score rows available for this distribution.
          </div>
          <div class="text-muted small mt-2">
            Use this to detect clumping (too many low scores) or an easy paper (many 35+).
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
              <div class="fw-semibold">Trend across exams</div>
              <div class="text-muted small">Average score for the current scope</div>
            </div>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
              <i class="bi bi-graph-up me-1"></i> Trend
            </span>
          </div>
          <canvas id="trendChart" height="150" aria-label="Trend chart"></canvas>
          <div id="trendNoData" class="text-muted small mt-2 d-none">
            Not enough trend points in the current scope.
          </div>
          <div class="text-muted small mt-2">
            Tip: Apply a Subject filter to get a clearer learning progression signal.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Compact subject table + pupil snapshots -->
  <div class="row g-3">
    <div class="col-12 col-xl-6">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
              <div class="fw-semibold">Subject overview (top 12)</div>
              <div class="text-muted small">
                Avg score in the selected exam
                <?php if ($hasPrev): ?> + Delta vs previous<?php endif; ?>
              </div>
            </div>
            <a class="btn btn-outline-primary btn-sm" href="<?= h($fullReportsUrl) ?>">
              <i class="bi bi-box-arrow-up-right me-1"></i> Full subject analysis
            </a>
          </div>

          <?php if ($effectiveExamId <= 0): ?>
            <div class="text-muted">No exam data in the current scope.</div>
          <?php elseif ($subjectId !== 0): ?>
            <div class="text-muted small">
              This table is shown when Subject = All. You are currently filtering a single subject.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Subject</th>
                    <th class="text-end">Avg</th>
                    <th class="text-end d-none d-md-table-cell">Delta</th>
                    <th class="text-end d-none d-lg-table-cell">n</th>
                    <th class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($subjectRows as $r): ?>
                    <?php
                      $avg = (float)$r['avg_now'];
                      $delta = $r['delta'];
                      $band = score_band($avg);
                    ?>
                    <tr>
                      <td class="text-truncate" style="max-width: 260px;">
                        <?= h($r['name']) ?>
                      </td>
                      <td class="text-end">
                        <span class="badge text-bg-<?= h($band) ?>"><?= h(number_format($avg, 2)) ?></span>
                      </td>
                      <td class="text-end d-none d-md-table-cell">
                        <span class="badge text-bg-<?= h(badge_delta($delta)) ?>">
                          <?= h(fmt_delta($delta, 2)) ?>
                        </span>
                      </td>
                      <td class="text-end d-none d-lg-table-cell"><?= h((string)$r['n']) ?></td>
                      <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm"
                           href="<?= h($dashboardUrl) ?>?<?= h(qs_merge(['subject_id' => (int)$r['subject_id']])) ?>">
                          View
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$subjectRows): ?>
                    <tr><td colspan="5" class="text-muted">No subject rows in scope.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
      <div class="row g-3">
        <div class="col-12">
          <div class="card shadow-sm border-0">
            <div class="card-body">
              <div class="d-flex align-items-start justify-content-between mb-2">
                <div>
                  <div class="fw-semibold">At-risk snapshot</div>
                  <div class="text-muted small">
                    <?= $hasPrev ? 'Largest declines first (avg score)' : 'Lowest averages first' ?>
                  </div>
                </div>
                <a class="btn btn-outline-danger btn-sm" href="<?= h($fullReportsUrl) ?>">
                  <i class="bi bi-exclamation-triangle me-1"></i> Investigate in Reports
                </a>
              </div>

              <?php if ($effectiveExamId <= 0): ?>
                <div class="text-muted">No exam data.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>Pupil</th>
                        <th class="text-end">Avg</th>
                        <?php if ($hasPrev): ?><th class="text-end">Delta</th><?php endif; ?>
                        <th class="text-end"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($atRisk as $r): ?>
                        <?php
                          $avgNow = isset($r['avg_now']) ? (float)$r['avg_now'] : null;
                          $avgPrev = isset($r['avg_prev']) ? (float)$r['avg_prev'] : null;
                          $d = ($hasPrev && $avgNow !== null && $avgPrev !== null) ? ($avgNow - $avgPrev) : null;
                          $band = ($avgNow === null) ? 'secondary' : score_band($avgNow);
                        ?>
                        <tr>
                          <td class="text-truncate" style="max-width: 240px;">
                            <?= h((string)$r['short_name']) ?>
                            <span class="text-muted small">| <?= h((string)$r['class_code']) ?></span>
                          </td>
                          <td class="text-end">
                            <?= ($avgNow === null) ? '-' : '<span class="badge text-bg-' . h($band) . '">' . h(number_format($avgNow, 2)) . '</span>' ?>
                          </td>
                          <?php if ($hasPrev): ?>
                            <td class="text-end">
                              <span class="badge text-bg-<?= h(badge_delta($d)) ?>"><?= h(fmt_delta($d, 2)) ?></span>
                            </td>
                          <?php endif; ?>
                          <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h($pupilBaseUrl) ?>?id=<?= (int)$r['id'] ?>">
                              Profile
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!$atRisk): ?>
                        <tr><td colspan="<?= $hasPrev ? '4' : '3' ?>" class="text-muted">No at-risk pupils detected in this scope.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>

            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="card shadow-sm border-0">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12 col-md-6">
                  <div class="fw-semibold mb-2">Top performers</div>
                  <?php if (!$topPupils): ?>
                    <div class="text-muted">No data.</div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($topPupils as $p): ?>
                        <?php $avg = (float)$p['avg_score']; ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                          <div class="text-truncate" style="max-width: 220px;">
                            <?= h((string)$p['short_name']) ?>
                            <span class="text-muted small">| <?= h((string)$p['class_code']) ?></span>
                          </div>
                          <span class="badge text-bg-<?= h(score_band($avg)) ?>"><?= h(number_format($avg, 2)) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="col-12 col-md-6">
                  <div class="fw-semibold mb-2">Lowest performers</div>
                  <?php if (!$bottomPupils): ?>
                    <div class="text-muted">No data.</div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($bottomPupils as $p): ?>
                        <?php $avg = (float)$p['avg_score']; ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                          <div class="text-truncate" style="max-width: 220px;">
                            <?= h((string)$p['short_name']) ?>
                            <span class="text-muted small">| <?= h((string)$p['class_code']) ?></span>
                          </div>
                          <span class="badge text-bg-<?= h(score_band($avg)) ?>"><?= h(number_format($avg, 2)) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

              </div>
              <div class="text-muted small mt-2">
                Note: Rankings are based on average score in the selected exam scope (not overall historical).
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

</div>

<script nonce="<?= h($cspNonce) ?>">
(function(){
  const distLabels = <?= $jsDistLabels ?>;
  const distData   = <?= $jsDistData ?>;

  const trendLabels = <?= $jsTrendLabels ?>;
  const trendData   = <?= $jsTrendData ?>;
  const passScore = <?= json_encode(PASS_SCORE) ?>;
  const goodScore = <?= json_encode(GOOD_SCORE) ?>;
  const excellentScore = <?= json_encode(EXCELLENT_SCORE) ?>;

  const selectedIndex = <?= $jsSelectedTrendIndex ?>;

  function renderCharts(){
    if (!window.Chart) return;

    const distEl = document.getElementById('distChart');
    const distNoDataEl = document.getElementById('distNoData');
    if (distEl) {
      const hasDistData = Array.isArray(distData) && distData.some((v) => Number(v) > 0);
      if (!hasDistData) {
        distEl.classList.add('d-none');
        if (distNoDataEl) distNoDataEl.classList.remove('d-none');
      } else {
        const distColors = distData.map((_, i) => {
          const bucketStart = i * 5;
          if (bucketStart < passScore) return 'rgba(220,53,69,0.78)';
          if (bucketStart < goodScore) return 'rgba(255,193,7,0.78)';
          if (bucketStart < excellentScore) return 'rgba(13,110,253,0.78)';
          return 'rgba(25,135,84,0.78)';
        });
        const distBorder = distColors.map((c) => c.replace('0.78', '1'));

        if (distNoDataEl) distNoDataEl.classList.add('d-none');
        distEl.classList.remove('d-none');

      new Chart(distEl, {
        type: 'bar',
        data: {
          labels: distLabels,
          datasets: [{
            label: 'Count',
            data: distData,
            backgroundColor: distColors,
            borderColor: distBorder,
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => `Count: ${ctx.parsed.y}`
              }
            }
          },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      });
      }
    }

    const trendEl = document.getElementById('trendChart');
    const trendNoDataEl = document.getElementById('trendNoData');
    if (trendEl) {
      const hasTrend = Array.isArray(trendData) && trendData.length > 0;
      if (!hasTrend) {
        trendEl.classList.add('d-none');
        if (trendNoDataEl) trendNoDataEl.classList.remove('d-none');
        return;
      }

      const pointRadius = trendData.map((_, i) => (selectedIndex !== null && i === selectedIndex) ? 6 : 3);
      const pointHoverRadius = trendData.map((_, i) => (selectedIndex !== null && i === selectedIndex) ? 7 : 4);
      const pointBg = trendData.map((_, i) => (selectedIndex !== null && i === selectedIndex) ? 'rgba(13,110,253,1)' : 'rgba(13,110,253,0.72)');

      const thresholdDataset = (label, value, color) => ({
        label,
        data: trendData.map(() => value),
        borderColor: color,
        borderDash: [6, 4],
        borderWidth: 1,
        pointRadius: 0,
        pointHoverRadius: 0,
        tension: 0,
      });

      if (trendNoDataEl) trendNoDataEl.classList.add('d-none');
      trendEl.classList.remove('d-none');

      new Chart(trendEl, {
        type: 'line',
        data: {
          labels: trendLabels,
          datasets: [
            {
              label: 'Average score',
              data: trendData,
              tension: 0.25,
              pointRadius,
              pointHoverRadius,
              pointBackgroundColor: pointBg,
              borderWidth: 2
            },
            thresholdDataset('Pass', passScore, 'rgba(220,53,69,0.8)'),
            thresholdDataset('Good', goodScore, 'rgba(255,193,7,0.9)'),
            thresholdDataset('Excellent', excellentScore, 'rgba(25,135,84,0.9)')
          ]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: true },
            tooltip: { mode: 'index', intersect: false }
          },
          interaction: { mode: 'index', intersect: false },
          scales: { y: { beginAtZero: true, suggestedMax: 40 } }
        }
      });
    }
  }

  function ensureChartJsThenRender(){
    if (window.Chart) { renderCharts(); return; }

    const existing = document.querySelector('script[src*="chart.umd"], script[src*="chart.js"]');
    if (existing) { setTimeout(renderCharts, 0); return; }

    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    s.onload = renderCharts;
    s.async = true;
    document.head.appendChild(s);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureChartJsThenRender);
  } else {
    ensureChartJsThenRender();
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

