<?php
// admin/class_report.php — Whole class statistics term-by-term, subject-by-subject
// Drop-in page (Bootstrap UI), uses: /inc/auth.php, /inc/db.php, /admin/header.php, /admin/footer.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

session_start_secure();
require_admin();

// ------------------------------
// Helpers
// ------------------------------
function fmt1(mixed $n): string
{
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 1, '.', '');
}
function fmt2(mixed $n): string
{
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 2, '.', '');
}
function fmtPct(mixed $n): string
{
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 1, '.', '') . '%';
}
function median(array $values): ?float
{
    if (!$values) return null;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) return (float)$values[$mid];
    return ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
}
function delta_badge(?float $delta): array
{
    // returns [badgeClass, iconClass, label]
    if ($delta === null) return ['text-bg-light text-dark', 'bi-dash-lg', '—'];
    if (abs($delta) < 0.0001) return ['text-bg-secondary', 'bi-dash-lg', '0.0'];
    if ($delta > 0) return ['text-bg-success', 'bi-arrow-up', '+' . fmt1($delta)];
    return ['text-bg-danger', 'bi-arrow-down', fmt1($delta)];
}
function score_badge_class(?float $avg, float $pass, float $good, float $excellent): string
{
    if ($avg === null) return 'text-bg-secondary';
    if ($avg >= $excellent) return 'text-bg-success';
    if ($avg >= $good) return 'text-bg-primary';
    if ($avg >= $pass) return 'text-bg-warning';
    return 'text-bg-danger';
}

// Thresholds (move to settings table later if needed)
$PASS = 24.0;      // 60% of 40
$GOOD = 30.0;
$EXCELLENT = 35.0;

// ------------------------------
// Inputs (GET)
// ------------------------------
$selectedYear  = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$selectedClass = isset($_GET['class_code']) ? trim((string)$_GET['class_code']) : '';
$selectedTrack = isset($_GET['track']) ? trim((string)$_GET['track']) : '';
$export        = isset($_GET['export']) && (string)$_GET['export'] === '1';

if ($selectedTrack === 'all') $selectedTrack = '';
if ($selectedClass === 'all') $selectedClass = '';
if ($selectedYear === 'all') $selectedYear = '';

// ------------------------------
// Load filter options
// ------------------------------
$years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll();
$classes = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code")->fetchAll();
$trackOptions = [
    '' => 'All tracks',
    'Aniq fanlar' => 'Aniq fanlar',
    'Tabiiy fanlar' => 'Tabiiy fanlar',
];

// Default selections
if ($selectedYear === '' && !empty($years[0]['academic_year'])) {
    $selectedYear = (string)$years[0]['academic_year'];
}
if ($selectedClass === '' && !empty($classes[0]['class_code'])) {
    $selectedClass = (string)$classes[0]['class_code'];
}

$canRun = ($selectedYear !== '' && $selectedClass !== '');

// ------------------------------
// Load exams + subjects + pupil count
// ------------------------------
$exams = [];
$subjects = [];
$pupilCount = 0;

if ($canRun) {
    $st = $pdo->prepare(
        "SELECT id, academic_year, term, exam_name, exam_date
         FROM exams
         WHERE academic_year = ?
         ORDER BY (term IS NULL), term ASC, (exam_date IS NULL), exam_date ASC, id ASC"
    );
    $st->execute([$selectedYear]);
    $exams = $st->fetchAll();

    $subjects = $pdo->query("SELECT id, name, max_points FROM subjects ORDER BY name")->fetchAll();

    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c
         FROM pupils
         WHERE class_code = ?
           AND (? = '' OR track = ?)"
    );
    $st->execute([$selectedClass, $selectedTrack, $selectedTrack]);
    $pupilCount = (int)($st->fetch()['c'] ?? 0);
}

$examIds = array_map(static fn($r) => (int)$r['id'], $exams);
$hasExams = !empty($examIds);

// ------------------------------
// Build stats
// ------------------------------
$agg = [];     // [subject_id][exam_id] => stats
$overall = []; // [exam_id] => overall stats (all subjects)
$subjectTotals = []; // [subject_id] => totals across all exams (for quick ranking)

