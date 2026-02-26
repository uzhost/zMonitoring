<?php
// admin/otm_results_view.php - View OTM results by class (read-only)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

function otmv_get_str(string $key, int $maxLen = 120): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function otmv_get_int(string $key, int $min = 0, int $max = PHP_INT_MAX): int
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    $n = (int)$v;
    if ($n < $min || $n > $max) return 0;
    return $n;
}

function otmv_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("\n        SELECT 1 FROM information_schema.tables\n        WHERE table_schema = DATABASE() AND table_name = :t\n        LIMIT 1\n    ");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function otmv_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("\n        SELECT 1 FROM information_schema.columns\n        WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c\n        LIMIT 1\n    ");
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function otmv_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}

function otmv_major_pair_code(?string $major1Name, ?string $major2Name): string
{
    $code = otmv_initial((string)$major1Name) . otmv_initial((string)$major2Name);
    return $code !== '' ? $code : '—';
}

function otmv_exam_label(array $e): string
{
    $year = trim((string)($e['year_code'] ?? ''));
    $kind = (string)($e['otm_kind'] ?? '');
    $kindLabel = $kind === 'repetition' ? 'Repetition' : ($kind === 'mock' ? 'Mock' : ucfirst($kind));
    $parts = [];
    if ($year !== '') $parts[] = $year;
    $parts[] = $kindLabel;
    $parts[] = (string)($e['exam_title'] ?? '');
    $parts[] = (string)($e['exam_date'] ?? '');
    $parts[] = '#' . (int)($e['attempt_no'] ?? 1);
    return implode(' · ', array_filter($parts, static fn($x) => $x !== ''));
}

function otmv_fmt_num(?float $n, int $dec = 2): string
{
    if ($n === null) return '—';
    return number_format($n, $dec, '.', '');
}

$page_title = 'OTM Results View';

$classCode = otmv_get_str('class_code', 30);
$otmExamId = otmv_get_int('otm_exam_id', 1);
$q = otmv_get_str('q', 60);
$showOnlyMissing = isset($_GET['missing']) && (string)$_GET['missing'] === '1';
$loadRequested = isset($_GET['load']) && (string)$_GET['load'] === '1';

$schemaOk = false;
$schemaVersionOk = false;
$hasPupilsTable = false;
$hasOtmMajorTable = false;
$hasOtmSubjectsTable = false;
$hasOtmExamsTable = false;
$hasStudyYearTable = false;
$hasOtmExamsSchema = false;

$classOptions = [];
$examOptions = [];
$selectedExam = null;
$rows = [];
$recentSessions = [];
$loadIssues = [];

$summary = [
    'total_pupils' => 0,
    'with_results' => 0,
    'missing_results' => 0,
    'missing_majors' => 0,
    'invalid_majors' => 0,
    'avg_total_score' => null,
    'avg_total_score_withcert' => null,
];

