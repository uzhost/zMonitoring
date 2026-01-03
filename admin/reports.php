<?php
// admin/reports.php — Full analysis reports (drop-in; DECIMAL-safe; fixes HY093; CSV export; zmonitoring_db.sql aligned)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_role('admin');

/* ----------------------------- helpers ----------------------------- */

function eh($v): string { return h((string)($v ?? '')); }

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
    return 'reports.php' . ($q ? ('?' . http_build_query($q)) : '');
}

/**
 * PDO helper with duplicate-placeholder rewrite (prevents HY093 on MySQL native prepares)
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

function badge_score_bucket(float $v, float $pass, float $good, float $excellent): string {
    if ($v < $pass) return 'text-bg-danger';
    if ($v < $good) return 'text-bg-warning text-dark';
    if ($v < $excellent) return 'text-bg-primary';
    return 'text-bg-success';
}
function pct(float $x, int $dec = 1): string { return number_format($x, $dec) . '%'; }

/* ----------------------------- filters ----------------------------- */

$filters = [
    'academic_year' => gs('academic_year', 12),
    'term'          => gi('term', 1, 2),
    'exam_id'       => gi('exam_id', 1),
    'subject_id'    => gi('subject_id', 1),
    'class_code'    => gs('class_code', 30),
    'track'         => gs('track', 40),
    'date_from'     => gdate('date_from'),
    'date_to'       => gdate('date_to'),
];

$passThreshold      = gf('pass', 0, 40) ?? 24.0;
$goodThreshold      = gf('good', 0, 40) ?? 30.0;
$excellentThreshold = gf('excellent', 0, 40) ?? 35.0;

$passThreshold = min($passThreshold, 40.0);
$goodThreshold = min(max($goodThreshold, $passThreshold), 40.0);
$excellentThreshold = min(max($excellentThreshold, $goodThreshold), 40.0);

/**
 * Slice WHERE
 */
$where = [];
$params = [];

