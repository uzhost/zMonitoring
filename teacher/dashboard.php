<?php
// teacher/dashboard.php — Teacher analytics dashboard (viewer role), filters + KPIs + rankings + charts
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

// Require login; teacher portal is read-only, but we allow viewer/admin/superadmin
if (admin_id() <= 0) {
    $to = $_SERVER['REQUEST_URI'] ?? '/teacher/dashboard.php';
    header('Location: /teacher/login.php?next=' . rawurlencode($to));
    exit;
}
if (!in_array(admin_role(), ['viewer', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

// CSP nonce (optional)
if (empty($_SESSION['csp_nonce']) || !is_string($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}
$cspNonce = $_SESSION['csp_nonce'];

// Security headers
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

$pageTitle = 'Teacher dashboard';

// -------------------- Filters --------------------
$year      = trim((string)($_GET['year'] ?? ''));
$examId    = (int)($_GET['exam_id'] ?? 0);
$classCode = trim((string)($_GET['class_code'] ?? ''));
$track     = trim((string)($_GET['track'] ?? ''));
$subjectId = (int)($_GET['subject_id'] ?? 0);

// Valid tracks (per schema)
$validTracks = ['Aniq', 'Tabiiy'];
if ($track !== '' && !in_array($track, $validTracks, true)) {
    $track = '';
}

// -------------------- Load filter options --------------------
$years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll();

// IMPORTANT FIX: do not reuse same named placeholder twice (PDO may have emulation disabled)
$examsStmt = $pdo->prepare("
  SELECT id, academic_year, term, exam_name, exam_date
  FROM exams
  WHERE (:y1 = '' OR academic_year = :y2)
  ORDER BY academic_year DESC, COALESCE(exam_date,'9999-12-31') DESC, id DESC
");
$examsStmt->execute([':y1' => $year, ':y2' => $year]);
$exams = $examsStmt->fetchAll();

$classes = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code")->fetchAll();
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();

// -------------------- Build WHERE for analytics --------------------
$where = ["1=1"];
$params = [];

if ($year !== '') {
    $where[] = "e.academic_year = :year";
    $params[':year'] = $year;
}
if ($examId > 0) {
    $where[] = "r.exam_id = :exam_id";
    $params[':exam_id'] = $examId;
}
if ($classCode !== '') {
    $where[] = "p.class_code = :class_code";
    $params[':class_code'] = $classCode;
}
if ($track !== '') {
    $where[] = "p.track = :track";
    $params[':track'] = $track;
}
if ($subjectId > 0) {
    $where[] = "r.subject_id = :subject_id";
    $params[':subject_id'] = $subjectId;
}

$whereSql = implode(" AND ", $where);

// -------------------- KPIs --------------------
$passThreshold = 18.4;

$kpiStmt = $pdo->prepare("
  SELECT
    COUNT(*) AS n_results,
    COUNT(DISTINCT r.pupil_id) AS n_pupils,
    AVG(r.score) AS avg_score
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereSql
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: ['n_results' => 0, 'n_pupils' => 0, 'avg_score' => null];

// Median (compute in PHP; OK for your dataset size)
$scoresStmt = $pdo->prepare("
  SELECT r.score
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereSql
  ORDER BY r.score
");
$scoresStmt->execute($params);

$scores = [];
while ($row = $scoresStmt->fetch()) {
    $scores[] = (float)$row['score'];
}

$median = null;
$n = count($scores);
if ($n > 0) {
    $mid = intdiv($n, 2);
    $median = ($n % 2 === 1) ? $scores[$mid] : (($scores[$mid - 1] + $scores[$mid]) / 2.0);
}

$passRate = null;
if ((int)$kpi['n_results'] > 0) {
    $passStmt = $pdo->prepare("
      SELECT AVG(CASE WHEN r.score >= :pass THEN 1 ELSE 0 END) AS pass_rate
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      JOIN exams  e ON e.id = r.exam_id
      WHERE $whereSql
    ");
    $passStmt->execute($params + [':pass' => $passThreshold]);
    $passRate = (float)($passStmt->fetch()['pass_rate'] ?? 0.0);
}

// -------------------- Rankings (by total score per pupil in scope) --------------------
$rankStmt = $pdo->prepare("
  SELECT
    p.id,
    p.class_code,
    p.track,
    CONCAT(p.surname, ' ', p.name, IF(p.middle_name IS NULL OR p.middle_name='', '', CONCAT(' ', p.middle_name))) AS full_name,
    SUM(r.score) AS total_score,
    AVG(r.score) AS avg_score,
    COUNT(*) AS n_subjects
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereSql
  GROUP BY p.id, p.class_code, p.track, full_name
  HAVING COUNT(*) > 0
  ORDER BY total_score DESC, avg_score DESC
  LIMIT 12
");
$rankStmt->execute($params);
$topPupils = $rankStmt->fetchAll();

$bottomStmt = $pdo->prepare("
  SELECT
    p.id,
    p.class_code,
    p.track,
    CONCAT(p.surname, ' ', p.name, IF(p.middle_name IS NULL OR p.middle_name='', '', CONCAT(' ', p.middle_name))) AS full_name,
    SUM(r.score) AS total_score,
    AVG(r.score) AS avg_score,
    COUNT(*) AS n_subjects
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereSql
  GROUP BY p.id, p.class_code, p.track, full_name
  HAVING COUNT(*) > 0
  ORDER BY total_score ASC, avg_score ASC
  LIMIT 12
");
$bottomStmt->execute($params);
$bottomPupils = $bottomStmt->fetchAll();

// -------------------- Distribution (0..40 buckets in 5-point bands) --------------------
$distStmt = $pdo->prepare("
  SELECT
    FLOOR(LEAST(GREATEST(r.score,0),40) / 5) * 5 AS bucket,
    COUNT(*) AS cnt
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE $whereSql
  GROUP BY bucket
  ORDER BY bucket
");
$distStmt->execute($params);

$distMap = array_fill(0, 9, 0); // 0,5,10,...,40
while ($row = $distStmt->fetch()) {
    $b = (int)$row['bucket'];
    $idx = (int)($b / 5);
    if ($idx >= 0 && $idx <= 8) {
        $distMap[$idx] = (int)$row['cnt'];
    }
}

$distLabels = [];
for ($i = 0; $i <= 8; $i++) {
    $start = $i * 5;
    $end = $start + 4;
    $distLabels[] = ($start === 40) ? "40" : "{$start}-{$end}";
}

// -------------------- Trend (avg score across exams) --------------------
$trendSql = "
  SELECT
    e.id AS exam_id,
    CONCAT(e.academic_year, ' T', COALESCE(e.term,'-'), ' — ', e.exam_name) AS label,
    COALESCE(e.exam_date, '9999-12-31') AS d,
    AVG(r.score) AS avg_score
  FROM results r
  JOIN pupils p ON p.id = r.pupil_id
  JOIN exams  e ON e.id = r.exam_id
  WHERE 1=1
";
$trendParams = [];

if ($classCode !== '') { $trendSql .= " AND p.class_code = :class_code_t";  $trendParams[':class_code_t'] = $classCode; }
if ($track !== '')     { $trendSql .= " AND p.track = :track_t";           $trendParams[':track_t'] = $track; }
if ($subjectId > 0)    { $trendSql .= " AND r.subject_id = :subject_id_t"; $trendParams[':subject_id_t'] = $subjectId; }
if ($year !== '')      { $trendSql .= " AND e.academic_year = :year_t";    $trendParams[':year_t'] = $year; }

$trendSql .= " GROUP BY e.id, label, d ORDER BY d ASC, e.id ASC";

$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute($trendParams);

$trend = [];
while ($row = $trendStmt->fetch()) {
    $trend[] = [
        'label' => (string)$row['label'],
        'avg'   => (float)$row['avg_score'],
    ];
}

// -------------------- Layout: include existing header/footer in SAME folder --------------------
require_once __DIR__ . '/header.php';
?>

<main class="container-fluid py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <h1 class="h4 mb-0">Dashboard</h1>
      <span class="badge text-bg-primary-subtle border text-primary-emphasis">
        <i class="bi bi-graph-up me-1"></i> Read-only analytics
      </span>
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
          <label class="form-label">Exam</label>
          <select class="form-select" name="exam_id">
            <option value="0">All</option>
            <?php foreach ($exams as $e): ?>
              <?php
                $id = (int)$e['id'];
                $label = (string)$e['exam_name'];
                $ay = (string)$e['academic_year'];
                $t  = ($e['term'] === null) ? '-' : (string)$e['term'];
                $d  = (string)($e['exam_date'] ?? '');
                $full = trim($ay . ' T' . $t . ' — ' . $label . ($d !== '' ? ' (' . $d . ')' : ''));
              ?>
              <option value="<?= $id ?>" <?= ($id === $examId) ? 'selected' : '' ?>><?= h($full) ?></option>
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
          <a class="btn btn-outline-secondary" href="/teacher/dashboard.php">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="text-muted small">Average score</div>
          <div class="display-6 metric mb-0"><?= ($kpi['avg_score'] === null) ? '—' : h(number_format((float)$kpi['avg_score'], 2)) ?></div>
          <div class="text-muted small">Results: <?= h((string)$kpi['n_results']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="text-muted small">Median score</div>
          <div class="display-6 metric mb-0"><?= ($median === null) ? '—' : h(number_format((float)$median, 2)) ?></div>
          <div class="text-muted small">Pupils: <?= h((string)$kpi['n_pupils']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="text-muted small">Pass rate (>= <?= h(number_format($passThreshold, 0)) ?>)</div>
          <div class="display-6 metric mb-0"><?= ($passRate === null) ? '—' : h(number_format($passRate * 100, 1)) . '%' ?></div>
          <div class="text-muted small">Threshold configurable</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="text-muted small">Scope</div>
          <div class="h5 mb-1"><?= ($classCode !== '') ? h($classCode) : 'All classes' ?></div>
          <div class="text-muted small">
            <?= ($track !== '') ? h($track) : 'All tracks' ?>
            <?= ($subjectId > 0) ? ' · Subject filtered' : '' ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts + Rankings -->
  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div>
              <div class="fw-semibold">Score distribution</div>
              <div class="text-muted small">Buckets (0–40)</div>
            </div>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
              <i class="bi bi-bar-chart me-1"></i> Histogram
            </span>
          </div>
          <canvas id="distChart" height="130"></canvas>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div>
              <div class="fw-semibold">Trend over exams</div>
              <div class="text-muted small">Average score by exam (best with a subject filter)</div>
            </div>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
              <i class="bi bi-graph-up me-1"></i> Trend
            </span>
          </div>
          <canvas id="trendChart" height="130"></canvas>
        </div>
      </div>
    </div>
  </div>
</main>

<?php
// Prepare chart data for JS
$jsDistLabels  = json_encode($distLabels, JSON_UNESCAPED_UNICODE);
$jsDistData    = json_encode(array_values($distMap), JSON_UNESCAPED_UNICODE);
$jsTrendLabels = json_encode(array_map(static fn($t) => $t['label'], $trend), JSON_UNESCAPED_UNICODE);
$jsTrendData   = json_encode(array_map(static fn($t) => $t['avg'], $trend), JSON_UNESCAPED_UNICODE);
?>

<script nonce="<?= h($cspNonce) ?>">
(function(){
  const distLabels = <?= $jsDistLabels ?>;
  const distData   = <?= $jsDistData ?>;

  const trendLabels = <?= $jsTrendLabels ?>;
  const trendData   = <?= $jsTrendData ?>;

  function renderCharts(){
    if (!window.Chart) return;

    const distEl = document.getElementById('distChart');
    if (distEl) {
      new Chart(distEl, {
        type: 'bar',
        data: { labels: distLabels, datasets: [{ label: 'Count', data: distData }] },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      });
    }

    const trendEl = document.getElementById('trendChart');
    if (trendEl) {
      new Chart(trendEl, {
        type: 'line',
        data: { labels: trendLabels, datasets: [{ label: 'Average score', data: trendData, tension: 0.25 }] },
        options: {
          responsive: true,
          plugins: { legend: { display: true } },
          scales: { y: { beginAtZero: true, suggestedMax: 40 } }
        }
      });
    }
  }

  function ensureChartJsThenRender(){
    if (window.Chart) { renderCharts(); return; }

    // If footer.php already includes Chart.js, it will be available by the time DOMContentLoaded fires.
    // If not, we load it dynamically (safe fallback).
    const existing = document.querySelector('script[src*="chart.umd"], script[src*="chart.js"]');
    if (existing) {
      // Wait a tick to allow it to evaluate
      setTimeout(renderCharts, 0);
      return;
    }

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
