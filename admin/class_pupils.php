<?php
// admin/class_pupils.php — Class pupils matrix (subjects x terms) with optional term comparison

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

session_start_secure();
require_admin(); // adjust if needed (e.g., require_role('viewer'))

$page_title = 'Class pupils — term comparison';

// -------------------------------
// Helpers
// -------------------------------
function fmt1(mixed $v): string
{
    if ($v === null || $v === '') return '—';
    $n = (float)$v;
    return (abs($n - round($n)) < 0.00001) ? (string)(int)round($n) : number_format($n, 1, '.', '');
}

function badge_score_class(float $score): string
{
    if ($score <= 18.39) return 'text-bg-danger';
    if ($score <= 24.39) return 'text-bg-warning text-dark';
    if ($score <= 34.39) return 'text-bg-primary text-white';
    return 'text-bg-success';
}

function delta_badge(float $d): array
{
    if (abs($d) < 0.00001) return ['cls' => 'text-bg-secondary', 'ic' => 'bi-dash', 'txt' => '0'];
    if ($d > 0) return ['cls' => 'text-bg-success', 'ic' => 'bi-arrow-up', 'txt' => '+' . fmt1($d)];
    return ['cls' => 'text-bg-danger', 'ic' => 'bi-arrow-down', 'txt' => fmt1($d)];
}

