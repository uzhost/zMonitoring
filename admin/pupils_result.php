<?php
// admin/pupils_result.php — Pupil results analytics (term-to-term, trends, deltas, charts)

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

session_start_secure();
require_admin();

$page_title = 'Pupil Results Analytics';

// ------------------------- helpers -------------------------
/** @return array<int, array<string,mixed>> */
function fetch_all(PDOStatement $st): array { return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }

function clamp_int(mixed $v, int $min, int $max): int {
    $i = (int)$v;
    return max($min, min($max, $i));
}

function valid_class_code(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '' || strlen($s) > 30) return null;
    // allow letters/digits/space/dash/underscore/dot
    if (!preg_match('/^[\pL\pN _.\-]+$/u', $s)) return null;
    return $s;
}

function score_badge(float $score, float $max = 40.0): array {
    // default thresholds: 0–15 red, 16–25 amber, 26–40 green (configurable later)
    if ($score <= 15.0) return ['danger', 'Needs support'];
    if ($score <= 25.0) return ['warning', 'Developing'];
    if ($score <= 34.0) return ['success', 'Good'];
    return ['success', 'Excellent'];
}

function delta_icon(float $delta): string {
    if ($delta > 0.0001) return '<i class="bi bi-arrow-up-right text-success"></i>';
    if ($delta < -0.0001) return '<i class="bi bi-arrow-down-right text-danger"></i>';
    return '<i class="bi bi-dash-lg text-secondary"></i>';
}

function fmt_score(?float $v): string {
    if ($v === null) return '—';
    // show .0 only if needed
    return (fmod($v, 1.0) === 0.0) ? (string)(int)$v : number_format($v, 1, '.', '');
}

function fmt_pct(?float $v): string {
    if ($v === null) return '—';
    return number_format($v, 1) . '%';
}

