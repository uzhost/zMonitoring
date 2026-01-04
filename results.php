<?php
// results.php — Public Student Results (No Login): Trends + Charts + Rank in class & parallels (grade-wide by track)

declare(strict_types=1);

// -----------------------------
// Bootstrap (core dependencies)
// -----------------------------
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php'; // session_start_secure(), h(), csrf_token(), verify_csrf()

// -----------------------------
// Security headers + CSP nonce
// -----------------------------
$cspNonce = base64_encode(random_bytes(16));

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Public results: avoid caching shared computers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// CSP: allow CDNs for Bootstrap/Icons/AOS/Chart.js; nonce-protect inline script
header(
  "Content-Security-Policy: " .
  "default-src 'self'; " .
  "base-uri 'self'; frame-ancestors 'none'; " .
  "img-src 'self' https: data:; " .
  "font-src 'self' https: data:; " .
  "style-src 'self' https: 'unsafe-inline'; " . // Bootstrap uses CSS; inline style attributes exist in page
  "script-src 'self' https: 'nonce-{$cspNonce}'; " .
  "connect-src 'self' https:;"
);

// -----------------------------
// Session + light rate limiting (public)
// -----------------------------
session_start_secure();
$now = time();
$_SESSION['public_rl'] ??= [];
$_SESSION['public_rl'] = array_values(array_filter(
  $_SESSION['public_rl'],
  static fn($t) => is_int($t) && (($now - $t) <= 600)
));
if (count($_SESSION['public_rl']) >= 80) {
  http_response_code(429);
  echo 'Too many requests. Please try again later.';
  exit;
}
$_SESSION['public_rl'][] = $now;

// -----------------------------
// Helpers (page-local only; do NOT duplicate core helpers)
// -----------------------------
function norm_login(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', '', $s) ?? '';
  return $s;
}

function valid_login(string $s): bool {
  return $s !== '' && strlen($s) <= 20 && (bool)preg_match('/^[A-Za-z0-9_.-]{2,20}$/', $s);
}

function score_class(float $score): string {
  // Keep your score banding (as you had)
  if ($score <= 18.4) return 'text-bg-danger';
  if ($score <= 24.4) return 'text-bg-warning';
  if ($score <= 34.4) return 'text-bg-primary';
  return 'text-bg-success';
}

function delta_badge(float $delta): array {
  if (abs($delta) < 0.0001) return ['text-bg-secondary', 'bi-dash-lg', 'No change'];
  if ($delta > 0) return ['text-bg-success', 'bi-arrow-up-right', 'Up'];
  return ['text-bg-danger', 'bi-arrow-down-right', 'Down'];
}

function fmt1(float $v): string {
  $s = number_format($v, 1, '.', '');
  return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
}

function pct_change(float $base, float $delta): ?float {
  if (abs($base) < 0.0001) return null;
  return ($delta / $base) * 100.0;
}

/**
 * Extract grade number from class code.
 * Examples: "10-A" => 10, "7B" => 7, "11-ALPHA" => 11
 */
function extract_grade(string $classCode): ?int {
  if (preg_match('/^\s*(\d{1,2})\b/', $classCode, $m)) return (int)$m[1];
  return null;
}

/**
 * Tiny SVG sparkline.
 * @param float[] $values
 */