if ($canRun && $hasExams) {
    $in = implode(',', array_fill(0, count($examIds), '?'));

    // Per subject/exam aggregates
    $sql = "SELECT r.exam_id, r.subject_id,
                   COUNT(*) AS n,
                   AVG(r.score) AS avg_score,
                   STDDEV_SAMP(r.score) AS sd_score,
                   MIN(r.score) AS min_score,
                   MAX(r.score) AS max_score,
                   SUM(CASE WHEN r.score >= ? THEN 1 ELSE 0 END) AS pass_n
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code = ?
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($in)
            GROUP BY r.exam_id, r.subject_id";
    $params = array_merge([$PASS, $selectedClass, $selectedTrack, $selectedTrack], $examIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll() as $row) {
        $eid = (int)$row['exam_id'];
        $sid = (int)$row['subject_id'];
        $n = (int)$row['n'];
        $passN = (int)$row['pass_n'];

        $avg = $row['avg_score'] !== null ? (float)$row['avg_score'] : null;

        $agg[$sid][$eid] = [
            'n' => $n,
            'avg' => $avg,
            'sd' => $row['sd_score'] !== null ? (float)$row['sd_score'] : null,
            'min' => $row['min_score'] !== null ? (float)$row['min_score'] : null,
            'max' => $row['max_score'] !== null ? (float)$row['max_score'] : null,
            'pass' => $n > 0 ? ($passN / $n * 100.0) : null,
            'pass_n' => $passN,
            'median' => null,
        ];

        if ($avg !== null) {
            $subjectTotals[$sid]['sum_avg'] = ($subjectTotals[$sid]['sum_avg'] ?? 0.0) + $avg;
            $subjectTotals[$sid]['k'] = ($subjectTotals[$sid]['k'] ?? 0) + 1;
        }
    }

    // Overall per exam aggregates (all subjects)
    $sql = "SELECT r.exam_id,
                   COUNT(*) AS n,
                   AVG(r.score) AS avg_score,
                   STDDEV_SAMP(r.score) AS sd_score,
                   SUM(CASE WHEN r.score >= ? THEN 1 ELSE 0 END) AS pass_n
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code = ?
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($in)
            GROUP BY r.exam_id";
    $params = array_merge([$PASS, $selectedClass, $selectedTrack, $selectedTrack], $examIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll() as $row) {
        $eid = (int)$row['exam_id'];
        $n = (int)$row['n'];
        $passN = (int)$row['pass_n'];
        $overall[$eid] = [
            'n' => $n,
            'avg' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
            'sd' => $row['sd_score'] !== null ? (float)$row['sd_score'] : null,
            'pass' => $n > 0 ? ($passN / $n * 100.0) : null,
            'median' => null,
        ];
    }

    // Median (PHP): load all scores once, grouped
    $sql = "SELECT r.exam_id, r.subject_id, r.score
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code = ?
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($in)
            ORDER BY r.exam_id ASC, r.subject_id ASC, r.score ASC";
    $params = array_merge([$selectedClass, $selectedTrack, $selectedTrack], $examIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $scores = [];        // [subject_id][exam_id] => [score...]
    $overallScores = []; // [exam_id] => [score...]
    while ($r = $st->fetch()) {
        $eid = (int)$r['exam_id'];
        $sid = (int)$r['subject_id'];
        $sc = (float)$r['score'];
        $scores[$sid][$eid][] = $sc;
        $overallScores[$eid][] = $sc;
    }
    foreach ($scores as $sid => $byExam) {
        foreach ($byExam as $eid => $list) {
            if (!isset($agg[(int)$sid][(int)$eid])) continue;
            $agg[(int)$sid][(int)$eid]['median'] = median($list);
        }
    }
    foreach ($overallScores as $eid => $list) {
        if (!isset($overall[(int)$eid])) continue;
        $overall[(int)$eid]['median'] = median($list);
    }
}

// ------------------------------
// CSV export
// ------------------------------
if ($export && $canRun && $hasExams) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="class_report_' . rawurlencode($selectedClass) . '_' . rawurlencode($selectedYear) . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Class', $selectedClass, 'Academic year', $selectedYear, 'Track', $selectedTrack !== '' ? $selectedTrack : 'All']);
    fputcsv($out, []);
    fputcsv($out, ['Subject', 'Exam', 'Term', 'Exam date', 'N', 'Avg', 'Median', 'SD', 'Min', 'Max', 'Pass %']);

    foreach ($subjects as $s) {
        $sid = (int)$s['id'];
        foreach ($exams as $e) {
            $eid = (int)$e['id'];
            $stt = $agg[$sid][$eid] ?? null;
            if (!$stt) continue;

            fputcsv($out, [
                (string)$s['name'],
                (string)$e['exam_name'],
                $e['term'] === null ? '' : (string)$e['term'],
                (string)($e['exam_date'] ?? ''),
                (string)$stt['n'],
                $stt['avg'] === null ? '' : number_format($stt['avg'], 1, '.', ''),
                $stt['median'] === null ? '' : number_format($stt['median'], 1, '.', ''),
                $stt['sd'] === null ? '' : number_format($stt['sd'], 2, '.', ''),
                $stt['min'] === null ? '' : number_format($stt['min'], 1, '.', ''),
                $stt['max'] === null ? '' : number_format($stt['max'], 1, '.', ''),
                $stt['pass'] === null ? '' : number_format($stt['pass'], 1, '.', ''),
            ]);
        }
    }
    fclose($out);
    exit;
}