try {
    $schemaOk = otmv_table_exists($pdo, 'otm_results');
    $hasPupilsTable = otmv_table_exists($pdo, 'pupils');
    $hasOtmMajorTable = otmv_table_exists($pdo, 'otm_major');
    $hasOtmSubjectsTable = otmv_table_exists($pdo, 'otm_subjects');
    $hasOtmExamsTable = otmv_table_exists($pdo, 'otm_exams');
    $hasStudyYearTable = otmv_table_exists($pdo, 'study_year');

    if ($schemaOk) {
        $schemaVersionOk =
            otmv_column_exists($pdo, 'otm_results', 'otm_exam_id') &&
            otmv_column_exists($pdo, 'otm_results', 'major1_subject_id') &&
            otmv_column_exists($pdo, 'otm_results', 'major2_subject_id') &&
            otmv_column_exists($pdo, 'otm_results', 'major1_correct') &&
            otmv_column_exists($pdo, 'otm_results', 'major2_correct') &&
            otmv_column_exists($pdo, 'otm_results', 'mandatory_ona_tili_correct') &&
            otmv_column_exists($pdo, 'otm_results', 'mandatory_matematika_correct') &&
            otmv_column_exists($pdo, 'otm_results', 'mandatory_uzb_tarix_correct') &&
            otmv_column_exists($pdo, 'otm_results', 'total_score') &&
            otmv_column_exists($pdo, 'otm_results', 'total_score_withcert');
    }

    if ($hasOtmExamsTable) {
        $hasOtmExamsSchema =
            otmv_column_exists($pdo, 'otm_exams', 'study_year_id') &&
            otmv_column_exists($pdo, 'otm_exams', 'otm_kind') &&
            otmv_column_exists($pdo, 'otm_exams', 'exam_title') &&
            otmv_column_exists($pdo, 'otm_exams', 'exam_date') &&
            otmv_column_exists($pdo, 'otm_exams', 'attempt_no');
    }

    if ($hasPupilsTable) {
        $classOptions = $pdo->query("\n            SELECT class_code, COUNT(*) AS cnt\n            FROM pupils\n            WHERE class_code IS NOT NULL AND class_code <> ''\n            GROUP BY class_code\n            ORDER BY class_code ASC\n        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($hasOtmExamsTable && $hasOtmExamsSchema) {
        $examOptions = $pdo->query("\n            SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n            FROM otm_exams e\n            LEFT JOIN study_year sy ON sy.id = e.study_year_id\n            ORDER BY e.is_active DESC, e.exam_date DESC, e.exam_title ASC, e.attempt_no DESC, e.id DESC\n            LIMIT 250\n        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($otmExamId > 0) {
            $stE = $pdo->prepare("\n                SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n                FROM otm_exams e\n                LEFT JOIN study_year sy ON sy.id = e.study_year_id\n                WHERE e.id = :id\n                LIMIT 1\n            ");
            $stE->execute([':id' => $otmExamId]);
            $selectedExam = $stE->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$selectedExam) {
                $otmExamId = 0;
                $_SESSION['flash'][] = ['type' => 'warning', 'msg' => 'Selected OTM exam was not found.'];
            }
        }

        $recentSessions = $pdo->query("\n            SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, sy.year_code,\n                   COUNT(r.id) AS rows_count\n            FROM otm_exams e\n            LEFT JOIN study_year sy ON sy.id = e.study_year_id\n            LEFT JOIN otm_results r ON r.otm_exam_id = e.id\n            GROUP BY e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, sy.year_code\n            ORDER BY e.exam_date DESC, e.id DESC\n            LIMIT 15\n        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('[OTM_RESULTS_VIEW] init failed: ' . $e->getMessage());
    $_SESSION['flash'][] = ['type' => 'danger', 'msg' => 'Failed to load OTM results viewer options.'];
}

$schemaReady = $schemaOk && $schemaVersionOk && $hasPupilsTable && $hasOtmMajorTable && $hasOtmSubjectsTable && $hasOtmExamsTable && $hasStudyYearTable && $hasOtmExamsSchema;
$loadReady = $schemaReady && $classCode !== '' && $otmExamId > 0;

if ($loadRequested && !$loadReady) {
    if (!$schemaReady) $loadIssues[] = 'Schema/tables not ready';
    if ($classCode === '') $loadIssues[] = 'Class';
    if ($otmExamId <= 0) $loadIssues[] = 'OTM exam';
}

if ($loadReady) {
    try {
        $sql = "\n            SELECT\n                p.id AS pupil_id,\n                p.surname, p.name, p.middle_name, p.class_code, p.track, p.student_login,\n                om.major1_subject_id AS otm_major1_subject_id,\n                om.major2_subject_id AS otm_major2_subject_id,\n                os1.code AS otm_major1_code, os1.name AS otm_major1_name,\n                os2.code AS otm_major2_code, os2.name AS otm_major2_name,\n                r.id AS otm_row_id,\n                r.major1_correct, r.major2_correct,\n                r.mandatory_ona_tili_correct, r.mandatory_matematika_correct, r.mandatory_uzb_tarix_correct,\n                r.major1_certificate_percent, r.major2_certificate_percent,\n                r.mandatory_ona_tili_certificate_percent, r.mandatory_matematika_certificate_percent, r.mandatory_uzb_tarix_certificate_percent,\n                r.total_score, r.total_score_withcert,\n                r.note, r.updated_at\n            FROM pupils p\n            LEFT JOIN otm_major om\n              ON om.pupil_id = p.id\n             AND om.is_active = 1\n            LEFT JOIN otm_subjects os1 ON os1.id = om.major1_subject_id\n            LEFT JOIN otm_subjects os2 ON os2.id = om.major2_subject_id\n            LEFT JOIN otm_results r\n              ON r.pupil_id = p.id\n             AND r.otm_exam_id = :otm_exam_id\n            WHERE p.class_code = :class_code\n        ";
        $params = [
            ':class_code' => $classCode,
            ':otm_exam_id' => $otmExamId,
        ];

        if ($q !== '') {
            $sql .= " AND (p.surname LIKE :q OR p.name LIKE :q OR p.student_login LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($showOnlyMissing) {
            $sql .= " AND r.id IS NULL";
        }
        $sql .= " ORDER BY p.surname ASC, p.name ASC, p.id ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $allSql = "\n            SELECT\n                p.id AS pupil_id,\n                om.major1_subject_id AS m1id, om.major2_subject_id AS m2id,\n                r.id AS rid, r.total_score, r.total_score_withcert\n            FROM pupils p\n            LEFT JOIN otm_major om ON om.pupil_id = p.id AND om.is_active = 1\n            LEFT JOIN otm_results r ON r.pupil_id = p.id AND r.otm_exam_id = :otm_exam_id\n            WHERE p.class_code = :class_code\n        ";
        $allParams = [':class_code' => $classCode, ':otm_exam_id' => $otmExamId];
        if ($q !== '') {
            $allSql .= " AND (p.surname LIKE :q OR p.name LIKE :q OR p.student_login LIKE :q)";
            $allParams[':q'] = '%' . $q . '%';
        }
        $stAll = $pdo->prepare($allSql);
        $stAll->execute($allParams);
        $allRows = $stAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sumTotal = 0.0;
        $sumTotalCert = 0.0;
        $nTotal = 0;
        $nTotalCert = 0;

        foreach ($allRows as $r) {
            $summary['total_pupils']++;
            $m1 = (int)($r['m1id'] ?? 0);
            $m2 = (int)($r['m2id'] ?? 0);
            if ($m1 <= 0 || $m2 <= 0) {
                $summary['missing_majors']++;
            } elseif ($m1 === $m2) {
                $summary['invalid_majors']++;
            }

            if (!empty($r['rid'])) {
                $summary['with_results']++;
                if ($r['total_score'] !== null) {
                    $sumTotal += (float)$r['total_score'];
                    $nTotal++;
                }
                if ($r['total_score_withcert'] !== null) {
                    $sumTotalCert += (float)$r['total_score_withcert'];
                    $nTotalCert++;
                }
            }
        }
        $summary['missing_results'] = max(0, $summary['total_pupils'] - $summary['with_results']);
        $summary['avg_total_score'] = $nTotal > 0 ? ($sumTotal / $nTotal) : null;
        $summary['avg_total_score_withcert'] = $nTotalCert > 0 ? ($sumTotalCert / $nTotalCert) : null;

        foreach ($rows as &$row) {
            $m1 = (int)($row['otm_major1_subject_id'] ?? 0);
            $m2 = (int)($row['otm_major2_subject_id'] ?? 0);
            $row['_major_pair_code'] = otmv_major_pair_code((string)($row['otm_major1_name'] ?? ''), (string)($row['otm_major2_name'] ?? ''));
            $row['_major_title'] = trim((string)($row['otm_major1_name'] ?? '') . (($m1 > 0 && $m2 > 0) ? ' / ' : '') . (string)($row['otm_major2_name'] ?? ''));
            $row['_major_state'] = 'ok';
            if ($m1 <= 0 || $m2 <= 0) $row['_major_state'] = 'missing';
            elseif ($m1 === $m2) $row['_major_state'] = 'invalid';
            $row['_has_result'] = !empty($row['otm_row_id']);
        }
        unset($row);
    } catch (Throwable $e) {
        $rows = [];
        error_log('[OTM_RESULTS_VIEW] load failed: ' . $e->getMessage());
        $_SESSION['flash'][] = ['type' => 'danger', 'msg' => 'Failed to load OTM results for the selected class/exam.'];
    }
}

require __DIR__ . '/header.php';
?>
<style nonce="<?= h_attr($_SESSION['csp_nonce'] ?? '') ?>">
  .otmv-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border-radius: 999px;
    border: 1px solid rgba(15,23,42,.10);
    background: rgba(255,255,255,.92);
    padding: .24rem .62rem;
    font-size: .82rem;
  }
  .otmv-metric {
    border: 1px solid rgba(15,23,42,.08);
    border-radius: .9rem;
    background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.92));
    padding: .75rem .85rem;
    min-height: 108px;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
  }
  .otmv-metric .label { color: #64748b; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; text-align: center; }
  .otmv-metric .value { font-weight: 800; font-size: 1.2rem; color: #0f172a; text-align: center; }
  .otmv-metric .otmv-sub { text-align: center; }
  .otmv-table td, .otmv-table th { vertical-align: middle; }
  .otmv-table thead th { background: #f8fafc; color: #0f172a; font-weight: 700; }
  .otmv-sticky-head thead th { position: sticky; top: 0; z-index: 1; }
  .otmv-table tbody tr:hover td { background: rgba(37,99,235,.045); }
  .otmv-name { font-weight: 700; color: #0f172a; }
  .otmv-sub { font-size: .78rem; color: #64748b; }
  .otmv-major-pill { border-radius: 999px; border: 1px solid rgba(37,99,235,.20); background: rgba(37,99,235,.08); color: #1d4ed8; padding: .1rem .45rem; font-size: .75rem; font-weight: 700; }
  .otmv-major-cell { text-align: center; }
  .otmv-major-name { text-align: center; }
  .otmv-num { text-align: center; font-weight: 600; }
  .otmv-total-cell { text-align: center; }
  .otmv-status-cell { text-align: center; min-width: 8.5rem; }
  .otmv-row-missing td { background: rgba(248,250,252,.85); }
  .otmv-row-nores td { background: rgba(255,251,235,.55); }
  .otmv-row-invalid td { background: rgba(254,242,242,.6); }
  .otmv-score { font-weight: 700; min-width: 3.5rem; display: inline-block; text-align: center; }
  .otmv-score.bad { color: #dc2626; }
  .otmv-score.warn { color: #b45309; }
  .otmv-score.good { color: #047857; }
</style>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-eye me-2"></i>OTM Results Viewer</div>
            <div class="small text-muted">View-only page for class OTM results by selected OTM exam.</div>
          </div>
          <div class="d-flex flex-wrap gap-2 small">
            <span class="otmv-chip"><i class="bi bi-shield-check"></i> View only</span>
            <?php if ($selectedExam): ?>
              <span class="otmv-chip"><i class="bi bi-calendar2-event"></i> <?= h(otmv_exam_label($selectedExam)) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$schemaReady): ?>
          <div class="alert alert-warning mb-3">
            <div class="fw-semibold mb-1">OTM results viewer schema is not ready.</div>
            <ul class="mb-0 small">
              <?php if (!$schemaOk): ?><li>Table <code>otm_results</code> not found.</li><?php endif; ?>
              <?php if ($schemaOk && !$schemaVersionOk): ?><li><code>otm_results</code> schema is missing required columns (including <code>otm_exam_id</code>).</li><?php endif; ?>
              <?php if (!$hasPupilsTable): ?><li>Table <code>pupils</code> not found.</li><?php endif; ?>
              <?php if (!$hasOtmMajorTable): ?><li>Table <code>otm_major</code> not found.</li><?php endif; ?>
              <?php if (!$hasOtmSubjectsTable): ?><li>Table <code>otm_subjects</code> not found.</li><?php endif; ?>
              <?php if (!$hasStudyYearTable): ?><li>Table <code>study_year</code> not found.</li><?php endif; ?>
              <?php if (!$hasOtmExamsTable || ($hasOtmExamsTable && !$hasOtmExamsSchema)): ?><li>Table <code>otm_exams</code> missing or outdated.</li><?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-3 col-xl-2">
            <label class="form-label">Class <span class="text-danger">*</span></label>
            <select name="class_code" class="form-select" required>
              <option value="">Select class</option>
              <?php foreach ($classOptions as $c): $cc = (string)$c['class_code']; ?>
                <option value="<?= h_attr($cc) ?>" <?= $classCode === $cc ? 'selected' : '' ?>><?= h($cc) ?> (<?= (int)$c['cnt'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6 col-xl-6">
            <label class="form-label">OTM Exam <span class="text-danger">*</span></label>
            <select name="otm_exam_id" class="form-select" required>
              <option value="">Select OTM exam</option>
              <?php foreach ($examOptions as $eo): $eid = (int)$eo['id']; ?>
                <option value="<?= $eid ?>" <?= $otmExamId === $eid ? 'selected' : '' ?>><?= h(otmv_exam_label($eo)) ?><?= ((int)($eo['is_active'] ?? 1) !== 1) ? ' [inactive]' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3 col-xl-2">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" maxlength="60" value="<?= h_attr($q) ?>" placeholder="Surname / name / login">
          </div>

          <div class="col-md-3 col-xl-2">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" value="1" id="missingOnly" name="missing" <?= $showOnlyMissing ? 'checked' : '' ?>>
              <label class="form-check-label" for="missingOnly">Missing only</label>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary flex-grow-1" name="load" value="1"><i class="bi bi-search me-1"></i>Load</button>
              <a class="btn btn-outline-secondary" href="otm_results_view.php"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
          </div>
        </form>

        <?php if ($loadIssues): ?>
          <div class="alert alert-warning mt-3 mb-0 small">
            Complete filters first: <?= h(implode(', ', $loadIssues)) ?>.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($recentSessions): ?>
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="fw-semibold mb-2"><i class="bi bi-clock-history me-2"></i>Recent OTM Exams</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Exam</th>
                  <th class="text-end">Rows</th>
                  <th class="text-end">Open</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentSessions as $rs): ?>
                  <tr>
                    <td><?= h(otmv_exam_label($rs)) ?></td>
                    <td class="text-end"><?= (int)$rs['rows_count'] ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-secondary" href="<?= h_attr('otm_results_view.php?' . http_build_query(['class_code' => $classCode, 'otm_exam_id' => (int)$rs['id'], 'load' => 1])) ?>">Open</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Pick a class above, then use Open to load that exam for the class.</div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-table me-2"></i>Class Results</div>
            <div class="small text-muted">
              <?php if ($loadReady): ?>
                <?= (int)$summary['total_pupils'] ?> pupils in class<?= $q !== '' ? ' (filtered search)' : '' ?>.
              <?php else: ?>
                Select class and OTM exam, then click Load.
              <?php endif; ?>
            </div>
          </div>
          <?php if ($loadReady): ?>
            <div class="d-flex flex-wrap gap-2 small">
              <span class="otmv-chip"><strong>With results:</strong> <?= (int)$summary['with_results'] ?></span>
              <span class="otmv-chip"><strong>Missing:</strong> <?= (int)$summary['missing_results'] ?></span>
              <span class="otmv-chip"><strong>No majors:</strong> <?= (int)$summary['missing_majors'] ?></span>
              <?php if ((int)$summary['invalid_majors'] > 0): ?>
                <span class="otmv-chip"><strong>Invalid majors:</strong> <?= (int)$summary['invalid_majors'] ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($loadReady): ?>
          <div class="row g-2 mb-3">
            <div class="col-sm-6 col-lg-3">
              <div class="otmv-metric">
                <div class="label">Avg Total Score</div>
                <div class="value"><?= h(otmv_fmt_num($summary['avg_total_score'] !== null ? (float)$summary['avg_total_score'] : null)) ?></div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="otmv-metric">
                <div class="label">Avg Total (With Cert)</div>
                <div class="value"><?= h(otmv_fmt_num($summary['avg_total_score_withcert'] !== null ? (float)$summary['avg_total_score_withcert'] : null)) ?></div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="otmv-metric">
                <div class="label">Coverage</div>
                <div class="value"><?= (int)$summary['total_pupils'] > 0 ? h(number_format(((int)$summary['with_results'] / max(1,(int)$summary['total_pupils'])) * 100, 1)) . '%' : '—' ?></div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="otmv-metric">
                <div class="label">Exam</div>
                <div class="value" style="font-size:.95rem; line-height:1.2;"><?= h($selectedExam ? (string)$selectedExam['exam_title'] : '—') ?></div>
                <div class="otmv-sub mt-1"><?= h($selectedExam ? ((string)$selectedExam['exam_date']) : '') ?></div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!$loadReady): ?>
          <div class="alert alert-info mb-0">Choose <strong>Class</strong> and <strong>OTM Exam</strong> to view results.</div>
        <?php elseif (!$rows): ?>
          <div class="alert alert-warning mb-0">No pupils/results found for the selected class/filter.</div>
        <?php else: ?>
          <div class="table-responsive otmv-sticky-head">
            <table class="table table-sm table-bordered align-middle otmv-table">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Pupil</th>
                  <th class="text-center">Majors</th>
                  <th class="text-center">Fan 1</th>
                  <th class="text-center">Fan 2</th>
                  <th class="text-center">Ona</th>
                  <th class="text-center">Matematika</th>
                  <th class="text-center">Tarix</th>
                  <th class="text-center">Total</th>
                  <th class="text-center">Total+Cert</th>
                  <th class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $i => $r): ?>
                  <?php
                    $rowClass = '';
                    if (($r['_major_state'] ?? '') === 'missing') $rowClass = 'otmv-row-missing';
                    elseif (($r['_major_state'] ?? '') === 'invalid') $rowClass = 'otmv-row-invalid';
                    elseif (empty($r['_has_result'])) $rowClass = 'otmv-row-nores';
                    $total = isset($r['total_score']) ? (float)$r['total_score'] : null;
                    $totalCert = isset($r['total_score_withcert']) ? (float)$r['total_score_withcert'] : null;
                    $scoreCls = 'bad';
                    if ($total !== null && $total >= 120) $scoreCls = 'good';
                    elseif ($total !== null && $total >= 90) $scoreCls = 'warn';
                  ?>
                  <tr class="<?= h_attr($rowClass) ?>">
                    <td><?= $i + 1 ?></td>
                    <td>
                      <div class="otmv-name"><?= h(trim((string)$r['surname'] . ' ' . (string)$r['name'])) ?></div>
                    </td>
                    <td class="otmv-major-cell">
                      <div class="d-flex flex-column gap-1 align-items-center">
                        <div class="d-flex align-items-center justify-content-center gap-2 flex-wrap">
                          <span class="otmv-major-pill"><?= h($r['_major_pair_code'] ?? '—') ?></span>
                          <?php if (($r['_major_state'] ?? '') === 'missing'): ?>
                            <span class="badge text-bg-warning text-dark">No majors</span>
                          <?php elseif (($r['_major_state'] ?? '') === 'invalid'): ?>
                            <span class="badge text-bg-danger">Invalid majors</span>
                          <?php endif; ?>
                        </div>
                        <div class="small text-muted otmv-major-name"><?= h($r['_major_title'] ?? '') ?></div>
                      </div>
                    </td>
                    <td class="otmv-num"><?= $r['major1_correct'] !== null ? (int)$r['major1_correct'] : '—' ?></td>
                    <td class="otmv-num"><?= $r['major2_correct'] !== null ? (int)$r['major2_correct'] : '—' ?></td>
                    <td class="otmv-num"><?= $r['mandatory_ona_tili_correct'] !== null ? (int)$r['mandatory_ona_tili_correct'] : '—' ?></td>
                    <td class="otmv-num"><?= $r['mandatory_matematika_correct'] !== null ? (int)$r['mandatory_matematika_correct'] : '—' ?></td>
                    <td class="otmv-num"><?= $r['mandatory_uzb_tarix_correct'] !== null ? (int)$r['mandatory_uzb_tarix_correct'] : '—' ?></td>
                    <td class="otmv-total-cell">
                      <?php if ($total !== null): ?>
                        <span class="otmv-score <?= h_attr($scoreCls) ?>"><?= h(otmv_fmt_num($total)) ?></span>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="otmv-total-cell">
                      <?php if ($totalCert !== null): ?>
                        <?php
                          $scoreCertCls = 'bad';
                          if ($totalCert >= 120) $scoreCertCls = 'good';
                          elseif ($totalCert >= 90) $scoreCertCls = 'warn';
                        ?>
                        <span class="otmv-score <?= h_attr($scoreCertCls) ?>"><?= h(otmv_fmt_num($totalCert)) ?></span>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="otmv-status-cell">
                      <?php if (!empty($r['_has_result'])): ?>
                        <span class="badge text-bg-success">OK</span>
                        <div class="otmv-sub mt-1"><?= h((string)($r['updated_at'] ?? '')) ?></div>
                      <?php elseif (($r['_major_state'] ?? '') === 'ok'): ?>
                        <span class="badge text-bg-secondary">Missing result</span>
                      <?php elseif (($r['_major_state'] ?? '') === 'missing'): ?>
                        <span class="badge text-bg-warning text-dark">No major setup</span>
                      <?php else: ?>
                        <span class="badge text-bg-danger">Major setup invalid</span>
                      <?php endif; ?>
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

<?php require __DIR__ . '/footer.php'; ?>