// ------------------------- AJAX: pupils for class -------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pupils') {
    header('Content-Type: application/json; charset=utf-8');

    $class = valid_class_code($_GET['class'] ?? null);
    if ($class === null) {
        echo json_encode(['ok' => false, 'error' => 'Invalid class.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("
        SELECT id, surname, name, middle_name, student_login
        FROM pupils
        WHERE class_code = ?
        ORDER BY surname, name, id
        LIMIT 2000
    ");
    $st->execute([$class]);
    $rows = fetch_all($st);

    $out = [];
    foreach ($rows as $r) {
        $full = trim(($r['surname'] ?? '') . ' ' . ($r['name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
        $out[] = [
            'id' => (int)$r['id'],
            'label' => $full !== '' ? $full : ('Pupil #' . (int)$r['id']),
            'login' => (string)($r['student_login'] ?? ''),
        ];
    }

    echo json_encode(['ok' => true, 'pupils' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------- page state -------------------------
$class = valid_class_code($_GET['class'] ?? null);
$pupilId = isset($_GET['pupil_id']) ? (int)$_GET['pupil_id'] : 0;

$selectedPupil = null;
$pupilsInClass = [];
$classes = [];

// Classes list (for selector)
$st = $pdo->query("
    SELECT class_code, track, COUNT(*) AS cnt
    FROM pupils
    GROUP BY class_code, track
    ORDER BY class_code, track
");
$classes = fetch_all($st);

// Pupils list (server-side fallback; JS will also load dynamically)
if ($class !== null) {
    $st = $pdo->prepare("
        SELECT id, surname, name, middle_name, student_login, track
        FROM pupils
        WHERE class_code = ?
        ORDER BY surname, name, id
        LIMIT 2000
    ");
    $st->execute([$class]);
    $pupilsInClass = fetch_all($st);
}

// Validate pupil belongs to class (if both selected)
if ($pupilId > 0) {
    if ($class === null) {
        // derive class from pupil if class not provided
        $st = $pdo->prepare("SELECT * FROM pupils WHERE id = ? LIMIT 1");
        $st->execute([$pupilId]);
        $selectedPupil = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selectedPupil) $class = (string)$selectedPupil['class_code'];
    } else {
        $st = $pdo->prepare("SELECT * FROM pupils WHERE id = ? AND class_code = ? LIMIT 1");
        $st->execute([$pupilId, $class]);
        $selectedPupil = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// ------------------------- analytics (only if pupil selected) -------------------------
$examSummary = [];       // per exam: avg/total
$seriesRows  = [];       // raw per subject per exam
$latestBySubject = [];   // subject latest + prev
$termCompare = [];       // per year term1 vs term2

$chart = [
    'labels' => [],
    'datasets' => [],
    'meta' => [],
];

$kpi = [
    'avg_all' => null,
    'median_all' => null,
    'subjects' => 0,
    'exams' => 0,
    'latest_exam' => null,
    'prev_exam' => null,
    'delta_avg' => null,
];

$topUp = [];
$topDown = [];

if ($selectedPupil) {
    // Per-exam summary for this pupil
    $st = $pdo->prepare("
        SELECT
            e.id AS exam_id,
            e.academic_year,
            e.term,
            e.exam_name,
            e.exam_date,
            COUNT(*) AS subjects,
            AVG(r.score) AS avg_score,
            SUM(r.score) AS total_score
        FROM results r
        JOIN exams e ON e.id = r.exam_id
        WHERE r.pupil_id = ?
        GROUP BY e.id, e.academic_year, e.term, e.exam_name, e.exam_date
        ORDER BY COALESCE(e.exam_date, '1900-01-01'), e.id
    ");
    $st->execute([(int)$selectedPupil['id']]);
    $examSummary = fetch_all($st);

    // Raw series for chart (subject x exam)
    $st = $pdo->prepare("
        SELECT
            s.id AS subject_id,
            s.name AS subject_name,
            e.id AS exam_id,
            e.academic_year,
            e.term,
            e.exam_name,
            e.exam_date,
            r.score
        FROM results r
        JOIN exams e ON e.id = r.exam_id
        JOIN subjects s ON s.id = r.subject_id
        WHERE r.pupil_id = ?
        ORDER BY s.id, COALESCE(e.exam_date, '1900-01-01'), e.id
    ");
    $st->execute([(int)$selectedPupil['id']]);
    $seriesRows = fetch_all($st);

    // Latest vs previous score per subject (window functions)
    $st = $pdo->prepare("
        WITH x AS (
            SELECT
                s.id AS subject_id,
                s.name AS subject_name,
                e.id AS exam_id,
                e.academic_year,
                e.term,
                e.exam_name,
                e.exam_date,
                r.score,
                LAG(r.score) OVER (
                    PARTITION BY s.id
                    ORDER BY COALESCE(e.exam_date,'1900-01-01'), e.id
                ) AS prev_score,
                LAG(e.id) OVER (
                    PARTITION BY s.id
                    ORDER BY COALESCE(e.exam_date,'1900-01-01'), e.id
                ) AS prev_exam_id,
                ROW_NUMBER() OVER (
                    PARTITION BY s.id
                    ORDER BY COALESCE(e.exam_date,'1900-01-01') DESC, e.id DESC
                ) AS rn_desc
            FROM results r
            JOIN exams e ON e.id = r.exam_id
            JOIN subjects s ON s.id = r.subject_id
            WHERE r.pupil_id = ?
        )
        SELECT *
        FROM x
        WHERE rn_desc = 1
        ORDER BY subject_name
    ");
    $st->execute([(int)$selectedPupil['id']]);
    $latestBySubject = fetch_all($st);

    // Term-to-term compare per academic year (use most recent exam per term)
    $st = $pdo->prepare("
        WITH t AS (
            SELECT
                s.id AS subject_id,
                s.name AS subject_name,
                e.academic_year,
                e.term,
                r.score,
                ROW_NUMBER() OVER (
                    PARTITION BY s.id, e.academic_year, e.term
                    ORDER BY COALESCE(e.exam_date,'1900-01-01') DESC, e.id DESC
                ) AS rn
            FROM results r
            JOIN exams e ON e.id = r.exam_id
            JOIN subjects s ON s.id = r.subject_id
            WHERE r.pupil_id = ?
              AND e.term IN (1,2)
        )
        SELECT
            subject_id,
            subject_name,
            academic_year,
            MAX(CASE WHEN term = 1 AND rn = 1 THEN score END) AS term1_score,
            MAX(CASE WHEN term = 2 AND rn = 1 THEN score END) AS term2_score
        FROM t
        WHERE rn = 1
        GROUP BY subject_id, subject_name, academic_year
        ORDER BY academic_year DESC, subject_name
    ");
    $st->execute([(int)$selectedPupil['id']]);
    $termCompare = fetch_all($st);

    // KPI: avg, median across all results
    $st = $pdo->prepare("SELECT r.score FROM results r WHERE r.pupil_id = ? ORDER BY r.score");
    $st->execute([(int)$selectedPupil['id']]);
    $allScores = $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    $scoresF = [];
    foreach ($allScores as $v) $scoresF[] = (float)$v;

    $kpi['subjects'] = count($latestBySubject);
    $kpi['exams'] = count($examSummary);

    if ($scoresF) {
        $kpi['avg_all'] = array_sum($scoresF) / count($scoresF);
        $n = count($scoresF);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            $kpi['median_all'] = $scoresF[$mid];
        } else {
            $kpi['median_all'] = ($scoresF[$mid - 1] + $scoresF[$mid]) / 2.0;
        }
    }

    // Latest & previous exam overall delta (by avg_score)
    if ($examSummary) {
        $kpi['latest_exam'] = $examSummary[count($examSummary) - 1];
        $kpi['prev_exam'] = (count($examSummary) >= 2) ? $examSummary[count($examSummary) - 2] : null;

        if ($kpi['latest_exam'] && $kpi['prev_exam']) {
            $kpi['delta_avg'] = (float)$kpi['latest_exam']['avg_score'] - (float)$kpi['prev_exam']['avg_score'];
        }
    }

    // Build chart labels (exams in chronological order) and datasets per subject
    $examOrder = [];
    foreach ($examSummary as $e) {
        $id = (int)$e['exam_id'];
        $date = $e['exam_date'] ? (string)$e['exam_date'] : '';
        $term = $e['term'] !== null ? 'T' . (int)$e['term'] : 'T?';
        $label = trim(($e['academic_year'] ?? '') . ' ' . $term . ' • ' . ($e['exam_name'] ?? 'Exam') . ($date !== '' ? ' (' . $date . ')' : ''));
        $examOrder[] = ['exam_id' => $id, 'label' => $label];
    }
    $chart['labels'] = array_map(fn($x) => $x['label'], $examOrder);

    // Map exam index
    $examIndex = [];
    foreach ($examOrder as $i => $x) $examIndex[(int)$x['exam_id']] = $i;

    // subject -> series array
    $bySubject = [];
    foreach ($seriesRows as $r) {
        $sid = (int)$r['subject_id'];
        if (!isset($bySubject[$sid])) {
            $bySubject[$sid] = [
                'name' => (string)$r['subject_name'],
                'data' => array_fill(0, count($examOrder), null),
            ];
        }
        $eid = (int)$r['exam_id'];
        if (isset($examIndex[$eid])) {
            $bySubject[$sid]['data'][$examIndex[$eid]] = (float)$r['score'];
        }
    }

    // Overall avg series (derived from examSummary)
    $avgSeries = [];
    foreach ($examSummary as $e) $avgSeries[] = (float)$e['avg_score'];

    $chart['datasets'][] = [
        'label' => 'Overall average',
        'data' => $avgSeries,
        'tension' => 0.25,
        'borderWidth' => 3,
        'pointRadius' => 3,
    ];

    foreach ($bySubject as $sid => $info) {
        $chart['datasets'][] = [
            'label' => $info['name'],
            'data' => $info['data'],
            'tension' => 0.25,
            'borderWidth' => 2,
            'pointRadius' => 2,
        ];
    }

    // Top increases / decreases (latest vs prev per subject)
    $deltas = [];
    foreach ($latestBySubject as $r) {
        $cur = (float)$r['score'];
        $prev = isset($r['prev_score']) ? (float)$r['prev_score'] : null;
        $delta = ($prev === null) ? null : ($cur - $prev);

        $pct = null;
        if ($prev !== null && abs($prev) > 0.0001) $pct = ($delta / $prev) * 100.0;

        $deltas[] = [
            'subject' => (string)$r['subject_name'],
            'current' => $cur,
            'prev' => $prev,
            'delta' => $delta,
            'pct' => $pct,
        ];
    }

    $withDelta = array_values(array_filter($deltas, fn($x) => $x['delta'] !== null));
    usort($withDelta, fn($a, $b) => ($b['delta'] <=> $a['delta']));
    $topUp = array_slice($withDelta, 0, 7);

    usort($withDelta, fn($a, $b) => ($a['delta'] <=> $b['delta']));
    $topDown = array_slice($withDelta, 0, 7);
}

// ------------------------- render -------------------------
require_once __DIR__ . '/header.php';

$nonce = '';
if (!empty($_SESSION['csp_nonce']) && is_string($_SESSION['csp_nonce'])) $nonce = $_SESSION['csp_nonce'];
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end" autocomplete="off">
          <div class="col-12 col-md-4">
            <label class="form-label fw-semibold">Class</label>
            <select name="class" id="classSelect" class="form-select">
              <option value="">— Choose class —</option>
              <?php foreach ($classes as $c): ?>
                <?php
                  $cc = (string)$c['class_code'];
                  $tr = (string)$c['track'];
                  $cnt = (int)$c['cnt'];
                  $label = $cc . ' — ' . $tr . ' (' . $cnt . ')';
                ?>
                <option value="<?= h_attr($cc) ?>" <?= ($class === $cc) ? 'selected' : '' ?>>
                  <?= h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Select a class first; pupils will load automatically.</div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Pupil</label>
            <select name="pupil_id" id="pupilSelect" class="form-select" <?= $class ? '' : 'disabled' ?>>
              <option value=""><?= $class ? '— Choose pupil —' : '— Choose class first —' ?></option>

              <?php if ($pupilsInClass): ?>
                <?php foreach ($pupilsInClass as $p): ?>
                  <?php
                    $pid = (int)$p['id'];
                    $full = trim((string)$p['surname'] . ' ' . (string)$p['name'] . ' ' . (string)($p['middle_name'] ?? ''));
                    $login = (string)$p['student_login'];
                    $opt = $full . ($login !== '' ? ' • ' . $login : '') . ' • #' . $pid;
                  ?>
                  <option value="<?= $pid ?>" <?= ($selectedPupil && (int)$selectedPupil['id'] === $pid) ? 'selected' : '' ?>>
                    <?= h($opt) ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
            <div class="form-text">Tip: choose the pupil, then you will see term comparisons, trends, and changes.</div>
          </div>

          <div class="col-12 col-md-2 d-grid">
            <button class="btn btn-dark">
              <i class="bi bi-search me-1"></i> View
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($selectedPupil): ?>
    <?php
      $pFull = trim((string)$selectedPupil['surname'] . ' ' . (string)$selectedPupil['name'] . ' ' . (string)($selectedPupil['middle_name'] ?? ''));
      $pClass = (string)$selectedPupil['class_code'];
      $pTrack = (string)$selectedPupil['track'];
      $pLogin = (string)$selectedPupil['student_login'];
      $pId = (int)$selectedPupil['id'];

      $latestAvg = $kpi['latest_exam'] ? (float)$kpi['latest_exam']['avg_score'] : null;
      $prevAvg   = $kpi['prev_exam'] ? (float)$kpi['prev_exam']['avg_score'] : null;
      $deltaAvg  = $kpi['delta_avg'] !== null ? (float)$kpi['delta_avg'] : null;

      $latestExamLabel = '—';
      if ($kpi['latest_exam']) {
          $e = $kpi['latest_exam'];
          $term = ($e['term'] !== null) ? 'T' . (int)$e['term'] : 'T?';
          $dt = $e['exam_date'] ? (string)$e['exam_date'] : '';
          $latestExamLabel = trim((string)$e['academic_year'] . ' ' . $term . ' • ' . (string)$e['exam_name'] . ($dt !== '' ? ' (' . $dt . ')' : ''));
      }
    ?>

    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
          <div>
            <div class="d-flex flex-wrap align-items-center gap-2">
              <h2 class="h5 mb-0"><?= h($pFull !== '' ? $pFull : ('Pupil #' . $pId)) ?></h2>
              <span class="badge text-bg-light border"><i class="bi bi-hash me-1"></i><?= h((string)$pId) ?></span>
              <span class="badge text-bg-primary"><i class="bi bi-people me-1"></i><?= h($pClass) ?></span>
              <span class="badge text-bg-secondary"><i class="bi bi-diagram-3 me-1"></i><?= h($pTrack) ?></span>
              <?php if ($pLogin !== ''): ?>
                <span class="badge text-bg-dark"><i class="bi bi-person-badge me-1"></i><?= h($pLogin) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-muted small mt-1">
              Latest exam: <span class="fw-semibold"><?= h($latestExamLabel) ?></span>
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <div class="border rounded-3 bg-light px-3 py-2">
              <div class="text-muted small">Overall average</div>
              <div class="fw-semibold"><?= $kpi['avg_all'] === null ? '—' : h(number_format((float)$kpi['avg_all'], 2)) ?></div>
            </div>
            <div class="border rounded-3 bg-light px-3 py-2">
              <div class="text-muted small">Median</div>
              <div class="fw-semibold"><?= $kpi['median_all'] === null ? '—' : h(fmt_score((float)$kpi['median_all'])) ?></div>
            </div>
            <div class="border rounded-3 bg-light px-3 py-2">
              <div class="text-muted small">Subjects</div>
              <div class="fw-semibold"><?= h((string)$kpi['subjects']) ?></div>
            </div>
            <div class="border rounded-3 bg-light px-3 py-2">
              <div class="text-muted small">Exams</div>
              <div class="fw-semibold"><?= h((string)$kpi['exams']) ?></div>
            </div>
            <div class="border rounded-3 bg-light px-3 py-2">
              <div class="text-muted small">Avg change (latest vs prev exam)</div>
              <div class="fw-semibold">
                <?php if ($deltaAvg === null): ?>
                  —
                <?php else: ?>
                  <?= delta_icon($deltaAvg) ?>
                  <span class="<?= $deltaAvg > 0 ? 'text-success' : ($deltaAvg < 0 ? 'text-danger' : 'text-secondary') ?>">
                    <?= h(number_format($deltaAvg, 2)) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Chart + toggles -->
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="fw-semibold">
            <i class="bi bi-graph-up-arrow me-1"></i> Trend chart (per exam)
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnShowAll">
              <i class="bi bi-eye me-1"></i> Show all
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHideSubjects">
              <i class="bi bi-eye-slash me-1"></i> Only overall
            </button>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-lg-9">
              <div class="ratio ratio-21x9">
                <canvas id="trendChart"></canvas>
              </div>
              <div class="text-muted small mt-2">
                Notes: “Overall average” is derived from the pupil’s subjects in each exam. Subject lines may contain gaps if a subject was not taken in an exam.
              </div>
            </div>
            <div class="col-12 col-lg-3">
              <div class="border rounded-3 p-3 bg-light">
                <div class="fw-semibold mb-2"><i class="bi bi-sliders me-1"></i> Chart filters</div>
                <div class="small text-muted mb-2">Toggle subjects to reduce visual noise.</div>
                <div class="d-grid gap-2" style="max-height: 320px; overflow:auto;">
                  <div class="form-check">
                    <input class="form-check-input ds-toggle" type="checkbox" id="ds0" data-ds="0" checked>
                    <label class="form-check-label fw-semibold" for="ds0">Overall average</label>
                  </div>
                  <?php for ($i = 1; $i < count($chart['datasets']); $i++): ?>
                    <div class="form-check">
                      <input class="form-check-input ds-toggle" type="checkbox" id="ds<?= (int)$i ?>" data-ds="<?= (int)$i ?>" checked>
                      <label class="form-check-label" for="ds<?= (int)$i ?>"><?= h($chart['datasets'][$i]['label']) ?></label>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Latest vs previous (subject deltas) -->
    <div class="col-12 col-xl-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white fw-semibold">
          <i class="bi bi-arrow-up-right-circle me-1"></i> Biggest improvements (latest vs previous)
        </div>
        <div class="card-body">
          <?php if (!$topUp): ?>
            <div class="text-muted">Not enough data to compute improvements yet (needs at least 2 exams per subject).</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Subject</th>
                    <th class="text-end">Prev</th>
                    <th class="text-end">Current</th>
                    <th class="text-end">Δ points</th>
                    <th class="text-end">Δ %</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topUp as $r): ?>
                    <?php
                      $d = (float)$r['delta'];
                      $pct = $r['pct'] !== null ? (float)$r['pct'] : null;
                    ?>
                    <tr>
                      <td class="fw-semibold"><?= h($r['subject']) ?></td>
                      <td class="text-end"><?= h(fmt_score($r['prev'])) ?></td>
                      <td class="text-end"><?= h(fmt_score($r['current'])) ?></td>
                      <td class="text-end">
                        <?= delta_icon($d) ?>
                        <span class="text-success fw-semibold"><?= h(number_format($d, 1)) ?></span>
                      </td>
                      <td class="text-end text-success"><?= h(fmt_pct($pct)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white fw-semibold">
          <i class="bi bi-arrow-down-right-circle me-1"></i> Biggest declines (latest vs previous)
        </div>
        <div class="card-body">
          <?php if (!$topDown): ?>
            <div class="text-muted">Not enough data to compute declines yet (needs at least 2 exams per subject).</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Subject</th>
                    <th class="text-end">Prev</th>
                    <th class="text-end">Current</th>
                    <th class="text-end">Δ points</th>
                    <th class="text-end">Δ %</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topDown as $r): ?>
                    <?php
                      $d = (float)$r['delta'];
                      $pct = $r['pct'] !== null ? (float)$r['pct'] : null;
                    ?>
                    <tr>
                      <td class="fw-semibold"><?= h($r['subject']) ?></td>
                      <td class="text-end"><?= h(fmt_score($r['prev'])) ?></td>
                      <td class="text-end"><?= h(fmt_score($r['current'])) ?></td>
                      <td class="text-end">
                        <?= delta_icon($d) ?>
                        <span class="text-danger fw-semibold"><?= h(number_format($d, 1)) ?></span>
                      </td>
                      <td class="text-end text-danger"><?= h(fmt_pct($pct)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Detailed subject table (latest & prev) -->
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div><i class="bi bi-list-check me-1"></i> Subject performance (latest vs previous)</div>
          <div class="small text-muted">Trend arrow is computed from the last two available data points per subject.</div>
        </div>
        <div class="card-body">
          <?php if (!$latestBySubject): ?>
            <div class="text-muted">No results found for this pupil.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Subject</th>
                    <th class="text-end">Prev</th>
                    <th class="text-end">Current</th>
                    <th class="text-end">Δ points</th>
                    <th class="text-end">Δ %</th>
                    <th class="text-end">Level</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($latestBySubject as $r): ?>
                    <?php
                      $cur = (float)$r['score'];
                      $prev = isset($r['prev_score']) ? (float)$r['prev_score'] : null;
                      $delta = ($prev === null) ? null : ($cur - $prev);

                      $pct = null;
                      if ($prev !== null && abs($prev) > 0.0001) $pct = ($delta / $prev) * 100.0;

                      [$cls, $lbl] = score_badge($cur, 40.0);
                    ?>
                    <tr>
                      <td class="fw-semibold"><?= h($r['subject_name']) ?></td>
                      <td class="text-end"><?= h(fmt_score($prev)) ?></td>
                      <td class="text-end">
                        <span class="badge text-bg-<?= h_attr($cls) ?>"><?= h(fmt_score($cur)) ?></span>
                      </td>
                      <td class="text-end">
                        <?php if ($delta === null): ?>
                          <span class="text-muted">—</span>
                        <?php else: ?>
                          <?= delta_icon((float)$delta) ?>
                          <span class="<?= $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : 'text-secondary') ?> fw-semibold">
                            <?= h(number_format((float)$delta, 1)) ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end"><?= h(fmt_pct($pct)) ?></td>
                      <td class="text-end">
                        <span class="badge text-bg-light border text-dark"><?= h($lbl) ?></span>
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

    <!-- Term-to-term comparisons -->
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">
          <i class="bi bi-signpost-split me-1"></i> Term-to-term comparison (latest per term within academic year)
        </div>
        <div class="card-body">
          <?php if (!$termCompare): ?>
            <div class="text-muted">No term-based data (Term 1 / Term 2) found for this pupil.</div>
          <?php else: ?>
            <?php
              // group by academic year
              $byYear = [];
              foreach ($termCompare as $r) {
                  $y = (string)$r['academic_year'];
                  $byYear[$y][] = $r;
              }
              $years = array_keys($byYear);
              rsort($years);
            ?>
            <div class="accordion" id="termAccordion">
              <?php foreach ($years as $idx => $y): ?>
                <?php
                  $collapseId = 'termYear' . $idx;
                  $headingId  = 'termHead' . $idx;
                  $rows = $byYear[$y];
                ?>
                <div class="accordion-item">
                  <h2 class="accordion-header" id="<?= h_attr($headingId) ?>">
                    <button class="accordion-button <?= $idx === 0 ? '' : 'collapsed' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#<?= h_attr($collapseId) ?>"
                            aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>" aria-controls="<?= h_attr($collapseId) ?>">
                      Academic year: <?= h($y) ?>
                    </button>
                  </h2>
                  <div id="<?= h_attr($collapseId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
                       aria-labelledby="<?= h_attr($headingId) ?>" data-bs-parent="#termAccordion">
                    <div class="accordion-body">
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead class="table-light">
                            <tr>
                              <th>Subject</th>
                              <th class="text-end">Term 1</th>
                              <th class="text-end">Term 2</th>
                              <th class="text-end">Δ points</th>
                              <th class="text-end">Δ %</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($rows as $r): ?>
                              <?php
                                $t1 = $r['term1_score'] !== null ? (float)$r['term1_score'] : null;
                                $t2 = $r['term2_score'] !== null ? (float)$r['term2_score'] : null;

                                $d = null;
                                $pct = null;
                                if ($t1 !== null && $t2 !== null) {
                                    $d = $t2 - $t1;
                                    if (abs($t1) > 0.0001) $pct = ($d / $t1) * 100.0;
                                }
                              ?>
                              <tr>
                                <td class="fw-semibold"><?= h($r['subject_name']) ?></td>
                                <td class="text-end"><?= h(fmt_score($t1)) ?></td>
                                <td class="text-end"><?= h(fmt_score($t2)) ?></td>
                                <td class="text-end">
                                  <?php if ($d === null): ?>
                                    <span class="text-muted">—</span>
                                  <?php else: ?>
                                    <?= delta_icon($d) ?>
                                    <span class="<?= $d > 0 ? 'text-success' : ($d < 0 ? 'text-danger' : 'text-secondary') ?> fw-semibold">
                                      <?= h(number_format($d, 1)) ?>
                                    </span>
                                  <?php endif; ?>
                                </td>
                                <td class="text-end"><?= h(fmt_pct($pct)) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                      <div class="text-muted small mt-2">
                        Computation rule: for each subject and term, the most recent exam in that term (by date/id) is used.
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php else: ?>
    <div class="col-12">
      <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-info-circle"></i>
        <div>
          Select a class and a pupil to view term comparisons, trends, and improvement/decline analytics.
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script<?= $nonce ? ' nonce="' . h_attr($nonce) . '"' : '' ?>>
(function () {
  const classSelect = document.getElementById('classSelect');
  const pupilSelect = document.getElementById('pupilSelect');

  async function loadPupils(classCode, preserveSelectedId) {
    if (!pupilSelect) return;

    pupilSelect.disabled = true;
    pupilSelect.innerHTML = '<option value="">Loading…</option>';

    try {
      const res = await fetch('pupils_result.php?ajax=pupils&class=' + encodeURIComponent(classCode), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      const data = await res.json();

      if (!data || !data.ok) {
        pupilSelect.innerHTML = '<option value="">Failed to load pupils</option>';
        pupilSelect.disabled = true;
        return;
      }

      const pupils = data.pupils || [];
      const opts = [];
      opts.push(new Option('— Choose pupil —', ''));

      for (const p of pupils) {
        const label = (p.label || ('Pupil #' + p.id)) + (p.login ? (' • ' + p.login) : '') + ' • #' + p.id;
        const o = new Option(label, String(p.id));
        if (preserveSelectedId && String(p.id) === String(preserveSelectedId)) o.selected = true;
        opts.push(o);
      }

      pupilSelect.replaceChildren(...opts);
      pupilSelect.disabled = false;
    } catch (e) {
      pupilSelect.innerHTML = '<option value="">Failed to load pupils</option>';
      pupilSelect.disabled = true;
    }
  }

  if (classSelect && pupilSelect) {
    classSelect.addEventListener('change', function () {
      const v = classSelect.value || '';
      if (!v) {
        pupilSelect.innerHTML = '<option value="">— Choose class first —</option>';
        pupilSelect.disabled = true;
        return;
      }
      loadPupils(v, null);
    });

    // If class preselected but pupils not yet loaded via JS, this is safe to call:
    const initialClass = classSelect.value || '';
    const initialPupil = pupilSelect.value || '';
    if (initialClass) {
      // refresh list to ensure it's current, preserve selection if present
      loadPupils(initialClass, initialPupil);
    }
  }

  // Chart
  const ctx = document.getElementById('trendChart');
  if (!ctx) return;

  const payload = <?= json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: payload.labels || [],
      datasets: (payload.datasets || []).map((ds) => ({
        label: ds.label,
        data: ds.data,
        tension: ds.tension ?? 0.25,
        borderWidth: ds.borderWidth ?? 2,
        pointRadius: ds.pointRadius ?? 2,
        spanGaps: true
      }))
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function (ctx) {
              const v = ctx.parsed.y;
              const val = (v === null || v === undefined) ? '—' : (Number.isInteger(v) ? String(v) : v.toFixed(1));
              return ctx.dataset.label + ': ' + val;
            }
          }
        }
      },
      scales: {
        y: {
          suggestedMin: 0,
          suggestedMax: 40,
          ticks: { stepSize: 5 }
        }
      }
    }
  });

  function setDatasetVisible(i, visible) {
    chart.setDatasetVisibility(i, !!visible);
    chart.update();
  }

  document.querySelectorAll('.ds-toggle').forEach((el) => {
    el.addEventListener('change', () => {
      const i = parseInt(el.getAttribute('data-ds'), 10);
      if (!Number.isFinite(i)) return;
      setDatasetVisible(i, el.checked);
    });
  });

  const btnShowAll = document.getElementById('btnShowAll');
  const btnHideSubjects = document.getElementById('btnHideSubjects');

  if (btnShowAll) {
    btnShowAll.addEventListener('click', () => {
      document.querySelectorAll('.ds-toggle').forEach((el) => { el.checked = true; });
      for (let i = 0; i < (payload.datasets || []).length; i++) setDatasetVisible(i, true);
    });
  }

  if (btnHideSubjects) {
    btnHideSubjects.addEventListener('click', () => {
      document.querySelectorAll('.ds-toggle').forEach((el, idx) => { el.checked = (idx === 0); });
      for (let i = 0; i < (payload.datasets || []).length; i++) setDatasetVisible(i, i === 0);
    });
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