function safe_int(?string $v, int $min = 0, int $max = 999): int
{
    $n = (int)($v ?? '');
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

// -------------------------------
// Inputs
// -------------------------------
$class_code = trim((string)($_GET['class_code'] ?? ''));
$academic_year = trim((string)($_GET['academic_year'] ?? ''));

// term_mode: all (comparison) | one (single term snapshot)
$term_mode = strtolower(trim((string)($_GET['term_mode'] ?? 'all')));
$term_mode = in_array($term_mode, ['all', 'one'], true) ? $term_mode : 'all';
$term_one  = safe_int(isset($_GET['term']) ? (string)$_GET['term'] : null, 0, 10); // used when term_mode=one

$sort = trim((string)($_GET['sort'] ?? 'surname'));     // surname | last_total | last_delta
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc'))); // asc | desc
$dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'asc';

// -------------------------------
// Data for filters
// -------------------------------
$classList = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code")->fetchAll();
$yearList  = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll();

if ($academic_year === '') {
    $academic_year = (string)($yearList[0]['academic_year'] ?? '');
}
if ($class_code === '' && !empty($classList[0]['class_code'])) {
    $class_code = (string)$classList[0]['class_code'];
}

if ($class_code === '' || $academic_year === '') {
    $page_actions = '';
    require __DIR__ . '/header.php';
    ?>
    <div class="alert alert-warning">
      Missing class or academic year. Please import pupils/exams first.
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// -------------------------------
// Representative exams per term (latest exam_date per term in selected year)
// -------------------------------
$stmt = $pdo->prepare("
  SELECT id, term, exam_name, exam_date
  FROM exams
  WHERE academic_year = ?
    AND term IS NOT NULL
  ORDER BY term ASC, (exam_date IS NULL) ASC, exam_date DESC, id DESC
");
$stmt->execute([$academic_year]);
$rows = $stmt->fetchAll();

$termExam = []; // term => exam row
foreach ($rows as $r) {
    $t = (int)$r['term'];
    if ($t <= 0) continue;
    if (!isset($termExam[$t])) $termExam[$t] = $r;
}
ksort($termExam);
$allTerms = array_keys($termExam);

if (!$allTerms) {
    $page_actions = '';
    require __DIR__ . '/header.php';
    ?>
    <div class="alert alert-warning">
      No exams with a <code>term</code> value found for academic year <strong><?= h($academic_year) ?></strong>.
      Please set <code>exams.term</code> (e.g., 1/2/3/4).
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// Determine active terms based on term_mode
$terms = $allTerms;
if ($term_mode === 'one') {
    if ($term_one <= 0 || !isset($termExam[$term_one])) {
        // default to the latest term available
        $term_one = (int)end($allTerms);
        reset($allTerms);
    }
    $terms = [$term_one];
}

// For "comparison" features, lastTerm is meaningful only when mode=all
$lastTerm = (int)end($terms);
reset($terms);

// Prev-to-last only if comparison
$prevToLast = null;
if ($term_mode === 'all') {
    foreach ($terms as $t) {
        if ($t < $lastTerm) $prevToLast = $t;
    }
}

// -------------------------------
// Pupils in class
// -------------------------------
$stmt = $pdo->prepare("
  SELECT id, surname, name, middle_name, class_code, track, student_login
  FROM pupils
  WHERE class_code = ?
  ORDER BY surname, name, middle_name, id
");
$stmt->execute([$class_code]);
$pupils = $stmt->fetchAll();

$pupilIds = array_map(static fn($p) => (int)$p['id'], $pupils);

if (!$pupilIds) {
    $page_actions = '';
    require __DIR__ . '/header.php';
    ?>
    <div class="alert alert-info">
      No pupils found for class <strong><?= h($class_code) ?></strong>.
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// -------------------------------
// Selected exam IDs (per active terms)
// -------------------------------
$examIds = [];
foreach ($terms as $t) {
    $examIds[] = (int)$termExam[$t]['id'];
}
$examIds = array_values(array_unique($examIds));

$inEx = implode(',', array_fill(0, count($examIds), '?'));
$inPu = implode(',', array_fill(0, count($pupilIds), '?'));

// -------------------------------
// Results for selected term exams
// -------------------------------
$sql = "
  SELECT r.pupil_id, r.subject_id, r.exam_id, r.score
  FROM results r
  WHERE r.exam_id IN ($inEx)
    AND r.pupil_id IN ($inPu)
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($examIds, $pupilIds));
$resRows = $stmt->fetchAll();

// Map exam_id -> term (only for active terms)
$examTerm = [];
foreach ($terms as $t) $examTerm[(int)$termExam[$t]['id']] = (int)$t;

// score[pupil_id][subject_id][term] = score
$score = [];
$takenSubjectIds = []; // IMPORTANT: only show subjects actually taken in selected scope
foreach ($resRows as $r) {
    $pid = (int)$r['pupil_id'];
    $sid = (int)$r['subject_id'];
    $eid = (int)$r['exam_id'];
    $t   = $examTerm[$eid] ?? null;
    if ($t === null) continue;
    $score[$pid][$sid][$t] = (float)$r['score'];
    $takenSubjectIds[$sid] = true;
}

// If nothing is taken for this class/scope, show empty state
if (!$takenSubjectIds) {
    $page_actions = '';
    require __DIR__ . '/header.php';
    ?>
    <div class="alert alert-info">
      No results found for <strong><?= h($class_code) ?></strong> in <strong><?= h($academic_year) ?></strong>
      (<?= $term_mode === 'one' ? 'Term T'.h((string)$term_one) : 'All terms' ?>).
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// -------------------------------
// Subjects (ONLY taken subjects)
// -------------------------------
$subjectsAll = $pdo->query("SELECT id, code, name, max_points FROM subjects ORDER BY id")->fetchAll();
$subjects = [];
$subjectById = [];
$maxTotal = 0.0;

foreach ($subjectsAll as $s) {
    $sid = (int)$s['id'];
    if (!isset($takenSubjectIds[$sid])) continue; // only taken subjects
    $subjects[] = $s;
    $subjectById[$sid] = $s;
    $maxTotal += (float)$s['max_points'];
}

// Guard if takenSubjectIds refer to missing subjects (should not happen, but safe)
if (!$subjects) {
    $page_actions = '';
    require __DIR__ . '/header.php';
    ?>
    <div class="alert alert-warning">
      Results exist, but matching subjects could not be loaded. Please verify <code>subjects</code> table integrity.
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// -------------------------------
// Totals per pupil per term (only taken subjects)
// -------------------------------
$total = []; // total[pupil_id][term] = float
foreach ($pupilIds as $pid) {
    foreach ($terms as $t) {
        $sum = 0.0;
        foreach ($subjects as $s) {
            $sid = (int)$s['id'];
            if (isset($score[$pid][$sid][$t])) $sum += (float)$score[$pid][$sid][$t];
        }
        $total[$pid][$t] = $sum;
    }
}

// -------------------------------
// Class-level totals per term (avg/median)
// -------------------------------
$classAvg = [];
$classMedian = [];
foreach ($terms as $t) {
    $arr = [];
    foreach ($pupilIds as $pid) $arr[] = (float)($total[$pid][$t] ?? 0.0);
    sort($arr);
    $n = count($arr);
    $avg = $n ? array_sum($arr) / $n : 0.0;
    $med = 0.0;
    if ($n) {
        $mid = intdiv($n, 2);
        $med = ($n % 2 === 1) ? $arr[$mid] : (($arr[$mid - 1] + $arr[$mid]) / 2.0);
    }
    $classAvg[$t] = $avg;
    $classMedian[$t] = $med;
}

// -------------------------------
// Sorting (server-side)
// -------------------------------
$dirMul = ($dir === 'desc') ? -1 : 1;

usort($pupils, static function(array $a, array $b) use ($sort, $dirMul, $lastTerm, $terms, $total, $subjects, $term_mode): int {
    $aid = (int)$a['id'];
    $bid = (int)$b['id'];

    if ($sort === 'last_total') {
        $va = (float)($total[$aid][$lastTerm] ?? 0.0);
        $vb = (float)($total[$bid][$lastTerm] ?? 0.0);
        if ($va === $vb) return $dirMul * (($aid <=> $bid));
        return $dirMul * (($va < $vb) ? -1 : 1);
    }

    if ($sort === 'last_delta') {
        if ($term_mode !== 'all') {
            // in "one term" mode, delta sort is meaningless; fallback to last_total
            $va = (float)($total[$aid][$lastTerm] ?? 0.0);
            $vb = (float)($total[$bid][$lastTerm] ?? 0.0);
            if ($va === $vb) return $dirMul * (($aid <=> $bid));
            return $dirMul * (($va < $vb) ? -1 : 1);
        }

        $prev = null;
        foreach ($terms as $t) { if ($t < $lastTerm) $prev = $t; }
        $da = 0.0;
        $db = 0.0;
        if ($prev !== null) {
            $da = (float)($total[$aid][$lastTerm] ?? 0.0) - (float)($total[$aid][$prev] ?? 0.0);
            $db = (float)($total[$bid][$lastTerm] ?? 0.0) - (float)($total[$bid][$prev] ?? 0.0);
        }
        if ($da === $db) return $dirMul * (($aid <=> $bid));
        return $dirMul * (($da < $db) ? -1 : 1);
    }

    $sa = mb_strtolower(trim((string)$a['surname'] . ' ' . (string)$a['name'] . ' ' . (string)($a['middle_name'] ?? '')));
    $sb = mb_strtolower(trim((string)$b['surname'] . ' ' . (string)$b['name'] . ' ' . (string)($b['middle_name'] ?? '')));
    if ($sa === $sb) return $dirMul * (((int)$a['id']) <=> ((int)$b['id']));
    return $dirMul * (($sa < $sb) ? -1 : 1);
});

// -------------------------------
// Page actions (optional)
// -------------------------------
$qBase = [
    'class_code' => $class_code,
    'academic_year' => $academic_year,
    'term_mode' => $term_mode,
];
if ($term_mode === 'one') $qBase['term'] = (string)$term_one;

$page_actions = '
  <a class="btn btn-sm btn-outline-primary" href="pupils.php?class_code=' . rawurlencode($class_code) . '">
    <i class="bi bi-people me-1"></i> Pupils list
  </a>
';

require __DIR__ . '/header.php';

// -------------------------------
// UI derived
// -------------------------------
$termLabels = [];
foreach ($termExam as $t => $e) {
    $d = $e['exam_date'] ? date('d M Y', strtotime((string)$e['exam_date'])) : '—';
    $termLabels[(int)$t] = "T{$t} — " . (string)$e['exam_name'] . " ({$d})";
}

function sort_link(string $label, string $key, string $currentSort, string $currentDir, array $qBase): string
{
    $dir = 'asc';
    if ($currentSort === $key && $currentDir === 'asc') $dir = 'desc';
    $q = $qBase + ['sort' => $key, 'dir' => $dir];
    $href = '?' . http_build_query($q);
    $ic = '';
    if ($currentSort === $key) $ic = $currentDir === 'asc' ? ' <i class="bi bi-sort-up"></i>' : ' <i class="bi bi-sort-down"></i>';
    return '<a class="link-dark text-decoration-none" href="' . h($href) . '">' . h($label) . $ic . '</a>';
}

$termsHuman = ($term_mode === 'one')
    ? ('T' . $term_one)
    : implode(', ', array_map(static fn($t) => 'T' . $t, $terms));

$modeAllActive = $term_mode === 'all';
$modeOneActive = $term_mode === 'one';
?>
<style>
  .filters-card{ border:1px solid rgba(0,0,0,.06); }
  .filters-card .card-body{ padding: 1rem; }
  .pill{
    display:inline-flex; align-items:center; gap:.45rem;
    padding:.35rem .6rem; border-radius:999px;
    border:1px solid rgba(0,0,0,.08);
    background:#fff;
    font-size:.875rem;
  }
  .mono{ font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  .small-muted{ font-size: .85rem; color: rgba(0,0,0,.58); }
  .kpi{
    border:1px solid rgba(0,0,0,.08);
    border-radius: .9rem;
    background: linear-gradient(180deg, rgba(248,249,250,.9), rgba(255,255,255,.9));
    padding: .9rem;
  }

  .matrix-wrap{
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 1rem;
    overflow: auto;
    max-height: 76vh;
    background: #fff;
  }
  table.matrix{
    min-width: 1200px;
    margin: 0;
  }
  table.matrix thead th{
    position: sticky;
    top: 0;
    z-index: 5;
    background: #f8f9fa;
    border-bottom: 1px solid rgba(0,0,0,.14);
    vertical-align: bottom;
  }
  table.matrix thead tr:nth-child(2) th{
    top: 48px;
    z-index: 6;
    background: #fbfcfd;
  }
  table.matrix tbody tr:nth-child(odd){
    background: rgba(0,0,0,.012);
  }

  th.sticky-left, td.sticky-left{
    position: sticky;
    left: 0;
    z-index: 7;
    background: #fff;
    border-right: 1px solid rgba(0,0,0,.08);
  }
  th.sticky-left-2, td.sticky-left-2{
    position: sticky;
    left: 54px;
    z-index: 7;
    background: #fff;
    border-right: 1px solid rgba(0,0,0,.08);
  }
  th.sticky-left-3, td.sticky-left-3{
    position: sticky;
    left: 320px;
    z-index: 7;
    background: #fff;
    border-right: 1px solid rgba(0,0,0,.08);
  }

  .score-cell{
    min-width: 78px;
    text-align: center;
    white-space: nowrap;
    padding-top: .45rem;
    padding-bottom: .45rem;
  }
  .score-badge{
    min-width: 44px;
    display: inline-flex;
    justify-content: center;
  }
  .delta-line{
    margin-top: .15rem;
    line-height: 1.1;
  }
  .group-sep{
    border-left: 2px solid rgba(0,0,0,.08) !important;
  }
  .subject-head{
    white-space: nowrap;
  }
</style>

<div class="card shadow-sm filters-card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="">
      <div class="col-12 col-lg-3">
        <label class="form-label small text-muted">Class</label>
        <select name="class_code" class="form-select">
          <?php foreach ($classList as $c): ?>
            <?php $cc = (string)$c['class_code']; ?>
            <option value="<?= h($cc) ?>" <?= $cc === $class_code ? 'selected' : '' ?>><?= h($cc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label small text-muted">Academic year</label>
        <select name="academic_year" class="form-select">
          <?php foreach ($yearList as $y): ?>
            <?php $yy = (string)$y['academic_year']; ?>
            <option value="<?= h($yy) ?>" <?= $yy === $academic_year ? 'selected' : '' ?>><?= h($yy) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label small text-muted">Term view</label>
        <div class="d-flex gap-2">
          <input type="hidden" name="term_mode" value="<?= h($term_mode) ?>" id="termModeInput">
          <div class="btn-group w-100" role="group" aria-label="Term mode">
            <button type="button" class="btn <?= $modeAllActive ? 'btn-primary' : 'btn-outline-primary' ?>"
                    onclick="document.getElementById('termModeInput').value='all'; document.getElementById('termSelectWrap').classList.add('d-none');">
              <i class="bi bi-bar-chart-line me-1"></i> All (compare)
            </button>
            <button type="button" class="btn <?= $modeOneActive ? 'btn-primary' : 'btn-outline-primary' ?>"
                    onclick="document.getElementById('termModeInput').value='one'; document.getElementById('termSelectWrap').classList.remove('d-none');">
              <i class="bi bi-grid-1x2 me-1"></i> One term
            </button>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-3" id="termSelectWrap" style="<?= $modeOneActive ? '' : 'display:none' ?>">
        <label class="form-label small text-muted">Select term</label>
        <select name="term" class="form-select">
          <?php foreach ($allTerms as $t): ?>
            <option value="<?= h((string)$t) ?>" <?= ($t === $term_one) ? 'selected' : '' ?>>
              <?= h($termLabels[$t] ?? ('T'.$t)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label small text-muted">Sorting</label>
        <div class="input-group">
          <select name="sort" class="form-select">
            <option value="surname" <?= $sort==='surname'?'selected':'' ?>>Surname / name</option>
            <option value="last_total" <?= $sort==='last_total'?'selected':'' ?>><?= $term_mode === 'one' ? 'Term total' : 'Last term total' ?></option>
            <option value="last_delta" <?= $sort==='last_delta'?'selected':'' ?>>Δ total (prev→last)</option>
          </select>
          <select name="dir" class="form-select" style="max-width:110px">
            <option value="asc" <?= $dir==='asc'?'selected':'' ?>>ASC</option>
            <option value="desc" <?= $dir==='desc'?'selected':'' ?>>DESC</option>
          </select>
          <button class="btn btn-primary" type="submit">
            <i class="bi bi-funnel me-1"></i> Apply
          </button>
        </div>
        <div class="small-muted mt-1">
          Scope: <span class="pill"><i class="bi bi-mortarboard"></i><?= h($class_code) ?></span>
          <span class="pill"><i class="bi bi-calendar-event"></i><?= h($academic_year) ?></span>
          <span class="pill"><i class="bi bi-collection"></i><?= h($termsHuman) ?></span>
          <span class="pill"><i class="bi bi-journals"></i><?= h((string)count($subjects)) ?> taken subjects</span>
        </div>
      </div>

      <div class="col-12 col-lg-6 d-flex justify-content-lg-end align-items-end">
        <div class="small text-muted">
          Tip: use Ctrl+F to find a pupil quickly.
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  // keep term selector visibility consistent when switching buttons
  (function(){
    const modeInput = document.getElementById('termModeInput');
    const wrap = document.getElementById('termSelectWrap');
    function sync(){
      if(!modeInput || !wrap) return;
      if(modeInput.value === 'one'){
        wrap.style.display = '';
      } else {
        wrap.style.display = 'none';
      }
    }
    sync();
    // On submit, input already set by buttons; on back/forward navigation, resync
    window.addEventListener('pageshow', sync);
  })();
</script>

<div class="row g-3 mb-3">
  <div class="col-12 col-xl-7">
    <div class="kpi h-100">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold"><i class="bi bi-people me-2"></i>Class overview</div>
          <div class="small-muted">
            Pupils: <span class="mono"><?= h((string)count($pupils)) ?></span> ·
            Taken subjects: <span class="mono"><?= h((string)count($subjects)) ?></span> ·
            Max total (taken subjects): <span class="mono"><?= h(fmt1($maxTotal)) ?></span>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <span class="badge text-bg-danger">0-18.4</span>
          <span class="badge text-bg-warning text-dark">18.5-24.4</span>
          <span class="badge text-bg-primary text-white">24.5-34.4</span>
          <span class="badge text-bg-success">34.5–40</span>
          <?php if ($term_mode === 'all'): ?>
            <span class="badge text-bg-success"><i class="bi bi-arrow-up me-1"></i>Improved</span>
            <span class="badge text-bg-danger"><i class="bi bi-arrow-down me-1"></i>Dropped</span>
            <span class="badge text-bg-secondary"><i class="bi bi-dash me-1"></i>No change</span>
          <?php endif; ?>
        </div>
      </div>

      <hr class="my-3">

      <div class="row g-2">
        <?php foreach ($terms as $t): ?>
          <div class="col-12 col-md-6">
            <div class="p-3 border rounded-3 bg-white">
              <div class="small text-muted mb-1"><?= h($termLabels[$t] ?? ('T'.$t)) ?></div>
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="small-muted">Average total</div>
                  <div class="fw-semibold mono"><?= h(fmt1($classAvg[$t] ?? 0.0)) ?></div>
                </div>
                <div class="text-end">
                  <div class="small-muted">Median total</div>
                  <div class="fw-semibold mono"><?= h(fmt1($classMedian[$t] ?? 0.0)) ?></div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($term_mode === 'all' && $prevToLast !== null): ?>
        <?php
          $d = (float)($classAvg[$lastTerm] ?? 0.0) - (float)($classAvg[$prevToLast] ?? 0.0);
          $db = delta_badge($d);
        ?>
        <div class="mt-3">
          <div class="small-muted">Average change (T<?= h((string)$prevToLast) ?> → T<?= h((string)$lastTerm) ?>)</div>
          <span class="badge <?= h($db['cls']) ?> mono">
            <i class="bi <?= h($db['ic']) ?> me-1"></i><?= h($db['txt']) ?>
          </span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="kpi h-100">
      <div class="fw-semibold mb-2"><i class="bi bi-sliders me-2"></i>What you are viewing</div>
      <ul class="mb-0 small-muted">
        <li><strong>Only taken subjects</strong> are shown (based on results in this scope).</li>
        <li><strong>All (compare)</strong> shows deltas between consecutive terms per subject and totals.</li>
        <li><strong>One term</strong> shows a clean snapshot for the selected term (no deltas).</li>
      </ul>

      <hr class="my-3">

      <div class="d-flex flex-wrap gap-2">
        <span class="pill"><i class="bi bi-mortarboard"></i><?= h($class_code) ?></span>
        <span class="pill"><i class="bi bi-calendar-event"></i><?= h($academic_year) ?></span>
        <span class="pill"><i class="bi bi-collection"></i><?= h($termsHuman) ?></span>
        <span class="pill"><i class="bi bi-journals"></i><?= h((string)count($subjects)) ?> subjects</span>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <div class="fw-semibold">
        <i class="bi bi-table me-2"></i>
        <?= $term_mode === 'one' ? 'Snapshot' : 'Comparison matrix' ?>
      </div>
      <div class="small text-muted">
        Sticky header + sticky pupil columns enabled.
      </div>
    </div>

    <div class="matrix-wrap">
      <table class="table table-sm table-hover align-middle matrix">
        <thead>
          <tr>
            <th class="sticky-left text-center" rowspan="2" style="width:54px">#</th>
            <th class="sticky-left-2" rowspan="2" style="min-width:266px">
              <?= sort_link('Pupil', 'surname', $sort, $dir, $qBase) ?>
            </th>

            <th class="sticky-left-3 text-center" rowspan="2" style="width:140px">
              <?= sort_link($term_mode === 'one' ? 'Term total' : 'Last total', 'last_total', $sort, $dir, $qBase) ?>
              <div class="small-muted"><?= $term_mode === 'one' ? ('T'.h((string)$lastTerm)) : ('T'.h((string)$lastTerm)) ?></div>
              <?php if ($term_mode === 'all'): ?>
                <div class="small-muted"><?= sort_link('Δ (prev→last)', 'last_delta', $sort, $dir, $qBase) ?></div>
              <?php endif; ?>
            </th>

            <?php foreach ($subjects as $idx => $s): ?>
              <?php $colspan = count($terms); ?>
              <th class="text-center subject-head <?= $idx === 0 ? 'group-sep' : '' ?>" colspan="<?= h((string)$colspan) ?>">
                <?= h((string)$s['name']) ?>
                <div class="small-muted">max <?= h((string)$s['max_points']) ?></div>
              </th>
            <?php endforeach; ?>

            <th class="text-center group-sep" colspan="<?= h((string)count($terms)) ?>">
              Totals (taken subjects)
              <div class="small-muted">max <?= h(fmt1($maxTotal)) ?></div>
            </th>
          </tr>

          <tr>
            <?php foreach ($subjects as $idx => $s): ?>
              <?php foreach ($terms as $t): ?>
                <th class="text-center small <?= $idx === 0 ? 'group-sep' : '' ?>">
                  T<?= h((string)$t) ?>
                </th>
              <?php endforeach; ?>
            <?php endforeach; ?>

            <?php foreach ($terms as $t): ?>
              <th class="text-center small group-sep">T<?= h((string)$t) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>

        <tbody>
          <?php $rowNum = 0; ?>
          <?php foreach ($pupils as $p): ?>
            <?php
              $rowNum++;
              $pid = (int)$p['id'];

              $full = trim((string)$p['surname'].' '.(string)$p['name'].' '.(string)($p['middle_name'] ?? ''));
              $login = (string)($p['student_login'] ?? '');
              $trk = (string)($p['track'] ?? '');

              $lastTotal = (float)($total[$pid][$lastTerm] ?? 0.0);

              $dTotal = 0.0;
              $hasPrev = false;
              if ($term_mode === 'all' && $prevToLast !== null) {
                  $hasPrev = true;
                  $dTotal = $lastTotal - (float)($total[$pid][$prevToLast] ?? 0.0);
              }
              $dbTot = delta_badge($dTotal);
            ?>
            <tr>
              <td class="sticky-left text-center mono"><?= h((string)$rowNum) ?></td>

              <td class="sticky-left-2">
                <div class="d-flex align-items-start justify-content-between gap-2">
                  <div>
                    <div class="fw-semibold"><?= h($full) ?></div>
                    <div class="small-muted">
                      <span class="mono"><?= h($login) ?></span>
                      <?php if ($trk !== ''): ?>
                        <span class="ms-2 badge text-bg-secondary-subtle border text-secondary-emphasis"><?= h($trk) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="/results.php?student_login=<?= h_attr($login) ?>"
                       target="_blank" rel="noopener">
                      <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                  </div>
                </div>
              </td>

              <td class="sticky-left-3 text-center">
                <div class="badge text-bg-dark mono"><?= h(fmt1($lastTotal)) ?></div>
                <?php if ($term_mode === 'all'): ?>
                  <div class="delta-line">
                    <?php if ($hasPrev): ?>
                      <span class="badge <?= h($dbTot['cls']) ?> mono">
                        <i class="bi <?= h($dbTot['ic']) ?> me-1"></i><?= h($dbTot['txt']) ?>
                      </span>
                    <?php else: ?>
                      <span class="small-muted">—</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>

              <?php foreach ($subjects as $sIdx => $s): ?>
                <?php
                  $sid = (int)$s['id'];
                  $prevScore = null;
                ?>
                <?php foreach ($terms as $tIdx => $t): ?>
                  <?php
                    $v = $score[$pid][$sid][$t] ?? null;
                    $cell = '—';
                    $badgeCls = 'text-bg-light border text-dark';
                    $deltaHtml = '';

                    if ($v !== null) {
                        $sv = (float)$v;
                        $cell = fmt1($sv);
                        $badgeCls = badge_score_class($sv);

                        if ($term_mode === 'all') {
                            if ($prevScore !== null) {
                                $dd = $sv - (float)$prevScore;
                                $db = delta_badge($dd);
                                $deltaHtml = '<div class="small-muted mono delta-line"><i class="bi '.$db['ic'].'"></i> '.h($db['txt']).'</div>';
                            } else {
                                $deltaHtml = '<div class="small-muted mono delta-line">—</div>';
                            }
                        }

                        $prevScore = $sv;
                    } else {
                        if ($term_mode === 'all') {
                            $deltaHtml = '<div class="small-muted mono delta-line">—</div>';
                        }
                    }

                    $isGroupSep = ($sIdx === 0 && $tIdx === 0) ? 'group-sep' : '';
                  ?>
                  <td class="score-cell <?= $isGroupSep ?>">
                    <span class="badge <?= h($badgeCls) ?> mono score-badge"><?= h($cell) ?></span>
                    <?= $term_mode === 'all' ? $deltaHtml : '' ?>
                  </td>
                <?php endforeach; ?>
              <?php endforeach; ?>

              <?php
                $prevTot = null;
              ?>
              <?php foreach ($terms as $tIdx => $t): ?>
                <?php
                  $tv = (float)($total[$pid][$t] ?? 0.0);
                  $deltaHtml = '';

                  if ($term_mode === 'all') {
                      if ($prevTot !== null) {
                          $dd = $tv - (float)$prevTot;
                          $db = delta_badge($dd);
                          $deltaHtml = '<div class="small-muted mono delta-line"><i class="bi '.$db['ic'].'"></i> '.h($db['txt']).'</div>';
                      } else {
                          $deltaHtml = '<div class="small-muted mono delta-line">—</div>';
                      }
                  }
                  $prevTot = $tv;
                ?>
                <td class="score-cell group-sep">
                  <span class="badge text-bg-dark mono score-badge"><?= h(fmt1($tv)) ?></span>
                  <?= $term_mode === 'all' ? $deltaHtml : '' ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
