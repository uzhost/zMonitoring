<?php
// teachers/class_report.php — Whole class statistics term-by-term, subject-by-subject
// Updated drop-in:
//  - Subjects list shows ONLY subjects that the selected class/group actually has results for (no zero rows)
//  - Scope: This class / All parallels (same grade) / Parallels by track (same grade + track)
//  - class_code format supported: "5 - A" (also tolerates "5-A", "5 -A", "5- A", and dash variants)
//
// Requires:
//   /inc/db.php, /teachers/_tguard.php, /teachers/header.php, /teachers/footer.php
//
// Notes:
//   - This file expects h() and h_attr() helpers to be available (typically from your security/header includes).

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
$tguard_allowed_methods = ['GET', 'HEAD'];
$tguard_allowed_levels = [1, 2, 3];
$tguard_login_path = '/teachers/login.php';
$tguard_fallback_path = '/teachers/class_report.php';
$tguard_require_active = true;
require_once __DIR__ . '/_tguard.php';

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
function extract_grade(string $classCode): ?string
{
    // Accepts: "5 - A", "5-A", "5 -A", "5- A", "10 - V", "10-V", "5"
    // Also tolerates en-dash/em-dash.
    $s = trim($classCode);
    if (preg_match('/^\s*(\d{1,2})\s*(?:[-–—]\s*.*)?$/u', $s, $m)) {
        return $m[1];
    }
    return null;
}

// Thresholds (adjust later via settings table if needed)
$PASS = 18.4;
$GOOD = 24.4;
$EXCELLENT = 34.4;

// ------------------------------
// Inputs (GET)
// ------------------------------
$selectedYear  = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$selectedClass = isset($_GET['class_code']) ? trim((string)$_GET['class_code']) : '';
$selectedTrack = isset($_GET['track']) ? trim((string)$_GET['track']) : '';
$scope         = isset($_GET['scope']) ? trim((string)$_GET['scope']) : 'single'; // single|grade|grade_track
$export        = isset($_GET['export']) && (string)$_GET['export'] === '1';

if ($selectedTrack === 'all') $selectedTrack = '';
if ($selectedClass === 'all') $selectedClass = '';
if ($selectedYear === 'all') $selectedYear = '';
if (!in_array($scope, ['single', 'grade', 'grade_track'], true)) $scope = 'single';

// ------------------------------
// Load filter options
// ------------------------------
$years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll();
$classes = $pdo->query(
    "SELECT DISTINCT class_code
     FROM pupils
     WHERE class_code IS NOT NULL AND TRIM(class_code) <> ''
     ORDER BY CAST(TRIM(SUBSTRING_INDEX(class_code, '-', 1)) AS UNSIGNED), class_code"
)->fetchAll();

$trackOptions = ['' => 'All tracks'];
$trackRows = $pdo->query(
    "SELECT DISTINCT track
     FROM pupils
     WHERE track IS NOT NULL AND TRIM(track) <> ''
     ORDER BY track"
)->fetchAll();
foreach ($trackRows as $tr) {
    $tv = trim((string)($tr['track'] ?? ''));
    if ($tv !== '') $trackOptions[$tv] = $tv;
}
if ($selectedTrack !== '' && !array_key_exists($selectedTrack, $trackOptions)) {
    $selectedTrack = '';
}
$trackRequiredError = ($scope === 'grade_track' && $selectedTrack === '');

$scopeOptions = [
    'single'      => 'This class only',
    'grade'       => 'All parallels (same Grade)',
    'grade_track' => 'Parallels by track (same Grade + Track)',
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
// Resolve group classes (single vs parallels)
// ------------------------------
$grade = $canRun ? extract_grade($selectedClass) : null;
$selectedClasses = []; // list of class_code included

if ($canRun) {
    if ($scope === 'single' || $grade === null) {
        $selectedClasses = [$selectedClass];
    } else {
        // robust match: "5 - %" or "5-%"
        $like1 = $grade . ' - %';
        $like2 = $grade . '-%';

        $useTrack = ($scope === 'grade_track' && $selectedTrack !== '');

        $st = $pdo->prepare(
            "SELECT DISTINCT class_code
             FROM pupils
             WHERE (class_code LIKE ? OR class_code LIKE ?)
               AND (? = '' OR track = ?)
             ORDER BY CAST(TRIM(SUBSTRING_INDEX(class_code, '-', 1)) AS UNSIGNED), class_code"
        );
        $st->execute([
            $like1,
            $like2,
            $useTrack ? $selectedTrack : '',
            $useTrack ? $selectedTrack : '',
        ]);

        $selectedClasses = array_values(array_map(
            static fn($r) => (string)$r['class_code'],
            $st->fetchAll()
        ));

        if (!$selectedClasses) $selectedClasses = [$selectedClass];
    }
}

$groupLabel = $selectedClass;
if ($scope !== 'single' && $grade !== null) {
    if ($scope === 'grade') {
        $groupLabel = "Grade {$grade} — all parallels";
    } else { // grade_track
        $groupLabel = "Grade {$grade} — parallels";
        $groupLabel .= ($selectedTrack !== '') ? " ({$selectedTrack})" : " (all tracks)";
    }
}

// ------------------------------
// Load exams + (only taken) subjects + pupil count
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
}

