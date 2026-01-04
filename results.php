<?php
// results.php — Public student results viewer (by student_login), term-by-term + subject comparisons + analytics

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php'; // for h()

// -----------------------------
// Basic security headers (public page)
// -----------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// NOTE: Keep CSP permissive enough for Bootstrap CDN; tighten later if you want.
header("Content-Security-Policy: default-src 'self' https:; img-src 'self' https: data:; style-src 'self' https: 'unsafe-inline'; script-src 'self' https: 'unsafe-inline'; base-uri 'self'; frame-ancestors 'none'");

// -----------------------------
// Very light rate limiting (per session)
// -----------------------------
session_start_secure();
$now = time();
$_SESSION['public_rl'] ??= [];
$_SESSION['public_rl'] = array_values(array_filter($_SESSION['public_rl'], fn($t) => is_int($t) && ($now - $t) <= 600));
if (count($_SESSION['public_rl']) >= 60) {
    http_response_code(429);
    echo 'Too many requests. Please try again later.';
    exit;
}
$_SESSION['public_rl'][] = $now;

// -----------------------------
// Helpers
// -----------------------------
function norm_login(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/', '', $s) ?? '';
    return $s;
}

function valid_login(string $s): bool
{
    // allow letters/digits/_- . (tune to your scheme)
    return $s !== '' && strlen($s) <= 20 && (bool)preg_match('/^[A-Za-z0-9_.-]{2,20}$/', $s);
}

function score_class(float $score): string
{
    if ($score <= 18.4) return 'text-bg-danger';
    if ($score <= 24.4) return 'text-bg-warning';
    if ($score <= 34.4) return 'text-bg-primary';
    return 'text-bg-success';
}

function delta_badge(float $delta): array
{
    if (abs($delta) < 0.0001) {
        return ['text-bg-secondary', 'bi-dash-lg', 'No change'];
    }
    if ($delta > 0) {
        return ['text-bg-success', 'bi-arrow-up-right', 'Up'];
    }
    return ['text-bg-danger', 'bi-arrow-down-right', 'Down'];
}

function fmt1(float $v): string
{
    // one decimal, but drop .0 for cleaner display if desired
    $s = number_format($v, 1, '.', '');
    return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
}

/**
 * Tiny SVG sparkline.
 * @param float[] $values
 */
function sparkline_svg(array $values, int $w = 140, int $h = 34): string
{
    $n = count($values);
    if ($n < 2) {
        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true"></svg>';
    }

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
    $trendUp = $last > $first + 0.0001;

    $stroke = $trendUp ? '#198754' : ($last < $first - 0.0001 ? '#dc3545' : '#6c757d');
    // inline styles are allowed via CSP above; if you tighten CSP, move to CSS.
    return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true">'
        . '<polyline fill="none" stroke="'.$stroke.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="'.h(implode(' ', $pts)).'"></polyline>'
        . '</svg>';
}

/**
 * Safe percent change, returns null if base is 0.
 */
function pct_change(float $base, float $delta): ?float
{
    if (abs($base) < 0.0001) return null;
    return ($delta / $base) * 100.0;
}

// -----------------------------
// Input handling
// -----------------------------
$student_login = '';
$searched = false;
$error = '';
$pupil = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');
    $searched = true;
    $student_login = norm_login((string)($_POST['student_login'] ?? ''));
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

// -----------------------------
// Fetch results + compute analytics (only if pupil found)
// -----------------------------
$timeline = [];          // ordered exams
$byExam = [];            // exam_id => ['exam'=>..., 'subjects'=>[subject_id=>row], 'total'=>float]
$subjectsIndex = [];     // subject_id => ['id'=>, 'name'=>, 'code'=>]
$subjectSeries = [];     // subject_id => list of ['exam'=>..., 'score'=>float, 'delta'=>float]
$totalsSeries = [];      // list totals aligned to timeline
$classStatsByExam = [];  // exam_id => ['rank'=>int|null,'count'=>int,'avg_total'=>float|null]