function sparkline_svg(array $values, int $w = 140, int $h = 34): string {
  $n = count($values);
  if ($n < 2) return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true"></svg>';

  $min = min($values);
  $max = max($values);
  if (abs($max - $min) < 0.0001) { $max = $min + 1.0; }

  $pad = 3;
  $innerW = $w - $pad * 2;
  $innerH = $h - $pad * 2;

  $pts = [];
  for ($i = 0; $i < $n; $i++) {
    $x = $pad + ($innerW * ($i / ($n - 1)));
    $yNorm = ($values[$i] - $min) / ($max - $min);
    $y = $pad + ($innerH * (1.0 - $yNorm));
    $pts[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
  }

  $last = $values[$n - 1];
  $first = $values[0];
  $stroke = ($last > $first + 0.0001) ? '#198754' : (($last < $first - 0.0001) ? '#dc3545' : '#6c757d');

  return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true">'
    . '<polyline fill="none" stroke="'.$stroke.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="'.h(implode(' ', $pts)).'"></polyline>'
    . '</svg>';
}

/**
 * Compute basic stats: avg, stdev.
 * @param float[] $vals
 */
function stats(array $vals): array {
  $n = count($vals);
  if ($n === 0) return ['avg' => null, 'stdev' => null, 'min' => null, 'max' => null];
  $avg = array_sum($vals) / $n;
  $var = 0.0;
  foreach ($vals as $v) $var += ($v - $avg) ** 2;
  $stdev = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
  return ['avg' => $avg, 'stdev' => $stdev, 'min' => min($vals), 'max' => max($vals)];
}

// -----------------------------
// Input handling
// -----------------------------
$student_login = '';
$searched = false;
$error = '';
$pupil = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['student_login'])) {
  $searched = true;
  $student_login = norm_login((string)$_GET['student_login']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  verify_csrf('csrf');
  $searched = true;
  $student_login = norm_login((string)($_POST['student_login'] ?? ''));
}

// -----------------------------
// Data containers
// -----------------------------
$timeline = [];
$byExam = [];
$subjectsIndex = [];
$subjectSeries = [];
$totalsSeries = [];
$classStatsByExam = [];
$parallelStatsByExam = [];

$insights = [
  'strengths' => [],
  'needs_support' => [],
  'biggest_up' => null,
  'biggest_down' => null,
  'risk_flags' => [],
];

// -----------------------------
// Main logic
// -----------------------------
try {
  if ($searched) {
    if (!valid_login($student_login)) {
      $error = 'Please enter a valid Student Login (2–20 chars; letters, digits, underscore, dash, dot).';
    } else {
      $stmt = $pdo->prepare("
        SELECT id, surname, name, middle_name, class_code, track, student_login
        FROM pupils
        WHERE student_login = ?
        LIMIT 1
      ");
      $stmt->execute([$student_login]);
      $pupil = $stmt->fetch() ?: null;
      if (!$pupil) {
        $error = 'Student not found. Please check the Student Login.';
      }
    }
  }

  if ($pupil && $error === '') {
    // All results rows
    $stmt = $pdo->prepare("
      SELECT
        r.exam_id,
        r.subject_id,
        CAST(r.score AS DECIMAL(6,1)) AS score,
        e.academic_year, e.term, e.exam_name, e.exam_date,
        s.name AS subject_name, s.code AS subject_code, s.max_points
      FROM results r
      INNER JOIN exams e ON e.id = r.exam_id
      INNER JOIN subjects s ON s.id = r.subject_id
      WHERE r.pupil_id = ?
      ORDER BY
        e.academic_year ASC,
        COALESCE(e.term, 99) ASC,
        COALESCE(e.exam_date, '2099-12-31') ASC,
        e.id ASC,
        s.name ASC
    ");
    $stmt->execute([(int)$pupil['id']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
      $examId = (int)$r['exam_id'];
      $subId  = (int)$r['subject_id'];
      $score  = (float)$r['score'];
      $maxPts = (float)((int)$r['max_points']);

      $subjectsIndex[$subId] = [
        'id' => $subId,
        'name' => (string)$r['subject_name'],
        'code' => (string)$r['subject_code'],
        'max_points' => (int)$r['max_points'],
      ];

      if (!isset($byExam[$examId])) {
        $byExam[$examId] = [
          'exam' => [
            'id' => $examId,
            'academic_year' => (string)$r['academic_year'],
            'term' => $r['term'] !== null ? (int)$r['term'] : null,
            'exam_name' => (string)$r['exam_name'],
            'exam_date' => $r['exam_date'],
          ],
          'subjects' => [],
          'total' => 0.0,
          'max_total' => 0.0,
        ];
      }

      $byExam[$examId]['subjects'][$subId] = [
        'subject_id' => $subId,
        'subject_name' => (string)$r['subject_name'],
        'score' => $score,
        'max_points' => (int)$r['max_points'],
      ];
      $byExam[$examId]['total'] += $score;
      $byExam[$examId]['max_total'] += $maxPts;
    }

    // Chronological timeline
    $timeline = array_values(array_map(static fn($x) => $x['exam'], $byExam));

    // Series per subject + totals
    $prevBySubject = [];
    foreach ($timeline as $ex) {
      $examId = (int)$ex['id'];
      $totalsSeries[] = (float)$byExam[$examId]['total'];

      foreach ($subjectsIndex as $sid => $_meta) {
        $sid = (int)$sid;
        $score = isset($byExam[$examId]['subjects'][$sid]) ? (float)$byExam[$examId]['subjects'][$sid]['score'] : null;
        if ($score === null) continue;

        $prev = $prevBySubject[$sid] ?? null;
        $delta = ($prev === null) ? 0.0 : ($score - $prev);

        $subjectSeries[$sid][] = [
          'exam' => $ex,
          'score' => $score,
          'delta' => $delta,
        ];
        $prevBySubject[$sid] = $score;
      }
    }

    // Rank in class
    $rankStmt = $pdo->prepare("
      WITH totals AS (
        SELECT p.id AS pupil_id, SUM(r.score) AS total
        FROM results r
        INNER JOIN pupils p ON p.id = r.pupil_id
        WHERE p.class_code = ?
          AND r.exam_id = ?
        GROUP BY p.id
      ),
      ranked AS (
        SELECT
          pupil_id, total,
          RANK() OVER (ORDER BY total DESC) AS rnk,
          COUNT(*) OVER () AS cnt,
          AVG(total) OVER () AS avg_total
        FROM totals
      )
      SELECT rnk, cnt, avg_total
      FROM ranked
      WHERE pupil_id = ?
      LIMIT 1
    ");

    // Rank in parallels: same grade + optionally same track
    $grade = extract_grade((string)$pupil['class_code']);
    $track = (string)($pupil['track'] ?? '');

    // IMPORTANT FIX: grade 1 must NOT match 10/11/... => use REGEXP '^GRADE([^0-9]|$)'
    $parallelStmt = $pdo->prepare("
      WITH totals AS (
        SELECT p.id AS pupil_id, SUM(r.score) AS total
        FROM results r
        INNER JOIN pupils p ON p.id = r.pupil_id
        WHERE p.class_code REGEXP ?
          AND ( ? = '' OR p.track = ? )
          AND r.exam_id = ?
        GROUP BY p.id
      ),
      ranked AS (
        SELECT
          pupil_id, total,
          RANK() OVER (ORDER BY total DESC) AS rnk,
          COUNT(*) OVER () AS cnt,
          AVG(total) OVER () AS avg_total
        FROM totals
      )
      SELECT rnk, cnt, avg_total
      FROM ranked
      WHERE pupil_id = ?
      LIMIT 1
    ");

    foreach ($timeline as $ex) {
      $examId = (int)$ex['id'];

      // class stats
      $rankStmt->execute([(string)$pupil['class_code'], $examId, (int)$pupil['id']]);
      $st = $rankStmt->fetch();
      $classStatsByExam[$examId] = [
        'rank' => $st ? (int)$st['rnk'] : null,
        'count' => $st ? (int)$st['cnt'] : 0,
        'avg_total' => $st ? (float)$st['avg_total'] : null,
      ];

      // parallels stats
      if ($grade !== null) {
        $regex = '^' . $grade . '([^0-9]|$)';
        $parallelStmt->execute([$regex, $track, $track, $examId, (int)$pupil['id']]);
        $pt = $parallelStmt->fetch();
        $parallelStatsByExam[$examId] = [
          'rank' => $pt ? (int)$pt['rnk'] : null,
          'count' => $pt ? (int)$pt['cnt'] : 0,
          'avg_total' => $pt ? (float)$pt['avg_total'] : null,
          'grade' => $grade,
          'track' => $track,
        ];
      }
    }

    // Insights
    $subjectRowsForInsights = [];
    foreach ($subjectSeries as $sid => $series) {
      $n = count($series);
      if ($n === 0) continue;

      $last = $series[$n - 1];
      $delta = (float)$last['delta'];

      $maxPoints = (float)($subjectsIndex[(int)$sid]['max_points'] ?? 40);
      $pct = $maxPoints > 0 ? ((float)$last['score'] / $maxPoints) * 100.0 : null;

      $vals = array_map(static fn($x) => (float)$x['score'], $series);
      $st = stats($vals);

      $subjectRowsForInsights[] = [
        'sid' => (int)$sid,
        'name' => (string)$subjectsIndex[(int)$sid]['name'],
        'latest' => (float)$last['score'],
        'delta' => $delta,
        'latest_pct' => $pct,
        'avg' => $st['avg'],
        'stdev' => $st['stdev'],
      ];
    }

    foreach ($subjectRowsForInsights as $sr) {
      $pct = $sr['latest_pct'];
      if ($pct !== null && $pct >= 75.0 && $sr['latest'] >= 30.0) {
        $insights['strengths'][] = $sr;
      } elseif ($pct !== null && ($pct < 50.0 || $sr['delta'] <= -3.0)) {
        $insights['needs_support'][] = $sr;
      }
    }

    usort($subjectRowsForInsights, static fn($a, $b) => $b['delta'] <=> $a['delta']);
    $insights['biggest_up'] = $subjectRowsForInsights[0] ?? null;

    usort($subjectRowsForInsights, static fn($a, $b) => $a['delta'] <=> $b['delta']);
    $insights['biggest_down'] = $subjectRowsForInsights[0] ?? null;

    // Risk flags
    $nT = count($totalsSeries);
    if ($nT >= 3) {
      $d1 = $totalsSeries[$nT - 1] - $totalsSeries[$nT - 2];
      $d2 = $totalsSeries[$nT - 2] - $totalsSeries[$nT - 3];
      if ($d1 < -0.0001 && $d2 < -0.0001) {
        $insights['risk_flags'][] = 'Two consecutive declines in total score.';
      }
    }

    $latestExamIdChrono = $timeline[count($timeline) - 1]['id'] ?? null;
    if ($latestExamIdChrono) {
      $maxTotal = (float)($byExam[(int)$latestExamIdChrono]['max_total'] ?? 0.0);
      $latestTotal = (float)($byExam[(int)$latestExamIdChrono]['total'] ?? 0.0);
      if ($maxTotal > 0) {
        $latestPctTotal = ($latestTotal / $maxTotal) * 100.0;
        if ($latestPctTotal < 50.0) $insights['risk_flags'][] = 'Latest total is below 50% of maximum possible.';
      }
    }
  }
} catch (Throwable $e) {
  // Never show stack traces publicly; log server-side if needed.
  $error = 'A system error occurred while preparing results. Please try again later.';
  $pupil = null;
  $timeline = [];
}

/// -----------------------------
// NEW: pick up to 4 exams for subject-by-subject comparison
// Default: last 4 exams (chronological). If fewer exist, use all.
// -----------------------------
$cmpExams = [];
$cmpLabels = [];
$cmpExamIds = [];

$timelineCount = count($timeline);
if ($timelineCount > 0) {
  $sliceStart = max(0, $timelineCount - 4);
  $cmpExams = array_slice($timeline, $sliceStart, 4); // chronological order
}

foreach ($cmpExams as $ex) {
  $eid = (int)$ex['id'];
  $cmpExamIds[] = $eid;

  $term = $ex['term'] ? ('T'.$ex['term']) : 'T-';
  $cmpLabels[] =
    $ex['academic_year'].' · '.$term.' · '.(($ex['exam_name'] ?? '') !== '' ? $ex['exam_name'] : 'Exam')
    .' · '.(($ex['exam_date'] ?? '') !== '' ? $ex['exam_date'] : '—');
}

// Build subject lists in stable order (by name)
$cmpSubjects = array_values($subjectsIndex);
usort($cmpSubjects, static fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));

$cmpSubLabels = [];
$cmpDatasets = []; // array of arrays: each exam => scores aligned to subjects
foreach ($cmpExamIds as $eid) $cmpDatasets[(string)$eid] = [];

foreach ($cmpSubjects as $s) {
  $sid = (int)$s['id'];
  $cmpSubLabels[] = (string)$s['name'];

  foreach ($cmpExamIds as $eid) {
    $v = null;
    if (isset($byExam[$eid]['subjects'][$sid])) {
      $v = (float)$byExam[$eid]['subjects'][$sid]['score'];
    }
    // Use nulls so Chart.js skips missing bars rather than showing 0
    $cmpDatasets[(string)$eid][] = ($v === null ? null : (float)$v);
  }
}

// Suggested max (keeps chart readable and consistent)
$cmpSuggestedMax = 40;
if (!empty($cmpSubjects)) {
  $m = 0;
  foreach ($cmpSubjects as $s) {
    $m = max($m, (int)($s['max_points'] ?? 40));
  }
  $cmpSuggestedMax = max(40, $m);
}



// -----------------------------
// Render
// -----------------------------
$pageTitle = 'Student Results (Public)';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">
  <noscript><style>[data-aos]{opacity:1 !important; transform:none !important;}</style></noscript>

  <style>
    body { background: #f6f7fb; }
    .hero {
      background:
        radial-gradient(1200px 420px at 10% 10%, rgba(13,110,253,.16), transparent 55%),
        radial-gradient(900px 360px at 90% 20%, rgba(25,135,84,.14), transparent 55%),
        #fff;
      border-bottom: 1px solid rgba(0,0,0,.06);
    }
    .mono { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }
    .small-muted { color: rgba(33,37,41,.65); }
    .table thead th { white-space: nowrap; }
    .score-pill { min-width: 56px; display:inline-flex; justify-content:center; }
    .spark svg { display:block; }
    .sticky-top-2 { top: .75rem; }
    .kpi-card { border: 1px solid rgba(0,0,0,.08); }
    .glass {
      background: rgba(255,255,255,.75);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(0,0,0,.06);
    }
    .section-title {
      letter-spacing: .02em;
      text-transform: uppercase;
      font-size: .78rem;
      color: rgba(33,37,41,.65);
    }
    .pill {
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.25rem .55rem;
      border-radius: 999px;
      border:1px solid rgba(0,0,0,.08);
      background:#fff;
      font-size: .85rem;
    }
    .badge-soft {
      background: rgba(13,110,253,.08);
      border: 1px solid rgba(13,110,253,.18);
      color: rgba(13,110,253,.9);
    }
    .badge-soft-success {
      background: rgba(25,135,84,.10);
      border: 1px solid rgba(25,135,84,.22);
      color: rgba(25,135,84,.95);
    }
    .badge-soft-danger {
      background: rgba(220,53,69,.10);
      border: 1px solid rgba(220,53,69,.22);
      color: rgba(220,53,69,.95);
    }
    .badge-soft-warning {
      background: rgba(255,193,7,.14);
      border: 1px solid rgba(255,193,7,.30);
      color: rgba(102,77,3,.95);
    }
    .shadow-soft { box-shadow: 0 10px 30px rgba(0,0,0,.06); }
    .chart-wrap { position: relative; height: 240px; }
    .chart-wrap-sm { position: relative; height: 180px; }
  </style>
</head>
<body>

<div class="hero py-4">
  <div class="container" data-aos="fade-down">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h1 class="h3 mb-1">Student Results Viewer</h1>
        <div class="small-muted">Enter a student login to view term-by-term performance, subject comparisons, ranks, and trends.</div>
      </div>
      <div class="text-end">
        <a class="btn btn-outline-secondary" href="/">
          <i class="bi bi-house-door me-1"></i> Main Page
        </a>
      </div>
    </div>

    <div class="mt-3">
      <form method="post" class="row g-2 align-items-center">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="col-sm-7 col-md-5 col-lg-4">
          <label class="form-label mb-1">Student Login</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
            <input class="form-control" name="student_login" value="<?= h($student_login) ?>" placeholder="e.g., 10A-023" maxlength="20" autocomplete="off" required>
          </div>
          </div>
        <div class="col-sm-auto pt-sm-4">
          <button class="btn btn-primary shadow-soft">
            <i class="bi bi-search me-1"></i> View results
          </button>
        </div>
      </form>

      <?php if ($error): ?>
        <div class="alert alert-danger mt-3 mb-0" data-aos="fade-up">
          <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
      <?php elseif ($searched && $pupil && empty($timeline)): ?>
        <div class="alert alert-warning mt-3 mb-0" data-aos="fade-up">
          <i class="bi bi-info-circle me-1"></i>No results found for this student yet.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="container my-4">

<?php if ($pupil && !empty($timeline)): ?>
  <?php
    $fullName = trim($pupil['surname'].' '.$pupil['name'].' '.($pupil['middle_name'] ?? ''));
    $grade = extract_grade((string)$pupil['class_code']);
    $track = (string)($pupil['track'] ?? '');

    $latestChrono = $timeline[count($timeline) - 1];
    $latestExamId = (int)$latestChrono['id'];
    $latestTotal = (float)$byExam[$latestExamId]['total'];
    $latestMaxTotal = (float)$byExam[$latestExamId]['max_total'];
    $latestTotalPct = ($latestMaxTotal > 0) ? ($latestTotal / $latestMaxTotal) * 100.0 : null;

    $firstChrono = $timeline[0];
    $firstTotal = (float)$byExam[(int)$firstChrono['id']]['total'];

    $totalDeltaAll = $latestTotal - $firstTotal;
    $totalPctAll = pct_change($firstTotal, $totalDeltaAll);
    [$dClass, $dIcon] = delta_badge($totalDeltaAll);

    $timelineDesc = array_reverse($timeline);

    $labelsChrono = [];
    foreach ($timeline as $ex) {
      $term = $ex['term'] ? ('T'.$ex['term']) : 'T-';
      $labelsChrono[] = $ex['academic_year'].' '.$term;
    }
    $totalsChrono = array_map(static fn($v) => (float)$v, $totalsSeries);

    // Latest-vs-previous subject deltas table
    $subjectRows = [];
    foreach ($subjectSeries as $sid => $series) {
      $n = count($series);
      if ($n === 0) continue;
      $last = $series[$n-1];
      $delta = (float)$last['delta'];
      $prevScore = $n >= 2 ? (float)$series[$n-2]['score'] : 0.0;
      $pct = ($n >= 2) ? pct_change($prevScore, $delta) : null;

      $vals = array_map(static fn($x) => (float)$x['score'], $series);
      $subjectRows[] = [
        'sid' => (int)$sid,
        'name' => (string)$subjectsIndex[(int)$sid]['name'],
        'latest' => (float)$last['score'],
        'delta' => $delta,
        'pct' => $pct,
        'spark' => $vals,
        'series_full' => $series,
      ];
    }
    usort($subjectRows, static fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

    $deltaLabels = array_map(static fn($sr) => $sr['name'], $subjectRows);
    $deltaValues = array_map(static fn($sr) => (float)$sr['delta'], $subjectRows);
    
    // --- NEW: per-bar colors for delta chart (green up, red down, gray flat) ---
    $deltaBarColors = array_map(static function($v) {
      $v = (float)$v;
      if (abs($v) < 0.0001) return 'rgba(108,117,125,0.85)'; // gray
      return $v > 0 ? 'rgba(25,135,84,0.85)' : 'rgba(220,53,69,0.85)'; // green / red
    }, $deltaValues);
    
    $deltaBarBorderColors = array_map(static function($v) {
      $v = (float)$v;
      if (abs($v) < 0.0001) return 'rgba(108,117,125,1)';
      return $v > 0 ? 'rgba(25,135,84,1)' : 'rgba(220,53,69,1)';
    }, $deltaValues);
    
    $clsLatest = $classStatsByExam[$latestExamId] ?? ['rank'=>null,'count'=>0,'avg_total'=>null];
    $parLatest = $parallelStatsByExam[$latestExamId] ?? ['rank'=>null,'count'=>0,'avg_total'=>null,'grade'=>$grade,'track'=>$track];
  ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card shadow-soft sticky-top sticky-top-2 glass" data-aos="fade-up">
        <div class="card-body">
         <div class="border rounded-3 p-3 mb-3 bg-white shadow-soft" data-aos="zoom-in">
          <div class="section-title">Student</div>
          <div class="h5 mb-1"><?= h($fullName) ?></div>

          <div class="d-flex flex-wrap gap-2 mt-2">
            <span class="pill"><i class="bi bi-mortarboard"></i><?= h($pupil['class_code']) ?></span>
            <span class="pill"><i class="bi bi-diagram-3"></i><?= h($track !== '' ? $track : '—') ?></span>
            <span class="pill"><i class="bi bi-key"></i><?= h($pupil['student_login']) ?></span>
          </div>

          <div class="mt-3">
            <div class="section-title">Overall trend</div>
            <div class="spark mt-1"><?= sparkline_svg($totalsChrono, 260, 44) ?></div>
          </div>

          <hr class="my-3">

          <div class="row g-2">
              <div class="col-6">
                <div class="kpi-card rounded-3 p-2 bg-white h-100 d-flex flex-column">
                  <div class="section-title">Latest total</div>
            
                  <div class="kpi-value-row d-flex align-items-center" style="min-height: 44px;">
                    <div class="h4 mb-0 mono"><?= h(fmt1($latestTotal)) ?></div>
                  </div>
            
                  <div class="small-muted mt-auto">
                    <?= $latestTotalPct === null ? '—' : h(number_format($latestTotalPct, 1)).'%' ?> of max
                  </div>
                </div>
              </div>
            
              <div class="col-6">
                <div class="kpi-card rounded-3 p-2 bg-white h-100 d-flex flex-column">
                  <div class="section-title">Change (all)</div>
            
                  <div class="kpi-value-row d-flex align-items-center gap-2 flex-wrap" style="min-height: 44px;">
                    <span class="badge <?= h($dClass) ?> mono">
                      <i class="bi <?= h($dIcon) ?> me-1"></i><?= h(fmt1($totalDeltaAll)) ?>
                    </span>
                    <span class="small-muted mono">
                      <?= $totalPctAll === null ? '—' : h(number_format($totalPctAll, 1)).'%' ?>
                    </span>
                  </div>
            
                  <div class="small-muted mt-auto">vs the first exam</div>
                </div>
              </div>
            </div>

          <div class="mt-3">
            <div class="section-title"><strong>Latest ranks</strong></div>

            <div class="d-flex flex-column gap-2 mt-2">
              <div class="d-flex align-items-center justify-content-between">
                <div class="small-muted"><i class="bi bi-trophy me-1"></i><strong>Class rank</strong></div>
                <?php if ($clsLatest['rank'] !== null && (int)$clsLatest['count'] > 0): ?>
                  <div>
                    <span class="badge badge-soft mono">
                      <?= h((string)$clsLatest['rank']) ?> / <?= h((string)$clsLatest['count']) ?>
                    </span>
                    <?php if ($clsLatest['avg_total'] !== null): ?>
                      <span class="badge badge-soft-success mono ms-1">
                        Avg <?= h(fmt1((float)$clsLatest['avg_total'])) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span class="badge text-bg-light border">—</span>
                <?php endif; ?>
              </div>

              <div class="d-flex align-items-center justify-content-between">
                <div class="small-muted"><i class="bi bi-people me-1"></i><strong><?= h((string)($parLatest['grade'] ?? '—')) ?>
                      <?= $track !== '' ? '· '.h($track) : '' ?></strong></div>
                <?php if ($parLatest['rank'] !== null && (int)$parLatest['count'] > 0): ?>
                  <div class="text-end">
                    <div>
                      <span class="badge badge-soft mono">
                        <?= h((string)$parLatest['rank']) ?> / <?= h((string)$parLatest['count']) ?>
                      </span>
                      <?php if ($parLatest['avg_total'] !== null): ?>
                        <span class="badge badge-soft-success mono ms-1">
                          Avg <?= h(fmt1((float)$parLatest['avg_total'])) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                   </div>
                <?php else: ?>
                  <span class="badge text-bg-light border">—</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!empty($insights['risk_flags'])): ?>
            <div class="mt-3 p-2 rounded-3 border bg-white" data-aos="fade-up">
              <div class="section-title text-danger">Attention</div>
              <?php foreach ($insights['risk_flags'] as $rf): ?>
                <div class="small"><span class="badge badge-soft-danger me-1">Flag</span><?= h($rf) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="mt-3 small-muted">
            <i class="bi bi-info-circle me-1"></i>
            Score bands: <span class="badge text-bg-danger"><46%</span>
            <span class="badge text-bg-warning text-dark"><66%</span>
            <span class="badge text-bg-primary text-white"><86%</span>
            <span class="badge text-bg-success">≥86%</span>
          </div>
         </div>
        </div>
      </div>
    </div>

    <div class="col-lg-8">

      <div class="row g-3">
      <div class="col-12">
        <div class="card shadow-soft" data-aos="fade-up">
    
          <div class="card-header bg-white">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div class="fw-semibold">
                <i class="bi bi-graph-up-arrow me-1"></i>
                Performance overview
              </div>
              <div class="small-muted">Trend across exams & latest subject-level changes</div>
            </div>
          </div>
    
          <div class="card-body">
            <!-- SIDE-BY-SIDE ROW -->
            <div class="row g-3 align-items-stretch">
    
              <!-- LEFT: TOTAL TREND -->
              <div class="col-12 col-lg-5">
                <div class="border rounded-3 p-3 bg-white shadow-soft h-100" data-aos="zoom-in">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                    <div class="fw-semibold">
                      <i class="bi bi-graph-up-arrow me-1"></i>Performance trend (Total)
                    </div>
                    <div class="small-muted">Chronological trend</div>
                  </div>
    
                  <div class="chart-wrap" style="height: 240px;">
                    <canvas id="totalsChart"></canvas>
                  </div>
    
                  <div class="small text-secondary mt-2">
                    Interpretation: A steady upward slope suggests improvement; sharp drops may indicate gaps or exam difficulty shifts.
                  </div>
                </div>
              </div>
    
              <!-- RIGHT: SUBJECT DELTAS -->
              <div class="col-12 col-lg-7">
                <div class="border rounded-3 p-3 bg-white shadow-soft h-100" data-aos="zoom-in">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                    <div class="fw-semibold">
                      <i class="bi bi-bar-chart-line me-1"></i>Latest changes by subject
                    </div>
                    <div class="small-muted">Δ vs previous exam</div>
                  </div>
    
                  <div class="chart-wrap-sm" style="height: 240px;">
                    <canvas id="deltaChart"></canvas>
                  </div>
    
                  <div class="small text-secondary mt-2">
                    Use this to immediately see which subjects improved and which require attention.
                  </div>
                </div>
              </div>
    
            </div><!-- /row -->
          </div><!-- /card-body -->
    
        </div>
      </div>
    </div>

    <div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card shadow-soft" data-aos="fade-up">
      <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="fw-semibold">
          <i class="bi bi-columns-gap me-1"></i>Subject comparison (last <?= h((string)count($cmpExamIds)) ?> exams)
        </div>
        <div class="small-muted">Grouped bars across the last <?= h((string)count($cmpExamIds)) ?> exams</div>
      </div>

      <div class="card-body">
        <?php if (count($cmpExamIds) >= 2): ?>
          <div class="border rounded-3 p-3 bg-white shadow-soft" data-aos="zoom-in">
            <div class="chart-wrap" style="height: 360px;">
              <canvas id="subjectCompareChart"></canvas>
            </div>
            <div class="small text-secondary mt-2">
              This compares subject scores across the last <?= h((string)count($cmpExamIds)) ?> exams. Missing bars mean no recorded score for that subject in the selected exam.
            </div>
          </div>
        <?php else: ?>
          <div class="text-secondary">Not enough exams to compare.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>


      <div class="card shadow-soft mt-3" data-aos="fade-up">
        <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="fw-semibold"><i class="bi bi-lightbulb me-1"></i>Key insights (automatic)</div>
          <div class="small-muted">Strengths, weaknesses, and highlights</div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="p-3 rounded-3 border bg-white h-100">
                <div class="section-title">Strengths</div>
                <?php if (!empty($insights['strengths'])): ?>
                  <div class="d-flex flex-column gap-2 mt-2">
                    <?php foreach (array_slice($insights['strengths'], 0, 4) as $s): ?>
                      <div class="d-flex align-items-center justify-content-between">
                        <div class="fw-semibold"><?= h($s['name']) ?></div>
                        <span class="badge badge-soft-success mono"><?= h(fmt1((float)$s['latest'])) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="small text-secondary mt-2">No strong signals yet; more data will improve insights.</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-md-6">
              <div class="p-3 rounded-3 border bg-white h-100">
                <div class="section-title">Needs support</div>
                <?php if (!empty($insights['needs_support'])): ?>
                  <div class="d-flex flex-column gap-2 mt-2">
                    <?php foreach (array_slice($insights['needs_support'], 0, 4) as $s): ?>
                      <div class="d-flex align-items-center justify-content-between">
                        <div class="fw-semibold"><?= h($s['name']) ?></div>
                        <span class="badge badge-soft-warning mono"><?= h(fmt1((float)$s['latest'])) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="small text-secondary mt-2">No major concerns detected on the latest exam.</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12">
              <div class="p-3 rounded-3 border bg-white">
                <div class="section-title">Highlights</div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                  <?php if ($insights['biggest_up']): ?>
                    <span class="badge badge-soft-success">
                      <i class="bi bi-arrow-up-right me-1"></i>
                      Biggest up: <?= h($insights['biggest_up']['name']) ?> (<?= h(fmt1((float)$insights['biggest_up']['delta'])) ?>)
                    </span>
                  <?php endif; ?>
                  <?php if ($insights['biggest_down']): ?>
                    <span class="badge badge-soft-danger">
                      <i class="bi bi-arrow-down-right me-1"></i>
                      Biggest down: <?= h($insights['biggest_down']['name']) ?> (<?= h(fmt1((float)$insights['biggest_down']['delta'])) ?>)
                    </span>
                  <?php endif; ?>
                  <span class="badge badge-soft">
                    <i class="bi bi-award me-1"></i>
                    Latest total: <?= h(fmt1($latestTotal)) ?>
                    <?= $latestTotalPct === null ? '' : ' ('.h(number_format($latestTotalPct, 1)).'%)' ?>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-soft mt-3" data-aos="fade-up">
        <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="fw-semibold"><i class="bi bi-list-check me-1"></i>Subject comparison (latest vs previous)</div>
          <div class="small-muted">Click a subject to open its full trend chart</div>
        </div>
        <div class="card-body ">
            <div class="border rounded-3 p-3 mb-3 bg-white shadow-soft" data-aos="zoom-in">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Subject</th>
                  <th class="text-center">Latest</th>
                  <th class="text-center">Δ Points</th>
                  <th class="text-center">Δ %</th>
                  <th class="text-center">Trend</th>
                  <th class="text-center">Details</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($subjectRows as $sr): ?>
                <?php
                  [$cls, $ic] = delta_badge((float)$sr['delta']);
                  $pctTxt = $sr['pct'] === null ? '—' : (number_format((float)$sr['pct'], 1) . '%');
                ?>
                <tr>
                  <td class="fw-semibold"><?= h($sr['name']) ?></td>
                  <td class="text-center">
                    <span class="badge <?= h(score_class((float)$sr['latest'])) ?> score-pill mono">
                      <?= h(fmt1((float)$sr['latest'])) ?>
                    </span>
                  </td>
                  <td class="text-center">
                    <span class="badge <?= h($cls) ?> mono">
                      <i class="bi <?= h($ic) ?> me-1"></i><?= h(fmt1((float)$sr['delta'])) ?>
                    </span>
                  </td>
                  <td class="text-center mono"><?= h($pctTxt) ?></td>
                  <td class="text-center spark"><?= sparkline_svg($sr['spark'], 140, 34) ?></td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#subjectTrendModal"
                            data-sid="<?= h((string)$sr['sid']) ?>"
                            data-name="<?= h($sr['name']) ?>">
                      <i class="bi bi-graph-up me-1"></i>Trend
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (empty($subjectRows)): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4">No subject data available.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          </div>
        </div>
      </div>

      <div class="card shadow-soft mt-3" data-aos="fade-up">
        <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="fw-semibold"><i class="bi bi-journal-text me-1"></i>Exam timeline (latest first)</div>
          <div class="small-muted">Each block shows totals, ranks, and subject deltas vs the previous exam</div>
        </div>

        <div class="card-body">
          <?php
            $prevExamMap = [];
            for ($i = 0; $i < count($timeline); $i++) {
              $eid = (int)$timeline[$i]['id'];
              $prevExamMap[$eid] = $i > 0 ? (int)$timeline[$i-1]['id'] : null;
            }
            $totalByExamChrono = [];
            foreach ($timeline as $ex) $totalByExamChrono[(int)$ex['id']] = (float)$byExam[(int)$ex['id']]['total'];
          ?>

          <?php foreach ($timelineDesc as $ex): ?>
            <?php
              $examId = (int)$ex['id'];
              $total = (float)$byExam[$examId]['total'];
              $maxTotal = (float)$byExam[$examId]['max_total'];
              $totalPct = $maxTotal > 0 ? ($total / $maxTotal) * 100.0 : null;

              $prevExamId = $prevExamMap[$examId] ?? null;
              $deltaTotal = ($prevExamId === null) ? 0.0 : ($total - (float)($totalByExamChrono[$prevExamId] ?? 0.0));
              $pctTotalDelta = ($prevExamId === null) ? null : pct_change((float)($totalByExamChrono[$prevExamId] ?? 0.0), $deltaTotal);
              [$tCls, $tIc] = delta_badge($deltaTotal);

              $termLabel = $ex['term'] ? ('Term '.$ex['term']) : 'Term —';
              $dateLabel = $ex['exam_date'] ? $ex['exam_date'] : '—';

              $classStats = $classStatsByExam[$examId] ?? ['rank'=>null,'count'=>0,'avg_total'=>null];
              $parStats = $parallelStatsByExam[$examId] ?? ['rank'=>null,'count'=>0,'avg_total'=>null,'grade'=>$grade,'track'=>$track];

              $subs = $byExam[$examId]['subjects'];
              uasort($subs, static fn($a, $b) => strcmp((string)$a['subject_name'], (string)$b['subject_name']));
            ?>

            <div class="border rounded-3 p-3 mb-3 bg-white shadow-soft" data-aos="zoom-in">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                  <div class="h6 mb-1">
                    <?= h($ex['academic_year']) ?> · <?= h($termLabel) ?> · <?= h($ex['exam_name']) ?>
                    <?php if ($examId === $latestExamId): ?>
                      <span class="badge badge-soft-success ms-1"><i class="bi bi-star-fill me-1"></i>Latest</span>
                    <?php endif; ?>
                  </div>
                  <div class="small-muted">
                    <i class="bi bi-calendar3 me-1"></i><?= h($dateLabel) ?>
                  </div>

                  <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php if ($classStats['rank'] !== null && (int)$classStats['count'] > 0): ?>
                      <span class="badge badge-soft">
                        <i class="bi bi-trophy me-1"></i>
                        Class rank <span class="mono"><?= h((string)$classStats['rank']) ?></span> / <span class="mono"><?= h((string)$classStats['count']) ?></span>
                      </span>
                      <?php if ($classStats['avg_total'] !== null): ?>
                        <span class="badge badge-soft-success">
                          <i class="bi bi-bar-chart me-1"></i>
                          Class avg <span class="mono"><?= h(fmt1((float)$classStats['avg_total'])) ?></span>
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge text-bg-light border"><i class="bi bi-people me-1"></i>Class analytics —</span>
                    <?php endif; ?>

                    <?php if ($parStats['rank'] !== null && (int)$parStats['count'] > 0): ?>
                      <span class="badge badge-soft">
                        <i class="bi bi-people me-1"></i>
                        Parallel rank <span class="mono"><?= h((string)$parStats['rank']) ?></span> / <span class="mono"><?= h((string)$parStats['count']) ?></span>
                        <span class="small-muted ms-1">(Grade <?= h((string)($parStats['grade'] ?? '—')) ?><?= $track !== '' ? ' · '.h($track) : '' ?>)</span>
                      </span>
                      <?php if ($parStats['avg_total'] !== null): ?>
                        <span class="badge badge-soft-success">
                          <i class="bi bi-bar-chart me-1"></i>
                          Parallel avg <span class="mono"><?= h(fmt1((float)$parStats['avg_total'])) ?></span>
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="text-end">
                  <div class="section-title">Exam total</div>
                  <div class="d-flex align-items-center justify-content-end gap-2">
                    <span class="badge text-bg-dark mono"><?= h(fmt1($total)) ?></span>
                    <span class="badge <?= h($tCls) ?> mono">
                      <i class="bi <?= h($tIc) ?> me-1"></i><?= h(fmt1($deltaTotal)) ?>
                    </span>
                    <span class="small-muted mono"><?= $pctTotalDelta === null ? '—' : h(number_format($pctTotalDelta, 1)).'%' ?></span>
                  </div>
                  <div class="small-muted mt-1">
                    <?= $totalPct === null ? '—' : h(number_format($totalPct, 1)).'%' ?> of max
                  </div>
                </div>
              </div>

              <div class="mt-3">
                <?php if ($maxTotal > 0): ?>
                  <div class="progress" role="progressbar" aria-label="Total percent" aria-valuenow="<?= h((string)($totalPct ?? 0.0)) ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: <?= h((string)min(100.0, max(0.0, (float)($totalPct ?? 0.0)))) ?>%"></div>
                  </div>
                <?php endif; ?>
              </div>

              <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Subject</th>
                      <th class="text-center">Score</th>
                      <th class="text-center">Δ vs previous</th>
                      <th class="text-center">Δ %</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($subs as $sid => $sr): ?>
                      <?php
                        $sid = (int)$sid;
                        $curr = (float)$sr['score'];
                        $prev = null;

                        if ($prevExamId !== null && isset($byExam[(int)$prevExamId]['subjects'][$sid])) {
                          $prev = (float)$byExam[(int)$prevExamId]['subjects'][$sid]['score'];
                        }

                        $d = ($prev === null) ? 0.0 : ($curr - $prev);
                        $p = ($prev === null) ? null : pct_change($prev, $d);
                        [$dc, $di] = delta_badge($d);
                        $pctTxt = $p === null ? '—' : (number_format($p, 1) . '%');
                      ?>
                      <tr>
                        <td class="fw-semibold"><?= h($sr['subject_name']) ?></td>
                        <td class="text-center">
                          <span class="badge <?= h(score_class($curr)) ?> score-pill mono"><?= h(fmt1($curr)) ?></span>
                        </td>
                        <td class="text-center">
                          <span class="badge <?= h($dc) ?> mono">
                            <i class="bi <?= h($di) ?> me-1"></i><?= h(fmt1($d)) ?>
                          </span>
                        </td>
                        <td class="text-center mono"><?= h($pctTxt) ?></td>
                      </tr>
                    <?php endforeach; ?>

                    <?php if (empty($subs)): ?>
                      <tr><td colspan="4" class="text-center text-secondary py-3">No subjects found for this exam.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="alert alert-light border mb-0" data-aos="fade-up">
            <div class="d-flex gap-2">
              <div class="pt-1"><i class="bi bi-shield-check"></i></div>
              <div>
                <div class="fw-semibold">Privacy note</div>
                <div class="small text-secondary mb-0">
                  This page is public but requires the student login to view results.
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>

  <div class="modal fade" id="subjectTrendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <div class="fw-semibold"><i class="bi bi-graph-up me-1"></i><span id="subModalTitle">Subject trend</span></div>
            <div class="small text-secondary">Chronological performance across exams</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="chart-wrap">
            <canvas id="subjectModalChart"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

</div>

<footer class="py-4">
  <div class="container">
    <div class="small text-secondary">
      © <?= h((string)date('Y')) ?> · Exam Analytics
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script nonce="<?= h($cspNonce) ?>">
  if (window.AOS) {
    AOS.init({ duration: 650, easing: 'ease-out-cubic', once: true, offset: 40 });
  }
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<?php if ($pupil && !empty($timeline)): ?>
<script nonce="<?= h($cspNonce) ?>">
  const labelsChrono = <?= json_encode($labelsChrono, JSON_UNESCAPED_UNICODE) ?>;
  const totalsChrono = <?= json_encode($totalsChrono, JSON_UNESCAPED_UNICODE) ?>;

  const deltaLabels = <?= json_encode($deltaLabels, JSON_UNESCAPED_UNICODE) ?>;
  const deltaValues = <?= json_encode($deltaValues, JSON_UNESCAPED_UNICODE) ?>;
  
  // --- NEW: per-bar colors for delta chart ---
    const deltaColors = <?= json_encode($deltaBarColors, JSON_UNESCAPED_UNICODE) ?>;
    const deltaBorderColors = <?= json_encode($deltaBarBorderColors, JSON_UNESCAPED_UNICODE) ?>;
    
    // --- NEW: subject comparison (up to 4 exams) ---
    const cmpLabels   = <?= json_encode($cmpLabels, JSON_UNESCAPED_UNICODE) ?>;
    const cmpExamIds  = <?= json_encode($cmpExamIds, JSON_UNESCAPED_UNICODE) ?>;
    const cmpSubLabels = <?= json_encode($cmpSubLabels, JSON_UNESCAPED_UNICODE) ?>;
    const cmpDatasets = <?= json_encode($cmpDatasets, JSON_UNESCAPED_UNICODE) ?>;
    const cmpSuggestedMax = <?= (int)$cmpSuggestedMax ?>;

  const subjectSeriesMap = <?= json_encode(array_reduce($subjectRows, static function($acc, $sr) {
      $sid = (string)$sr['sid'];
      $acc[$sid] = [
        'name' => $sr['name'],
        'scores' => array_map(static fn($x) => (float)$x['score'], $sr['series_full']),
        'labels' => array_map(static function($x) {
          $term = $x['exam']['term'] ? ('T'.$x['exam']['term']) : 'T-';
          return $x['exam']['academic_year'].' '.$term;
        }, $sr['series_full']),
      ];
      return $acc;
  }, []), JSON_UNESCAPED_UNICODE) ?>;

  const totalsCtx = document.getElementById('totalsChart');
    if (totalsCtx) {
      new Chart(totalsCtx, {
        type: 'line',
        data: {
          labels: labelsChrono,
          datasets: [{
            label: 'Total score',
            data: totalsChrono,
            tension: 0.25,
            fill: true,
            pointRadius: 3,
            pointHoverRadius: 5,
    
            // --- NEW: multi-colored trend segments ---
            segment: {
              borderColor: ctx => {
                const y0 = ctx.p0.parsed.y;
                const y1 = ctx.p1.parsed.y;
    
                if (y1 > y0) return 'rgba(25,135,84,0.95)';   // green ↑
                if (y1 < y0) return 'rgba(220,53,69,0.95)';   // red ↓
                return 'rgba(108,117,125,0.9)';               // gray →
              }
            },
    
            // points reflect direction too
            pointBackgroundColor: ctx => {
              const i = ctx.dataIndex;
              if (i === 0) return 'rgba(13,110,253,0.9)'; // first point
              const prev = totalsChrono[i - 1];
              const curr = totalsChrono[i];
              if (curr > prev) return 'rgba(25,135,84,0.95)';
              if (curr < prev) return 'rgba(220,53,69,0.95)';
              return 'rgba(108,117,125,0.9)';
            }
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: true },
            tooltip: {
              callbacks: {
                label: ctx => {
                  const v = ctx.parsed.y;
                  const i = ctx.dataIndex;
                  if (i === 0) return `Total: ${v.toFixed(1)}`;
                  const d = v - totalsChrono[i - 1];
                  const sign = d > 0 ? '+' : '';
                  return `Total: ${v.toFixed(1)} (${sign}${d.toFixed(1)})`;
                }
              }
            }
          },
          interaction: { mode: 'index', intersect: false },
          scales: {
            y: {
              beginAtZero: true,
              grid: {
                color: ctx =>
                  ctx.tick.value === 0
                    ? 'rgba(0,0,0,0.25)'
                    : 'rgba(0,0,0,0.08)',
                lineWidth: ctx => (ctx.tick.value === 0 ? 2 : 1)
              }
            }
          }
        }
      });
    }


  const deltaCtx = document.getElementById('deltaChart');
    if (deltaCtx) {
      new Chart(deltaCtx, {
        type: 'bar',
        data: {
          labels: deltaLabels,
          datasets: [{
            label: 'Δ points (latest vs previous)',
            data: deltaValues,
            backgroundColor: deltaColors,
            borderColor: deltaBorderColors,
            borderWidth: 1,
            borderRadius: 8,
            maxBarThickness: 28,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: true },
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  const v = ctx.parsed.y ?? 0;
                  const sign = v > 0 ? '+' : '';
                  return `Δ ${sign}${v.toFixed(1)} points`;
                }
              }
            }
          },
          scales: {
            x: {
              ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 }
            },
            y: {
              beginAtZero: true,
              grid: {
                // emphasize the zero baseline to separate up/down visually
                color: (ctx) => (ctx.tick && ctx.tick.value === 0)
                  ? 'rgba(0,0,0,0.25)'
                  : 'rgba(0,0,0,0.08)',
                lineWidth: (ctx) => (ctx.tick && ctx.tick.value === 0) ? 2 : 1
              }
            }
          }
        }
      });
    }
    
    const subjectCompareCtx = document.getElementById('subjectCompareChart');
    if (subjectCompareCtx && Array.isArray(cmpExamIds) && cmpExamIds.length >= 2) {
    
      // Build up to 4 datasets dynamically (Chart.js will auto-color each dataset)
      const ds = cmpExamIds.map((eid, i) => ({
        label: cmpLabels[i] || `Exam ${i + 1}`,
        data: (cmpDatasets && cmpDatasets[String(eid)]) ? cmpDatasets[String(eid)] : [],
        borderWidth: 1,
        borderRadius: 8,
        maxBarThickness: 22
      }));
    
      new Chart(subjectCompareCtx, {
        type: 'bar',
        data: {
          labels: cmpSubLabels,
          datasets: ds
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: true },
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  const v = ctx.parsed.y;
                  if (v === null || typeof v === 'undefined') return `${ctx.dataset.label}: —`;
                  return `${ctx.dataset.label}: ${v.toFixed(1)} pts`;
                },
                // show delta between last two exams in tooltip (useful even with 4 datasets)
                afterBody: (items) => {
                  if (!items || items.length < 2) return '';
                  const a = items[items.length - 2].parsed.y;
                  const b = items[items.length - 1].parsed.y;
                  if (a == null || b == null) return '';
                  const d = b - a;
                  const sign = d > 0 ? '+' : '';
                  return `Δ (last two): ${sign}${d.toFixed(1)} pts`;
                }
              }
            }
          },
          scales: {
            x: { ticks: { maxRotation: 45, minRotation: 0 } },
            y: { beginAtZero: true, suggestedMax: cmpSuggestedMax }
          }
        }
      });
    }


    
    


  let subjectModalChart = null;
  const modalEl = document.getElementById('subjectTrendModal');
  const titleEl = document.getElementById('subModalTitle');
  const canvasEl = document.getElementById('subjectModalChart');

  function buildSubjectChart(sid) {
    const item = subjectSeriesMap[String(sid)];
    if (!item || !canvasEl) return;

    if (titleEl) titleEl.textContent = item.name + ' — Trend';

    if (subjectModalChart) {
      subjectModalChart.destroy();
      subjectModalChart = null;
    }

    subjectModalChart = new Chart(canvasEl, {
      type: 'line',
      data: {
        labels: item.labels,
        datasets: [{
          label: item.name,
          data: item.scores,
          tension: 0.25,
          fill: true,
          pointRadius: 3,
          pointHoverRadius: 5,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: true },
          tooltip: { mode: 'index', intersect: false }
        },
        interaction: { mode: 'index', intersect: false },
        options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: true }, tooltip: { mode: 'index', intersect: false } },
      interaction: { mode: 'index', intersect: false },
      scales: { y: { beginAtZero: true, suggestedMax: 40 } }
    }

      }
    });
  }

  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;
      const sid = btn.getAttribute('data-sid');
      buildSubjectChart(sid);
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
      if (subjectModalChart) {
        subjectModalChart.destroy();
        subjectModalChart = null;
      }
    });
  }
</script>
<?php endif; ?>

</body>
</html>