$examIds = array_map(static fn($r) => (int)$r['id'], $exams);
$hasExams = !empty($examIds);

if ($canRun && $hasExams && $selectedClasses) {
    $inClasses = implode(',', array_fill(0, count($selectedClasses), '?'));
    $inExams = implode(',', array_fill(0, count($examIds), '?'));

    // pupil count in group
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c
         FROM pupils
         WHERE class_code IN ($inClasses)
           AND (? = '' OR track = ?)"
    );
    $st->execute(array_merge($selectedClasses, [$selectedTrack, $selectedTrack]));
    $pupilCount = (int)($st->fetch()['c'] ?? 0);

    // subjects that actually exist in results for this group + selected year exams
    $sql = "SELECT DISTINCT s.id, s.name, s.max_points
            FROM subjects s
            INNER JOIN results r ON r.subject_id = s.id
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code IN ($inClasses)
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($inExams)
            ORDER BY s.name";
    $params = array_merge($selectedClasses, [$selectedTrack, $selectedTrack], $examIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $subjects = $st->fetchAll();
}

// ------------------------------
// Build stats
// ------------------------------
$agg = [];     // [subject_id][exam_id] => stats
$overall = []; // [exam_id] => overall stats
$groupAgg = []; // [subject_id][exam_id][group] => stats