if ($filters['academic_year']) { $where[] = 'e.academic_year = :academic_year'; $params['academic_year'] = $filters['academic_year']; }
if ($filters['term'])          { $where[] = 'e.term = :term'; $params['term'] = (int)$filters['term']; }
if ($filters['exam_id'])       { $where[] = 'e.id = :exam_id'; $params['exam_id'] = (int)$filters['exam_id']; }
if ($filters['subject_id'])    { $where[] = 's.id = :subject_id'; $params['subject_id'] = (int)$filters['subject_id']; }
if ($filters['class_code'])    { $where[] = 'p.class_code = :class_code'; $params['class_code'] = $filters['class_code']; }
if ($filters['track'])         { $where[] = 'p.track = :track'; $params['track'] = $filters['track']; }
if ($filters['date_from'])     { $where[] = 'e.exam_date >= :date_from'; $params['date_from'] = $filters['date_from']; }
if ($filters['date_to'])       { $where[] = 'e.exam_date <= :date_to'; $params['date_to'] = $filters['date_to']; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ----------------------------- CSV exports (BEFORE HTML) ----------------------------- */

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
        foreach ($headers as $k) {
            // header keys are also expected as aliases in SQL below
            $line[] = isset($r[$k]) ? (string)$r[$k] : '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$export = gs('export', 30); // subject|pupils|classes|raw
if ($export) {
    $stamp = date('Ymd_His');

    if ($export === 'subject') {
        export_csv(
            $pdo,
            "subject_summary_{$stamp}.csv",
            ['subject_code','subject_name','n','mean','median','stdev','pass_rate'],
            "
            WITH base AS (
              SELECT
                s.id AS subject_id,
                s.code AS subject_code,
                s.name AS subject_name,
                r.score AS score
              FROM results r
              JOIN pupils p   ON p.id = r.pupil_id
              JOIN subjects s ON s.id = r.subject_id
              JOIN exams e    ON e.id = r.exam_id
              $whereSql
            ),
            med AS (
              SELECT
                subject_id,
                AVG(score) AS median
              FROM (
                SELECT
                  subject_id, score,
                  ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY score) AS rn,
                  COUNT(*) OVER (PARTITION BY subject_id) AS cnt
                FROM base
              ) t
              WHERE rn IN (FLOOR((cnt + 1)/2), FLOOR((cnt + 2)/2))
              GROUP BY subject_id
            )
            SELECT
              b.subject_code AS subject_code,
              b.subject_name AS subject_name,
              COUNT(*) AS n,
              ROUND(AVG(b.score), 2) AS mean,
              ROUND(COALESCE(m.median, 0), 2) AS median,
              ROUND(STDDEV_POP(b.score), 2) AS stdev,
              ROUND(100 * AVG(b.score >= :pass), 1) AS pass_rate
            FROM base b
            LEFT JOIN med m ON m.subject_id = b.subject_id
            GROUP BY b.subject_id, b.subject_code, b.subject_name, m.median
            ORDER BY mean ASC, stdev DESC, n DESC
            ",
            $params + ['pass' => $passThreshold]
        );
    }

    if ($export === 'pupils') {
        export_csv(
            $pdo,
            "pupil_summary_{$stamp}.csv",
            ['student_login','surname','name','class_code','track','n','mean','min','max','pass_rate'],
            "
            SELECT
              p.student_login AS student_login,
              p.surname AS surname,
              p.name AS name,
              p.class_code AS class_code,
              p.track AS track,
              COUNT(*) AS n,
              ROUND(AVG(r.score), 2) AS mean,
              ROUND(MIN(r.score), 2) AS min,
              ROUND(MAX(r.score), 2) AS max,
              ROUND(100 * AVG(r.score >= :pass), 1) AS pass_rate
            FROM results r
            JOIN pupils p   ON p.id = r.pupil_id
            JOIN subjects s ON s.id = r.subject_id
            JOIN exams e    ON e.id = r.exam_id
            $whereSql
            GROUP BY p.id, p.student_login, p.surname, p.name, p.class_code, p.track
            ORDER BY mean DESC, n DESC
            ",
            $params + ['pass' => $passThreshold]
        );
    }

    if ($export === 'classes') {
        export_csv(
            $pdo,
            "class_summary_{$stamp}.csv",
            ['class_code','track','n','mean','stdev','pass_rate'],
            "
            SELECT
              p.class_code AS class_code,
              p.track AS track,
              COUNT(*) AS n,
              ROUND(AVG(r.score), 2) AS mean,
              ROUND(STDDEV_POP(r.score), 2) AS stdev,
              ROUND(100 * AVG(r.score >= :pass), 1) AS pass_rate
            FROM results r
            JOIN pupils p   ON p.id = r.pupil_id
            JOIN subjects s ON s.id = r.subject_id
            JOIN exams e    ON e.id = r.exam_id
            $whereSql
            GROUP BY p.class_code, p.track
            ORDER BY mean DESC, pass_rate DESC, n DESC
            ",
            $params + ['pass' => $passThreshold]
        );
    }

    if ($export === 'raw') {
        export_csv(
            $pdo,
            "raw_results_{$stamp}.csv",
            ['academic_year','term','exam_name','exam_date','class_code','track','student_login','surname','name','subject_code','subject_name','score'],
            "
            SELECT
              e.academic_year AS academic_year,
              e.term AS term,
              e.exam_name AS exam_name,
              e.exam_date AS exam_date,
              p.class_code AS class_code,
              p.track AS track,
              p.student_login AS student_login,
              p.surname AS surname,
              p.name AS name,
              s.code AS subject_code,
              s.name AS subject_name,
              r.score AS score
            FROM results r
            JOIN pupils p   ON p.id = r.pupil_id
            JOIN subjects s ON s.id = r.subject_id
            JOIN exams e    ON e.id = r.exam_id
            $whereSql
            ORDER BY COALESCE(e.exam_date,'9999-12-31') DESC, e.id DESC, p.class_code ASC, p.surname ASC, p.name ASC, s.name ASC
            ",
            $params
        );
    }
}

/* ----------------------------- page HTML ----------------------------- */

$page_title = 'Reports';
require __DIR__ . '/header.php';

/* ----------------------------- filter sources ----------------------------- */

$years    = fetch_all($pdo, "SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC");
$subjects = fetch_all($pdo, "SELECT id, code, name FROM subjects ORDER BY name ASC");
$classes  = fetch_all($pdo, "SELECT DISTINCT class_code FROM pupils WHERE class_code IS NOT NULL AND class_code <> '' ORDER BY class_code ASC");
$tracks   = fetch_all($pdo, "SELECT DISTINCT track FROM pupils WHERE track IS NOT NULL AND track <> '' ORDER BY track ASC");

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

/* ----------------------------- core KPIs ----------------------------- */

$kpi = fetch_one($pdo, "
    SELECT
      COUNT(*) AS n_results,
      COUNT(DISTINCT r.pupil_id) AS n_pupils,
      COUNT(DISTINCT r.subject_id) AS n_subjects,
      COUNT(DISTINCT r.exam_id) AS n_exams,
      AVG(r.score) AS avg_score,
      MIN(r.score) AS min_score,
      MAX(r.score) AS max_score
    FROM results r
    JOIN pupils p   ON p.id = r.pupil_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN exams e    ON e.id = r.exam_id
    $whereSql
", $params) ?? [];

$nResults  = (int)($kpi['n_results'] ?? 0);
$nPupils   = (int)($kpi['n_pupils'] ?? 0);
$nSubjects = (int)($kpi['n_subjects'] ?? 0);
$nExams    = (int)($kpi['n_exams'] ?? 0);

$median = null;
if ($nResults > 0) {
    $median = fetch_one($pdo, "
        WITH x AS (
          SELECT
            r.score AS score,
            ROW_NUMBER() OVER (ORDER BY r.score) AS rn,
            COUNT(*) OVER () AS cnt
          FROM results r
          JOIN pupils p   ON p.id = r.pupil_id
          JOIN subjects s ON s.id = r.subject_id
          JOIN exams e    ON e.id = r.exam_id
          $whereSql
        )
        SELECT AVG(score) AS median_score
        FROM x
        WHERE rn IN (FLOOR((cnt + 1)/2), FLOOR((cnt + 2)/2))
    ", $params);
}

$passRow = ['passed' => 0, 'total' => 0];
if ($nResults > 0) {
    $passRow = fetch_one($pdo, "
        SELECT
          SUM(r.score >= :pass) AS passed,
          COUNT(*) AS total
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
    ", $params + ['pass' => $passThreshold]) ?? $passRow;
}
$passRate = (!empty($passRow['total'])) ? ((float)$passRow['passed'] / (float)$passRow['total']) * 100.0 : 0.0;

/* ----------------------------- subject summary ----------------------------- */

$subjectSummary = [];
if ($nResults > 0) {
    $subjectSummary = fetch_all($pdo, "
        WITH base AS (
          SELECT
            s.id AS subject_id,
            s.code AS subject_code,
            s.name AS subject_name,
            r.score AS score
          FROM results r
          JOIN pupils p   ON p.id = r.pupil_id
          JOIN subjects s ON s.id = r.subject_id
          JOIN exams e    ON e.id = r.exam_id
          $whereSql
        ),
        med AS (
          SELECT
            subject_id,
            AVG(score) AS median
          FROM (
            SELECT
              subject_id, score,
              ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY score) AS rn,
              COUNT(*) OVER (PARTITION BY subject_id) AS cnt
            FROM base
          ) t
          WHERE rn IN (FLOOR((cnt + 1)/2), FLOOR((cnt + 2)/2))
          GROUP BY subject_id
        )
        SELECT
          b.subject_id,
          b.subject_code,
          b.subject_name,
          COUNT(*) AS n,
          AVG(b.score) AS mean_score,
          COALESCE(m.median, NULL) AS median_score,
          STDDEV_POP(b.score) AS stdev_score,
          AVG(b.score >= :pass) * 100.0 AS pass_rate
        FROM base b
        LEFT JOIN med m ON m.subject_id = b.subject_id
        GROUP BY b.subject_id, b.subject_code, b.subject_name, m.median
        ORDER BY mean_score ASC, stdev_score DESC, n DESC
    ", $params + ['pass' => $passThreshold]);
}

/* ----------------------------- class comparison ----------------------------- */

$classSummary = [];
if ($nResults > 0) {
    $classSummary = fetch_all($pdo, "
        SELECT
          p.class_code,
          p.track,
          COUNT(*) AS n,
          AVG(r.score) AS mean_score,
          STDDEV_POP(r.score) AS stdev_score,
          AVG(r.score >= :pass) * 100.0 AS pass_rate
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY p.class_code, p.track
        ORDER BY mean_score DESC, pass_rate DESC, n DESC
    ", $params + ['pass' => $passThreshold]);
}

/* ----------------------------- top/bottom pupils ----------------------------- */

$topPupils = $bottomPupils = [];
if ($nResults > 0) {
    $topPupils = fetch_all($pdo, "
        SELECT
          p.id,
          p.student_login,
          p.surname,
          p.name,
          p.class_code,
          p.track,
          COUNT(*) AS n,
          AVG(r.score) AS mean_score,
          MIN(r.score) AS min_score,
          MAX(r.score) AS max_score
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY p.id, p.student_login, p.surname, p.name, p.class_code, p.track
        ORDER BY mean_score DESC, n DESC
        LIMIT 10
    ", $params);

    $bottomPupils = fetch_all($pdo, "
        SELECT
          p.id,
          p.student_login,
          p.surname,
          p.name,
          p.class_code,
          p.track,
          COUNT(*) AS n,
          AVG(r.score) AS mean_score,
          MIN(r.score) AS min_score,
          MAX(r.score) AS max_score
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY p.id, p.student_login, p.surname, p.name, p.class_code, p.track
        ORDER BY mean_score ASC, n DESC
        LIMIT 10
    ", $params);
}

/* ----------------------------- at-risk (simple heuristics) ----------------------------- */
/**
 * Heuristic:
 * - Enough data: >= 6 results in slice
 * - AND either mean < passThreshold OR min_score == 0 OR pass_rate < 50
 */
$atRisk = [];
if ($nResults > 0) {
    $atRisk = fetch_all($pdo, "
        SELECT
          p.id,
          p.student_login,
          p.surname,
          p.name,
          p.class_code,
          p.track,
          COUNT(*) AS n,
          AVG(r.score) AS mean_score,
          MIN(r.score) AS min_score,
          MAX(r.score) AS max_score,
          AVG(r.score >= :pass) * 100.0 AS pass_rate
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY p.id, p.student_login, p.surname, p.name, p.class_code, p.track
        HAVING COUNT(*) >= 6
           AND (AVG(r.score) < :pass OR MIN(r.score) <= 0 OR (AVG(r.score >= :pass) * 100.0) < 50)
        ORDER BY mean_score ASC, pass_rate ASC, n DESC
        LIMIT 20
    ", $params + ['pass' => $passThreshold]);
}

/* ----------------------------- trend by exam (slice trend) ----------------------------- */

$trend = [];
if ($nResults > 0) {
    $trend = fetch_all($pdo, "
        SELECT
          e.id AS exam_id,
          e.exam_name,
          e.exam_date,
          e.academic_year,
          e.term,
          COUNT(*) AS n,
          AVG(r.score) AS mean_score,
          STDDEV_POP(r.score) AS stdev_score,
          AVG(r.score >= :pass) * 100.0 AS pass_rate
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY e.id, e.exam_name, e.exam_date, e.academic_year, e.term
        HAVING COUNT(*) >= 10
        ORDER BY COALESCE(e.exam_date,'9999-12-31') ASC, e.id ASC
        LIMIT 18
    ", $params + ['pass' => $passThreshold]);
}

/* ----------------------------- UI ----------------------------- */

$chips = [];
foreach ([
    'academic_year' => 'Year',
    'term'          => 'Term',
    'exam_id'       => 'Exam ID',
    'subject_id'    => 'Subject ID',
    'class_code'    => 'Class',
    'track'         => 'Track',
    'date_from'     => 'From',
    'date_to'       => 'To',
] as $k => $label) {
    $v = $filters[$k] ?? null;
    if ($v !== null && $v !== '') $chips[] = ['k' => $k, 't' => $label . ': ' . (string)$v];
}

?>
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="d-flex align-items-center gap-2">
            <div class="fw-semibold"><i class="bi bi-clipboard-data me-2"></i>Reports</div>
            <?php if ($chips): ?>
              <span class="badge text-bg-light border"><i class="bi bi-funnel me-1"></i><?= count($chips) ?> filters</span>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="reports.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= eh(url_with(['export' => 'raw'])) ?>"><i class="bi bi-download me-1"></i>Export RAW</a>
          </div>
        </div>

        <?php if ($chips): ?>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <?php foreach ($chips as $c): ?>
              <a class="badge rounded-pill text-bg-secondary-subtle border text-secondary-emphasis text-decoration-none"
                 href="<?= eh(url_with([$c['k'] => null])) ?>"
                 title="Remove filter">
                <i class="bi bi-x-circle me-1"></i><?= eh($c['t']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="get" class="row g-2 mt-3">
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
                <?php
                  $eid = (int)$e['id'];
                  $label = trim((string)$e['exam_name']) . ' — ' . (string)$e['academic_year'];
                  if (!empty($e['term'])) $label .= ' (Term ' . (int)$e['term'] . ')';
                  if (!empty($e['exam_date'])) $label .= ' • ' . (string)$e['exam_date'];
                ?>
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

          <div class="col-md-1">
            <label class="form-label">Class</label>
            <select name="class_code" class="form-select">
              <option value="">All</option>
              <?php foreach ($classes as $c): $cc = (string)$c['class_code']; ?>
                <option value="<?= eh($cc) ?>" <?= ($filters['class_code'] === $cc) ? 'selected' : '' ?>><?= eh($cc) ?></option>
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
            <label class="form-label">Date from</label>
            <input type="date" name="date_from" class="form-control" value="<?= eh($filters['date_from']) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Date to</label>
            <input type="date" name="date_to" class="form-control" value="<?= eh($filters['date_to']) ?>">
          </div>

          <div class="col-12 mt-2">
            <a class="text-decoration-none" data-bs-toggle="collapse" href="#advancedThresholds" role="button" aria-expanded="false" aria-controls="advancedThresholds">
              <i class="bi bi-sliders me-1"></i>Thresholds (DECIMAL)
            </a>
          </div>
          <div class="collapse" id="advancedThresholds">
            <div class="row g-2 mt-1">
              <div class="col-md-2">
                <label class="form-label">Pass</label>
                <input type="number" step="0.1" name="pass" min="0" max="40" class="form-control" value="<?= eh($passThreshold) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Good</label>
                <input type="number" step="0.1" name="good" min="0" max="40" class="form-control" value="<?= eh($goodThreshold) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Excellent</label>
                <input type="number" step="0.1" name="excellent" min="0" max="40" class="form-control" value="<?= eh($excellentThreshold) ?>">
              </div>
            </div>
          </div>

          <div class="col-12 d-flex flex-wrap gap-2 align-items-end mt-3">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Apply</button>
            <a class="btn btn-outline-secondary" href="<?= eh(url_with(['export' => 'subject'])) ?>"><i class="bi bi-download me-1"></i>Export Subject Summary</a>
            <a class="btn btn-outline-secondary" href="<?= eh(url_with(['export' => 'classes'])) ?>"><i class="bi bi-download me-1"></i>Export Class Summary</a>
            <a class="btn btn-outline-secondary" href="<?= eh(url_with(['export' => 'pupils'])) ?>"><i class="bi bi-download me-1"></i>Export Pupil Summary</a>
          </div>
        </form>

        <?php if ($nResults === 0): ?>
          <div class="alert alert-info mt-3 mb-0">
            <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i>No data for this slice.</div>
            <div class="small">Relax filters, choose another exam, or import results.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Results</div>
      <div class="fs-4 fw-semibold"><?= $nResults ?></div>
      <div class="small text-muted"><?= $nPupils ?> pupils • <?= $nSubjects ?> subjects • <?= $nExams ?> exams</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Average</div>
      <div class="fs-4 fw-semibold"><?= ($kpi['avg_score'] ?? null) !== null ? number_format((float)$kpi['avg_score'], 2) : '—' ?></div>
      <div class="small text-muted">Min <?= eh($kpi['min_score'] ?? '—') ?> • Max <?= eh($kpi['max_score'] ?? '—') ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Median</div>
      <div class="fs-4 fw-semibold"><?= !empty($median['median_score']) ? number_format((float)$median['median_score'], 2) : '—' ?></div>
      <div class="small text-muted">Window-function based</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Pass rate (≥ <?= eh($passThreshold) ?>)</div>
      <div class="fs-4 fw-semibold"><?= pct($passRate, 1) ?></div>
      <div class="small text-muted"><?= (int)($passRow['passed'] ?? 0) ?> / <?= (int)($passRow['total'] ?? 0) ?></div>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="fw-semibold"><i class="bi bi-journal-text me-2"></i>Subject summary</div>
          <div class="small text-muted">
            Lower mean often indicates higher difficulty; higher StdDev indicates more dispersion in scores.
          </div>
        </div>

        <?php if (!$subjectSummary): ?>
          <div class="text-muted">No subject-level summary available for this slice.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Subject</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Median</th>
                  <th class="text-end">StdDev</th>
                  <th class="text-end">Pass%</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($subjectSummary as $r): ?>
                  <?php
                    $mean = (float)$r['mean_score'];
                    $medianS = $r['median_score'] !== null ? (float)$r['median_score'] : null;
                    $stdev = (float)$r['stdev_score'];
                    $pr = (float)$r['pass_rate'];
                    $badge = badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold);
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= eh($r['subject_name']) ?></div>
                      <div class="small text-muted"><?= eh($r['subject_code']) ?></div>
                    </td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end"><span class="badge <?= eh($badge) ?>"><?= number_format($mean, 2) ?></span></td>
                    <td class="text-end"><?= $medianS !== null ? number_format($medianS, 2) : '—' ?></td>
                    <td class="text-end"><?= number_format($stdev, 2) ?></td>
                    <td class="text-end"><?= pct($pr, 1) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-people me-2"></i>Class / Track comparison</div>
        <?php if (!$classSummary): ?>
          <div class="text-muted">No class-level summary available.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Class</th>
                  <th>Track</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Pass%</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($classSummary as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= eh($r['class_code']) ?></td>
                    <td><?= eh($r['track']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end"><?= number_format((float)$r['mean_score'], 2) ?></td>
                    <td class="text-end"><?= pct((float)$r['pass_rate'], 1) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Compare within the same exam/subject for the cleanest signal.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-trophy me-2"></i>Top pupils (by mean)</div>
        <?php if (!$topPupils): ?>
          <div class="text-muted">No pupil ranking available.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Pupil</th>
                  <th>Class</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Min</th>
                  <th class="text-end">Max</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topPupils as $r): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= eh($r['surname']) ?> <?= eh($r['name']) ?></div>
                      <div class="small text-muted"><code><?= eh($r['student_login']) ?></code> • <?= eh($r['track']) ?></div>
                    </td>
                    <td><?= eh($r['class_code']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$r['mean_score'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['min_score'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['max_score'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-2"></i>At-risk pupils (heuristic)</div>
        <div class="small text-muted mb-2">
          Criteria: at least 6 results in slice AND (mean &lt; pass OR any 0 OR pass-rate &lt; 50%).
        </div>
        <?php if (!$atRisk): ?>
          <div class="text-muted">No at-risk pupils detected under the current heuristic for this slice.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Pupil</th>
                  <th>Class</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Pass%</th>
                  <th class="text-end">Min</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($atRisk as $r): ?>
                  <?php $mean = (float)$r['mean_score']; ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= eh($r['surname']) ?> <?= eh($r['name']) ?></div>
                      <div class="small text-muted"><code><?= eh($r['student_login']) ?></code> • <?= eh($r['track']) ?></div>
                    </td>
                    <td><?= eh($r['class_code']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end"><span class="badge <?= eh(badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold)) ?>"><?= number_format($mean, 2) ?></span></td>
                    <td class="text-end"><?= pct((float)$r['pass_rate'], 1) ?></td>
                    <td class="text-end"><?= number_format((float)$r['min_score'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">
            Review zeros carefully (absent vs actual 0) to avoid misclassification.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-activity me-2"></i>Trend by exam (slice)</div>
        <?php if (!$trend): ?>
          <div class="text-muted">Not enough per-exam rows for a stable trend (need at least 10 results per exam).</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Exam</th>
                  <th>Year</th>
                  <th>Term</th>
                  <th>Date</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">StdDev</th>
                  <th class="text-end">Pass%</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($trend as $t): ?>
                  <tr>
                    <td class="fw-semibold"><?= eh($t['exam_name']) ?></td>
                    <td><?= eh($t['academic_year']) ?></td>
                    <td><?= eh($t['term']) ?></td>
                    <td><?= eh($t['exam_date']) ?></td>
                    <td class="text-end"><?= (int)$t['n'] ?></td>
                    <td class="text-end"><?= number_format((float)$t['mean_score'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$t['stdev_score'], 2) ?></td>
                    <td class="text-end"><?= pct((float)$t['pass_rate'], 1) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">
            For best trend interpretation, fix Subject and Class (otherwise this mixes multiple skill domains).
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