// ------------------------------
// Render
// ------------------------------
$page_title = 'Class report';
require_once __DIR__ . '/header.php';

$examLabel = static function (array $e): string {
    $t = $e['term'] === null ? '' : ('Term ' . (int)$e['term']);
    $d = $e['exam_date'] ? (string)$e['exam_date'] : '';
    $name = (string)$e['exam_name'];
    $parts = array_filter([$t, $name, $d], static fn($x) => $x !== '');
    return implode(' · ', $parts);
};

?>
<div class="container-fluid py-3" id="mainContent">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h4 mb-0"><i class="bi bi-people me-2"></i>Class report</h1>
      <div class="text-muted small">Whole class statistics term-by-term, subject-by-subject</div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($canRun && $hasExams): ?>
        <a class="btn btn-outline-secondary btn-sm"
           href="?academic_year=<?= h_attr($selectedYear) ?>&class_code=<?= h_attr($selectedClass) ?>&track=<?= h_attr($selectedTrack) ?>&export=1">
          <i class="bi bi-download me-1"></i>Download CSV
        </a>
      <?php endif; ?>
    </div>
  </div>

  <form class="card shadow-sm mb-3" method="get" action="">
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-sm-6 col-lg-3">
          <label class="form-label">Academic year</label>
          <select name="academic_year" class="form-select">
            <?php foreach ($years as $y): $yy = (string)$y['academic_year']; ?>
              <option value="<?= h_attr($yy) ?>" <?= $yy === $selectedYear ? 'selected' : '' ?>><?= h($yy) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-sm-6 col-lg-3">
          <label class="form-label">Class</label>
          <select name="class_code" class="form-select">
            <?php foreach ($classes as $c): $cc = (string)$c['class_code']; ?>
              <option value="<?= h_attr($cc) ?>" <?= $cc === $selectedClass ? 'selected' : '' ?>><?= h($cc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-sm-6 col-lg-3">
          <label class="form-label">Track</label>
          <select name="track" class="form-select">
            <?php foreach ($trackOptions as $val => $label): ?>
              <option value="<?= h_attr($val) ?>" <?= $val === $selectedTrack ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-sm-6 col-lg-3 d-flex gap-2">
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary" href="class_report.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
      </div>

      <div class="mt-3 d-flex flex-wrap gap-2 small text-muted">
        <span class="badge text-bg-light text-dark border"><i class="bi bi-check2-circle me-1"></i>Pass: <?= h(fmt1($PASS)) ?>/40</span>
        <span class="badge text-bg-light text-dark border"><i class="bi bi-award me-1"></i>Good: <?= h(fmt1($GOOD)) ?>/40</span>
        <span class="badge text-bg-light text-dark border"><i class="bi bi-stars me-1"></i>Excellent: <?= h(fmt1($EXCELLENT)) ?>/40</span>
      </div>
    </div>
  </form>

  <?php if (!$canRun): ?>
    <div class="alert alert-warning">Select an academic year and class to view the report.</div>
  <?php elseif (!$hasExams): ?>
    <div class="alert alert-warning">No exams found for the selected academic year.</div>
  <?php else: ?>

    <div class="row g-3 mb-3">
      <div class="col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-muted small">Class</div>
                <div class="h5 mb-0"><?= h($selectedClass) ?></div>
                <div class="text-muted small"><?= h($selectedYear) ?><?= $selectedTrack !== '' ? ' · ' . h($selectedTrack) : '' ?></div>
              </div>
              <div class="display-6"><i class="bi bi-mortarboard"></i></div>
            </div>
            <hr class="my-3">
            <div class="d-flex justify-content-between"><div class="text-muted">Pupils</div><div class="fw-semibold mono"><?= h((string)$pupilCount) ?></div></div>
            <div class="d-flex justify-content-between"><div class="text-muted">Exams</div><div class="fw-semibold mono"><?= h((string)count($exams)) ?></div></div>
            <div class="d-flex justify-content-between"><div class="text-muted">Subjects</div><div class="fw-semibold mono"><?= h((string)count($subjects)) ?></div></div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="fw-semibold"><i class="bi bi-activity me-2"></i>Overall (all subjects) — by term</div>
              <div class="small text-muted">avg · median · pass rate · stdev</div>
            </div>

            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="min-width:220px;">Exam</th>
                    <th class="text-end" style="width:70px;">N</th>
                    <th class="text-end" style="width:140px;">Avg (Δ)</th>
                    <th class="text-end" style="width:120px;">Median</th>
                    <th class="text-end" style="width:120px;">Pass</th>
                    <th class="text-end" style="width:120px;">SD</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $prevAvg = null;
                  foreach ($exams as $e):
                    $eid = (int)$e['id'];
                    $stt = $overall[$eid] ?? null;
                    $avg = $stt['avg'] ?? null;
                    $delta = ($avg !== null && $prevAvg !== null) ? ($avg - $prevAvg) : null;
                    [$dCls, $dIc, $dTxt] = delta_badge($delta);
                ?>
                  <tr>
                    <td><div class="fw-semibold"><?= h($examLabel($e)) ?></div></td>
                    <td class="text-end mono"><?= h((string)($stt['n'] ?? 0)) ?></td>
                    <td class="text-end">
                      <span class="badge text-bg-dark mono"><?= h(fmt1($avg)) ?></span>
                      <span class="badge <?= h_attr($dCls) ?> mono ms-1"><i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?></span>
                    </td>
                    <td class="text-end mono"><?= h(fmt1($stt['median'] ?? null)) ?></td>
                    <td class="text-end mono"><?= h(fmtPct($stt['pass'] ?? null)) ?></td>
                    <td class="text-end mono"><?= h($stt && $stt['sd'] !== null ? number_format((float)$stt['sd'], 2, '.', '') : '—') ?></td>
                  </tr>
                <?php
                    if ($avg !== null) $prevAvg = $avg;
                  endforeach;
                ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="fw-semibold"><i class="bi bi-table me-2"></i>Subject-by-subject matrix</div>
          <div class="small text-muted">Each cell: avg (Δ) · median · pass% · min–max</div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="min-width:220px;">Subject</th>
                <?php foreach ($exams as $e): ?>
                  <th style="min-width:240px;">
                    <div class="fw-semibold"><?= h($e['term'] === null ? (string)$e['exam_name'] : ('Term ' . (int)$e['term'])) ?></div>
                    <div class="text-muted small"><?= h($e['exam_date'] ?? '') ?></div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>

            <tbody>
            <?php foreach ($subjects as $s): ?>
              <?php
                $sid = (int)$s['id'];
                $prev = null;
              ?>
              <tr>
                <td class="fw-semibold">
                  <?= h((string)$s['name']) ?>
                  <div class="text-muted small">max <?= h((string)$s['max_points']) ?></div>
                </td>

                <?php foreach ($exams as $e): ?>
                  <?php
                    $eid = (int)$e['id'];
                    $stt = $agg[$sid][$eid] ?? null;

                    $avg = $stt['avg'] ?? null;
                    $delta = ($avg !== null && $prev !== null) ? ($avg - $prev) : null;
                    [$dCls, $dIc, $dTxt] = delta_badge($delta);
                    $avgCls = score_badge_class($avg, $PASS, $GOOD, $EXCELLENT);
                  ?>
                  <td>
                    <?php if (!$stt): ?>
                      <div class="text-muted small">—</div>
                    <?php else: ?>
                      <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                          <span class="badge <?= h_attr($avgCls) ?> mono"><?= h(fmt1($avg)) ?></span>
                          <span class="badge <?= h_attr($dCls) ?> mono"><i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?></span>
                        </div>
                        <div class="text-muted small mono">N <?= h((string)$stt['n']) ?></div>
                      </div>

                      <div class="d-flex justify-content-between mt-1">
                        <div class="small text-muted">median</div>
                        <div class="small mono"><?= h(fmt1($stt['median'])) ?></div>
                      </div>
                      <div class="d-flex justify-content-between">
                        <div class="small text-muted">pass</div>
                        <div class="small mono"><?= h(fmtPct($stt['pass'])) ?></div>
                      </div>
                      <div class="d-flex justify-content-between">
                        <div class="small text-muted">min–max</div>
                        <div class="small mono"><?= h(fmt1($stt['min'])) ?>–<?= h(fmt1($stt['max'])) ?></div>
                      </div>
                      <div class="d-flex justify-content-between">
                        <div class="small text-muted">sd</div>
                        <div class="small mono"><?= h(fmt2($stt['sd'])) ?></div>
                      </div>

                      <?php if ($avg !== null) $prev = $avg; ?>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>

              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="small text-muted mt-2">
          Notes: pass% is share of results where score ≥ <?= h(fmt1($PASS)) ?>.
          Deltas compare averages to the previous exam (by ordering Term, then date).
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