if ($canRun && $hasExams && $selectedClasses) {
    $inExams = implode(',', array_fill(0, count($examIds), '?'));
    $inClasses = implode(',', array_fill(0, count($selectedClasses), '?'));

    // Per subject/exam aggregates (for group)
    $sql = "SELECT r.exam_id, r.subject_id,
                   COUNT(*) AS n,
                   AVG(r.score) AS avg_score,
                   STDDEV_SAMP(r.score) AS sd_score,
                   MIN(r.score) AS min_score,
                   MAX(r.score) AS max_score,
                   SUM(CASE WHEN r.score >= ? THEN 1 ELSE 0 END) AS pass_n
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code IN ($inClasses)
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($inExams)
            GROUP BY r.exam_id, r.subject_id";
    $params = array_merge([$PASS], $selectedClasses, [$selectedTrack, $selectedTrack], $examIds);
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
    }

    // Overall per exam aggregates (group)
    $sql = "SELECT r.exam_id,
                   COUNT(*) AS n,
                   AVG(r.score) AS avg_score,
                   STDDEV_SAMP(r.score) AS sd_score,
                   SUM(CASE WHEN r.score >= ? THEN 1 ELSE 0 END) AS pass_n
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code IN ($inClasses)
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($inExams)
            GROUP BY r.exam_id";
    $params = array_merge([$PASS], $selectedClasses, [$selectedTrack, $selectedTrack], $examIds);
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

    // ------------------------------
    // Group-by-term-by-subject comparison (class_group 1 vs 2)
    // ------------------------------
    $inExams   = implode(',', array_fill(0, count($examIds), '?'));
    $inClasses = implode(',', array_fill(0, count($selectedClasses), '?'));

    $sql = "
        SELECT
            r.exam_id,
            r.subject_id,
            p.class_group,
            COUNT(*)                  AS n,
            AVG(r.score)              AS avg_score,
            SUM(r.score >= ?)         AS pass_n
        FROM results r
        INNER JOIN pupils p ON p.id = r.pupil_id
        WHERE p.class_code IN ($inClasses)
          AND p.class_group IN (1,2)
          AND (? = '' OR p.track = ?)
          AND r.exam_id IN ($inExams)
        GROUP BY r.exam_id, r.subject_id, p.class_group
    ";

    $params = array_merge(
        [$PASS],
        $selectedClasses,
        [$selectedTrack, $selectedTrack],
        $examIds
    );

    $st = $pdo->prepare($sql);
    $st->execute($params);

    foreach ($st->fetchAll() as $row) {
        $eid = (int)$row['exam_id'];
        $sid = (int)$row['subject_id'];
        $grp = (int)$row['class_group'];
        $n   = (int)$row['n'];

        $groupAgg[$sid][$eid][$grp] = [
            'n'    => $n,
            'avg'  => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
            'pass' => $n > 0 ? ((int)$row['pass_n'] / $n * 100.0) : null,
        ];
    }
    // Medians (PHP): load scores once
    $sql = "SELECT r.exam_id, r.subject_id, r.score
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code IN ($inClasses)
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($inExams)
            ORDER BY r.exam_id ASC, r.subject_id ASC, r.score ASC";
    $params = array_merge($selectedClasses, [$selectedTrack, $selectedTrack], $examIds);
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
// CSV export (subjects already filtered to "taken")
// ------------------------------
if ($export && $canRun && $hasExams) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="class_report_' . rawurlencode($selectedClass) . '_' . rawurlencode($selectedYear) . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Group', $groupLabel, 'Academic year', $selectedYear, 'Track', $selectedTrack !== '' ? $selectedTrack : 'All', 'Scope', $scope]);
    if ($scope !== 'single') fputcsv($out, ['Classes included', implode(', ', $selectedClasses)]);
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
                $stt['avg'] === null ? '' : number_format((float)$stt['avg'], 1, '.', ''),
                $stt['median'] === null ? '' : number_format((float)$stt['median'], 1, '.', ''),
                $stt['sd'] === null ? '' : number_format((float)$stt['sd'], 2, '.', ''),
                $stt['min'] === null ? '' : number_format((float)$stt['min'], 1, '.', ''),
                $stt['max'] === null ? '' : number_format((float)$stt['max'], 1, '.', ''),
                $stt['pass'] === null ? '' : number_format((float)$stt['pass'], 1, '.', ''),
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
      <div class="text-muted small">Whole class (or parallels) statistics term-by-term, subject-by-subject</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print
      </button>
      <?php if ($canRun && $hasExams): ?>
        <a class="btn btn-outline-secondary btn-sm"
           href="?academic_year=<?= h_attr($selectedYear) ?>&class_code=<?= h_attr($selectedClass) ?>&track=<?= h_attr($selectedTrack) ?>&scope=<?= h_attr($scope) ?>&export=1">
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
          <div class="form-text">
            Format: <span class="mono">2025-2026</span>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <label class="form-label">Class</label>
          <select name="class_code" class="form-select">
            <?php foreach ($classes as $c): $cc = (string)$c['class_code']; ?>
              <option value="<?= h_attr($cc) ?>" <?= $cc === $selectedClass ? 'selected' : '' ?>><?= h($cc) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            Format: <span class="mono">5 - A</span>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <label class="form-label">Scope</label>
          <select name="scope" id="scopeSelect" class="form-select">
            <?php foreach ($scopeOptions as $val => $label): ?>
              <option value="<?= h_attr($val) ?>" <?= $val === $scope ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            <?php if ($grade !== null): ?>
              Grade detected: <span class="mono"><?= h($grade) ?></span>
            <?php else: ?>
              Grade not detected; parallels mode falls back to "This class only".
            <?php endif; ?>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <label class="form-label">Track</label>
          <select name="track" id="trackSelect" class="form-select<?= $trackRequiredError ? ' is-invalid' : '' ?>">
            <?php foreach ($trackOptions as $val => $label): ?>
              <option value="<?= h_attr($val) ?>" <?= $val === $selectedTrack ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            Required only for "Parallels by track".
          </div>
          <?php if ($trackRequiredError): ?>
            <div class="invalid-feedback">Choose a track for "Parallels by track".</div>
          <?php endif; ?>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary" href="class_report.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
      </div>

      <div class="mt-3 d-flex flex-wrap gap-2 small">
        <span class="badge text-bg-light text-dark border"><i class="bi bi-bullseye me-1"></i>Scope: <?= h($scopeOptions[$scope] ?? $scope) ?></span>
        <span class="badge text-bg-light text-dark border"><i class="bi bi-mortarboard me-1"></i>Class: <?= h($selectedClass) ?></span>
        <?php if ($selectedTrack !== ''): ?>
          <span class="badge text-bg-light text-dark border"><i class="bi bi-diagram-3 me-1"></i>Track: <?= h($selectedTrack) ?></span>
        <?php endif; ?>
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

  <?php elseif (!$subjects): ?>
    <div class="alert alert-warning">
      No subject results found for the selected filters (year/class/scope/track).
      <?php if ($scope === 'grade_track' && $selectedTrack === ''): ?>
        <div class="small mt-1">Tip: choose a Track when using "Parallels by track".</div>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <div class="row g-3 mb-3">
      <div class="col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-muted small">Group</div>
                <div class="h5 mb-0"><?= h($groupLabel) ?></div>
                <div class="text-muted small"><?= h($selectedYear) ?><?= $selectedTrack !== '' ? ' · ' . h($selectedTrack) : '' ?></div>
              </div>
              <div class="display-6"><i class="bi bi-mortarboard"></i></div>
            </div>

            <?php if ($scope !== 'single' && $selectedClasses): ?>
              <hr class="my-3">
              <div class="text-muted small mb-1">Classes included</div>
              <div class="small">
                <?php foreach ($selectedClasses as $cc): ?>
                  <span class="badge text-bg-light text-dark border me-1 mb-1"><?= h($cc) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <hr class="my-3">
            <div class="d-flex justify-content-between"><div class="text-muted">Pupils</div><div class="fw-semibold mono"><?= h((string)$pupilCount) ?></div></div>
            <div class="d-flex justify-content-between"><div class="text-muted">Exams</div><div class="fw-semibold mono"><?= h((string)count($exams)) ?></div></div>
            <div class="d-flex justify-content-between"><div class="text-muted">Subjects (taken)</div><div class="fw-semibold mono"><?= h((string)count($subjects)) ?></div></div>
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
      <div class="fw-semibold">
        <i class="bi bi-table me-2"></i>Subject-by-subject matrix
        <span class="badge text-bg-light text-dark border ms-2">Readable view</span>
      </div>
      <div class="small text-muted">
        Each cell: <span class="fw-semibold">avg</span> (Δ) · median · pass% · min–max · sd
      </div>
    </div>

    <!-- Inline styles scoped to this card only -->
    <style>
      .matrix-wrap{
        border: 1px solid rgba(0,0,0,.08);
        border-radius: .75rem;
        overflow: auto;
        max-height: 76vh;
        background: #fff;
      }
      .matrix-table{
        min-width: 980px;
        margin: 0;
      }
      .matrix-table thead th{
        position: sticky;
        top: 0;
        z-index: 3;
        background: #f8f9fa;
        border-bottom: 1px solid rgba(0,0,0,.12);
        vertical-align: bottom;
      }
      .matrix-table th.subject-col{
        position: sticky;
        left: 0;
        z-index: 4;
        background: #f8f9fa;
        border-right: 1px solid rgba(0,0,0,.12);
        min-width: 240px;
      }
      .matrix-table td.subject-col{
        position: sticky;
        left: 0;
        z-index: 2;
        background: #ffffff;
        border-right: 1px solid rgba(0,0,0,.12);
      }
      .matrix-table td{
        background: #fff;
      }
      .matrix-table tbody tr:nth-child(odd) td:not(.subject-col){
        background: rgba(13,110,253,.03);
      }
      .exam-head{
        line-height: 1.1;
      }
      .cell-box{
        padding: .45rem .5rem;
        border-radius: .5rem;
        background: rgba(0,0,0,.02);
        border: 1px solid rgba(0,0,0,.06);
      }
      .cell-top{
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: .5rem;
        margin-bottom: .35rem;
      }
      .cell-kv{
        display: grid;
        grid-template-columns: 1fr auto;
        gap: .15rem .5rem;
        font-size: .8125rem;
        line-height: 1.25;
      }
      .kv-k{
        color: #6c757d;
        white-space: nowrap;
      }
      .kv-v{
        font-variant-numeric: tabular-nums;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        white-space: nowrap;
        text-align: right;
      }
      .badge.mono{
        font-variant-numeric: tabular-nums;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      }
      .n-pill{
        font-size: .75rem;
        padding: .1rem .4rem;
        border-radius: 999px;
        background: rgba(0,0,0,.06);
        color: #495057;
        font-variant-numeric: tabular-nums;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      }
      .muted-dash{
        padding: .4rem .5rem;
        color: #6c757d;
        background: rgba(0,0,0,.02);
        border: 1px dashed rgba(0,0,0,.15);
        border-radius: .5rem;
        text-align: center;
      }
     /* Subject column: match KPI cell style (same as other columns) */
