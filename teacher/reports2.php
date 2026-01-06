<?php
// teacher/reports.php — Full analysis reports (drop-in; DECIMAL-safe; fixes HY093; CSV export; at-risk + pupil modal + subject trend modal)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_guard.php';

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
function delta_badge(float $delta): array {
    if ($delta > 0.00001) return ['cls' => 'text-bg-success', 'ic' => 'bi-arrow-up-right', 'sign' => '+'];
    if ($delta < -0.00001) return ['cls' => 'text-bg-danger', 'ic' => 'bi-arrow-down-right', 'sign' => ''];
    return ['cls' => 'text-bg-secondary', 'ic' => 'bi-dash', 'sign' => ''];
}
function pct(float $x, int $dec = 1): string { return number_format($x, $dec) . '%'; }
function clamp01(float $x): float { return max(0.0, min(1.0, $x)); }

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

// Your current defaults (keep)
$passThreshold      = gf('pass', 0, 40) ?? 18.4;
$goodThreshold      = gf('good', 0, 40) ?? 24.4;
$excellentThreshold = gf('excellent', 0, 40) ?? 34.4;

$passThreshold = min($passThreshold, 40.0);
$goodThreshold = min(max($goodThreshold, $passThreshold), 40.0);
$excellentThreshold = min(max($excellentThreshold, $goodThreshold), 40.0);

// At-risk tuning
$defaultRiskMinN = ($filters['subject_id'] || $filters['exam_id']) ? 1 : 2;
$riskMinN = gi('risk_min_n', 1, 999) ?? $defaultRiskMinN;
$riskPassPct = gf('risk_pass_pct', 0, 100) ?? 50.0;

// When a class is selected, show smaller curated lists (max 24 pupils per class)
$listLimit = $filters['class_code'] ? 12 : 24;

/* ----------------------------- Slice WHERE (for summaries) ----------------------------- */

$where = [];
$params = [];

// Keep track of where clauses by key so we can create "no exam_id" scopes without fragile parsing.
$whereByKey = [];
$paramsByKey = [];

if ($filters['academic_year']) { $whereByKey['academic_year'] = 'e.academic_year = :academic_year'; $paramsByKey['academic_year'] = $filters['academic_year']; }
if ($filters['term'])          { $whereByKey['term']          = 'e.term = :term'; $paramsByKey['term'] = (int)$filters['term']; }
if ($filters['exam_id'])       { $whereByKey['exam_id']       = 'e.id = :exam_id'; $paramsByKey['exam_id'] = (int)$filters['exam_id']; }
if ($filters['subject_id'])    { $whereByKey['subject_id']    = 's.id = :subject_id'; $paramsByKey['subject_id'] = (int)$filters['subject_id']; }
if ($filters['class_code'])    { $whereByKey['class_code']    = 'p.class_code = :class_code'; $paramsByKey['class_code'] = $filters['class_code']; }
if ($filters['track'])         { $whereByKey['track']         = 'p.track = :track'; $paramsByKey['track'] = $filters['track']; }
if ($filters['date_from'])     { $whereByKey['date_from']     = 'e.exam_date >= :date_from'; $paramsByKey['date_from'] = $filters['date_from']; }
if ($filters['date_to'])       { $whereByKey['date_to']       = 'e.exam_date <= :date_to'; $paramsByKey['date_to'] = $filters['date_to']; }

$where = array_values($whereByKey);
$params = $paramsByKey;

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$whereNoExamByKey = $whereByKey;
$paramsNoExamByKey = $paramsByKey;
unset($whereNoExamByKey['exam_id'], $paramsNoExamByKey['exam_id']);
$whereNoExamSql = $whereNoExamByKey ? ('WHERE ' . implode(' AND ', array_values($whereNoExamByKey))) : '';
$paramsNoExam = $paramsNoExamByKey;

/* ----------------------------- AJAX endpoints (BEFORE HTML) ----------------------------- */

$ajax = gs('ajax', 40);

