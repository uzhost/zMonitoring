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
require_once __DIR__ . '/../inc/functions.php';
$tguard_allowed_methods = ['GET', 'HEAD'];
$tguard_allowed_levels = [1, 2, 3];
$tguard_login_path = '/teachers/login.php';
$tguard_fallback_path = '/teachers/class_report.php';
$tguard_enforce_read_scope = true;
$tguard_require_active = true;
require_once __DIR__ . '/_tguard.php';

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
$missingTrackForScope = $trackRequiredError;

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

$canRun = ($selectedYear !== '' && $selectedClass !== '' && !$missingTrackForScope);

// ------------------------------
// Resolve group classes (single vs parallels)
// ------------------------------
$grade = ($selectedClass !== '') ? resolve_class_grade($pdo, $selectedClass) : null;
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
             WHERE (
                    REPLACE(REPLACE(class_code, '–', '-'), '—', '-') LIKE ?
                    OR REPLACE(REPLACE(class_code, '–', '-'), '—', '-') LIKE ?
                   )
               AND (? = '' OR track = ?)
             ORDER BY CAST(TRIM(SUBSTRING_INDEX(REPLACE(REPLACE(class_code, '–', '-'), '—', '-'), '-', 1)) AS UNSIGNED), class_code"
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
$distribution = []; // [exam_id] => student-level distribution intelligence