.subject-cell{
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-height: 96px;       /* aligns with typical KPI cell height */
  text-align: left;       /* same visual rhythm as data */
}

.subject-title{
  font-weight: 600;
  line-height: 1.2;
}

.subject-sub{
  margin-top: .2rem;
}

.matrix-table thead th{
  text-align: center;
}
    </style>

    <div class="matrix-wrap mt-2">
      <table class="table table-sm table-bordered align-middle matrix-table">
        <thead>
          <tr>
                    <th class="subject-col">Subject</th>
                    <?php foreach ($exams as $e): ?>
                      <th style="min-width: 280px;">
                        <div class="exam-head fw-semibold">
                          <?= h($e['term'] === null ? (string)$e['exam_name'] : ('Term ' . (int)$e['term'])) ?>
                        </div>
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
        <td>
          <div class="cell-box subject-empty">
            <div class="cell-top justify-content-center">
              <span class="fw-semibold">
                <?= h((string)$s['name']) ?><br>
              </span>
            </div>
            </div>
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
                  <div class="muted-dash">—</div>
                    <?php else: ?>
                      <div class="cell-box">
                        <div class="cell-top">
                          <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="badge <?= h_attr($avgCls) ?> mono" style="font-size:.9rem;">
                              <?= h(fmt1($avg)) ?>
                            </span>
                            <span class="badge <?= h_attr($dCls) ?> mono">
                              <i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?>
                            </span>
                          </div>
                          <span class="n-pill">N <?= h((string)$stt['n']) ?></span>
                        </div>
    
                        <div class="cell-kv">
                          <div class="kv-k">pass</div>
                          <div class="kv-v"><?= h(fmtPct($stt['pass'])) ?></div>
    
                          <div class="kv-k">min–max</div>
                          <div class="kv-v"><?= h(fmt1($stt['min'])) ?>–<?= h(fmt1($stt['max'])) ?></div>
    
                          <div class="kv-k">sd</div>
                          <div class="kv-v"><?= h(fmt2($stt['sd'])) ?></div>
                    </div>
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
      <i class="bi bi-info-circle me-1"></i>
      Subjects are limited to those with actual results for the selected group/year.
      Pass% = share of results where score ≥ <?= h(fmt1($PASS)) ?>.
      Deltas compare averages to the previous exam (ordered by term/date).
      <span class="ms-2">Tip: scroll inside the box; headers and the Subject column stay visible.</span>
    </div>
  </div>