if ($ajax === 'pupil_scores') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    $pupilId = gi('pupil_id', 1);
    if (!$pupilId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing pupil_id']);
        exit;
    }

    $w = ['r.pupil_id = :pupil_id'];
    $pp = ['pupil_id' => $pupilId];

    if ($filters['academic_year']) { $w[] = 'e.academic_year = :ay'; $pp['ay'] = $filters['academic_year']; }
    if ($filters['term'])          { $w[] = 'e.term = :t'; $pp['t'] = (int)$filters['term']; }
    if ($filters['exam_id'])       { $w[] = 'e.id = :eid'; $pp['eid'] = (int)$filters['exam_id']; }

    $wSql = 'WHERE ' . implode(' AND ', $w);

    $p = fetch_one($pdo, "
        SELECT id, student_login, surname, name, middle_name, class_code, track
        FROM pupils
        WHERE id = :id
        LIMIT 1
    ", ['id' => $pupilId]);

    if (!$p) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Pupil not found']);
        exit;
    }

    $rows = fetch_all($pdo, "
        SELECT
          e.id AS exam_id,
          e.academic_year,
          e.term,
          e.exam_name,
          e.exam_date,
          s.id AS subject_id,
          s.code AS subject_code,
          s.name AS subject_name,
          r.score
        FROM results r
        JOIN exams e    ON e.id = r.exam_id
        JOIN subjects s ON s.id = r.subject_id
        $wSql
        ORDER BY COALESCE(e.exam_date,'9999-12-31') DESC, e.id DESC, s.name ASC
    ", $pp);

    echo json_encode([
        'ok' => true,
        'pupil' => $p,
        'thresholds' => [
            'pass' => $passThreshold,
            'good' => $goodThreshold,
            'excellent' => $excellentThreshold,
        ],
        'scope' => [
            'academic_year' => $filters['academic_year'],
            'term' => $filters['term'],
            'exam_id' => $filters['exam_id'],
        ],
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'subject_trend') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    $subjectId = gi('subject_id', 1);
    if (!$subjectId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing subject_id']);
        exit;
    }

    // Respect current filters, but always constrain to requested subject_id.
    $w = ['s.id = :sid'];
    $pp = ['sid' => $subjectId];

    if ($filters['academic_year']) { $w[] = 'e.academic_year = :ay'; $pp['ay'] = $filters['academic_year']; }
    if ($filters['term'])          { $w[] = 'e.term = :t'; $pp['t'] = (int)$filters['term']; }
    if ($filters['class_code'])    { $w[] = 'p.class_code = :cc'; $pp['cc'] = $filters['class_code']; }
    if ($filters['track'])         { $w[] = 'p.track = :tr'; $pp['tr'] = $filters['track']; }
    if ($filters['date_from'])     { $w[] = 'e.exam_date >= :df'; $pp['df'] = $filters['date_from']; }
    if ($filters['date_to'])       { $w[] = 'e.exam_date <= :dt'; $pp['dt'] = $filters['date_to']; }

    // If user selected a single exam_id, show single-exam stats (still useful).
    if ($filters['exam_id']) { $w[] = 'e.id = :eid'; $pp['eid'] = (int)$filters['exam_id']; }

    $wSql = 'WHERE ' . implode(' AND ', $w);

    $meta = fetch_one($pdo, "SELECT id, code, name FROM subjects WHERE id = :id LIMIT 1", ['id' => $subjectId]);
    if (!$meta) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Subject not found']);
        exit;
    }

    $rows = fetch_all($pdo, "
        SELECT
          e.id AS exam_id,
          e.academic_year,
          e.term,
          e.exam_name,
          e.exam_date,
          COUNT(*) AS n,
          AVG(r.score) AS mean_score,
          STDDEV_POP(r.score) AS stdev_score,
          AVG(r.score >= :pass) * 100.0 AS pass_rate
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $wSql
        GROUP BY e.id, e.academic_year, e.term, e.exam_name, e.exam_date
        ORDER BY COALESCE(e.exam_date,'9999-12-31') ASC, e.id ASC
    ", array_merge($pp, ['pass' => $passThreshold]));

    echo json_encode([
        'ok' => true,
        'subject' => $meta,
        'thresholds' => [
            'pass' => $passThreshold,
            'good' => $goodThreshold,
            'excellent' => $excellentThreshold,
        ],
        'scope' => [
            'academic_year' => $filters['academic_year'],
            'term' => $filters['term'],
            'exam_id' => $filters['exam_id'],
            'class_code' => $filters['class_code'],
            'track' => $filters['track'],
        ],
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
        foreach ($headers as $k) $line[] = isset($r[$k]) ? (string)$r[$k] : '';
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
            array_merge($params, ['pass' => $passThreshold])
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
            array_merge($params, ['pass' => $passThreshold])
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
            array_merge($params, ['pass' => $passThreshold])
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

// CSP nonce support for inline scripts
$cspNonce = '';
if (function_exists('csp_nonce')) {
    $cspNonce = (string)csp_nonce();
} elseif (!empty($_SESSION['csp_nonce'])) {
    $cspNonce = (string)$_SESSION['csp_nonce'];
}

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
    ", array_merge($params, ['pass' => $passThreshold])) ?? $passRow;
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
    ", array_merge($params, ['pass' => $passThreshold]));
}

/* ----------------------------- subject delta between latest 2 exams (when exam_id not fixed) ----------------------------- */

$subjectDeltaMap = []; // subject_id => ['delta'=>float, 'pct'=>?float]
$subjectExamPair = null; // [latestId, prevId]
if ($nResults > 0 && !$filters['exam_id']) {
    // Find last two exams present in THIS slice (but ignoring exam_id, obviously).
    $pair = fetch_all($pdo, "
        SELECT
          e.id,
          e.exam_date,
          e.exam_name
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereNoExamSql
        GROUP BY e.id, e.exam_date, e.exam_name
        ORDER BY COALESCE(e.exam_date,'9999-12-31') DESC, e.id DESC
        LIMIT 2
    ", $paramsNoExam);

    if (count($pair) === 2) {
        $latestId = (int)$pair[0]['id'];
        $prevId   = (int)$pair[1]['id'];
        $subjectExamPair = [$latestId, $prevId];

        $m1 = fetch_all($pdo, "
            SELECT r.subject_id, AVG(r.score) AS mean_score
            FROM results r
            JOIN pupils p   ON p.id = r.pupil_id
            JOIN subjects s ON s.id = r.subject_id
            JOIN exams e    ON e.id = r.exam_id
            $whereNoExamSql
            AND e.id = :eid
            GROUP BY r.subject_id
        ", array_merge($paramsNoExam, ['eid' => $latestId]));

        $m2 = fetch_all($pdo, "
            SELECT r.subject_id, AVG(r.score) AS mean_score
            FROM results r
            JOIN pupils p   ON p.id = r.pupil_id
            JOIN subjects s ON s.id = r.subject_id
            JOIN exams e    ON e.id = r.exam_id
            $whereNoExamSql
            AND e.id = :eid
            GROUP BY r.subject_id
        ", array_merge($paramsNoExam, ['eid' => $prevId]));

        $a = [];
        foreach ($m1 as $r) $a[(int)$r['subject_id']] = (float)$r['mean_score'];
        $b = [];
        foreach ($m2 as $r) $b[(int)$r['subject_id']] = (float)$r['mean_score'];

        $allSids = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($allSids as $sid) {
            $x = $a[$sid] ?? null;
            $y = $b[$sid] ?? null;
            if ($x === null || $y === null) continue;
            $d = $x - $y;
            $p = (abs($y) > 0.00001) ? ($d / $y) * 100.0 : null;
            $subjectDeltaMap[$sid] = ['delta' => $d, 'pct' => $p];
        }
    }
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
    ", array_merge($params, ['pass' => $passThreshold]));
}

$classMaxMean = 0.0;
$classMaxPass = 0.0;
foreach ($classSummary as $r) {
    $classMaxMean = max($classMaxMean, (float)$r['mean_score']);
    $classMaxPass = max($classMaxPass, (float)$r['pass_rate']);
}
$classMaxMean = max(1.0, $classMaxMean);
$classMaxPass = max(1.0, $classMaxPass);

/* ----------------------------- at-risk FIRST (so we can exclude from top list) ----------------------------- */

$atRisk = [];
$excludeHaving = '';
$excludeParams = [];

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
          AVG(r.score >= :pass) * 100.0 AS pass_rate,
          CASE
            WHEN AVG(r.score) < :pass THEN 'Low mean'
            WHEN MIN(r.score) <= 0 THEN 'Zero score'
            WHEN (AVG(r.score >= :pass) * 100.0) < :risk_pass_pct THEN 'Low pass rate'
            ELSE '—'
          END AS risk_reason
        FROM results r
        JOIN pupils p   ON p.id = r.pupil_id
        JOIN subjects s ON s.id = r.subject_id
        JOIN exams e    ON e.id = r.exam_id
        $whereSql
        GROUP BY p.id, p.student_login, p.surname, p.name, p.class_code, p.track
        HAVING COUNT(*) >= :risk_min_n
           AND (
             AVG(r.score) < :pass
             OR MIN(r.score) <= 0
             OR (AVG(r.score >= :pass) * 100.0) < :risk_pass_pct
           )
        ORDER BY mean_score ASC, pass_rate ASC, n DESC
        LIMIT {$listLimit}
    ", array_merge($params, [
        'pass' => $passThreshold,
        'risk_min_n' => $riskMinN,
        'risk_pass_pct' => $riskPassPct,
    ]));

    // Build exclusion placeholders for the "goodies" list (SQL-level)
    if ($atRisk) {
        $ids = [];
        foreach ($atRisk as $r) $ids[] = (int)$r['id'];
        $ids = array_values(array_unique($ids));
        if ($ids) {
            $ph = [];
            foreach ($ids as $i => $id) {
                $k = "ex{$i}";
                $ph[] = ":{$k}";
                $excludeParams[$k] = $id;
            }
            $excludeHaving = " AND p.id NOT IN (" . implode(',', $ph) . ")";
        }
    }
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
        HAVING AVG(r.score) >= :good
        {$excludeHaving}
        ORDER BY mean_score DESC, n DESC
        LIMIT {$listLimit}
    ", array_merge($params, $excludeParams, ['good' => $goodThreshold]));

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
        LIMIT {$listLimit}
    ", $params);
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
    ", array_merge($params, ['pass' => $passThreshold]));
}

/* ----------------------------- UI chips ----------------------------- */

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
<style>
  /* Scoped UI polish for this page */
  .report-pill{
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.35rem .6rem; border-radius:999px;
    border:1px solid rgba(0,0,0,.08);
    background: rgba(255,255,255,.85);
  }
  .row-click{
    cursor:pointer;
  }
  .row-click:hover{
    background: rgba(13,110,253,.06) !important;
  }
  .metric-badge{
    font-variant-numeric: tabular-nums;
  }
  .metric-stack{
    display:flex; gap:.35rem; justify-content:flex-end; align-items:center; flex-wrap:wrap;
  }
  .rank-badge{
    width:2.1rem; display:inline-flex; justify-content:center;
  }
  .heatbar{
    height: .5rem;
    border-radius: 999px;
    background: rgba(0,0,0,.06);
    overflow:hidden;
  }
  .heatbar > span{
    display:block; height:100%;
    background: currentColor;
    opacity:.85;
  }
  .subject-link{
    display:inline-flex; align-items:center; gap:.4rem;
    text-decoration:none;
  }
  .subject-link:hover .subject-name{
    text-decoration: underline;
  }
  .kpi-mini{
    border: 1px solid rgba(0,0,0,.08);
    border-radius: .75rem;
    padding: .6rem .75rem;
    background: rgba(248,249,250,.7);
  }
  .kpi-mini .v{
    font-size:1.15rem; font-weight:700;
  }
  .mono{
    font-variant-numeric: tabular-nums;
  }
</style>

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
                 href="<?= eh(url_with([$c['k'] => null])) ?>" title="Remove filter">
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
              <i class="bi bi-sliders me-1"></i>Thresholds & Risk
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
              <div class="col-md-2">
                <label class="form-label">Risk Min N</label>
                <input type="number" name="risk_min_n" min="1" max="999" class="form-control" value="<?= eh($riskMinN) ?>">
                <div class="form-text">Default: <?= (int)$defaultRiskMinN ?> for current filters.</div>
              </div>
              <div class="col-md-2">
                <label class="form-label">Risk Pass%</label>
                <input type="number" step="0.1" name="risk_pass_pct" min="0" max="100" class="form-control" value="<?= eh($riskPassPct) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">List limit</label>
                <input type="number" class="form-control" value="<?= (int)$listLimit ?>" disabled>
                <div class="form-text">Class selected ⇒ 12, otherwise 24.</div>
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

<!-- KPI cards -->
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
      <?php $avgv = ($kpi['avg_score'] ?? null) !== null ? (float)$kpi['avg_score'] : null; ?>
      <div class="fs-4 fw-semibold">
        <?php if ($avgv === null): ?>—<?php else: ?>
          <span class="badge <?= eh(badge_score_bucket($avgv, $passThreshold, $goodThreshold, $excellentThreshold)) ?> metric-badge">
            <?= number_format($avgv, 2) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="small text-muted">Min <?= eh($kpi['min_score'] ?? '—') ?> • Max <?= eh($kpi['max_score'] ?? '—') ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Median</div>
      <?php $medv = !empty($median['median_score']) ? (float)$median['median_score'] : null; ?>
      <div class="fs-4 fw-semibold">
        <?php if ($medv === null): ?>—<?php else: ?>
          <span class="badge <?= eh(badge_score_bucket($medv, $passThreshold, $goodThreshold, $excellentThreshold)) ?> metric-badge">
            <?= number_format($medv, 2) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="small text-muted">Window-function based</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Pass rate (≥ <?= eh($passThreshold) ?>)</div>
      <div class="fs-4 fw-semibold">
        <span class="badge <?= eh(($passRate >= 75) ? 'text-bg-success' : (($passRate >= 50) ? 'text-bg-primary' : (($passRate >= 25) ? 'text-bg-warning text-dark' : 'text-bg-danger'))) ?> metric-badge">
          <?= pct($passRate, 1) ?>
        </span>
      </div>
      <div class="small text-muted"><?= (int)($passRow['passed'] ?? 0) ?> / <?= (int)($passRow['total'] ?? 0) ?></div>
    </div></div>
  </div>
</div>

<!-- Subject + Class summaries -->
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="fw-semibold"><i class="bi bi-journal-text me-2"></i>Subject summary</div>
          <div class="small text-muted">
            Click a subject to see exam-to-exam changes (points & %).
            <?php if ($filters['exam_id']): ?>
              <span class="ms-2 badge text-bg-light border"><i class="bi bi-pin-angle me-1"></i>Single exam selected</span>
            <?php elseif ($subjectExamPair): ?>
              <span class="ms-2 badge text-bg-light border"><i class="bi bi-repeat me-1"></i>Δ vs previous exam</span>
            <?php endif; ?>
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
                  <th class="text-end">Δ Mean</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($subjectSummary as $r): ?>
                  <?php
                    $sid = (int)$r['subject_id'];
                    $mean = (float)$r['mean_score'];
                    $medianS = $r['median_score'] !== null ? (float)$r['median_score'] : null;
                    $stdev = (float)$r['stdev_score'];
                    $pr = (float)$r['pass_rate'];
                    $badge = badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold);

                    $d = $subjectDeltaMap[$sid]['delta'] ?? null;
                    $dp = $subjectDeltaMap[$sid]['pct'] ?? null;
                    $dMeta = ($d !== null) ? delta_badge((float)$d) : null;
                  ?>
                  <tr>
                    <td>
                      <a href="javascript:void(0)" class="subject-link subject-row"
                         data-subject-id="<?= $sid ?>"
                         data-subject-label="<?= eh($r['subject_name']) ?>">
                        <i class="bi bi-graph-up-arrow text-primary"></i>
                        <span class="fw-semibold subject-name"><?= eh($r['subject_name']) ?></span>
                      </a>
                      <div class="small text-muted"><?= eh($r['subject_code']) ?></div>
                    </td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">
                      <span class="badge <?= eh($badge) ?> metric-badge">
                        <?php if ($mean >= $excellentThreshold): ?><i class="bi bi-award me-1"></i><?php endif; ?>
                        <?= number_format($mean, 2) ?>
                      </span>
                    </td>
                    <td class="text-end"><?= $medianS !== null ? number_format($medianS, 2) : '—' ?></td>
                    <td class="text-end"><?= number_format($stdev, 2) ?></td>
                    <td class="text-end">
                      <span class="badge <?= eh(($pr >= 75) ? 'text-bg-success' : (($pr >= 50) ? 'text-bg-primary' : (($pr >= 25) ? 'text-bg-warning text-dark' : 'text-bg-danger'))) ?> metric-badge">
                        <?= pct($pr, 1) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <?php if ($filters['exam_id'] || !$subjectExamPair || $dMeta === null): ?>
                        <span class="text-muted">—</span>
                      <?php else: ?>
                        <span class="badge <?= eh($dMeta['cls']) ?> metric-badge" title="Change vs previous exam (mean)">
                          <i class="bi <?= eh($dMeta['ic']) ?> me-1"></i><?= eh($dMeta['sign']) ?><?= number_format((float)$d, 2) ?>
                        </span>
                        <div class="small text-muted mono">
                          <?= $dp === null ? '—' : (number_format((float)$dp, 1) . '%') ?>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">
            Tip: For clean comparisons, select <span class="fw-semibold">Class</span> (and optionally Track) then click a subject.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="fw-semibold"><i class="bi bi-people me-2"></i>Class / Track comparison</div>
          <span class="badge text-bg-light border">
            <i class="bi bi-bar-chart-line me-1"></i>Ranked + heat
          </span>
        </div>

        <?php if (!$classSummary): ?>
          <div class="text-muted">No class-level summary available.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:44px">#</th>
                  <th>Class</th>
                  <th>Track</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Pass%</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($classSummary as $i => $r): ?>
                  <?php
                    $rank = $i + 1;
                    $mean = (float)$r['mean_score'];
                    $passP = (float)$r['pass_rate'];

                    $meanBadge = badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold);
                    $meanW = (int)round(clamp01($mean / $classMaxMean) * 100);
                    $passW = (int)round(clamp01($passP / $classMaxPass) * 100);

                    $meanColor = ($mean >= $excellentThreshold) ? 'text-success' : (($mean >= $goodThreshold) ? 'text-primary' : (($mean >= $passThreshold) ? 'text-warning' : 'text-danger'));
                    $passColor = ($passP >= 75) ? 'text-success' : (($passP >= 50) ? 'text-primary' : (($passP >= 25) ? 'text-warning' : 'text-danger'));

                    $topRow = ($rank === 1) ? 'table-success' : '';
                  ?>
                  <tr class="<?= eh($topRow) ?>">
                    <td class="text-center">
                      <span class="badge text-bg-dark rank-badge"><?= $rank ?></span>
                    </td>
                    <td class="fw-semibold"><?= eh($r['class_code']) ?></td>
                    <td><?= eh($r['track']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">
                      <div class="metric-stack">
                        <span class="badge <?= eh($meanBadge) ?> metric-badge"><?= number_format($mean, 2) ?></span>
                      </div>
                      <div class="heatbar <?= eh($meanColor) ?>" title="Relative mean vs best group">
                        <span style="width: <?= $meanW ?>%"></span>
                      </div>
                    </td>
                    <td class="text-end">
                      <div class="metric-stack">
                        <span class="badge <?= eh(($passP >= 75) ? 'text-bg-success' : (($passP >= 50) ? 'text-bg-primary' : (($passP >= 25) ? 'text-bg-warning text-dark' : 'text-bg-danger'))) ?> metric-badge">
                          <?= pct($passP, 1) ?>
                        </span>
                      </div>
                      <div class="heatbar <?= eh($passColor) ?>" title="Relative pass rate vs best group">
                        <span style="width: <?= $passW ?>%"></span>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="small text-muted mt-2">
            For the cleanest signal, compare within the same exam + subject. If this mixes exams, use the filters above.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Goodies vs At-risk -->
<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
          <div class="fw-semibold">
            <i class="bi bi-trophy me-2"></i>High performers
            <span class="badge text-bg-light border">(mean ≥ <?= eh($goodThreshold) ?>)</span>
          </div>
          <span class="badge text-bg-light border">
            <i class="bi bi-palette me-1"></i>Color-coded scores
          </span>
        </div>
        <div class="small text-muted mb-2">
          Click a pupil row to open a clearer insights view (per-exam totals, deltas, then detailed rows).
        </div>

        <?php if (!$topPupils): ?>
          <div class="text-muted">No high performers found for this slice.</div>
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
                  <?php
                    $mean = (float)$r['mean_score'];
                    $minv = (float)$r['min_score'];
                    $maxv = (float)$r['max_score'];
                    $bMean = badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold);
                    $bMin  = badge_score_bucket($minv, $passThreshold, $goodThreshold, $excellentThreshold);
                    $bMax  = badge_score_bucket($maxv, $passThreshold, $goodThreshold, $excellentThreshold);
                    $rowTint = ($mean >= $excellentThreshold) ? 'table-success' : (($mean >= $goodThreshold) ? '' : '');
                  ?>
                  <tr class="pupil-row row-click <?= eh($rowTint) ?>" role="button"
                      data-pupil-id="<?= (int)$r['id'] ?>"
                      data-pupil-label="<?= eh($r['surname'] . ' ' . $r['name']) ?>">
                    <td>
                      <div class="fw-semibold">
                        <i class="bi bi-person-badge me-1 text-primary"></i>
                        <?= eh($r['surname']) ?> <?= eh($r['name']) ?>
                      </div>
                      <div class="small text-muted"><code><?= eh($r['student_login']) ?></code> • <?= eh($r['track']) ?></div>
                    </td>
                    <td><?= eh($r['class_code']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">
                      <span class="badge <?= eh($bMean) ?> metric-badge">
                        <?php if ($mean >= $excellentThreshold): ?><i class="bi bi-award me-1"></i><?php endif; ?>
                        <?= number_format($mean, 2) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <span class="badge <?= eh($bMin) ?> metric-badge"><?= number_format($minv, 2) ?></span>
                    </td>
                    <td class="text-end">
                      <span class="badge <?= eh($bMax) ?> metric-badge"><?= number_format($maxv, 2) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Click a pupil row to view insights and all scores.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
          <div class="fw-semibold"><i class="bi bi-exclamation-triangle me-2"></i>At-risk pupils (heuristic)</div>
          <span class="badge text-bg-light border">
            Min N: <?= (int)$riskMinN ?> • Risk Pass%: <?= eh($riskPassPct) ?>
          </span>
        </div>
        <div class="small text-muted mb-2">
          Criteria: N ≥ <?= (int)$riskMinN ?> AND (mean &lt; pass OR any 0 OR pass% &lt; <?= eh($riskPassPct) ?>).
        </div>

        <?php if (!$atRisk): ?>
          <div class="alert alert-secondary mb-0">
            <div class="fw-semibold">No at-risk pupils found for this slice.</div>
            <div class="small text-muted mt-1">
              If you selected one subject or one exam, set <span class="fw-semibold">Risk Min N</span> to 1.
            </div>
          </div>
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
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($atRisk as $r): ?>
                  <?php
                    $mean = (float)$r['mean_score'];
                    $minv = (float)$r['min_score'];
                    $bMean = badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold);
                    $bMin  = badge_score_bucket($minv, $passThreshold, $goodThreshold, $excellentThreshold);
                  ?>
                  <tr class="pupil-row row-click" role="button"
                      data-pupil-id="<?= (int)$r['id'] ?>"
                      data-pupil-label="<?= eh($r['surname'] . ' ' . $r['name']) ?>">
                    <td>
                      <div class="fw-semibold">
                        <i class="bi bi-person-exclamation me-1 text-danger"></i>
                        <?= eh($r['surname']) ?> <?= eh($r['name']) ?>
                      </div>
                      <div class="small text-muted"><code><?= eh($r['student_login']) ?></code> • <?= eh($r['track']) ?></div>
                    </td>
                    <td><?= eh($r['class_code']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">
                      <span class="badge <?= eh($bMean) ?> metric-badge"><?= number_format($mean, 2) ?></span>
                    </td>
                    <td class="text-end">
                      <?php $pr = (float)$r['pass_rate']; ?>
                      <span class="badge <?= eh(($pr >= 75) ? 'text-bg-success' : (($pr >= 50) ? 'text-bg-primary' : (($pr >= 25) ? 'text-bg-warning text-dark' : 'text-bg-danger'))) ?> metric-badge">
                        <?= pct($pr, 1) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <span class="badge <?= eh($bMin) ?> metric-badge"><?= number_format($minv, 2) ?></span>
                    </td>
                    <td><span class="badge text-bg-light border"><?= eh($r['risk_reason'] ?? '—') ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Click a pupil row to view the insight view (exam deltas + all subjects).</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Trend -->
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
                <?php
                  $prevMean = null;
                  foreach ($trend as $t):
                    $mean = (float)$t['mean_score'];
                    $d = ($prevMean === null) ? null : ($mean - (float)$prevMean);
                    $dMeta = ($d === null) ? null : delta_badge((float)$d);
                ?>
                  <tr>
                    <td class="fw-semibold"><?= eh($t['exam_name']) ?></td>
                    <td><?= eh($t['academic_year']) ?></td>
                    <td><?= eh($t['term']) ?></td>
                    <td><?= eh($t['exam_date']) ?></td>
                    <td class="text-end"><?= (int)$t['n'] ?></td>
                    <td class="text-end">
                      <span class="badge <?= eh(badge_score_bucket($mean, $passThreshold, $goodThreshold, $excellentThreshold)) ?> metric-badge">
                        <?= number_format($mean, 2) ?>
                      </span>
                      <?php if ($dMeta): ?>
                        <span class="badge <?= eh($dMeta['cls']) ?> ms-1 metric-badge" title="Change vs previous row">
                          <i class="bi <?= eh($dMeta['ic']) ?> me-1"></i><?= eh($dMeta['sign']) ?><?= number_format((float)$d, 2) ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end"><?= number_format((float)$t['stdev_score'], 2) ?></td>
                    <td class="text-end">
                      <?php $pr = (float)$t['pass_rate']; ?>
                      <span class="badge <?= eh(($pr >= 75) ? 'text-bg-success' : (($pr >= 50) ? 'text-bg-primary' : (($pr >= 25) ? 'text-bg-warning text-dark' : 'text-bg-danger'))) ?> metric-badge">
                        <?= pct($pr, 1) ?>
                      </span>
                    </td>
                  </tr>
                <?php
                    $prevMean = $mean;
                  endforeach;
                ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">For best interpretation, fix Subject and Class (otherwise this mixes domains).</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Pupil Scores Modal (Upgraded) -->
<div class="modal fade" id="pupilScoresModal" tabindex="-1" aria-labelledby="pupilScoresModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="pupilScoresModalLabel">Pupil insights</h5>
          <div class="small text-muted" id="pupilScoresModalSub">Loading...</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div id="pupilScoresLoading" class="d-flex align-items-center gap-2 text-muted">
          <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
          <div>Loading scores...</div>
        </div>

        <div id="pupilScoresError" class="alert alert-danger d-none mb-0"></div>

        <div id="pupilScoresContent" class="d-none">

          <!-- Mini KPIs -->
          <div class="row g-2 mb-3" id="pupilKpisRow">
            <div class="col-md-3"><div class="kpi-mini">
              <div class="small text-muted">Overall mean</div>
              <div class="v mono" id="pk_mean">—</div>
              <div class="small text-muted">All rows in scope</div>
            </div></div>
            <div class="col-md-3"><div class="kpi-mini">
              <div class="small text-muted">Best subject</div>
              <div class="v mono" id="pk_best">—</div>
              <div class="small text-muted" id="pk_best_sub">—</div>
            </div></div>
            <div class="col-md-3"><div class="kpi-mini">
              <div class="small text-muted">Needs support</div>
              <div class="v mono" id="pk_worst">—</div>
              <div class="small text-muted" id="pk_worst_sub">—</div>
            </div></div>
            <div class="col-md-3"><div class="kpi-mini">
              <div class="small text-muted">Last exam Δ</div>
              <div class="v mono" id="pk_last_delta">—</div>
              <div class="small text-muted">Total points vs previous</div>
            </div></div>
          </div>

          <!-- Per-exam summary -->
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="fw-semibold"><i class="bi bi-layers me-2"></i>Exam summary</div>
            <div class="small text-muted">Totals (sum of subjects) + Δ points and Δ% vs previous exam.</div>
          </div>
          <div class="table-responsive mb-3">
            <table class="table table-sm table-striped align-middle mb-0" id="pupilExamSummaryTable">
              <thead class="table-light">
                <tr>
                  <th>Exam</th>
                  <th>Year</th>
                  <th>Term</th>
                  <th>Date</th>
                  <th class="text-end">Subjects</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Δ pts</th>
                  <th class="text-end">Δ %</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>

          <!-- Detailed rows -->
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="fw-semibold"><i class="bi bi-list-check me-2"></i>Detailed scores</div>
            <div class="small text-muted">Grouped by exam (latest first). Color indicates thresholds.</div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0" id="pupilScoresTable">
              <thead class="table-light">
                <tr>
                  <th>Exam</th>
                  <th>Year</th>
                  <th>Term</th>
                  <th>Date</th>
                  <th>Subject</th>
                  <th class="text-end">Score</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>

          <div class="small text-muted mt-2">
            If Term filter is set, modal shows that term only; otherwise all terms in the selected scope.
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">
          <i class="bi bi-x-lg me-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Subject Trend Modal -->
<div class="modal fade" id="subjectTrendModal" tabindex="-1" aria-labelledby="subjectTrendModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="subjectTrendModalLabel">Subject trend</h5>
          <div class="small text-muted" id="subjectTrendModalSub">Loading...</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div id="subjectTrendLoading" class="d-flex align-items-center gap-2 text-muted">
          <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
          <div>Loading subject trend...</div>
        </div>

        <div id="subjectTrendError" class="alert alert-danger d-none mb-0"></div>

        <div id="subjectTrendContent" class="d-none">
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0" id="subjectTrendTable">
              <thead class="table-light">
                <tr>
                  <th>Exam</th>
                  <th>Year</th>
                  <th>Term</th>
                  <th>Date</th>
                  <th class="text-end">N</th>
                  <th class="text-end">Mean</th>
                  <th class="text-end">Pass%</th>
                  <th class="text-end">Δ pts</th>
                  <th class="text-end">Δ %</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">
            Δ compares each exam to the previous one in chronological order (within the current filters).
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">
          <i class="bi bi-x-lg me-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>

<script<?= $cspNonce ? ' nonce="' . eh($cspNonce) . '"' : '' ?>>
(() => {
  const TH = {
    pass: <?= json_encode($passThreshold) ?>,
    good: <?= json_encode($goodThreshold) ?>,
    excellent: <?= json_encode($excellentThreshold) ?>
  };

  function scoreBadgeClass(v){
    v = Number(v);
    if (Number.isNaN(v)) return 'text-bg-secondary';
    if (v < TH.pass) return 'text-bg-danger';
    if (v < TH.good) return 'text-bg-warning text-dark';
    if (v < TH.excellent) return 'text-bg-primary';
    return 'text-bg-success';
  }
  function passBadgeClass(p){
    p = Number(p);
    if (Number.isNaN(p)) return 'text-bg-secondary';
    if (p >= 75) return 'text-bg-success';
    if (p >= 50) return 'text-bg-primary';
    if (p >= 25) return 'text-bg-warning text-dark';
    return 'text-bg-danger';
  }
  function deltaMeta(d){
    d = Number(d);
    if (!Number.isFinite(d)) return {cls:'text-bg-secondary', ic:'bi-dash', sign:''};
    if (d > 0.00001) return {cls:'text-bg-success', ic:'bi-arrow-up-right', sign:'+'};
    if (d < -0.00001) return {cls:'text-bg-danger', ic:'bi-arrow-down-right', sign:''};
    return {cls:'text-bg-secondary', ic:'bi-dash', sign:''};
  }
  function fmt2(x){
    const n = Number(x);
    if (!Number.isFinite(n)) return '—';
    return n.toFixed(2);
  }
  function fmt1p(x){
    const n = Number(x);
    if (!Number.isFinite(n)) return '—';
    return n.toFixed(1) + '%';
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }

  /* --------------------- PUPIL MODAL --------------------- */

  const pupilModalEl = document.getElementById('pupilScoresModal');
  const pupilTitleEl = document.getElementById('pupilScoresModalLabel');
  const pupilSubEl = document.getElementById('pupilScoresModalSub');
  const pupilLoadingEl = document.getElementById('pupilScoresLoading');
  const pupilErrorEl = document.getElementById('pupilScoresError');
  const pupilContentEl = document.getElementById('pupilScoresContent');

  const pkMean = document.getElementById('pk_mean');
  const pkBest = document.getElementById('pk_best');
  const pkBestSub = document.getElementById('pk_best_sub');
  const pkWorst = document.getElementById('pk_worst');
  const pkWorstSub = document.getElementById('pk_worst_sub');
  const pkLastDelta = document.getElementById('pk_last_delta');

  const pupilExamTbody = document.querySelector('#pupilExamSummaryTable tbody');
  const pupilScoresTbody = document.querySelector('#pupilScoresTable tbody');

  function getBootstrapModal(el) {
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      return new window.bootstrap.Modal(el);
    }
    return null;
  }
  function setPupilState(state, msg) {
    pupilLoadingEl.classList.toggle('d-none', state !== 'loading');
    pupilErrorEl.classList.toggle('d-none', state !== 'error');
    pupilContentEl.classList.toggle('d-none', state !== 'ready');
    if (state === 'error') pupilErrorEl.textContent = msg || 'Failed to load.';
  }

  function computePupilInsights(rows){
    // rows are sorted by exam desc (as returned). We will group by exam_id.
    const byExam = new Map();
    const bySubject = new Map();

    let sum = 0, cnt = 0;

    for (const r of rows){
      const exId = String(r.exam_id ?? '');
      if (!byExam.has(exId)) {
        byExam.set(exId, {
          exam_id: r.exam_id,
          exam_name: r.exam_name,
          exam_date: r.exam_date,
          academic_year: r.academic_year,
          term: r.term,
          n: 0,
          total: 0,
          mean: 0
        });
      }
      const g = byExam.get(exId);

      const sc = Number(r.score);
      if (Number.isFinite(sc)) {
        g.n += 1;
        g.total += sc;
        sum += sc; cnt += 1;

        const sid = String(r.subject_id ?? '');
        const sname = r.subject_name || '';
        const key = sid + '|' + sname;
        if (!bySubject.has(key)) bySubject.set(key, {subject_id:r.subject_id, subject_name:sname, sum:0, n:0});
        const s = bySubject.get(key);
        s.sum += sc; s.n += 1;
      }
    }

    for (const g of byExam.values()){
      g.mean = g.n ? g.total / g.n : 0;
    }

    // Determine best/worst subject mean
    let best = null, worst = null;
    for (const s of bySubject.values()){
      const m = s.n ? s.sum / s.n : null;
      if (m === null) continue;
      if (!best || m > best.mean) best = {mean:m, name:s.subject_name};
      if (!worst || m < worst.mean) worst = {mean:m, name:s.subject_name};
    }

    // Exam ordering: we want chronological for deltas in summary, but also handle missing dates.
    const exams = Array.from(byExam.values());
    exams.sort((a,b) => {
      const da = a.exam_date || '9999-12-31';
      const db = b.exam_date || '9999-12-31';
      if (da < db) return -1;
      if (da > db) return 1;
      return Number(a.exam_id) - Number(b.exam_id);
    });

    // Compute deltas
    for (let i=0;i<exams.length;i++){
      const cur = exams[i];
      const prev = exams[i-1];
      cur.delta_pts = prev ? (cur.total - prev.total) : null;
      cur.delta_pct = (prev && prev.total && Math.abs(prev.total) > 0.00001) ? ((cur.total - prev.total) / prev.total) * 100 : null;
    }

    // Last exam delta (latest chronological)
    const last = exams.length ? exams[exams.length - 1] : null;

    return {
      overallMean: cnt ? (sum / cnt) : null,
      best,
      worst,
      examsChrono: exams,
      lastDeltaPts: last ? last.delta_pts : null
    };
  }

  async function loadPupilScores(pupilId, label) {
    pupilTitleEl.textContent = 'Pupil insights: ' + (label || ('Pupil #' + pupilId));
    pupilSubEl.textContent = 'Loading...';
    pupilExamTbody.innerHTML = '';
    pupilScoresTbody.innerHTML = '';
    pkMean.textContent = '—';
    pkBest.textContent = '—'; pkBestSub.textContent = '—';
    pkWorst.textContent = '—'; pkWorstSub.textContent = '—';
    pkLastDelta.textContent = '—';
    setPupilState('loading');

    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'pupil_scores');
    url.searchParams.set('pupil_id', String(pupilId));

    try {
      const res = await fetch(url.toString(), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });

      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (_) {}

      if (!res.ok || !data || !data.ok) {
        const err = (data && data.error) ? data.error : ('Request failed (HTTP ' + res.status + ')');
        throw new Error(err);
      }

      const p = data.pupil || {};
      const scope = data.scope || {};
      const rows = data.rows || [];

      const who = [
        ((p.surname || '') + ' ' + (p.name || '')).trim(),
        p.student_login ? ('(' + p.student_login + ')') : '',
        p.class_code ? ('Class ' + p.class_code) : '',
        p.track ? ('• ' + p.track) : ''
      ].filter(Boolean).join(' ');

      const scopeText = [
        scope.academic_year ? ('Year ' + scope.academic_year) : null,
        scope.term ? ('Term ' + scope.term) : 'All terms',
        scope.exam_id ? ('Exam #' + scope.exam_id) : 'All exams'
      ].filter(Boolean).join(' • ');

      pupilSubEl.textContent = who + ' — ' + scopeText;

      if (!rows.length) {
        setPupilState('error', 'No scores found for this pupil in the current scope.');
        return;
      }

      // Insights
      const ins = computePupilInsights(rows);

      if (ins.overallMean !== null) {
        pkMean.innerHTML = `<span class="badge ${scoreBadgeClass(ins.overallMean)} metric-badge">${fmt2(ins.overallMean)}</span>`;
      }

      if (ins.best) {
        pkBest.innerHTML = `<span class="badge ${scoreBadgeClass(ins.best.mean)} metric-badge">${fmt2(ins.best.mean)}</span>`;
        pkBestSub.textContent = ins.best.name || '—';
      }
      if (ins.worst) {
        pkWorst.innerHTML = `<span class="badge ${scoreBadgeClass(ins.worst.mean)} metric-badge">${fmt2(ins.worst.mean)}</span>`;
        pkWorstSub.textContent = ins.worst.name || '—';
      }

      if (ins.lastDeltaPts !== null && Number.isFinite(ins.lastDeltaPts)) {
        const m = deltaMeta(ins.lastDeltaPts);
        pkLastDelta.innerHTML = `<span class="badge ${m.cls} metric-badge"><i class="bi ${m.ic} me-1"></i>${m.sign}${fmt2(ins.lastDeltaPts)}</span>`;
      }

      // Exam summary table
      const exFrag = document.createDocumentFragment();
      for (const ex of ins.examsChrono) {
        const tr = document.createElement('tr');

        const tdExam = document.createElement('td');
        tdExam.className = 'fw-semibold';
        tdExam.textContent = ex.exam_name || ('Exam #' + ex.exam_id);

        const tdAy = document.createElement('td'); tdAy.textContent = ex.academic_year || '';
        const tdTerm = document.createElement('td'); tdTerm.textContent = ex.term || '';
        const tdDate = document.createElement('td'); tdDate.textContent = ex.exam_date || '';

        const tdN = document.createElement('td'); tdN.className='text-end mono'; tdN.textContent = String(ex.n || 0);
        const tdTotal = document.createElement('td'); tdTotal.className='text-end mono fw-semibold';
        tdTotal.innerHTML = `<span class="badge text-bg-dark metric-badge">${fmt2(ex.total)}</span>`;

        const tdMean = document.createElement('td'); tdMean.className='text-end mono';
        tdMean.innerHTML = `<span class="badge ${scoreBadgeClass(ex.mean)} metric-badge">${fmt2(ex.mean)}</span>`;

        const tdD = document.createElement('td'); tdD.className='text-end mono';
        const tdP = document.createElement('td'); tdP.className='text-end mono';

        if (ex.delta_pts === null) {
          tdD.innerHTML = '<span class="text-muted">—</span>';
          tdP.innerHTML = '<span class="text-muted">—</span>';
        } else {
          const m = deltaMeta(ex.delta_pts);
          tdD.innerHTML = `<span class="badge ${m.cls} metric-badge"><i class="bi ${m.ic} me-1"></i>${m.sign}${fmt2(ex.delta_pts)}</span>`;
          tdP.innerHTML = `<span class="text-muted">${ex.delta_pct === null ? '—' : (ex.delta_pct.toFixed(1) + '%')}</span>`;
        }

        tr.append(tdExam, tdAy, tdTerm, tdDate, tdN, tdTotal, tdMean, tdD, tdP);
        exFrag.appendChild(tr);
      }
      pupilExamTbody.appendChild(exFrag);

      // Detailed rows table (keep provided order: latest first)
      const frag = document.createDocumentFragment();
      for (const r of rows) {
        const tr = document.createElement('tr');

        const exam = document.createElement('td');
        exam.className = 'fw-semibold';
        exam.textContent = r.exam_name || ('Exam #' + r.exam_id);

        const ay = document.createElement('td');
        ay.textContent = r.academic_year || '';

        const term = document.createElement('td');
        term.textContent = r.term || '';

        const date = document.createElement('td');
        date.textContent = r.exam_date || '';

        const subj = document.createElement('td');
        subj.innerHTML =
          `<div class="fw-semibold">${escapeHtml(r.subject_name || '')}</div>
           <div class="small text-muted">${escapeHtml(r.subject_code || '')}</div>`;

        const score = document.createElement('td');
        score.className = 'text-end';
        const sc = Number(r.score);
        if (Number.isFinite(sc)) {
          score.innerHTML = `<span class="badge ${scoreBadgeClass(sc)} metric-badge">${fmt2(sc)}</span>`;
        } else {
          score.textContent = (r.score ?? '');
        }

        tr.append(exam, ay, term, date, subj, score);
        frag.appendChild(tr);
      }
      pupilScoresTbody.appendChild(frag);

      setPupilState('ready');
    } catch (e) {
      setPupilState('error', e && e.message ? e.message : 'Failed to load scores.');
    }
  }

  /* --------------------- SUBJECT TREND MODAL --------------------- */

  const subjModalEl = document.getElementById('subjectTrendModal');
  const subjTitleEl = document.getElementById('subjectTrendModalLabel');
  const subjSubEl = document.getElementById('subjectTrendModalSub');
  const subjLoadingEl = document.getElementById('subjectTrendLoading');
  const subjErrorEl = document.getElementById('subjectTrendError');
  const subjContentEl = document.getElementById('subjectTrendContent');
  const subjTbody = document.querySelector('#subjectTrendTable tbody');

  function setSubjectState(state, msg) {
    subjLoadingEl.classList.toggle('d-none', state !== 'loading');
    subjErrorEl.classList.toggle('d-none', state !== 'error');
    subjContentEl.classList.toggle('d-none', state !== 'ready');
    if (state === 'error') subjErrorEl.textContent = msg || 'Failed to load.';
  }

  async function loadSubjectTrend(subjectId, label){
    subjTitleEl.textContent = 'Subject trend: ' + (label || ('Subject #' + subjectId));
    subjSubEl.textContent = 'Loading...';
    subjTbody.innerHTML = '';
    setSubjectState('loading');

    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'subject_trend');
    url.searchParams.set('subject_id', String(subjectId));

    try{
      const res = await fetch(url.toString(), {
        headers: {'Accept':'application/json'},
        credentials: 'same-origin'
      });

      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (_) {}

      if (!res.ok || !data || !data.ok) {
        const err = (data && data.error) ? data.error : ('Request failed (HTTP ' + res.status + ')');
        throw new Error(err);
      }

      const subj = data.subject || {};
      const rows = data.rows || [];
      const scope = data.scope || {};

      const scopeText = [
        scope.academic_year ? ('Year ' + scope.academic_year) : null,
        scope.term ? ('Term ' + scope.term) : 'All terms',
        scope.class_code ? ('Class ' + scope.class_code) : null,
        scope.track ? ('• ' + scope.track) : null,
        scope.exam_id ? ('• Exam #' + scope.exam_id) : null
      ].filter(Boolean).join(' ');

      subjSubEl.textContent = (subj.code ? (subj.code + ' — ') : '') + (subj.name || label || '') + ' • ' + scopeText;

      if (!rows.length) {
        setSubjectState('error', 'No rows for this subject in the current scope.');
        return;
      }

      let prevMean = null;
      const frag = document.createDocumentFragment();

      for (const r of rows) {
        const tr = document.createElement('tr');

        const tdExam = document.createElement('td'); tdExam.className='fw-semibold';
        tdExam.textContent = r.exam_name || ('Exam #' + r.exam_id);

        const tdAy = document.createElement('td'); tdAy.textContent = r.academic_year || '';
        const tdTerm = document.createElement('td'); tdTerm.textContent = r.term || '';
        const tdDate = document.createElement('td'); tdDate.textContent = r.exam_date || '';

        const tdN = document.createElement('td'); tdN.className='text-end mono'; tdN.textContent = String(r.n ?? '');

        const mean = Number(r.mean_score);
        const passP = Number(r.pass_rate);
        const delta = (prevMean === null) ? null : (mean - prevMean);
        const deltaPct = (prevMean !== null && Math.abs(prevMean) > 0.00001) ? ((mean - prevMean) / prevMean) * 100 : null;

        const tdMean = document.createElement('td'); tdMean.className='text-end mono';
        tdMean.innerHTML = `<span class="badge ${scoreBadgeClass(mean)} metric-badge">${fmt2(mean)}</span>`;

        const tdPass = document.createElement('td'); tdPass.className='text-end mono';
        tdPass.innerHTML = `<span class="badge ${passBadgeClass(passP)} metric-badge">${fmt1p(passP)}</span>`;

        const tdD = document.createElement('td'); tdD.className='text-end mono';
        const tdP = document.createElement('td'); tdP.className='text-end mono';

        if (delta === null) {
          tdD.innerHTML = '<span class="text-muted">—</span>';
          tdP.innerHTML = '<span class="text-muted">—</span>';
        } else {
          const m = deltaMeta(delta);
          tdD.innerHTML = `<span class="badge ${m.cls} metric-badge"><i class="bi ${m.ic} me-1"></i>${m.sign}${fmt2(delta)}</span>`;
          tdP.innerHTML = `<span class="text-muted">${deltaPct === null ? '—' : (deltaPct.toFixed(1) + '%')}</span>`;
        }

        tr.append(tdExam, tdAy, tdTerm, tdDate, tdN, tdMean, tdPass, tdD, tdP);
        frag.appendChild(tr);

        prevMean = mean;
      }

      subjTbody.appendChild(frag);
      setSubjectState('ready');
    } catch(e){
      setSubjectState('error', e && e.message ? e.message : 'Failed to load subject trend.');
    }
  }

  /* --------------------- CLICK HANDLERS --------------------- */

  document.addEventListener('click', (ev) => {
    const pupilRow = ev.target.closest('.pupil-row');
    if (pupilRow) {
      const pupilId = pupilRow.getAttribute('data-pupil-id');
      const label = pupilRow.getAttribute('data-pupil-label') || '';
      if (!pupilId) return;

      const modal = getBootstrapModal(pupilModalEl);
      if (!modal) {
        alert('Bootstrap JS is not available. Ensure bootstrap.bundle.min.js is loaded.');
        return;
      }
      modal.show();
      loadPupilScores(pupilId, label);
      return;
    }

    const subjRow = ev.target.closest('.subject-row');
    if (subjRow) {
      const subjectId = subjRow.getAttribute('data-subject-id');
      const label = subjRow.getAttribute('data-subject-label') || '';
      if (!subjectId) return;

      const modal = getBootstrapModal(subjModalEl);
      if (!modal) {
        alert('Bootstrap JS is not available. Ensure bootstrap.bundle.min.js is loaded.');
        return;
      }
      modal.show();
      loadSubjectTrend(subjectId, label);
      return;
    }
  });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