$bandDefs = [
    ['key' => 'weak',   'label' => '0–45.99%',   'from' => 0.0,  'to' => 45.99,  'class' => 'band-weak'],
    ['key' => 'lower',  'label' => '46–65.99%',  'from' => 46.0, 'to' => 65.99,  'class' => 'band-lower'],
    ['key' => 'middle', 'label' => '66–85.99%',  'from' => 66.0, 'to' => 85.99,  'class' => 'band-middle'],
    ['key' => 'elite',  'label' => '86–100%',    'from' => 86.0, 'to' => 100.0,  'class' => 'band-elite'],
];

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
            $agg[(int)$sid][(int)$eid]['median'] = stats_median($list);
        }
    }
    foreach ($overallScores as $eid => $list) {
        if (!isset($overall[(int)$eid])) continue;
        $overall[(int)$eid]['median'] = stats_median($list);
        $sd = $overall[(int)$eid]['sd'] ?? null;
        $avg = $overall[(int)$eid]['avg'] ?? null;
        $med = $overall[(int)$eid]['median'] ?? null;
        $overall[(int)$eid]['skew'] = ($avg !== null && $med !== null && $sd !== null && $sd > 0.00001)
            ? (($avg - $med) / $sd)
            : null;
    }

    // Student-level distribution intelligence (per exam)
    $sql = "SELECT
                r.exam_id,
                r.pupil_id,
                SUM(r.score) AS total_score,
                SUM(CASE WHEN s.max_points IS NULL OR s.max_points <= 0 THEN 40 ELSE s.max_points END) AS total_max
            FROM results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            INNER JOIN subjects s ON s.id = r.subject_id
            WHERE p.class_code IN ($inClasses)
              AND (? = '' OR p.track = ?)
              AND r.exam_id IN ($inExams)
            GROUP BY r.exam_id, r.pupil_id";
    $params = array_merge($selectedClasses, [$selectedTrack, $selectedTrack], $examIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $pupilPctByExam = []; // [exam_id] => [pct...]
    while ($r = $st->fetch()) {
        $eid = (int)$r['exam_id'];
        $total = $r['total_score'] !== null ? (float)$r['total_score'] : 0.0;
        $max = $r['total_max'] !== null ? (float)$r['total_max'] : 0.0;
        if ($max <= 0) continue;
        $pct = max(0.0, min(100.0, ($total / $max) * 100.0));
        $pupilPctByExam[$eid][] = $pct;
    }

    foreach ($exams as $e) {
        $eid = (int)$e['id'];
        $vals = $pupilPctByExam[$eid] ?? [];
        $n = count($vals);
        $avg = null;
        $med = null;
        $sd = null;
        $skew = null;
        $bands = ['weak' => 0, 'lower' => 0, 'middle' => 0, 'elite' => 0];

        if ($n > 0) {
            $avg = array_sum($vals) / $n;
            $med = stats_median($vals);
            $sd = stats_stddev_sample($vals);
            if ($med !== null && $sd !== null && $sd > 0.00001) {
                $skew = ($avg - $med) / $sd;
            }

            foreach ($vals as $pct) {
                if ($pct < 46.0) $bands['weak']++;
                elseif ($pct < 66.0) $bands['lower']++;
                elseif ($pct < 86.0) $bands['middle']++;
                else $bands['elite']++;
            }
        }

        $distribution[$eid] = [
            'n' => $n,
            'avg' => $avg,
            'median' => $med,
            'sd' => $sd,
            'skew' => $skew,
            'direction' => describe_skew_direction($skew),
            'weak_share' => $n > 0 ? ($bands['weak'] / $n * 100.0) : null,
            'lower_share' => $n > 0 ? ($bands['lower'] / $n * 100.0) : null,
            'middle_share' => $n > 0 ? ($bands['middle'] / $n * 100.0) : null,
            'elite_share' => $n > 0 ? ($bands['elite'] / $n * 100.0) : null,
            'middle_layers_share' => $n > 0 ? (($bands['lower'] + $bands['middle']) / $n * 100.0) : null,
            'mean_median_gap' => ($avg !== null && $med !== null) ? ($avg - $med) : null,
            'bands' => $bands,
        ];
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
$show_page_header = false;
require_once __DIR__ . '/header.php';

$examLabel = static function (array $e): string {
    $t = $e['term'] === null ? '' : ('Term ' . (int)$e['term']);
    $d = $e['exam_date'] ? (string)$e['exam_date'] : '';
    $name = (string)$e['exam_name'];
    $parts = array_filter([$t, $name, $d], static fn($x) => $x !== '');
    return implode(' · ', $parts);
};
$latestExamId = !empty($exams) ? (int)$exams[count($exams) - 1]['id'] : 0;
$latestDist = $latestExamId > 0 ? ($distribution[$latestExamId] ?? null) : null;
$prevExamId = (count($exams) > 1) ? (int)$exams[count($exams) - 2]['id'] : 0;
$prevDist = $prevExamId > 0 ? ($distribution[$prevExamId] ?? null) : null;
$inlineNonce = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csp_nonce']) && is_string($_SESSION['csp_nonce']))
    ? (string)$_SESSION['csp_nonce']
    : '';

$subjectSignals = [];
foreach ($subjects as $s) {
    $sid = (int)($s['id'] ?? 0);
    if ($sid <= 0) continue;

    $latestSubjectStats = null;
    $latestSubjectExam = null;
    $latestExamIdx = null;
    for ($i = count($exams) - 1; $i >= 0; $i--) {
        $eid = (int)$exams[$i]['id'];
        if (isset($agg[$sid][$eid])) {
            $latestSubjectStats = $agg[$sid][$eid];
            $latestSubjectExam = $exams[$i];
            $latestExamIdx = $i;
            break;
        }
    }
    if ($latestSubjectStats === null || $latestSubjectExam === null) continue;

    $prevSubjectStats = null;
    if ($latestExamIdx !== null) {
        for ($i = $latestExamIdx - 1; $i >= 0; $i--) {
            $eid = (int)$exams[$i]['id'];
            if (isset($agg[$sid][$eid])) {
                $prevSubjectStats = $agg[$sid][$eid];
                break;
            }
        }
    }

    $latestAvg = isset($latestSubjectStats['avg']) ? (float)$latestSubjectStats['avg'] : null;
    $latestPass = isset($latestSubjectStats['pass']) ? (float)$latestSubjectStats['pass'] : null;
    $deltaAvg = ($latestAvg !== null && isset($prevSubjectStats['avg']) && $prevSubjectStats['avg'] !== null)
        ? ($latestAvg - (float)$prevSubjectStats['avg'])
        : null;
    $deltaPass = ($latestPass !== null && isset($prevSubjectStats['pass']) && $prevSubjectStats['pass'] !== null)
        ? ($latestPass - (float)$prevSubjectStats['pass'])
        : null;

    $riskScore = 0.0;
    if ($latestAvg !== null && $latestAvg < $PASS) {
        $riskScore += 3.0 + (($PASS - $latestAvg) / 2.0);
    }
    if ($latestPass !== null) {
        if ($latestPass < 60.0) {
            $riskScore += 4.0 + ((60.0 - $latestPass) / 10.0);
        } elseif ($latestPass < 75.0) {
            $riskScore += 2.0 + ((75.0 - $latestPass) / 15.0);
        }
    }
    if ($deltaAvg !== null && $deltaAvg < -0.8) {
        $riskScore += 2.0 + (abs($deltaAvg) / 2.0);
    }
    if ($deltaPass !== null && $deltaPass < -5.0) {
        $riskScore += 1.0 + (abs($deltaPass) / 8.0);
    }

    $momentumScore = 0.0;
    if ($deltaAvg !== null && $deltaAvg > 0.8) $momentumScore += $deltaAvg;
    if ($deltaPass !== null && $deltaPass > 4.0) $momentumScore += ($deltaPass / 5.0);
    if ($latestPass !== null && $latestPass >= 85.0) $momentumScore += 0.8;

    $subjectSignals[] = [
        'subject_id' => $sid,
        'subject_name' => (string)($s['name'] ?? ''),
        'max_points' => $s['max_points'] ?? null,
        'latest_avg' => $latestAvg,
        'latest_pass' => $latestPass,
        'latest_n' => (int)($latestSubjectStats['n'] ?? 0),
        'delta_avg' => $deltaAvg,
        'delta_pass' => $deltaPass,
        'risk_score' => $riskScore,
        'momentum_score' => $momentumScore,
    ];
}

$prioritySubjects = array_values(array_filter(
    $subjectSignals,
    static fn(array $x): bool => (float)($x['risk_score'] ?? 0.0) >= 3.0
));
usort($prioritySubjects, static function (array $a, array $b): int {
    return ($b['risk_score'] <=> $a['risk_score'])
        ?: (($a['latest_pass'] ?? -1) <=> ($b['latest_pass'] ?? -1));
});
$prioritySubjects = array_slice($prioritySubjects, 0, 6);

$momentumSubjects = array_values(array_filter(
    $subjectSignals,
    static fn(array $x): bool => (float)($x['momentum_score'] ?? 0.0) >= 1.5
));
usort($momentumSubjects, static function (array $a, array $b): int {
    return ($b['momentum_score'] <=> $a['momentum_score'])
        ?: (($b['delta_avg'] ?? -INF) <=> ($a['delta_avg'] ?? -INF));
});
$momentumSubjects = array_slice($momentumSubjects, 0, 6);

$examCount = count($exams);
$subjectCount = count($subjects);

$overviewStats = [
    ['label' => 'Pupils', 'value' => (string)$pupilCount],
    ['label' => 'Exams', 'value' => (string)$examCount],
    ['label' => 'Subjects (taken)', 'value' => (string)$subjectCount],
];

?>
<style<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
  .cr-top-shell{
    --cr-accent:#0d6efd;
    --cr-accent-2:#0ea5a4;
    --cr-ink:#0f172a;
    --cr-muted:#64748b;
    --cr-border:rgba(15,23,42,.10);
    --cr-surface:#ffffff;
    --cr-soft:linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.98) 100%);
  }
  .cr-hero{
    border:1px solid var(--cr-border);
    border-radius:1rem;
    background:
      radial-gradient(560px 180px at 0% 0%, rgba(13,110,253,.10), transparent 70%),
      radial-gradient(520px 160px at 100% 100%, rgba(14,165,164,.10), transparent 70%),
      var(--cr-soft);
    box-shadow:0 8px 24px rgba(15,23,42,.04);
    padding:.75rem .9rem;
  }
  .cr-hero-mark{
    width:2.2rem;height:2.2rem;border-radius:.75rem;
    display:inline-flex;align-items:center;justify-content:center;
    color:#fff;font-size:1rem;
    background:linear-gradient(135deg, var(--cr-accent) 0%, var(--cr-accent-2) 100%);
    box-shadow:0 .4rem .9rem rgba(13,110,253,.22);
  }
  .cr-hero-title{
    color:var(--cr-ink);
    letter-spacing:-.02em;
  }
  .cr-hero-sub{
    color:var(--cr-muted);
    max-width:84ch;
    line-height:1.28;
    margin-top:.1rem;
  }
  .cr-toolbar-btn{
    border-radius:.75rem;
    padding:.42rem .75rem;
    font-weight:500;
    background:rgba(255,255,255,.75);
    border-color:rgba(15,23,42,.14);
  }
  .cr-filters-card{
    border:1px solid var(--cr-border);
    border-radius:1rem;
    background:var(--cr-surface);
    box-shadow:0 12px 28px rgba(15,23,42,.05);
    overflow:hidden;
  }
  .cr-filters-head{
    border-bottom:1px solid rgba(15,23,42,.06);
    background:
      linear-gradient(180deg, rgba(248,250,252,.9), rgba(255,255,255,.85));
    padding:.65rem .9rem;
  }
  .cr-filters-head .title{
    font-weight:700;
    color:var(--cr-ink);
    letter-spacing:-.01em;
  }
  .cr-filters-head .sub{
    color:var(--cr-muted);
    font-size:.8rem;
    line-height:1.2;
  }
  .cr-field{
    padding:.4rem .45rem;
    border:1px solid rgba(15,23,42,.06);
    border-radius:.9rem;
    background:linear-gradient(180deg, #fff, #fbfdff);
    height:100%;
  }
  .cr-field .form-label{
    margin-bottom:.3rem;
    font-weight:600;
    color:#1f2937;
    font-size:.9rem;
  }
  .cr-field .form-select{
    border-radius:.7rem;
    min-height:42px;
    border-color:rgba(15,23,42,.10);
    box-shadow:none;
    background-color:#fff;
  }
  .cr-field .form-select:focus{
    border-color:rgba(13,110,253,.45);
    box-shadow:0 0 0 .2rem rgba(13,110,253,.10);
  }
  .cr-field.disabled{
    background:linear-gradient(180deg, #f8fafc, #f1f5f9);
  }
  .cr-field.disabled .form-label,
  .cr-field.disabled .invalid-feedback{ color:#94a3b8; }
  .cr-actions{
    border-top:1px dashed rgba(15,23,42,.10);
    margin-top:.1rem;
    padding-top:.65rem;
  }
  .cr-actions .btn{
    border-radius:.75rem;
    min-height:40px;
    padding-inline:.8rem;
  }
  .cr-actions .btn-primary{
    box-shadow:0 .45rem .9rem rgba(13,110,253,.18);
  }
  .cr-reset-btn{
    min-width:40px;
  }
  .cr-applied{
    border-top:1px dashed rgba(15,23,42,.10);
    margin-top:.55rem;
    padding-top:.65rem;
  }
  .cr-chip{
    display:inline-flex;align-items:center;gap:.4rem;
    border-radius:999px;
    padding:.32rem .6rem;
    border:1px solid rgba(15,23,42,.10);
    background:linear-gradient(180deg,#fff,#f8fafc);
    color:#1f2937;
    font-size:.8rem;
    font-weight:600;
  }
  .cr-chip i{ color:#64748b; }
  .cr-chip.metric{
    background:linear-gradient(180deg, rgba(13,110,253,.06), rgba(13,110,253,.02));
    border-color:rgba(13,110,253,.18);
  }
  .cr-chip.scope{
    background:linear-gradient(180deg, rgba(14,165,164,.08), rgba(14,165,164,.02));
    border-color:rgba(14,165,164,.18);
  }
  .cr-header-actions{
    display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;
  }
  @media (max-width: 991.98px){
    .cr-hero{ padding:.7rem .8rem; }
    .cr-filters-head{ padding:.6rem .8rem; }
  }
</style>
<div class="container-fluid py-3" id="mainContent">
  <div class="cr-top-shell">
  <div class="cr-hero d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
    <div class="d-flex align-items-start gap-2">
      <span class="cr-hero-mark" aria-hidden="true"><i class="bi bi-people"></i></span>
      <div>
        <h1 class="h4 mb-0 cr-hero-title">Class report</h1>
        <div class="cr-hero-sub small">Whole class (or parallels) statistics term-by-term, subject-by-subject. Use the filters below to switch between a single class, all parallels, or a track-specific cohort.</div>
      </div>
    </div>
    <div class="cr-header-actions">
      <?php if ($canRun && $hasExams): ?>
        <a class="btn btn-outline-secondary btn-sm cr-toolbar-btn"
           href="?academic_year=<?= h_attr($selectedYear) ?>&class_code=<?= h_attr($selectedClass) ?>&track=<?= h_attr($selectedTrack) ?>&scope=<?= h_attr($scope) ?>&export=1">
          <i class="bi bi-download me-1"></i>Download CSV
        </a>
      <?php endif; ?>
    </div>
  </div>

  <form class="cr-filters-card mb-3" method="get" action="">
    <div class="cr-filters-head d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <div class="title"><i class="bi bi-sliders me-2"></i>Filters</div>
        <div class="sub">Refine cohort scope and jump to the most useful views.</div>
      </div>
      <?php if ($canRun): ?>
        <div class="small text-muted">
          <span class="me-2"><i class="bi bi-mortarboard me-1"></i><?= h($groupLabel) ?></span>
          <span><i class="bi bi-calendar3 me-1"></i><?= h($selectedYear) ?></span>
        </div>
      <?php endif; ?>
    </div>
    <div class="card-body p-3">
      <div class="row g-2 align-items-end">
        <div class="col-sm-6 col-lg-3">
          <div class="cr-field">
            <label class="form-label">Academic year</label>
            <select name="academic_year" class="form-select">
              <?php foreach ($years as $y): $yy = (string)$y['academic_year']; ?>
                <option value="<?= h_attr($yy) ?>" <?= $yy === $selectedYear ? 'selected' : '' ?>><?= h($yy) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <div class="cr-field">
            <label class="form-label">Class</label>
            <select name="class_code" class="form-select">
              <?php foreach ($classes as $c): $cc = (string)$c['class_code']; ?>
                <option value="<?= h_attr($cc) ?>" <?= $cc === $selectedClass ? 'selected' : '' ?>><?= h($cc) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <div class="cr-field">
            <label class="form-label">Scope</label>
            <select name="scope" id="scopeSelect" class="form-select">
              <?php foreach ($scopeOptions as $val => $label): ?>
                <option value="<?= h_attr($val) ?>" <?= $val === $scope ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3" id="trackFieldWrap">
          <div class="cr-field<?= $scope !== 'grade_track' ? ' disabled' : '' ?>">
            <label class="form-label">Track</label>
            <select name="track" id="trackSelect" class="form-select<?= $trackRequiredError ? ' is-invalid' : '' ?>"<?= $scope === 'grade_track' ? ' required' : '' ?>>
              <?php foreach ($trackOptions as $val => $label): ?>
                <option value="<?= h_attr($val) ?>" <?= $val === $selectedTrack ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($trackRequiredError): ?>
              <div class="invalid-feedback d-block">Choose a track for "Parallels by track".</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-12">
        <div class="d-flex flex-wrap gap-2 align-items-center cr-actions">
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary cr-reset-btn" href="class_report.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
          <?php if ($canRun && $hasExams): ?>
            <a class="btn btn-outline-primary" href="#actionInsights"><i class="bi bi-lightning-charge me-1"></i>Action insights</a>
            <a class="btn btn-outline-primary" href="#subjectMatrix"><i class="bi bi-table me-1"></i>Subject matrix</a>
          <?php endif; ?>
        </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 small cr-applied">
        <span class="cr-chip scope"><i class="bi bi-bullseye"></i>Scope: <?= h($scopeOptions[$scope] ?? $scope) ?></span>
        <span class="cr-chip"><i class="bi bi-mortarboard"></i>Class: <?= h($selectedClass) ?></span>
        <?php if ($selectedTrack !== ''): ?>
          <span class="cr-chip"><i class="bi bi-diagram-3"></i>Track: <?= h($selectedTrack) ?></span>
        <?php endif; ?>
        <span class="cr-chip metric"><i class="bi bi-check2-circle"></i>Pass: <?= h(format_decimal_1($PASS)) ?>/40</span>
        <span class="cr-chip metric"><i class="bi bi-award"></i>Good: <?= h(format_decimal_1($GOOD)) ?>/40</span>
        <span class="cr-chip metric"><i class="bi bi-stars"></i>Excellent: <?= h(format_decimal_1($EXCELLENT)) ?>/40</span>
      </div>
    </div>
  </form>
  </div>

  <?php if (!$canRun): ?>
    <?php if ($missingTrackForScope): ?>
      <div class="alert alert-warning">
        Choose a <strong>Track</strong> when using <strong>Parallels by track (same Grade + Track)</strong>.
      </div>
    <?php else: ?>
      <div class="alert alert-warning">Select an academic year and class to view the report.</div>
    <?php endif; ?>

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

    <style<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
      .cr-overview-strip .card{
        border:1px solid rgba(15,23,42,.10);
        border-radius:1rem;
        box-shadow:0 12px 28px rgba(15,23,42,.05);
        overflow:hidden;
      }
      .cr-overview-strip{
        align-items:stretch;
      }
      .cr-summary-card{
        background:
          radial-gradient(380px 180px at 100% 0%, rgba(13,110,253,.08), transparent 70%),
          linear-gradient(180deg, #fff 0%, #fbfdff 100%);
      }
      .cr-summary-top{
        display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;
        padding-bottom:.7rem;
        border-bottom:1px solid rgba(15,23,42,.08);
      }
      .cr-summary-title{
        color:#0f172a; letter-spacing:-.02em;
      }
      .cr-summary-icon{
        width:2.55rem;height:2.55rem;border-radius:.8rem;
        display:flex;align-items:center;justify-content:center;
        color:#fff;font-size:1.05rem;
        background:linear-gradient(135deg, #0d6efd 0%, #0ea5a4 100%);
        box-shadow:0 .55rem 1rem rgba(13,110,253,.18);
      }
      .cr-summary-section{
        margin-top:.6rem;
        padding-top:.6rem;
        border-top:1px dashed rgba(15,23,42,.10);
      }
      .cr-class-badges{
        display:flex; flex-wrap:wrap; gap:.35rem;
      }
      .cr-class-chip{
        display:inline-flex; align-items:center;
        border-radius:.7rem;
        padding:.28rem .5rem;
        border:1px solid rgba(15,23,42,.10);
        background:#fff;
        font-size:.8rem;
        font-weight:600;
        color:#1f2937;
      }
      .cr-summary-stats{
        display:grid;
        gap:.45rem;
        grid-template-columns:repeat(3, minmax(0,1fr));
      }
      .cr-summary-stat{
        display:flex; flex-direction:column; justify-content:center; align-items:center; gap:.12rem;
        padding:.38rem .45rem;
        border-radius:.7rem;
        background:rgba(248,250,252,.9);
        border:1px solid rgba(15,23,42,.06);
        text-align:center;
      }
      .cr-summary-stat .k{
        color:#64748b; font-weight:600; font-size:.74rem; line-height:1.15;
        text-align:center;
      }
      .cr-summary-stat .v{
        min-width:0;
        text-align:center;
        color:#0f172a;
        font-weight:700;
        font-size:1.05rem;
        line-height:1.05;
        font-variant-numeric:tabular-nums;
        font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      }
      .cr-overall-card{
        background:linear-gradient(180deg, #fff 0%, #fcfdff 100%);
      }
      .cr-overall-head{
        display:flex; justify-content:space-between; align-items:center; gap:.75rem;
        margin-bottom:.55rem;
      }
      .cr-overall-title{
        display:flex; align-items:center; gap:.6rem;
        font-weight:700; color:#1f2937; letter-spacing:-.015em;
      }
      .cr-overall-title .icon{
        width:1.8rem;height:1.8rem;border-radius:.55rem;
        display:inline-flex;align-items:center;justify-content:center;
        color:#0d6efd;background:rgba(13,110,253,.10);
      }
      .cr-overall-sub{
        color:#64748b;
        font-size:.84rem;
        white-space:nowrap;
      }
      .cr-overall-table-shell{
        border:1px solid rgba(15,23,42,.08);
        border-radius:.85rem;
        overflow:hidden;
        background:#fff;
      }
      .cr-overall-table{
        margin-bottom:0;
      }
      .cr-overall-table thead th{
        border-bottom:1px solid rgba(15,23,42,.10);
        background:
          linear-gradient(180deg, rgba(248,250,252,.95), rgba(241,245,249,.95));
        color:#111827;
        font-weight:700;
        white-space:nowrap;
      }
      .cr-overall-table td{
        border-color:rgba(15,23,42,.08);
        padding-top:.5rem;
        padding-bottom:.5rem;
      }
      .cr-overall-table tbody tr:hover td{
        background:rgba(13,110,253,.03);
      }
      .cr-overall-table tbody tr.is-latest td{
        background:rgba(14,165,164,.035);
      }
      .cr-overall-exam-name{
        font-weight:700; color:#111827;
      }
      .cr-overall-exam-meta{
        color:#64748b; font-size:.78rem;
      }
      .cr-overall-metric-badges{
        display:inline-flex; align-items:center; gap:.35rem; flex-wrap:wrap; justify-content:flex-end;
      }
      .cr-overall-table .badge{
        border-radius:.55rem;
      }
      @media (max-width: 991.98px){
        .cr-overall-head{ align-items:flex-start; flex-direction:column; }
        .cr-overall-sub{ white-space:normal; }
      }
      @media (max-width: 575.98px){
        .cr-summary-stats{
          grid-template-columns:1fr;
        }
        .cr-summary-stat{
          flex-direction:column;
          align-items:center;
          justify-content:center;
          gap:.15rem;
        }
        .cr-summary-stat .k{
          font-size:.82rem;
          line-height:1.2;
          text-align:center;
        }
        .cr-summary-stat .v{
          font-size:1rem;
          text-align:center;
          min-width:0;
        }
      }
    </style>

    <div class="row g-3 mb-3 cr-overview-strip">
      <div class="col-lg-4">
        <div class="card shadow-sm h-100 cr-summary-card">
          <div class="card-body p-3">
            <div class="cr-summary-top">
              <div>
                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em;">Group</div>
                <div class="h4 mb-1 cr-summary-title"><?= h($groupLabel) ?></div>
                <?php if ($scope !== 'single' && $selectedClasses): ?>
                  <div class="cr-class-badges mt-2">
                    <?php foreach ($selectedClasses as $cc): ?>
                      <span class="cr-class-chip"><?= h($cc) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="cr-summary-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></div>
            </div>

            <div class="cr-summary-section">
              <div class="cr-summary-stats">
                <?php foreach ($overviewStats as $row): ?>
                  <div class="cr-summary-stat">
                    <div class="k"><?= h((string)$row['label']) ?></div>
                    <div class="v"><?= h((string)$row['value']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card shadow-sm h-100 cr-overall-card">
          <div class="card-body p-3">
            <div class="cr-overall-head">
              <div class="cr-overall-title">
                <span class="icon"><i class="bi bi-activity"></i></span>
                <span>Overall (all subjects)</span>
              </div>
              <div class="cr-overall-sub">avg · median · pass rate · stdev</div>
            </div>

            <div class="cr-overall-table-shell">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0 cr-overall-table">
                <thead>
                  <tr>
                    <th style="min-width:220px;">Exam</th>
                    <th class="text-end" style="width:70px;">N</th>
                    <th class="text-end" style="width:140px;">Avg (Δ)</th>
                    <th class="text-end" style="width:120px;">Median</th>
                    <th class="text-end" style="width:120px;">Pass</th>
                    <th class="text-end" style="width:120px;">SD</th>
                    <th class="text-end" style="width:120px;">Skewness</th>
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
                    [$dCls, $dIc, $dTxt] = badge_delta($delta);
                    $overallRowClass = ($eid === $latestExamId) ? 'is-latest' : '';
                ?>
                  <tr class="<?= h_attr($overallRowClass) ?>">
                    <td>
                      <div class="cr-overall-exam-name"><?= h((string)$e['exam_name']) ?></div>
                    </td>
                    <td class="text-end mono"><?= h((string)($stt['n'] ?? 0)) ?></td>
                    <td class="text-end">
                      <span class="cr-overall-metric-badges">
                        <span class="badge text-bg-dark mono"><?= h(format_decimal_1($avg)) ?></span>
                        <span class="badge <?= h_attr($dCls) ?> mono"><i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?></span>
                      </span>
                    </td>
                    <td class="text-end mono"><?= h(format_decimal_1($stt['median'] ?? null)) ?></td>
                    <td class="text-end mono"><?= h(format_percent_1($stt['pass'] ?? null)) ?></td>
                    <td class="text-end mono"><?= h($stt && $stt['sd'] !== null ? number_format((float)$stt['sd'], 2, '.', '') : '—') ?></td>
                    <td class="text-end mono"><?= h($stt && isset($stt['skew']) && $stt['skew'] !== null ? number_format((float)$stt['skew'], 2, '.', '') : '—') ?></td>
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
    </div>

    <div class="card shadow-sm mb-3" id="actionInsights">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <div class="fw-semibold">
            <i class="bi bi-lightning-charge me-2"></i>Action insights
            <span class="badge text-bg-light text-dark border ms-2">teacher-ready</span>
          </div>
        </div>

        <style<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
          .ai-panel{
            border:1px solid rgba(15,23,42,.09);
            border-radius:.9rem;
            background:linear-gradient(180deg,#fff,#fbfdff);
          }
          .ai-panel-head{
            display:flex;justify-content:space-between;align-items:center;gap:.6rem;
            margin-bottom:.35rem;
          }
          .ai-panel-title{
            font-weight:700;letter-spacing:-.01em;
          }
          .ai-table-wrap{
            border:1px solid rgba(15,23,42,.08);
            border-radius:.75rem;
            overflow:hidden;
            background:#fff;
          }
          .ai-table{
            margin:0;
            table-layout:fixed;
          }
          .ai-table thead th{
            background:linear-gradient(180deg, rgba(248,250,252,.95), rgba(241,245,249,.95));
            border-bottom:1px solid rgba(15,23,42,.10);
            color:#475569;
            font-size:.73rem;
            font-weight:700;
            letter-spacing:.02em;
            white-space:nowrap;
            padding:.45rem .5rem;
          }
          .ai-table td{
            border-color:rgba(15,23,42,.08);
            vertical-align:middle;
            padding:.45rem .5rem;
          }
          .ai-table tbody tr:last-child td{
            border-bottom:0;
          }
          .ai-col-subject{ width:44%; }
          .ai-col-n{ width:10%; }
          .ai-col-metric{ width:15.33%; }
          .ai-subject{
            font-weight:700;
            color:#111827;
            line-height:1.15;
            margin-bottom:.08rem;
          }
          .ai-subnote{
            font-size:.76rem;
            color:#64748b;
            line-height:1.15;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
          }
          .ai-n{
            text-align:center;
            color:#0f172a;
            font-weight:700;
            font-variant-numeric:tabular-nums;
            font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
          }
          .ai-table .badge{
            border-radius:.5rem;
            font-size:.78rem;
            padding:.28rem .45rem;
            line-height:1;
            white-space:nowrap;
          }
          .ai-table .badge.mono{
            font-variant-numeric:tabular-nums;
            font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
          }
          .ai-pill{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:4.4rem;
          }
          .ai-table .delta{
            min-width:4.1rem;
            justify-content:center;
          }
          .ai-pass-cell{
            text-align:center;
          }
          .ai-pass-delta{
            margin-top:.18rem;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:.2rem .42rem;
            border-radius:.45rem;
            border:1px solid rgba(15,23,42,.10);
            background:rgba(15,23,42,.04);
            font-size:.74rem;
            font-weight:800;
            color:#334155;
            line-height:1;
            white-space:nowrap;
            font-variant-numeric:tabular-nums;
            font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
          }
          .ai-pass-delta.pos{
            color:#0f5132;
            background:rgba(25,135,84,.14);
            border-color:rgba(25,135,84,.28);
          }
          .ai-pass-delta.neg{
            color:#842029;
            background:rgba(220,53,69,.14);
            border-color:rgba(220,53,69,.28);
          }
          .ai-pass-delta.zero{
            color:#0c5460;
            background:rgba(13,202,240,.10);
            border-color:rgba(13,202,240,.22);
          }
          .ai-empty{
            color:#64748b;
            font-size:.85rem;
            padding:.15rem 0 .05rem;
          }
          @media (max-width: 991.98px){
            .ai-col-subject{ width:42%; }
            .ai-col-n{ width:11%; }
            .ai-col-metric{ width:15.67%; }
          }
          @media (max-width: 767.98px){
            .ai-table{
              min-width:640px;
            }
          }
        </style>

        <div class="row g-3">
          <div class="col-xl-6">
            <div class="ai-panel p-3 h-100">
              <div class="ai-panel-head">
                <div class="ai-panel-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Priority support subjects</div>
                <span class="badge text-bg-light text-dark border"><?= h((string)count($prioritySubjects)) ?></span>
              </div>
              <?php if (!$prioritySubjects): ?>
                <div class="ai-empty">No subjects crossed the current risk threshold. Continue routine monitoring.</div>
              <?php else: ?>
                <div class="ai-table-wrap table-responsive">
                  <table class="table table-sm align-middle ai-table">
                    <thead>
                      <tr>
                        <th class="ai-col-subject">Subject</th>
                        <th class="ai-col-n text-center">N</th>
                        <th class="ai-col-metric text-center">Avg</th>
                        <th class="ai-col-metric text-center">Change</th>
                        <th class="ai-col-metric text-center">Pass rate</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($prioritySubjects as $it): ?>
                        <?php
                          [$dCls, $dIc, $dTxt] = badge_delta(isset($it['delta_avg']) ? (float)$it['delta_avg'] : null);
                          $passDelta = $it['delta_pass'] ?? null;
                          $passDeltaText = $passDelta === null ? '—' : ((($passDelta > 0) ? '+' : '') . number_format((float)$passDelta, 1, '.', '') . ' pp');
                          $passDeltaCls = $passDelta === null ? '' : (($passDelta > 0) ? 'pos' : (($passDelta < 0) ? 'neg' : 'zero'));
                        ?>
                        <tr>
                          <td>
                            <div class="ai-subject"><?= h((string)$it['subject_name']) ?></div>
                            <div class="ai-subnote">Priority support</div>
                          </td>
                          <td class="ai-n"><?= h((string)($it['latest_n'] ?? 0)) ?></td>
                          <td class="text-center">
                            <span class="badge <?= h_attr(badge_score_by_threshold(isset($it['latest_avg']) ? (float)$it['latest_avg'] : null, $PASS, $GOOD, $EXCELLENT)) ?> mono ai-pill"><?= h(format_decimal_1($it['latest_avg'] ?? null)) ?></span>
                          </td>
                          <td class="text-center">
                            <span class="badge <?= h_attr($dCls) ?> mono delta d-inline-flex align-items-center ai-pill"><i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?></span>
                          </td>
                          <td class="ai-pass-cell">
                            <span class="badge text-bg-dark mono ai-pill"><?= h(format_percent_1($it['latest_pass'] ?? null)) ?></span>
                            <div class="ai-pass-delta <?= h_attr($passDeltaCls) ?>"><?= h($passDeltaText) ?></div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-xl-6">
            <div class="ai-panel p-3 h-100">
              <div class="ai-panel-head">
                <div class="ai-panel-title text-success"><i class="bi bi-graph-up-arrow me-2"></i>Momentum subjects</div>
                <span class="badge text-bg-light text-dark border"><?= h((string)count($momentumSubjects)) ?></span>
              </div>
              <?php if (!$momentumSubjects): ?>
                <div class="ai-empty">No strong upward signals yet. Check upcoming exams for trend confirmation.</div>
              <?php else: ?>
                <div class="ai-table-wrap table-responsive">
                  <table class="table table-sm align-middle ai-table">
                    <thead>
                      <tr>
                        <th class="ai-col-subject">Subject</th>
                        <th class="ai-col-n text-center">N</th>
                        <th class="ai-col-metric text-center">Avg</th>
                        <th class="ai-col-metric text-center">Change</th>
                        <th class="ai-col-metric text-center">Pass rate</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($momentumSubjects as $it): ?>
                        <?php
                          [$dCls, $dIc, $dTxt] = badge_delta(isset($it['delta_avg']) ? (float)$it['delta_avg'] : null);
                          $passDelta = $it['delta_pass'] ?? null;
                          $passDeltaText = $passDelta === null ? '—' : ((($passDelta > 0) ? '+' : '') . number_format((float)$passDelta, 1, '.', '') . ' pp');
                          $passDeltaCls = $passDelta === null ? '' : (($passDelta > 0) ? 'pos' : (($passDelta < 0) ? 'neg' : 'zero'));
                        ?>
                        <tr>
                          <td>
                            <div class="ai-subject"><?= h((string)$it['subject_name']) ?></div>
                            <div class="ai-subnote">Momentum signal</div>
                          </td>
                          <td class="ai-n"><?= h((string)($it['latest_n'] ?? 0)) ?></td>
                          <td class="text-center">
                            <span class="badge <?= h_attr(badge_score_by_threshold(isset($it['latest_avg']) ? (float)$it['latest_avg'] : null, $PASS, $GOOD, $EXCELLENT)) ?> mono ai-pill"><?= h(format_decimal_1($it['latest_avg'] ?? null)) ?></span>
                          </td>
                          <td class="text-center">
                            <span class="badge <?= h_attr($dCls) ?> mono delta d-inline-flex align-items-center ai-pill"><i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?></span>
                          </td>
                          <td class="ai-pass-cell">
                            <span class="badge text-bg-dark mono ai-pill"><?= h(format_percent_1($it['latest_pass'] ?? null)) ?></span>
                            <div class="ai-pass-delta <?= h_attr($passDeltaCls) ?>"><?= h($passDeltaText) ?></div>
                          </td>
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
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="fw-semibold">
            <i class="bi bi-bar-chart-steps me-2"></i>Distribution Intelligence
            <span class="badge text-bg-light text-dark border ms-2">student-level</span>
          </div>
          <div class="small text-muted">Skewness = (Average − Median) / SD</div>
        </div>

        <style<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
          .band-track{display:flex;height:.9rem;border-radius:999px;overflow:hidden;background:#edf0f3;border:1px solid rgba(15,23,42,.06)}
          .band-seg{height:100%}
          .band-weak{background:#dc3545}
          .band-lower{background:#fd7e14}
          .band-middle{background:#0d6efd}
          .band-elite{background:#198754}
          .dist-kpi{border:1px solid rgba(15,23,42,.08);border-radius:.8rem;padding:.65rem .75rem;background:linear-gradient(180deg,#fff,#fbfdff)}
          .dist-kpi .label{font-size:.78rem;color:#6c757d;margin-bottom:.12rem}
          .dist-kpi .value{font-size:1.05rem;font-weight:700;line-height:1.2}
          .dist-kpi .sub{font-size:.78rem;color:#6c757d}
          .dist-table-shell{border:1px solid rgba(15,23,42,.08);border-radius:.85rem;overflow:hidden;background:#fff}
          .dist-table{margin-bottom:0}
          .dist-table th{white-space:nowrap;font-weight:700;background:linear-gradient(180deg, rgba(248,250,252,.95), rgba(241,245,249,.95));border-bottom:1px solid rgba(15,23,42,.10)}
          .dist-table td{border-color:rgba(15,23,42,.08);padding-top:.45rem;padding-bottom:.45rem}
          .dist-table .metric-col{background:rgba(13,110,253,.025)}
          .metric-pill{display:inline-block;min-width:4.15rem;padding:.22rem .45rem;border-radius:.5rem;background:#fff;border:1px solid rgba(15,23,42,.08);font-weight:700}
          .risk-pill{min-width:5.6rem;display:inline-block}
          .band-counts{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center}
          .band-count{display:inline-flex;align-items:center;justify-content:center;min-width:2rem;height:1.55rem;padding:0 .5rem;border-radius:999px;color:#fff;font-weight:700;line-height:1}
          .dist-table tbody tr:hover td{background:rgba(13,110,253,.03)}
          .dist-exam-name{font-weight:700;color:#111827;letter-spacing:-.01em}
          .dist-band-foot{display:flex;flex-wrap:wrap;gap:.3rem;align-items:center;margin-top:.25rem}
          .dist-band-foot-label{font-size:.75rem;color:#64748b;font-weight:600}
          .dist-delta-chip{
            display:inline-flex;align-items:center;justify-content:center;
            padding:.16rem .42rem;border-radius:.45rem;
            border:1px solid rgba(15,23,42,.10);
            background:rgba(15,23,42,.03);
            font-size:.74rem;font-weight:700;line-height:1;white-space:nowrap;
            font-variant-numeric:tabular-nums;
            font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
            color:#475569;
          }
          .dist-delta-chip.weak-pos{background:rgba(220,53,69,.12);border-color:rgba(220,53,69,.22);color:#842029}
          .dist-delta-chip.weak-neg{background:rgba(25,135,84,.12);border-color:rgba(25,135,84,.22);color:#0f5132}
          .dist-delta-chip.elite-pos{background:rgba(25,135,84,.12);border-color:rgba(25,135,84,.22);color:#0f5132}
          .dist-delta-chip.elite-neg{background:rgba(220,53,69,.12);border-color:rgba(220,53,69,.22);color:#842029}
          .dist-delta-chip.neutral{background:rgba(148,163,184,.10);border-color:rgba(148,163,184,.25);color:#64748b}
          .delta-pos{color:#198754}
          .delta-neg{color:#dc3545}
        </style>

        <div class="d-flex flex-wrap gap-2 mb-3 small">
          <?php foreach ($bandDefs as $bd): ?>
            <span class="badge text-bg-light text-dark border">
              <span class="d-inline-block rounded-circle me-1 <?= h_attr($bd['class']) ?>" style="width:.55rem;height:.55rem;"></span>
              <?= h($bd['label']) ?>
            </span>
          <?php endforeach; ?>
        </div>

        <?php if ($latestDist && ($latestDist['n'] ?? 0) > 0): ?>
          <?php
            $latestN = (int)($latestDist['n'] ?? 0);
            $latestWeak = (int)($latestDist['bands']['weak'] ?? 0);
            $latestMiddleLayers = (int)(($latestDist['bands']['lower'] ?? 0) + ($latestDist['bands']['middle'] ?? 0));
            $latestElite = (int)($latestDist['bands']['elite'] ?? 0);
            $weakShare = $latestDist['weak_share'] ?? null;
            $middleLayersShare = $latestDist['middle_layers_share'] ?? null;
            $eliteShare = $latestDist['elite_share'] ?? null;
            $deltaWeak = ($prevDist && isset($prevDist['weak_share']) && $weakShare !== null && $prevDist['weak_share'] !== null)
              ? ((float)$weakShare - (float)$prevDist['weak_share'])
              : null;
            $deltaElite = ($prevDist && isset($prevDist['elite_share']) && $eliteShare !== null && $prevDist['elite_share'] !== null)
              ? ((float)$eliteShare - (float)$prevDist['elite_share'])
              : null;
            [$midCls, $midLabel] = badge_middle_state($latestDist['lower_share'] ?? null, $latestDist['middle_share'] ?? null);
            [$riskCls, $riskLabel] = badge_distribution_risk($weakShare, $eliteShare, $latestDist['skew'] ?? null);
          ?>
          <div class="row g-2 mb-3">
            <div class="col-md-3">
              <div class="dist-kpi">
                <div class="label">Weak cluster</div>
                <div class="value"><?= h((string)$latestWeak) ?> <span class="mono text-muted">(<?= h($weakShare !== null ? number_format((float)$weakShare, 1, '.', '') : '—') ?>%)</span></div>
                <div class="sub">Δ vs prev:
                  <?php if ($deltaWeak === null): ?>—
                  <?php else: ?><span class="<?= $deltaWeak > 0 ? 'delta-neg' : ($deltaWeak < 0 ? 'delta-pos' : '') ?>"><?= h(($deltaWeak > 0 ? '+' : '') . number_format((float)$deltaWeak, 1, '.', '')) ?> pp</span><?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="dist-kpi">
                <div class="label">Middle layers</div>
                <div class="value"><?= h((string)$latestMiddleLayers) ?> <span class="mono text-muted">(<?= h($middleLayersShare !== null ? number_format((float)$middleLayersShare, 1, '.', '') : '—') ?>%)</span></div>
                <div class="sub"><span class="badge <?= h_attr($midCls) ?>"><?= h($midLabel) ?></span></div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="dist-kpi">
                <div class="label">Elite layer</div>
                <div class="value"><?= h((string)$latestElite) ?> <span class="mono text-muted">(<?= h($eliteShare !== null ? number_format((float)$eliteShare, 1, '.', '') : '—') ?>%)</span></div>
                <div class="sub">Δ vs prev:
                  <?php if ($deltaElite === null): ?>—
                  <?php else: ?><span class="<?= $deltaElite > 0 ? 'delta-pos' : ($deltaElite < 0 ? 'delta-neg' : '') ?>"><?= h(($deltaElite > 0 ? '+' : '') . number_format((float)$deltaElite, 1, '.', '')) ?> pp</span><?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="dist-kpi">
                <div class="label">Skewness signal</div>
                <div class="value"><?= h(($latestDist['skew'] ?? null) !== null ? number_format((float)$latestDist['skew'], 2, '.', '') : '—') ?></div>
                <div class="sub"><span class="badge <?= h_attr($riskCls) ?>"><?= h($riskLabel) ?></span> · <?= h((string)($latestDist['direction'] ?? 'No spread')) ?></div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="dist-table-shell">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 dist-table">
            <thead class="table-light">
              <tr>
                <th style="min-width:180px;">Exam</th>
                <th class="text-end" style="width:66px;">N</th>
                <th class="text-end" style="width:96px;">Avg %</th>
                <th class="text-end" style="width:108px;">Median %</th>
                <th class="text-end" style="width:88px;">SD</th>
                <th class="text-end" style="width:88px;">Skew</th>
                <th class="text-center" style="width:120px;">Risk</th>
                <th style="min-width:290px;">Bands</th>
              </tr>
            </thead>
            <tbody>
              <?php $prevRowDist = null; ?>
              <?php foreach ($exams as $e): ?>
                <?php
                  $eid = (int)$e['id'];
                  $dist = $distribution[$eid] ?? [
                    'n' => 0, 'avg' => null, 'median' => null, 'sd' => null, 'skew' => null,
                    'direction' => 'No spread', 'weak_share' => null, 'lower_share' => null,
                    'middle_share' => null, 'elite_share' => null, 'middle_layers_share' => null,
                    'bands' => []
                  ];
                  $dn = (int)($dist['n'] ?? 0);
                  [$riskCls, $riskLabel] = badge_distribution_risk($dist['weak_share'] ?? null, $dist['elite_share'] ?? null, $dist['skew'] ?? null);
                  $weakDeltaRow = ($prevRowDist && isset($prevRowDist['weak_share']) && $prevRowDist['weak_share'] !== null && ($dist['weak_share'] ?? null) !== null)
                    ? ((float)$dist['weak_share'] - (float)$prevRowDist['weak_share']) : null;
                  $eliteDeltaRow = ($prevRowDist && isset($prevRowDist['elite_share']) && $prevRowDist['elite_share'] !== null && ($dist['elite_share'] ?? null) !== null)
                    ? ((float)$dist['elite_share'] - (float)$prevRowDist['elite_share']) : null;
                  $weakDeltaText = $weakDeltaRow === null ? 'Weak —' : ('Weak ' . (($weakDeltaRow > 0 ? '+' : '') . number_format((float)$weakDeltaRow, 1, '.', '') . ' pp'));
                  $eliteDeltaText = $eliteDeltaRow === null ? 'Elite —' : ('Elite ' . (($eliteDeltaRow > 0 ? '+' : '') . number_format((float)$eliteDeltaRow, 1, '.', '') . ' pp'));
                  $weakDeltaChipCls = $weakDeltaRow === null ? 'neutral' : ($weakDeltaRow > 0 ? 'weak-pos' : ($weakDeltaRow < 0 ? 'weak-neg' : 'neutral'));
                  $eliteDeltaChipCls = $eliteDeltaRow === null ? 'neutral' : ($eliteDeltaRow > 0 ? 'elite-pos' : ($eliteDeltaRow < 0 ? 'elite-neg' : 'neutral'));
                ?>
                <tr>
                  <td>
                    <div class="dist-exam-name"><?= h((string)$e['exam_name']) ?></div>
                  </td>
                  <td class="text-end mono"><?= h((string)$dn) ?></td>
                  <td class="text-end mono metric-col"><span class="metric-pill"><?= h($dist['avg'] !== null ? number_format((float)$dist['avg'], 1, '.', '') : '—') ?></span></td>
                  <td class="text-end mono metric-col"><span class="metric-pill"><?= h($dist['median'] !== null ? number_format((float)$dist['median'], 1, '.', '') : '—') ?></span></td>
                  <td class="text-end mono metric-col"><span class="metric-pill"><?= h($dist['sd'] !== null ? number_format((float)$dist['sd'], 2, '.', '') : '—') ?></span></td>
                  <td class="text-end mono metric-col"><span class="metric-pill"><?= h($dist['skew'] !== null ? number_format((float)$dist['skew'], 2, '.', '') : '—') ?></span></td>
                  <td class="text-center metric-col"><span class="badge risk-pill <?= h_attr($riskCls) ?>"><?= h($riskLabel) ?></span></td>
                  <td>
                    <?php if ($dn <= 0): ?>
                      <div class="text-muted small">No pupil totals in this exam.</div>
                    <?php else: ?>
                      <div class="band-track mb-1" aria-label="Performance band distribution">
                        <?php foreach ($bandDefs as $bd): ?>
                          <?php
                            $bc = (int)($dist['bands'][$bd['key']] ?? 0);
                            $bw = $dn > 0 ? ($bc / $dn * 100.0) : 0.0;
                          ?>
                          <div class="band-seg <?= h_attr($bd['class']) ?>" style="width: <?= h_attr(number_format($bw, 4, '.', '')) ?>%;" title="<?= h_attr($bd['label'] . ': ' . $bc) ?>"></div>
                        <?php endforeach; ?>
                      </div>
                      <div class="band-counts mt-2 mb-1">
                        <?php foreach ($bandDefs as $bd): ?>
                          <?php
                            $bc = (int)($dist['bands'][$bd['key']] ?? 0);
                          ?>
                          <span class="band-count mono <?= h_attr($bd['class']) ?>" title="<?= h_attr($bd['label']) ?>"><?= h((string)$bc) ?></span>
                        <?php endforeach; ?>
                      </div>
                      <div class="dist-band-foot">
                        <span class="dist-band-foot-label">vs previous</span>
                        <span class="dist-delta-chip <?= h_attr($weakDeltaChipCls) ?>"><?= h($weakDeltaText) ?></span>
                        <span class="dist-delta-chip <?= h_attr($eliteDeltaChipCls) ?>"><?= h($eliteDeltaText) ?></span>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if ($dn > 0) $prevRowDist = $dist; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm" id="subjectMatrix">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 matrix-head">
      <div class="fw-semibold d-flex flex-wrap align-items-center gap-2">
        <span class="matrix-head-icon" aria-hidden="true"><i class="bi bi-table"></i></span>
        <span>Subject-by-subject matrix</span>
        <span class="badge text-bg-light text-dark border">Readable view</span>
      </div>
      <div class="small text-muted">
        Each cell: <span class="fw-semibold">avg</span> (Δ) · median · pass% · min–max · sd
      </div>
    </div>

    <!-- Inline styles scoped to this card only -->
    <style<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
      .matrix-head-icon{
        width:1.8rem;height:1.8rem;border-radius:.55rem;
        display:inline-flex;align-items:center;justify-content:center;
        background:rgba(13,110,253,.10);color:#0d6efd;
      }
      .matrix-wrap{
        border: 1px solid rgba(15,23,42,.10);
        border-radius: .9rem;
        overflow: auto;
        max-height: 76vh;
        background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
      }
      .matrix-table{
        min-width: 980px;
        margin: 0;
        border-collapse: separate;
        border-spacing: 0;
      }
      .matrix-table thead th{
        position: sticky;
        top: 0;
        z-index: 3;
        background:
          linear-gradient(180deg, rgba(248,250,252,.96), rgba(241,245,249,.96));
        border-bottom: 1px solid rgba(15,23,42,.12);
        vertical-align: bottom;
        padding-top: .6rem;
        padding-bottom: .55rem;
      }
      .matrix-table th.subject-col{
        position: sticky;
        left: 0;
        z-index: 4;
        background:
          linear-gradient(180deg, rgba(248,250,252,.98), rgba(241,245,249,.98));
        border-right: 1px solid rgba(15,23,42,.12);
        min-width: 240px;
        box-shadow: 8px 0 12px -12px rgba(15,23,42,.18);
      }
      .matrix-table td.subject-col{
        position: sticky;
        left: 0;
        z-index: 2;
        background: #ffffff;
        border-right: 1px solid rgba(15,23,42,.10);
        box-shadow: 8px 0 12px -12px rgba(15,23,42,.14);
      }
      .matrix-table td{
        background: transparent;
        border-color: rgba(15,23,42,.08);
        padding: .35rem;
      }
      .matrix-table tbody tr:nth-child(odd) td:not(.subject-col){
        background: rgba(13,110,253,.018);
      }
      .matrix-table tbody tr:hover td:not(.subject-col){
        background: rgba(13,110,253,.04);
      }
      .matrix-table th.latest-exam-col{
        background:
          linear-gradient(180deg, rgba(14,165,164,.10), rgba(14,165,164,.04));
      }
      .matrix-table td.latest-exam-col{
        background: rgba(14,165,164,.015);
      }
      .exam-head{
        min-height: 2.05rem;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1.1;
      }
      .exam-head-name{
        font-weight: 700;
        color: #111827;
        letter-spacing: -.01em;
      }
      .cell-box{
        padding: .42rem .48rem;
        border-radius: .7rem;
        background: linear-gradient(180deg, rgba(255,255,255,.95), rgba(248,250,252,.9));
        border: 1px solid rgba(15,23,42,.08);
        box-shadow: 0 1px 0 rgba(255,255,255,.7) inset;
      }
      .cell-box.cell-box-latest{
        border-color: rgba(14,165,164,.20);
        background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(240,253,250,.92));
      }
      .cell-top{
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: .5rem;
        margin-bottom: .35rem;
        padding-bottom: .3rem;
        border-bottom: 1px dashed rgba(15,23,42,.08);
      }
      .cell-stats-table{
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
      }
      .cell-stats-table thead tr{
        border-bottom: 1px dashed rgba(15,23,42,.06);
      }
      .cell-stats-table th,
      .cell-stats-table td{
        padding: .12rem .15rem;
        font-size: .78rem;
        line-height: 1.1;
        border: 0;
        background: transparent;
      }
      .cell-stats-table th{
        color: #64748b;
        white-space: nowrap;
        text-transform: lowercase;
        font-weight: 500;
        text-align: center;
      }
      .cell-stats-table td{
        font-variant-numeric: tabular-nums;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        white-space: nowrap;
        text-align: center;
        color: #0f172a;
        font-weight: 600;
      }
      .badge.mono{
        font-variant-numeric: tabular-nums;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      }
      .n-pill{
        font-size: .72rem;
        padding: .15rem .45rem;
        border-radius: 999px;
        background: rgba(15,23,42,.05);
        color: #475569;
        font-variant-numeric: tabular-nums;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        border: 1px solid rgba(15,23,42,.05);
      }
      .muted-dash{
        padding: .75rem .6rem;
        color: #94a3b8;
        background: linear-gradient(180deg, rgba(248,250,252,.95), rgba(241,245,249,.9));
        border: 1px dashed rgba(15,23,42,.14);
        border-radius: .7rem;
        text-align: center;
        font-weight: 600;
      }
     /* Subject column: match KPI cell style (same as other columns) */
.subject-cell{
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: flex-start;
  height: 100%;
  min-height: 118px;       /* closer to compact KPI cell height */
  text-align: left;       /* same visual rhythm as data */
  background:
    linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.9));
  border-color: rgba(15,23,42,.08);
}

.subject-title{
  font-weight: 700;
  line-height: 1.2;
  color:#111827;
  letter-spacing:-.01em;
}

.matrix-table thead th{
  text-align: center;
}
      .matrix-table .avg-chip{
        font-size: .9rem;
        letter-spacing: .01em;
      }
      .matrix-table .delta-chip{
        min-width: 4.2rem;
        justify-content: center;
      }
      @media (max-width: 991.98px){
        .matrix-wrap{ max-height: 68vh; }
        .matrix-table th.subject-col{ min-width: 220px; }
      }
    </style>

    <div class="matrix-wrap mt-2">
      <table class="table table-sm table-bordered align-middle matrix-table">
        <thead>
          <tr>
                    <th class="subject-col">Subject</th>
                    <?php foreach ($exams as $e): ?>
                      <?php $isLatestExamCol = ((int)$e['id'] === $latestExamId); ?>
                      <th class="<?= $isLatestExamCol ? 'latest-exam-col' : '' ?>" style="min-width: 280px;">
                        <div class="exam-head">
                          <div class="exam-head-name"><?= h((string)$e['exam_name']) ?></div>
                        </div>
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
        <td class="subject-col">
          <div class="cell-box subject-cell">
            <div class="subject-title"><?= h((string)$s['name']) ?></div>
          </div>
        </td>

            <?php foreach ($exams as $e): ?>
              <?php
                $eid = (int)$e['id'];
                $stt = $agg[$sid][$eid] ?? null;

                $avg = $stt['avg'] ?? null;
                $delta = ($avg !== null && $prev !== null) ? ($avg - $prev) : null;
                [$dCls, $dIc, $dTxt] = badge_delta($delta);
                $avgCls = badge_score_by_threshold($avg, $PASS, $GOOD, $EXCELLENT);
              ?>
              <?php $isLatestExamCol = ($eid === $latestExamId); ?>
              <td class="<?= $isLatestExamCol ? 'latest-exam-col' : '' ?>">
                <?php if (!$stt): ?>
                  <div class="muted-dash">—</div>
                    <?php else: ?>
                      <div class="cell-box<?= $isLatestExamCol ? ' cell-box-latest' : '' ?>">
                        <div class="cell-top">
                          <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="badge <?= h_attr($avgCls) ?> mono avg-chip">
                              <?= h(format_decimal_1($avg)) ?>
                            </span>
                            <span class="badge <?= h_attr($dCls) ?> mono delta-chip d-inline-flex align-items-center">
                              <i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?>
                            </span>
                          </div>
                          <span class="n-pill">N <?= h((string)$stt['n']) ?></span>
                        </div>
    
                        <table class="cell-stats-table" aria-label="Subject exam statistics">
                          <thead>
                            <tr>
                              <th scope="col">median</th>
                              <th scope="col">pass</th>
                              <th scope="col">min–max</th>
                              <th scope="col">sd</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr>
                              <td><?= h(format_decimal_1($stt['median'] ?? null)) ?></td>
                              <td><?= h(format_percent_1($stt['pass'])) ?></td>
                              <td><?= h(format_decimal_1($stt['min'])) ?>–<?= h(format_decimal_1($stt['max'])) ?></td>
                              <td><?= h(format_decimal_2($stt['sd'])) ?></td>
                            </tr>
                          </tbody>
                        </table>
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
      Pass% = share of results where score ≥ <?= h(format_decimal_1($PASS)) ?>.
      Deltas compare averages to the previous exam (ordered by term/date).
      <span class="ms-2">Tip: scroll inside the box; headers and the Subject column stay visible.</span>
    </div>
  </div>
</div>

<?php if (!empty($groupAgg)): ?>
  <div class="card shadow-sm mt-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="fw-semibold d-flex flex-wrap align-items-center gap-2">
          <i class="bi bi-diagram-3 me-2"></i>Group comparison matrix
          <span class="badge text-bg-light text-dark border">Term-by-term · Subject-by-subject</span>
        </div>
        <div class="small text-muted">
          Each cell compares <span class="fw-semibold">G1</span> vs <span class="fw-semibold">G2</span> (Avg, Pass). Δ = Avg(G2 − G1).
        </div>
      </div>

      <style<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
        .gmx-wrap{
          border: 1px solid rgba(15,23,42,.10);
          border-radius: .9rem;
          overflow: auto;
          max-height: 76vh;
          background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
          box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
        }
        .gmx-table-shell{
          border: 1px solid rgba(15,23,42,.08);
          border-radius: .85rem;
          overflow: hidden;
          background: #fff;
        }
        .gmx-table{
          min-width: 980px;
          margin: 0;
          border-collapse: separate;
          border-spacing: 0;
        }
        .gmx-table thead th{
          position: sticky;
          top: 0;
          z-index: 3;
          background:
            linear-gradient(180deg, rgba(248,250,252,.96), rgba(241,245,249,.96));
          border-bottom: 1px solid rgba(15,23,42,.12);
          vertical-align: bottom;
          text-align: center;
          padding-top: .55rem;
          padding-bottom: .5rem;
        }
        .gmx-table th.subject-col{
          position: sticky;
          left: 0;
          z-index: 4;
          background:
            linear-gradient(180deg, rgba(248,250,252,.98), rgba(241,245,249,.98));
          border-right: 1px solid rgba(15,23,42,.12);
          min-width: 240px;
          text-align: left;
          box-shadow: 8px 0 12px -12px rgba(15,23,42,.18);
        }
        .gmx-table td.subject-col{
          position: sticky;
          left: 0;
          z-index: 2;
          background: #fff;
          border-right: 1px solid rgba(15,23,42,.10);
          box-shadow: 8px 0 12px -12px rgba(15,23,42,.14);
        }
        .gmx-table td{
          border-color: rgba(15,23,42,.08);
          padding: .35rem;
          background: transparent;
        }
        .gmx-table tbody tr:nth-child(odd) td:not(.subject-col){
          background: rgba(13,110,253,.018);
        }
        .gmx-table tbody tr:hover td:not(.subject-col){
          background: rgba(13,110,253,.035);
        }
        .gmx-exam-name{
          font-weight: 700;
          color: #111827;
          letter-spacing: -.01em;
        }
        .gmx-subject-cell{
          display:flex;
          align-items:center;
          min-height: 124px;
          border-radius: .7rem;
          border: 1px solid rgba(15,23,42,.08);
          background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.9));
          padding: .55rem .65rem;
        }
        .gmx-subject-title{
          font-weight: 700;
          color: #111827;
          line-height: 1.2;
          letter-spacing: -.01em;
        }
        .gmx-cell{
          padding: .45rem .5rem;
          border-radius: .75rem;
          border: 1px solid rgba(15,23,42,.08);
          background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.92));
          box-shadow: 0 1px 0 rgba(255,255,255,.7) inset;
        }
        .gmx-cell-head{
          display:flex;
          align-items:center;
          justify-content:space-between;
          gap:.5rem;
          margin-bottom:.35rem;
          padding-bottom:.28rem;
          border-bottom: 1px dashed rgba(15,23,42,.08);
        }
        .gmx-cell-head .label{
          font-size:.75rem;
          color:#64748b;
          font-weight:600;
          white-space:nowrap;
        }
        .gmx-cell-head .badge{
          border-radius:.5rem;
          font-variant-numeric: tabular-nums;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
        }
        .gmx-mini-table{
          width:100%;
          border-collapse: collapse;
          table-layout: fixed;
        }
        .gmx-mini-table thead tr{
          border-bottom:1px dashed rgba(15,23,42,.07);
        }
        .gmx-mini-table tbody tr + tr{
          border-top:1px dashed rgba(15,23,42,.06);
        }
        .gmx-mini-table th,
        .gmx-mini-table td{
          border:0;
          background:transparent;
          padding:.16rem .15rem;
          font-size:.78rem;
          line-height:1.15;
          vertical-align:middle;
        }
        .gmx-mini-table th{
          color:#64748b;
          font-weight:600;
          white-space:nowrap;
        }
        .gmx-mini-table thead th{
          color:#475569;
          font-size:.72rem;
          letter-spacing:.02em;
        }
        .gmx-mini-table .metric-col{
          width:30%;
          text-align:left;
        }
        .gmx-mini-table .group-col{
          width:35%;
          text-align:center;
        }
        .gmx-mini-table .gmx-val{
          color:#0f172a;
          text-align:center;
          font-weight:600;
          white-space:nowrap;
          font-variant-numeric: tabular-nums;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
        }
        .gmx-mini-table .gmx-val.dim{
          color:#64748b;
        }
        .gmx-mini-table .gmx-badge{
          display:inline-flex;
          align-items:center;
          justify-content:center;
          min-width:3.85rem;
          border-radius:.5rem;
          font-size:.78rem;
          line-height:1;
          padding:.22rem .42rem;
          font-variant-numeric: tabular-nums;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
        }
        .gmx-mini-table .pass-pill{
          display:inline-flex;
          align-items:center;
          justify-content:center;
          min-width:4.6rem;
          border-radius:.45rem;
          border:1px solid rgba(15,23,42,.08);
          background: rgba(15,23,42,.03);
          color:#334155;
          padding:.16rem .35rem;
          font-size:.75rem;
          line-height:1;
          font-variant-numeric: tabular-nums;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
        }
        .gmx-dash{
          min-height: 124px;
          display:flex;
          align-items:center;
          justify-content:center;
          padding: .55rem .6rem;
          color: #94a3b8;
          background: linear-gradient(180deg, rgba(248,250,252,.95), rgba(241,245,249,.9));
          border: 1px dashed rgba(15,23,42,.14);
          border-radius: .75rem;
          text-align: center;
          font-weight: 600;
        }
        .gmx-table .badge.mono{
          font-variant-numeric: tabular-nums;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
        }
        @media (max-width: 991.98px){
          .gmx-wrap{ max-height: 68vh; }
          .gmx-table th.subject-col{ min-width: 220px; }
          .gmx-table{ min-width: 920px; }
        }
      </style>

      <div class="gmx-wrap mt-2">
        <div class="gmx-table-shell">
        <table class="table table-sm table-bordered align-middle gmx-table">
          <thead>
            <tr>
              <th class="subject-col">Subject</th>
              <?php foreach ($exams as $e): ?>
                <th style="min-width: 320px;">
                  <div class="gmx-exam-name"><?= h((string)$e['exam_name']) ?></div>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($subjects as $s): ?>
              <?php $sid = (int)$s['id']; ?>
              <tr>
                <td class="subject-col">
                  <div class="gmx-subject-cell">
                    <div class="gmx-subject-title"><?= h((string)$s['name']) ?></div>
                  </div>
                </td>

                <?php foreach ($exams as $e): ?>
                  <?php
                    $eid = (int)$e['id'];

                    $g1 = $groupAgg[$sid][$eid][1] ?? null;
                    $g2 = $groupAgg[$sid][$eid][2] ?? null;

                    $a1 = $g1['avg'] ?? null;
                    $a2 = $g2['avg'] ?? null;
                    $p1 = $g1['pass'] ?? null;
                    $p2 = $g2['pass'] ?? null;
                    $delta = ($a1 !== null && $a2 !== null) ? ($a2 - $a1) : null;
                    [$dCls, $dIc, $dTxt] = badge_delta($delta);

                    $a1Cls = badge_score_by_threshold($a1, $PASS, $GOOD, $EXCELLENT);
                    $a2Cls = badge_score_by_threshold($a2, $PASS, $GOOD, $EXCELLENT);
                  ?>

                  <td>
                    <?php if (!$g1 && !$g2): ?>
                      <div class="gmx-dash">—</div>
                    <?php else: ?>
                      <div class="gmx-cell">
                        <div class="gmx-cell-head">
                          <div class="label">Δ (G2 − G1)</div>
                          <span class="badge <?= h_attr($dCls) ?> mono">
                            <i class="bi <?= h_attr($dIc) ?> me-1"></i><?= h($dTxt) ?>
                          </span>
                        </div>

                        <table class="gmx-mini-table" aria-label="Group comparison details">
                          <thead>
                            <tr>
                              <th class="metric-col">Metric</th>
                              <th class="group-col">G1</th>
                              <th class="group-col">G2</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr>
                              <th scope="row" class="metric-col">Avg</th>
                              <td class="gmx-val">
                                <?php if ($a1 === null): ?>
                                  <span class="gmx-val dim">—</span>
                                <?php else: ?>
                                  <span class="badge <?= h_attr($a1Cls) ?> mono gmx-badge"><?= h(format_decimal_1($a1)) ?></span>
                                <?php endif; ?>
                              </td>
                              <td class="gmx-val">
                                <?php if ($a2 === null): ?>
                                  <span class="gmx-val dim">—</span>
                                <?php else: ?>
                                  <span class="badge <?= h_attr($a2Cls) ?> mono gmx-badge"><?= h(format_decimal_1($a2)) ?></span>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <tr>
                              <th scope="row" class="metric-col">Pass %</th>
                              <td class="gmx-val">
                                <?php if ($p1 === null): ?>
                                  <span class="gmx-val dim">—</span>
                                <?php else: ?>
                                  <span class="pass-pill"><?= h(format_percent_1($p1)) ?></span>
                                <?php endif; ?>
                              </td>
                              <td class="gmx-val">
                                <?php if ($p2 === null): ?>
                                  <span class="gmx-val dim">—</span>
                                <?php else: ?>
                                  <span class="pass-pill"><?= h(format_percent_1($p2)) ?></span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="small text-muted mt-2">
        <i class="bi bi-info-circle me-1"></i>
        This matrix compares <span class="fw-semibold">class_group 1 vs 2</span> per subject per exam (term/date order).
        Pass% = score ≥ <?= h(format_decimal_1($PASS)) ?>. Δ is computed only when both groups have data in the same term.
      </div>
    </div>
  </div>
<?php endif; ?>

<?php endif; ?>
</div>

<script<?= $inlineNonce !== '' ? ' nonce="' . h_attr($inlineNonce) . '"' : '' ?>>
(function () {
  var scopeSelect = document.getElementById('scopeSelect');
  var trackSelect = document.getElementById('trackSelect');
  var trackWrap = document.getElementById('trackFieldWrap');
  if (!scopeSelect || !trackSelect) return;

  function syncTrackField() {
    var needsTrack = scopeSelect.value === 'grade_track';
    trackSelect.required = needsTrack;
    trackSelect.disabled = !needsTrack;
    if (trackWrap) {
      trackWrap.classList.toggle('opacity-75', !needsTrack);
      var trackField = trackWrap.querySelector('.cr-field');
      if (trackField) {
        trackField.classList.toggle('disabled', !needsTrack);
      }
    }
    if (!needsTrack) {
      trackSelect.classList.remove('is-invalid');
    }
  }

  scopeSelect.addEventListener('change', syncTrackField);
  syncTrackField();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
