<?php
// teacher/results_min.php - Pupils with mean below threshold, by parallel/class, with CSV + Excel export (drop-in)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
$tguard_allowed_methods = ['GET', 'HEAD'];
$tguard_allowed_levels = [1, 2, 3];
$tguard_login_path = '/teachers/login.php';
$tguard_fallback_path = '/teachers/results_min.php';
$tguard_require_active = true;
require_once __DIR__ . '/_tguard.php';

/* ----------------------------- helpers ----------------------------- */

function eh(mixed $v): string { return h((string)($v ?? '')); }

function fnum(mixed $v, int $decimals = 2): string
{
    if ($v === null || $v === '') return '-';
    return number_format((float)$v, $decimals, '.', '');
}

function exam_label(array $e): string
{
    $label = trim((string)($e['exam_name'] ?? ''));
    $ay = trim((string)($e['academic_year'] ?? ''));
    if ($ay !== '') $label .= ($label !== '' ? ' - ' : '') . $ay;
    if (!empty($e['term'])) $label .= ' (Term ' . (int)$e['term'] . ')';
    if (!empty($e['exam_date'])) $label .= ' | ' . (string)$e['exam_date'];
    return $label !== '' ? $label : ('Exam #' . (int)($e['id'] ?? 0));
}

function mean_badge_class(float $mean, float $threshold): string
{
    $delta = $threshold - $mean;
    if ($delta >= 8.0) return 'text-bg-danger';
    if ($delta >= 4.0) return 'text-bg-warning text-dark';
    return 'text-bg-secondary';
}

function gi(string $key, ?int $min = null, ?int $max = null): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') return null;
    if (!preg_match('/^\d+$/', (string)$_GET[$key])) return null;
    $v = (int)$_GET[$key];
    if ($min !== null && $v < $min) return null;
    if ($max !== null && $v > $max) return null;
    return $v;
}
function gf(string $key, ?float $min = null, ?float $max = null): ?float {
    if (!isset($_GET[$key]) || $_GET[$key] === '') return null;
    $raw = str_replace(',', '.', trim((string)$_GET[$key]));
    if (!preg_match('/^\d+(\.\d+)?$/', $raw)) return null;
    $v = (float)$raw;
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
function url_with(array $overrides): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    return 'results_min.php' . ($q ? ('?' . http_build_query($q)) : '');
}

/**
 * PDO helper with duplicate-placeholder rewrite (prevents HY093 on MySQL native prepares)
 * (same approach as teacher/reports.php)
 */
function fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $norm = [];
    foreach ($params as $k => $v) $norm[ltrim((string)$k, ':')] = $v;
    $params = $norm;

    $seen = [];
    $bind = [];
    $sql2 = preg_replace_callback(
        '/\:([a-zA-Z_][a-zA-Z0-9_]*)/',
        function ($m) use (&$seen, &$bind, $params) {
            $name = $m[1];
            if (!array_key_exists($name, $params)) {
                throw new RuntimeException("Missing SQL parameter :{$name}");
            }
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            if ($seen[$name] === 1) {
                $bind[$name] = $params[$name];
                return ':' . $name;
            }
            $new = $name . '__' . $seen[$name];
            $bind[$new] = $params[$name];
            return ':' . $new;
        },
        $sql
    );

    $st = $pdo->prepare($sql2);
    $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_one(PDO $pdo, string $sql, array $params = []): ?array {
    $rows = fetch_all($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function export_csv(PDO $pdo, string $filename, array $headers, string $sql, array $params): void
{
    $rows = fetch_all($pdo, $sql, $params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $k) $line[] = isset($r[$k]) ? (string)$r[$k] : '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/**
 * "Excel export" without extra libs:
 * emits an HTML table with XLS content-type (opens in Excel reliably).
 */
function export_excel_html(PDO $pdo, string $filename, array $headers, string $sql, array $params): void
{
    $rows = fetch_all($pdo, $sql, $params);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    echo "\xEF\xBB\xBF";
    echo "<table border='1'>\n<tr>";
    foreach ($headers as $h) {
        echo "<th>" . htmlspecialchars($h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</th>";
    }
    echo "</tr>\n";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($headers as $k) {
            $val = isset($r[$k]) ? (string)$r[$k] : '';
            echo "<td>" . htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</td>";
        }
        echo "</tr>\n";
    }
    echo "</table>";
    exit;
}

/* ----------------------------- filters ----------------------------- */

$filters = [
    'academic_year' => gs('academic_year', 12),
    'term'          => gi('term', 1, 2),
    'exam_id'       => gi('exam_id', 1),
    'subject_id'    => gi('subject_id', 1),
    'track'         => gs('track', 40),
    'parallel'      => gi('parallel', 1, 12),  // grade number: 5,6,7...
    'class_code'    => gs('class_code', 30),   // exact: 5-A, 5-B, 5-V...
    'date_from'     => gdate('date_from'),
    'date_to'       => gdate('date_to'),
];

$threshold = gf('threshold', 0, 100) ?? 18.4;  // default CHSB fail boundary
$minN      = gi('min_n', 1, 999) ?? 1;
$perPage   = gi('per_page', 10, 200) ?? 50;
$page      = gi('page', 1) ?? 1;
$dateRangeAutoFixed = false;

if ($filters['date_from'] && $filters['date_to'] && $filters['date_from'] > $filters['date_to']) {
    [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
    $dateRangeAutoFixed = true;
}

/* ----------------------------- build WHERE ----------------------------- */

$whereByKey = [];
$paramsByKey = [];

if ($filters['academic_year']) { $whereByKey['academic_year'] = 'e.academic_year = :academic_year'; $paramsByKey['academic_year'] = $filters['academic_year']; }
if ($filters['term'])          { $whereByKey['term']          = 'e.term = :term'; $paramsByKey['term'] = (int)$filters['term']; }
if ($filters['exam_id'])       { $whereByKey['exam_id']       = 'e.id = :exam_id'; $paramsByKey['exam_id'] = (int)$filters['exam_id']; }
if ($filters['subject_id'])    { $whereByKey['subject_id']    = 's.id = :subject_id'; $paramsByKey['subject_id'] = (int)$filters['subject_id']; }
if ($filters['track'])         { $whereByKey['track']         = 'p.track = :track'; $paramsByKey['track'] = $filters['track']; }
if ($filters['date_from'])     { $whereByKey['date_from']     = 'e.exam_date >= :date_from'; $paramsByKey['date_from'] = $filters['date_from']; }
if ($filters['date_to'])       { $whereByKey['date_to']       = 'e.exam_date <= :date_to'; $paramsByKey['date_to'] = $filters['date_to']; }

if ($filters['class_code']) {
    $whereByKey['class_code'] = 'p.class_code = :class_code';
    $paramsByKey['class_code'] = $filters['class_code'];
} elseif ($filters['parallel']) {
    // Match leading grade number and ensure it doesn't match 5 vs 50:
    // ^5([^0-9]|$) covers: 5-A, 5A, "5", "5 " etc.
    $whereByKey['parallel'] = 'p.class_code REGEXP :parallel_re';
    $paramsByKey['parallel_re'] = '^' . (int)$filters['parallel'] . '([^0-9]|$)';
}

$whereSql = $whereByKey ? ('WHERE ' . implode(' AND ', array_values($whereByKey))) : '';
$params = $paramsByKey;

/* ----------------------------- export endpoints (BEFORE HTML) ----------------------------- */

$export = gs('export', 20); // csv|xls
if ($export === 'csv' || $export === 'xls') {
    $stamp = date('Ymd_His');
    $fileBase = 'pupils_below_threshold_' . $stamp;

    $sql = "
        SELECT
          p.class_code AS class_code,
          p.track AS track,
          p.student_login AS student_login,
          p.surname AS surname,
          p.name AS name,
          COUNT(*) AS n,
          ROUND(AVG(r.score), 2) AS mean,
          ROUND(MIN(r.score), 2) AS min,
          ROUND(MAX(r.score), 2) AS max
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY p.id, p.class_code, p.track, p.student_login, p.surname, p.name
        HAVING COUNT(*) >= :min_n AND AVG(r.score) < :threshold
        ORDER BY mean ASC, n DESC, p.class_code ASC, p.surname ASC, p.name ASC
    ";

    $headers = ['class_code','track','student_login','surname','name','n','mean','min','max'];
    $pp = array_merge($params, ['threshold' => $threshold, 'min_n' => $minN]);

    if ($export === 'csv') {
        export_csv($pdo, $fileBase . '.csv', $headers, $sql, $pp);
    }
    export_excel_html($pdo, $fileBase . '.xls', $headers, $sql, $pp);
}

/* ----------------------------- page HTML ----------------------------- */

$page_title = 'Low average pupils';
require __DIR__ . '/header.php';

// CSP nonce support for inline scripts
$cspNonce = '';
if (function_exists('csp_nonce')) $cspNonce = (string)csp_nonce();
elseif (!empty($_SESSION['csp_nonce'])) $cspNonce = (string)$_SESSION['csp_nonce'];

/* ----------------------------- filter sources ----------------------------- */

$years = fetch_all($pdo, "SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC");
$subjects = fetch_all($pdo, "SELECT id, code, name FROM subjects ORDER BY name ASC");
$tracks = fetch_all($pdo, "SELECT DISTINCT track FROM pupils WHERE track IS NOT NULL AND track <> '' ORDER BY track ASC");
$classes = fetch_all($pdo, "SELECT DISTINCT class_code FROM pupils WHERE class_code IS NOT NULL AND class_code <> '' ORDER BY class_code ASC");

// Parallels (grades) derived from class_code leading digits (MySQL-version agnostic)
$parallelSet = [];
foreach ($classes as $c) {
    $cc = (string)($c['class_code'] ?? '');
    if ($cc !== '' && preg_match('/^\s*(\d{1,2})/', $cc, $m)) {
        $parallelSet[(int)$m[1]] = true;
    }
}
$parallelVals = array_keys($parallelSet);
sort($parallelVals, SORT_NUMERIC);
$parallels = array_map(static fn (int $p) => ['parallel' => $p], $parallelVals);

/* Exams filtered by year/term for convenience */
$examWhere = [];
$examParams = [];
if ($filters['academic_year']) { $examWhere[] = 'academic_year = :ay'; $examParams['ay'] = $filters['academic_year']; }
if ($filters['term'])          { $examWhere[] = 'term = :t'; $examParams['t'] = (int)$filters['term']; }
$examWhereSql = $examWhere ? ('WHERE ' . implode(' AND ', $examWhere)) : '';

$exams = fetch_all($pdo, "
    SELECT id, academic_year, term, exam_name, exam_date
    FROM exams
    $examWhereSql
    ORDER BY COALESCE(exam_date,'9999-12-31') DESC, id DESC
", $examParams);

/* ----------------------------- data query ----------------------------- */

$list = fetch_all($pdo, "
    SELECT
      p.id AS pupil_id,
      p.class_code,
      p.track,
      p.student_login,
      p.surname,
      p.name,
      COUNT(*) AS n,
      AVG(r.score) AS mean_score,
      MIN(r.score) AS min_score,
      MAX(r.score) AS max_score
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $whereSql
    GROUP BY p.id, p.class_code, p.track, p.student_login, p.surname, p.name
    HAVING COUNT(*) >= :min_n AND AVG(r.score) < :threshold
    ORDER BY mean_score ASC, n DESC, p.class_code ASC, p.surname ASC, p.name ASC
", array_merge($params, ['threshold' => $threshold, 'min_n' => $minN]));

$total = count($list);

$meanSum = 0.0;
$worstMean = null;
$bestMean = null;
$criticalCount = 0;
$warningCount = 0;

foreach ($list as $row) {
    $mean = (float)$row['mean_score'];
    $meanSum += $mean;
    $worstMean = ($worstMean === null || $mean < $worstMean) ? $mean : $worstMean;
    $bestMean = ($bestMean === null || $mean > $bestMean) ? $mean : $bestMean;
    $delta = $threshold - $mean;
    if ($delta >= 8.0) $criticalCount++;
    elseif ($delta >= 4.0) $warningCount++;
}

$avgMean = $total > 0 ? ($meanSum / $total) : null;

$pageCount = max(1, (int)ceil($total / $perPage));
if ($page > $pageCount) $page = $pageCount;
$offset = ($page - 1) * $perPage;
$pagedList = array_slice($list, $offset, $perPage);

$selectedExamLabel = null;
if ($filters['exam_id']) {
    foreach ($exams as $e) {
        if ((int)$e['id'] === (int)$filters['exam_id']) {
            $selectedExamLabel = exam_label($e);
            break;
        }
    }
    if ($selectedExamLabel === null) {
        $selectedExam = fetch_one(
            $pdo,
            "SELECT id, academic_year, term, exam_name, exam_date FROM exams WHERE id = :id",
            ['id' => (int)$filters['exam_id']]
        );
        if ($selectedExam) $selectedExamLabel = exam_label($selectedExam);
    }
}

$activeFilters = [];
if ($filters['academic_year']) $activeFilters[] = ['Academic year', (string)$filters['academic_year']];
if ($filters['term'])          $activeFilters[] = ['Term', (string)$filters['term']];
if ($selectedExamLabel)        $activeFilters[] = ['Exam', $selectedExamLabel];
if ($filters['subject_id']) {
    foreach ($subjects as $s) {
        if ((int)$s['id'] === (int)$filters['subject_id']) {
            $activeFilters[] = ['Subject', (string)$s['name']];
            break;
        }
    }
}
if ($filters['track'])      $activeFilters[] = ['Track', (string)$filters['track']];
if ($filters['parallel'])   $activeFilters[] = ['Parallel', (string)$filters['parallel']];
if ($filters['class_code']) $activeFilters[] = ['Class', (string)$filters['class_code']];
if ($filters['date_from'])  $activeFilters[] = ['From', (string)$filters['date_from']];
if ($filters['date_to'])    $activeFilters[] = ['To', (string)$filters['date_to']];
$activeFilters[] = ['Threshold', '< ' . fnum($threshold, 1)];
$activeFilters[] = ['Min N', '>= ' . (int)$minN];

/* ----------------------------- UI ----------------------------- */

?>
<style>
  .mono { font-variant-numeric: tabular-nums; }
  .results-min-hero {
    border: 1px solid rgba(13, 110, 253, .16);
    background: linear-gradient(180deg, rgba(13,110,253,.07) 0%, rgba(13,110,253,.02) 100%);
  }
  .kpi-mini{
    border: 1px solid rgba(0,0,0,.08);
    border-radius: .75rem;
    padding: .7rem .85rem;
    background: rgba(248,249,250,.7);
  }
  .kpi-mini .v{ font-size:1.1rem; font-weight:700; line-height:1.2; }
  .filter-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .24rem .55rem;
    border-radius: 999px;
    border: 1px solid rgba(0,0,0,.1);
    background: #fff;
    font-size: .78rem;
  }
  .filter-chip .k { color: #6c757d; }
  .mean-pill { min-width: 4.3rem; font-weight: 700; }
  .score-muted { color: #6c757d; }
  .table-row-soft-critical > td { background: rgba(220,53,69,.07) !important; }
</style>

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card shadow-sm results-min-hero">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <div class="fw-semibold"><i class="bi bi-exclamation-triangle me-2"></i>Pupils below average threshold</div>
            <div class="text-muted small">Filter by parallel (grade) or exact class and export the same filtered data.</div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="results_min.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= eh(url_with(['export' => 'csv', 'page' => null])) ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= eh(url_with(['export' => 'xls', 'page' => null])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
          </div>
        </div>

        <?php if ($dateRangeAutoFixed): ?>
          <div class="alert alert-warning py-2 px-3 mt-3 mb-0">
            <i class="bi bi-exclamation-circle me-1"></i>
            Date range was auto-corrected because "From" was later than "To".
          </div>
        <?php endif; ?>

        <form method="get" class="row g-2 mt-3">
          <input type="hidden" name="page" value="1">

          <div class="col-md-2">
            <label class="form-label">Academic Year</label>
            <select name="academic_year" class="form-select">
              <option value="">All</option>
              <?php foreach ($years as $y): $ay = (string)$y['academic_year']; ?>
                <option value="<?= eh($ay) ?>" <?= ($filters['academic_year'] === $ay) ? 'selected' : '' ?>><?= eh($ay) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-1">
            <label class="form-label">Term</label>
            <select name="term" class="form-select">
              <option value="">All</option>
              <option value="1" <?= ($filters['term'] === 1) ? 'selected' : '' ?>>1</option>
              <option value="2" <?= ($filters['term'] === 2) ? 'selected' : '' ?>>2</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Exam</label>
            <select name="exam_id" class="form-select">
              <option value="">All</option>
              <?php foreach ($exams as $e): ?>
                <?php $eid = (int)$e['id']; $label = exam_label($e); ?>
                <option value="<?= $eid ?>" <?= ($filters['exam_id'] === $eid) ? 'selected' : '' ?>><?= eh($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Subject</label>
            <select name="subject_id" class="form-select">
              <option value="">All</option>
              <?php foreach ($subjects as $s): $sid = (int)$s['id']; ?>
                <option value="<?= $sid ?>" <?= ($filters['subject_id'] === $sid) ? 'selected' : '' ?>>
                  <?= eh($s['name']) ?><?= $s['code'] ? ' (' . eh($s['code']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Track</label>
            <select name="track" class="form-select">
              <option value="">All</option>
              <?php foreach ($tracks as $t): $tv = (string)$t['track']; ?>
                <option value="<?= eh($tv) ?>" <?= ($filters['track'] === $tv) ? 'selected' : '' ?>><?= eh($tv) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Parallel (grade)</label>
            <select name="parallel" class="form-select" <?= $filters['class_code'] ? 'disabled' : '' ?>>
              <option value="">All</option>
              <?php foreach ($parallels as $p): $pv = (int)($p['parallel'] ?? 0); if ($pv <= 0) continue; ?>
                <option value="<?= $pv ?>" <?= ($filters['parallel'] === $pv) ? 'selected' : '' ?>><?= $pv ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($filters['class_code']): ?>
              <div class="form-text">Disabled because exact Class is selected.</div>
            <?php endif; ?>
          </div>

          <div class="col-md-2">
            <label class="form-label">Class (exact)</label>
            <select name="class_code" class="form-select">
              <option value="">All</option>
              <?php foreach ($classes as $c): $cc = (string)$c['class_code']; ?>
                <option value="<?= eh($cc) ?>" <?= ($filters['class_code'] === $cc) ? 'selected' : '' ?>><?= eh($cc) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">If set, it overrides Parallel.</div>
          </div>

          <div class="col-md-2">
            <label class="form-label">Date from</label>
            <input type="date" name="date_from" class="form-control" value="<?= eh($filters['date_from']) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Date to</label>
            <input type="date" name="date_to" class="form-control" value="<?= eh($filters['date_to']) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Threshold (mean &lt;)</label>
            <input type="number" step="0.1" min="0" max="100" name="threshold" class="form-control" value="<?= eh($threshold) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Min N</label>
            <input type="number" min="1" max="999" name="min_n" class="form-control" value="<?= eh($minN) ?>">
            <div class="form-text">Minimum score rows per pupil in the scope.</div>
          </div>

          <div class="col-md-2">
            <label class="form-label">Per page</label>
            <select name="per_page" class="form-select">
              <?php foreach ([25, 50, 100, 200] as $pp): ?>
                <option value="<?= $pp ?>" <?= ($perPage === $pp) ? 'selected' : '' ?>><?= $pp ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 d-flex flex-wrap gap-2 align-items-end mt-2">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Apply</button>
            <a class="btn btn-outline-secondary" href="<?= eh(url_with(['export' => 'csv', 'page' => null])) ?>"><i class="bi bi-download me-1"></i>CSV</a>
            <a class="btn btn-outline-secondary" href="<?= eh(url_with(['export' => 'xls', 'page' => null])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($activeFilters): ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($activeFilters as [$k, $v]): ?>
      <span class="filter-chip"><span class="k"><?= eh($k) ?>:</span><span><?= eh($v) ?></span></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="kpi-mini">
      <div class="small text-muted">Found pupils</div>
      <div class="v mono"><?= (int)$total ?></div>
      <div class="small text-muted">Mean &lt; <?= eh(fnum($threshold, 1)) ?> | Min N &gt;= <?= (int)$minN ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="kpi-mini">
      <div class="small text-muted">Average mean (result set)</div>
      <div class="v mono"><?= $avgMean === null ? '-' : eh(fnum($avgMean)) ?></div>
      <div class="small text-muted">Best: <?= $bestMean === null ? '-' : eh(fnum($bestMean)) ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="kpi-mini">
      <div class="small text-muted">Lowest mean</div>
      <div class="v mono"><?= $worstMean === null ? '-' : eh(fnum($worstMean)) ?></div>
      <div class="small text-muted">Largest deficit vs threshold</div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="kpi-mini">
      <div class="small text-muted">Severity split</div>
      <div class="v">
        <span class="badge text-bg-danger me-1"><?= (int)$criticalCount ?></span>
        <span class="badge text-bg-warning text-dark"><?= (int)$warningCount ?></span>
      </div>
      <div class="small text-muted">Danger (&gt;=8) and warning (&gt;=4)</div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if (!$pagedList): ?>
      <div class="alert alert-info mb-0">
        <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i>No pupils match the criteria.</div>
        <div class="small text-muted mt-1">Relax filters, reduce Min N, or increase the threshold.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:44px">#</th>
              <th>Pupil</th>
              <th>Class</th>
              <th>Track</th>
              <th class="text-end">N</th>
              <th class="text-end">Mean</th>
              <th class="text-end">Min</th>
              <th class="text-end">Max</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pagedList as $i => $r): ?>
              <?php
                $mean = (float)$r['mean_score'];
                $minv = (float)$r['min_score'];
                $maxv = (float)$r['max_score'];
                $delta = $threshold - $mean;
                $rowClass = $delta >= 8.0 ? 'table-row-soft-critical' : '';
              ?>
              <tr class="<?= $rowClass ?>">
                <td class="text-center"><?= (int)($offset + $i + 1) ?></td>
                <td>
                  <div class="fw-semibold">
                    <i class="bi bi-person me-1 text-primary"></i>
                    <?= eh($r['surname']) ?> <?= eh($r['name']) ?>
                  </div>
                  <div class="small text-muted"><code><?= eh($r['student_login']) ?></code></div>
                </td>
                <td><?= eh($r['class_code']) ?></td>
                <td><?= eh($r['track'] ?: '-') ?></td>
                <td class="text-end mono"><?= (int)$r['n'] ?></td>
                <td class="text-end mono">
                  <span class="badge mean-pill <?= eh(mean_badge_class($mean, $threshold)) ?>"><?= eh(fnum($mean)) ?></span>
                </td>
                <td class="text-end mono score-muted"><?= eh(fnum($minv)) ?></td>
                <td class="text-end mono score-muted"><?= eh(fnum($maxv)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total > $perPage): ?>
        <?php
          $startItem = $offset + 1;
          $endItem = min($offset + $perPage, $total);
          $windowStart = max(1, $page - 2);
          $windowEnd = min($pageCount, $page + 2);
        ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
          <div class="small text-muted">Showing <?= (int)$startItem ?>-<?= (int)$endItem ?> of <?= (int)$total ?> pupils</div>
          <nav aria-label="results-min-pagination">
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= eh(url_with(['page' => $page - 1])) ?>">Prev</a>
              </li>
              <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                  <a class="page-link" href="<?= eh(url_with(['page' => $p])) ?>"><?= $p ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $pageCount ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= eh(url_with(['page' => $page + 1])) ?>">Next</a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>

      <div class="small text-muted mt-2">
        Export uses the same filters you applied (Year/Term/Exam/Subject/Parallel/Class/Track/Date range/Threshold/Min N).
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>