if ($pupil) {
    // Pull all rows for this pupil with exam + subject details
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

    // Build structures
    foreach ($rows as $r) {
        $examId = (int)$r['exam_id'];
        $subId  = (int)$r['subject_id'];
        $score  = (float)$r['score'];

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
                    'exam_date' => $r['exam_date'], // string or null
                ],
                'subjects' => [],
                'total' => 0.0,
            ];
        }

        $byExam[$examId]['subjects'][$subId] = [
            'subject_id' => $subId,
            'subject_name' => (string)$r['subject_name'],
            'score' => $score,
            'max_points' => (int)$r['max_points'],
        ];
        $byExam[$examId]['total'] += $score;
    }

    // Timeline (ordered exams)
    $timeline = array_values(array_map(fn($x) => $x['exam'], $byExam));

    // Subject series (aligned to timeline; compute deltas per subject)
    $prevBySubject = [];
    foreach ($timeline as $ex) {
        $examId = (int)$ex['id'];
        $totalsSeries[] = (float)$byExam[$examId]['total'];

        foreach ($subjectsIndex as $sid => $sMeta) {
            $sid = (int)$sid;
            $score = isset($byExam[$examId]['subjects'][$sid]) ? (float)$byExam[$examId]['subjects'][$sid]['score'] : null;
            if ($score === null) {
                continue; // skip missing subjects in this exam
            }
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

    // Class rank + class average total (per exam) within pupil's class_code
    // Uses MySQL 8 window functions.
    $rankStmt = $pdo->prepare("
        WITH totals AS (
            SELECT
                p.id AS pupil_id,
                SUM(r.score) AS total
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code = ?
              AND r.exam_id = ?
            GROUP BY p.id
        ),
        ranked AS (
            SELECT
                pupil_id,
                total,
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
        try {
            $rankStmt->execute([(string)$pupil['class_code'], $examId, (int)$pupil['id']]);
            $st = $rankStmt->fetch();
            $classStatsByExam[$examId] = [
                'rank' => $st ? (int)$st['rnk'] : null,
                'count' => $st ? (int)$st['cnt'] : 0,
                'avg_total' => $st ? (float)$st['avg_total'] : null,
            ];
        } catch (Throwable $e) {
            // If window functions / CTE disabled for any reason, fail gracefully.
            $classStatsByExam[$examId] = ['rank' => null, 'count' => 0, 'avg_total' => null];
        }
    }
}

// -----------------------------
// Rendering
// -----------------------------
$pageTitle = 'Student Results';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body { background: #f6f7fb; }
    .hero { background: radial-gradient(1200px 420px at 10% 10%, rgba(13,110,253,.16), transparent 55%),
                     radial-gradient(900px 360px at 90% 20%, rgba(25,135,84,.14), transparent 55%),
                     #fff; border-bottom: 1px solid rgba(0,0,0,.06); }
    .kpi { border: 1px solid rgba(0,0,0,.08); }
    .mono { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }
    .small-muted { color: rgba(33,37,41,.65); }
    .table thead th { white-space: nowrap; }
    .score-pill { min-width: 56px; display:inline-flex; justify-content:center; }
    .spark svg { display:block; }
    .sticky-top-2 { top: .75rem; }
  </style>
</head>
<body>

<div class="hero py-4">
  <div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h1 class="h3 mb-1">Student Results Viewer</h1>
        <div class="small-muted">Enter a student login to view term-by-term performance, subject comparisons, and trends.</div>
      </div>

      <div class="text-end">
        <a class="btn btn-outline-secondary" href="">
          <i class="bi bi-shield-lock me-1"></i> Student's Results
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
          <button class="btn btn-primary">
            <i class="bi bi-search me-1"></i> View results
          </button>
        </div>
      </form>

      <?php if ($error): ?>
        <div class="alert alert-danger mt-3 mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
      <?php elseif ($searched && $pupil && empty($timeline)): ?>
        <div class="alert alert-warning mt-3 mb-0">
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
    $allTotals = $totalsSeries;
    $latestTotal = $allTotals[count($allTotals)-1] ?? 0.0;
    $firstTotal  = $allTotals[0] ?? 0.0;
    $totalDelta  = $latestTotal - $firstTotal;
    $totalPct    = pct_change($firstTotal, $totalDelta);
    [$dClass, $dIcon, $dLabel] = delta_badge($totalDelta);
  ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card shadow-sm sticky-top sticky-top-2">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between gap-2">
            <div>
              <div class="text-uppercase small text-secondary">Student</div>
              <div class="h5 mb-1"><?= h($fullName) ?></div>
              <div class="small-muted">
                <span class="me-2"><i class="bi bi-mortarboard me-1"></i><?= h($pupil['class_code']) ?></span>
                <span><i class="bi bi-diagram-3 me-1"></i><?= h($pupil['track']) ?></span>
              </div>
              <div class="mt-2 small">
                <span class="badge text-bg-light border"><i class="bi bi-key me-1"></i><?= h($pupil['student_login']) ?></span>
              </div>
            </div>
            <div class="text-end">
              <div class="text-uppercase small text-secondary">Overall trend</div>
              <div class="spark mt-1"><?= sparkline_svg($allTotals, 150, 38) ?></div>
            </div>
          </div>

          <hr class="my-3">

          <div class="row g-2">
            <div class="col-6">
              <div class="kpi rounded-3 p-2 bg-white">
                <div class="small text-secondary">Latest total</div>
                <div class="h4 mb-0 mono"><?= h(fmt1($latestTotal)) ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="kpi rounded-3 p-2 bg-white">
                <div class="small text-secondary">Change</div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge <?= h($dClass) ?> mono">
                    <i class="bi <?= h($dIcon) ?> me-1"></i><?= h(fmt1($totalDelta)) ?>
                  </span>
                  <?php if ($totalPct !== null): ?>
                    <span class="small-muted mono"><?= h(number_format($totalPct, 1)) ?>%</span>
                  <?php else: ?>
                    <span class="small-muted">—</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-3 small-muted">
            <i class="bi bi-info-circle me-1"></i>
            Score colors: <span class="badge text-bg-danger"><46%</span>
            <span class="badge text-bg-warning text-dark"><66%</span>
            <span class="badge text-bg-primary text-white"><86%</span>
            <span class="badge text-bg-success">≥86%</span>
          </div>

        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <!-- Subject summary -->
      <div class="card shadow-sm mb-3">
        <div class="card-header bg-white">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="fw-semibold"><i class="bi bi-bar-chart-line me-1"></i>Subject-by-subject comparison (latest vs previous)</div>
            <div class="small-muted">Shows last score and the delta from the previous exam where that subject exists.</div>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Subject</th>
                  <th class="text-center">Latest</th>
                  <th class="text-center">Δ Points</th>
                  <th class="text-center">Δ %</th>
                  <th class="text-center">Trend</th>
                </tr>
              </thead>
              <tbody>
              <?php
                // Rank subjects by absolute change (latest delta)
                $subjectRows = [];
                foreach ($subjectSeries as $sid => $series) {
                    $n = count($series);
                    if ($n === 0) continue;
                    $last = $series[$n-1];
                    $delta = (float)$last['delta'];
                    $prevScore = $n >= 2 ? (float)$series[$n-2]['score'] : 0.0;
                    $pct = ($n >= 2) ? pct_change($prevScore, $delta) : null;

                    $vals = array_map(fn($x) => (float)$x['score'], $series);
                    $subjectRows[] = [
                        'sid' => (int)$sid,
                        'name' => (string)$subjectsIndex[(int)$sid]['name'],
                        'latest' => (float)$last['score'],
                        'delta' => $delta,
                        'pct' => $pct,
                        'spark' => $vals,
                    ];
                }
                usort($subjectRows, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));
              ?>

              <?php foreach ($subjectRows as $sr): ?>
                <?php
                  [$cls, $ic, $lbl] = delta_badge((float)$sr['delta']);
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
                </tr>
              <?php endforeach; ?>

              <?php if (empty($subjectRows)): ?>
                <tr><td colspan="5" class="text-center text-secondary py-4">No subject data available.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Term-by-term / exam-by-exam -->
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="fw-semibold"><i class="bi bi-journal-text me-1"></i>Term-by-term results</div>
            <div class="small-muted">Each exam shows subject scores, totals, and change from the previous exam.</div>
          </div>
        </div>

        <div class="card-body">
          <?php
            $prevTotal = null;
            foreach ($timeline as $idx => $ex):
              $examId = (int)$ex['id'];
              $total = (float)$byExam[$examId]['total'];
              $deltaTotal = ($prevTotal === null) ? 0.0 : ($total - $prevTotal);
              $pctTotal = ($prevTotal === null) ? null : pct_change($prevTotal, $deltaTotal);
              $prevTotal = $total;

              [$tCls, $tIc, $tLbl] = delta_badge($deltaTotal);
              $termLabel = $ex['term'] ? ('Term '.$ex['term']) : 'Term —';
              $dateLabel = $ex['exam_date'] ? $ex['exam_date'] : '—';
              $classStats = $classStatsByExam[$examId] ?? ['rank'=>null,'count'=>0,'avg_total'=>null];
          ?>

            <div class="border rounded-3 p-3 mb-3 bg-white">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                  <div class="h6 mb-1">
                    <?= h($ex['academic_year']) ?> · <?= h($termLabel) ?> · <?= h($ex['exam_name']) ?>
                  </div>
                  <div class="small-muted">
                    <i class="bi bi-calendar3 me-1"></i><?= h($dateLabel) ?>
                  </div>
                </div>

                <div class="text-end">
                  <div class="small text-secondary">Exam total</div>
                  <div class="d-flex align-items-center justify-content-end gap-2">
                    <span class="badge text-bg-dark mono"><?= h(fmt1($total)) ?></span>
                    <span class="badge <?= h($tCls) ?> mono">
                      <i class="bi <?= h($tIc) ?> me-1"></i><?= h(fmt1($deltaTotal)) ?>
                    </span>
                    <span class="small-muted mono">
                      <?= $pctTotal === null ? '—' : h(number_format($pctTotal, 1)).'%' ?>
                    </span>
                  </div>
                  <div class="small-muted mt-1">
                    <?php if ($classStats['rank'] !== null && (int)$classStats['count'] > 0): ?>
                      <i class="bi bi-trophy me-1"></i>Rank in class:
                      <span class="fw-semibold"><?= h((string)$classStats['rank']) ?></span> / <?= h((string)$classStats['count']) ?>
                      <?php if ($classStats['avg_total'] !== null): ?>
                        · Class avg: <span class="mono"><?= h(fmt1((float)$classStats['avg_total'])) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <i class="bi bi-people me-1"></i>Class analytics: —
                    <?php endif; ?>
                  </div>
                </div>
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
                    <?php
                      // compute per-subject delta vs previous exam (same subject)
                      $prevExamId = $idx > 0 ? (int)$timeline[$idx-1]['id'] : null;

                      // stable subject ordering by subject name
                      $subs = $byExam[$examId]['subjects'];
                      uasort($subs, fn($a, $b) => strcmp((string)$a['subject_name'], (string)$b['subject_name']));

                      foreach ($subs as $sid => $sr):
                        $sid = (int)$sid;
                        $curr = (float)$sr['score'];
                        $prev = null;
                        if ($prevExamId !== null && isset($byExam[$prevExamId]['subjects'][$sid])) {
                            $prev = (float)$byExam[$prevExamId]['subjects'][$sid]['score'];
                        }
                        $d = ($prev === null) ? 0.0 : ($curr - $prev);
                        $p = ($prev === null) ? null : pct_change($prev, $d);
                        [$dc, $di, $dl] = delta_badge($d);
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

          <div class="alert alert-light border mb-0">
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
</body>
</html>