</div>

<?php if (!empty($groupAgg)): ?>
  <div class="card shadow-sm mt-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="fw-semibold">
          <i class="bi bi-diagram-3 me-2"></i>Group comparison matrix
          <span class="badge text-bg-light text-dark border ms-2">Term-by-term · Subject-by-subject</span>
        </div>
        <div class="small text-muted">
          Each cell shows <span class="fw-semibold">G1</span> vs <span class="fw-semibold">G2</span> (avg · pass · N) and Δ = G2 − G1
        </div>
      </div>

      <style>
        .gmx-wrap{
          border: 1px solid rgba(0,0,0,.08);
          border-radius: .75rem;
          overflow: auto;
          max-height: 76vh;
          background: #fff;
        }
        .gmx-table{
          min-width: 980px;
          margin: 0;
        }
        .gmx-table thead th{
          position: sticky;
          top: 0;
          z-index: 3;
          background: #f8f9fa;
          border-bottom: 1px solid rgba(0,0,0,.12);
          vertical-align: bottom;
          text-align: center;
        }
        .gmx-table th.subject-col{
          position: sticky;
          left: 0;
          z-index: 4;
          background: #f8f9fa;
          border-right: 1px solid rgba(0,0,0,.12);
          min-width: 240px;
          text-align: left;
        }
        .gmx-table td.subject-col{
          position: sticky;
          left: 0;
          z-index: 2;
          background: #fff;
          border-right: 1px solid rgba(0,0,0,.12);
        }
        .gmx-table tbody tr:nth-child(odd) td:not(.subject-col){
          background: rgba(13,110,253,.03);
        }
        .gmx-cell{
          padding: .5rem;
          border-radius: .65rem;
          border: 1px solid rgba(0,0,0,.06);
          background: rgba(0,0,0,.02);
        }
        .gmx-top{
          display:flex;
          justify-content: space-between;
          align-items: center;
          gap: .5rem;
          margin-bottom: .4rem;
        }
        .gmx-split{
          display:grid;
          grid-template-columns: 1fr 1fr;
          gap: .4rem;
        }
        .gmx-side{
          border: 1px solid rgba(0,0,0,.06);
          border-radius: .55rem;
          padding: .35rem .4rem;
          background: rgba(255,255,255,.65);
          min-width: 0;
        }
        .gmx-side .lbl{
          font-size: .72rem;
          color: #6c757d;
          display:flex;
          justify-content: space-between;
          gap: .35rem;
          margin-bottom: .15rem;
        }
        .gmx-side .val{
          display:flex;
          align-items: center;
          justify-content: space-between;
          gap: .4rem;
          flex-wrap: wrap;
        }
        .gmx-side .mini{
          font-size: .75rem;
          color: #6c757d;
          font-variant-numeric: tabular-nums;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
        }
        .gmx-dash{
          padding: .55rem .6rem;
          color: #6c757d;
          background: rgba(0,0,0,.02);
          border: 1px dashed rgba(0,0,0,.15);
          border-radius: .65rem;
          text-align: center;
        }
      </style>

      <div class="gmx-wrap mt-2">
        <table class="table table-sm table-bordered align-middle gmx-table">
          <thead>
            <tr>
              <th class="subject-col">Subject</th>
              <?php foreach ($exams as $e): ?>
                <th style="min-width: 320px;">
                  <div class="fw-semibold">
                    <?= h($e['term'] === null ? (string)$e['exam_name'] : ('Term ' . (int)$e['term'])) ?>
                  </div>
                  <div class="text-muted small"><?= h($e['exam_date'] ?? '') ?></div>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($subjects as $s): ?>
              <?php $sid = (int)$s['id']; ?>
              <tr>
                <td class="subject-col">
                  <div class="cell-box">
                    <div class="fw-semibold"><?= h((string)$s['name']) ?></div>
                    <div class="text-muted small">max <?= h((string)$s['max_points']) ?></div>
                  </div>
                </td>

                <?php foreach ($exams as $e): ?>
                  <?php
                    $eid = (int)$e['id'];

                    $g1 = $groupAgg[$sid][$eid][1] ?? null;
                    $g2 = $groupAgg[$sid][$eid][2] ?? null;

                    $a1 = $g1['avg'] ?? null;
                    $a2 = $g2['avg'] ?? null;

                    $delta = ($a1 !== null && $a2 !== null) ? ($a2 - $a1) : null;
                    [$dCls, $dIc, $dTxt] = delta_badge($delta);

                    $a1Cls = score_badge_class($a1, $PASS, $GOOD, $EXCELLENT);
                    $a2Cls = score_badge_class($a2, $PASS, $GOOD, $EXCELLENT);
                  ?>

                  <td>
                    <?php if (!$g1 && !$g2): ?>
                      <div class="gmx-dash">—</div>
                    <?php else: ?>
                      <div class="gmx-cell">
                        <div class="gmx-top">
                          <div class="small text-muted">Δ (Group 2 − Group 1)</div>
                          <span class="badge <?= h_attr($dCls) ?> mono">
                            <i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?>
                          </span>
                        </div>

                        <div class="gmx-split">
                          <div class="gmx-side">
                            <div class="lbl">
                              <span class="fw-semibold">Group 1</span>
                              <span class="mini"><?= $g1 ? 'N ' . h((string)$g1['n']) : 'N —' ?></span>
                            </div>
                            <div class="val">
                              <span class="badge <?= h_attr($a1Cls) ?> mono"><?= h(fmt1($a1)) ?></span>
                              <span class="mini">pass <?= h(fmtPct($g1['pass'] ?? null)) ?></span>
                            </div>
                          </div>

                          <div class="gmx-side">
                            <div class="lbl">
                              <span class="fw-semibold">Group 2</span>
                              <span class="mini"><?= $g2 ? 'N ' . h((string)$g2['n']) : 'N —' ?></span>
                            </div>
                            <div class="val">
                              <span class="badge <?= h_attr($a2Cls) ?> mono"><?= h(fmt1($a2)) ?></span>
                              <span class="mini">pass <?= h(fmtPct($g2['pass'] ?? null)) ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="small text-muted mt-2">
        <i class="bi bi-info-circle me-1"></i>
        This matrix compares <span class="fw-semibold">class_group 1 vs 2</span> per subject per exam (term/date order).
        Pass% = score ≥ <?= h(fmt1($PASS)) ?>. Δ is computed only when both groups have data in the same term.
      </div>
    </div>
  </div>
<?php endif; ?>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
