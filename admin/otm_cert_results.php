<?php
// admin/otm_cert_results.php - View OTM certificate-based calculated scores (read-only)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

function otmcv_get_str(string $key, int $maxLen = 120): string {
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    return $v;
}

function otmcv_get_int(string $key, int $min = 0, int $max = PHP_INT_MAX): int {
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    $n = (int)$v;
    if ($n < $min || $n > $max) return 0;
    return $n;
}

function otmcv_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function otmcv_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1");
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function otmcv_fmt(?float $n, int $dec = 2): string {
    return $n === null ? '—' : number_format($n, $dec, '.', '');
}

function otmcv_pct(?float $n): string {
    return $n === null ? '—' : number_format($n, 2, '.', '') . '%';
}

function otmcv_exam_label(array $e): string {
    $kind = (string)($e['otm_kind'] ?? '');
    $kindLabel = $kind === 'repetition' ? 'Repetition' : ($kind === 'mock' ? 'Mock' : ucfirst($kind));
    $year = trim((string)($e['year_code'] ?? ''));
    $parts = [];
    if ($year !== '') $parts[] = $year;
    $parts[] = $kindLabel;
    $parts[] = (string)($e['exam_title'] ?? '');
    $parts[] = (string)($e['exam_date'] ?? '');
    $parts[] = '#' . (int)($e['attempt_no'] ?? 1);
    return implode(' · ', array_filter($parts, static fn($x) => $x !== ''));
}

$page_title = 'OTM Certificate Results';

$classCode = otmcv_get_str('class_code', 30);
$otmExamId = otmcv_get_int('otm_exam_id', 1);
$q = otmcv_get_str('q', 60);
$onlyCert = isset($_GET['only_cert']) && (string)($_GET['only_cert']) === '1';
$load = isset($_GET['load']) && (string)($_GET['load']) === '1';

$schemaOk = false;
$schemaVersionOk = false;
$hasPupils = false;
$hasOtmExams = false;
$hasStudyYear = false;
$hasOtmMajor = false;
$hasOtmSubjects = false;
$hasOtmExamsSchema = false;

$classOptions = [];
$examOptions = [];
$selectedExam = null;
$rows = [];
$issues = [];

$summary = [
    'rows' => 0,
    'with_any_cert' => 0,
    'avg_cert_total' => null,
    'max_cert_total' => null,
];

try {
    $schemaOk = otmcv_table_exists($pdo, 'otm_results');
    $hasPupils = otmcv_table_exists($pdo, 'pupils');
    $hasOtmExams = otmcv_table_exists($pdo, 'otm_exams');
    $hasStudyYear = otmcv_table_exists($pdo, 'study_year');
    $hasOtmMajor = otmcv_table_exists($pdo, 'otm_major');
    $hasOtmSubjects = otmcv_table_exists($pdo, 'otm_subjects');

    if ($schemaOk) {
        $required = [
            'otm_exam_id',
            'major1_certificate_percent','major2_certificate_percent',
            'mandatory_ona_tili_certificate_percent','mandatory_matematika_certificate_percent','mandatory_uzb_tarix_certificate_percent',
            'major1_certificate_score','major2_certificate_score',
            'mandatory_ona_tili_certificate_score','mandatory_matematika_certificate_score','mandatory_uzb_tarix_certificate_score',
            'major1_final_score','major2_final_score','mandatory_ona_tili_final_score','mandatory_matematika_final_score','mandatory_uzb_tarix_final_score',
            'total_score','total_score_withcert'
        ];
        $schemaVersionOk = true;
        foreach ($required as $col) {
            if (!otmcv_column_exists($pdo, 'otm_results', $col)) { $schemaVersionOk = false; break; }
        }
    }

    if ($hasOtmExams) {
        $hasOtmExamsSchema =
            otmcv_column_exists($pdo, 'otm_exams', 'study_year_id') &&
            otmcv_column_exists($pdo, 'otm_exams', 'otm_kind') &&
            otmcv_column_exists($pdo, 'otm_exams', 'exam_title') &&
            otmcv_column_exists($pdo, 'otm_exams', 'exam_date') &&
            otmcv_column_exists($pdo, 'otm_exams', 'attempt_no');
    }

    if ($hasPupils) {
        $classOptions = $pdo->query("SELECT class_code, COUNT(*) AS cnt FROM pupils WHERE class_code <> '' GROUP BY class_code ORDER BY class_code ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($hasOtmExams && $hasOtmExamsSchema) {
        $examOptions = $pdo->query("\n            SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n            FROM otm_exams e\n            LEFT JOIN study_year sy ON sy.id = e.study_year_id\n            ORDER BY e.is_active DESC, e.exam_date DESC, e.exam_title ASC, e.attempt_no DESC, e.id DESC\n            LIMIT 250\n        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($otmExamId > 0) {
            $stE = $pdo->prepare("\n                SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n                FROM otm_exams e\n                LEFT JOIN study_year sy ON sy.id = e.study_year_id\n                WHERE e.id = :id LIMIT 1\n            ");
            $stE->execute([':id' => $otmExamId]);
            $selectedExam = $stE->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$selectedExam) $otmExamId = 0;
        }
    }
} catch (Throwable $e) {
    error_log('[OTM_CERT_RESULTS] init failed: ' . $e->getMessage());
    $_SESSION['flash'][] = ['type' => 'danger', 'msg' => 'Failed to load OTM certificate results page.'];
}

$schemaReady = $schemaOk && $schemaVersionOk && $hasPupils && $hasOtmExams && $hasStudyYear && $hasOtmMajor && $hasOtmSubjects && $hasOtmExamsSchema;
$loadReady = $schemaReady && $classCode !== '' && $otmExamId > 0;

if ($load && !$loadReady) {
    if (!$schemaReady) $issues[] = 'Schema/tables not ready';
    if ($classCode === '') $issues[] = 'Class';
    if ($otmExamId <= 0) $issues[] = 'OTM exam';
}

if ($loadReady) {
    try {
        $sql = "\n            SELECT\n                p.id AS pupil_id, p.surname, p.name, p.class_code,\n                os1.name AS major1_name, os2.name AS major2_name,\n                r.id AS otm_row_id, r.updated_at,\n                r.major1_correct, r.major2_correct,\n                r.mandatory_ona_tili_correct, r.mandatory_matematika_correct, r.mandatory_uzb_tarix_correct,\n                r.major1_certificate_percent, r.major2_certificate_percent,\n                r.mandatory_ona_tili_certificate_percent, r.mandatory_matematika_certificate_percent, r.mandatory_uzb_tarix_certificate_percent,\n                r.major1_certificate_score, r.major2_certificate_score,\n                r.mandatory_ona_tili_certificate_score, r.mandatory_matematika_certificate_score, r.mandatory_uzb_tarix_certificate_score,\n                r.major1_final_score, r.major2_final_score,\n                r.mandatory_ona_tili_final_score, r.mandatory_matematika_final_score, r.mandatory_uzb_tarix_final_score,\n                r.total_score, r.total_score_withcert\n            FROM pupils p\n            LEFT JOIN otm_major om ON om.pupil_id = p.id AND om.is_active = 1\n            LEFT JOIN otm_subjects os1 ON os1.id = om.major1_subject_id\n            LEFT JOIN otm_subjects os2 ON os2.id = om.major2_subject_id\n            LEFT JOIN otm_results r ON r.pupil_id = p.id AND r.otm_exam_id = :otm_exam_id\n            WHERE p.class_code = :class_code\n        ";
        $params = [':class_code' => $classCode, ':otm_exam_id' => $otmExamId];
        if ($q !== '') {
            $sql .= " AND (p.surname LIKE :q OR p.name LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($onlyCert) {
            $sql .= " AND r.id IS NOT NULL AND (\n                r.major1_certificate_percent IS NOT NULL OR r.major2_certificate_percent IS NOT NULL OR\n                r.mandatory_ona_tili_certificate_percent IS NOT NULL OR r.mandatory_matematika_certificate_percent IS NOT NULL OR r.mandatory_uzb_tarix_certificate_percent IS NOT NULL\n            )";
        }
        $sql .= " ORDER BY p.surname ASC, p.name ASC, p.id ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sumCertTotal = 0.0; $nCertTotal = 0;
        $maxCertTotal = null;
        $withAnyCert = 0;

        foreach ($rows as &$r) {
            $hasRow = !empty($r['otm_row_id']);
            $hasAnyCert = (
                $r['major1_certificate_percent'] !== null ||
                $r['major2_certificate_percent'] !== null ||
                $r['mandatory_ona_tili_certificate_percent'] !== null ||
                $r['mandatory_matematika_certificate_percent'] !== null ||
                $r['mandatory_uzb_tarix_certificate_percent'] !== null
            );
            $r['_has_any_cert'] = $hasAnyCert;
            if ($hasAnyCert) $withAnyCert++;

            $certScores = [
                $r['major1_certificate_score'],
                $r['major2_certificate_score'],
                $r['mandatory_ona_tili_certificate_score'],
                $r['mandatory_matematika_certificate_score'],
                $r['mandatory_uzb_tarix_certificate_score'],
            ];
            $certTotal = 0.0;
            foreach ($certScores as $cs) {
                $certTotal += ($cs !== null) ? (float)$cs : 0.0;
            }
            $r['_cert_total'] = $hasRow ? round($certTotal, 2) : null;

            if ($hasRow) {
                $sumCertTotal += $certTotal;
                $nCertTotal++;
                if ($maxCertTotal === null || $certTotal > $maxCertTotal) $maxCertTotal = $certTotal;
            }
        }
        unset($r);

        $summary['rows'] = count($rows);
        $summary['with_any_cert'] = $withAnyCert;
        $summary['avg_cert_total'] = $nCertTotal > 0 ? ($sumCertTotal / $nCertTotal) : null;
        $summary['max_cert_total'] = $maxCertTotal;
    } catch (Throwable $e) {
        error_log('[OTM_CERT_RESULTS] load failed: ' . $e->getMessage());
        $_SESSION['flash'][] = ['type' => 'danger', 'msg' => 'Failed to load certificate-based OTM results.'];
        $rows = [];
    }
}

require __DIR__ . '/header.php';
?>
<style nonce="<?= h_attr($_SESSION['csp_nonce'] ?? '') ?>">
  .otmcv-chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid rgba(15,23,42,.10);border-radius:999px;background:#fff;padding:.22rem .6rem;font-size:.82rem}
  .otmcv-metric{border:1px solid rgba(15,23,42,.08);border-radius:.9rem;background:linear-gradient(180deg,#fff,#f8fafc);padding:.75rem .9rem;min-height:110px;height:100%;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center}
  .otmcv-metric .label{color:#64748b;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
  .otmcv-metric .value{font-weight:800;font-size:1.2rem;color:#0f172a}
  .otmcv-table th,.otmcv-table td{vertical-align:middle}
  .otmcv-table{border-color:rgba(15,23,42,.10)}
  .otmcv-table thead th{background:#f3f4f6;position:sticky;top:0;z-index:1;border-color:rgba(15,23,42,.12)}
  .otmcv-table thead tr:first-child th{font-weight:800;font-size:.93rem;color:#0f172a}
  .otmcv-table thead tr:nth-child(2) th{font-size:.74rem;letter-spacing:.03em;text-transform:uppercase;color:#475569;background:#f3f4f6}
  .otmcv-table thead th.otmcv-group{background:linear-gradient(180deg,#f5f5f5,#ececec)}
  .otmcv-table thead th.otmcv-group.g-major1{border-bottom:2px solid rgba(37,99,235,.28)}
  .otmcv-table thead th.otmcv-group.g-major2{border-bottom:2px solid rgba(124,58,237,.22)}
  .otmcv-table thead th.otmcv-group.g-ona{border-bottom:2px solid rgba(22,163,74,.22)}
  .otmcv-table thead th.otmcv-group.g-math{border-bottom:2px solid rgba(217,119,6,.22)}
  .otmcv-table thead th.otmcv-group.g-tarix{border-bottom:2px solid rgba(14,165,233,.22)}
  .otmcv-table tbody td{border-color:rgba(15,23,42,.08)}
  .otmcv-table tbody tr:nth-child(odd) td{background:#ffffff}
  .otmcv-table tbody tr:nth-child(even) td{background:#f5f5f5}
  .otmcv-table tbody tr:hover td{background:rgba(15,23,42,.035)!important}
  .otmcv-table tbody td:nth-child(4), .otmcv-table tbody td:nth-child(6), .otmcv-table tbody td:nth-child(8), .otmcv-table tbody td:nth-child(10), .otmcv-table tbody td:nth-child(12){background-image:linear-gradient(180deg,rgba(148,163,184,.08),rgba(148,163,184,.03));}
  .otmcv-table tbody td:nth-child(5), .otmcv-table tbody td:nth-child(7), .otmcv-table tbody td:nth-child(9), .otmcv-table tbody td:nth-child(11), .otmcv-table tbody td:nth-child(13){background-image:linear-gradient(180deg,rgba(255,255,255,.9),rgba(255,255,255,.65));}
  .otmcv-table tbody td:nth-child(5), .otmcv-table tbody td:nth-child(7), .otmcv-table tbody td:nth-child(9), .otmcv-table tbody td:nth-child(11), .otmcv-table tbody td:nth-child(13), .otmcv-table tbody td:nth-child(14){border-right-width:2px;border-right-color:rgba(15,23,42,.14)}
  .otmcv-name{font-weight:700;color:#0f172a;line-height:1.25;font-size:.94rem}
  .otmcv-sub{font-size:.78rem;color:#64748b}
  .otmcv-major{font-weight:600;font-size:.90rem;line-height:1.2;color:#111827}
  .otmcv-center{text-align:center}
  .otmcv-right{text-align:right}
  .otmcv-score,.otmcv-pct{font-variant-numeric:tabular-nums}
  .otmcv-score{display:inline-flex;align-items:center;justify-content:center;min-width:4.2rem;padding:.18rem .38rem;border-radius:.45rem;text-align:center;font-weight:800;font-size:.92rem;background:rgba(15,23,42,.04);border:1px solid rgba(15,23,42,.06);color:#0f172a;position:relative}
  .otmcv-score.bad{color:#dc2626;background:rgba(220,38,38,.08);border-color:rgba(220,38,38,.15)}
  .otmcv-score.warn{color:#b45309;background:rgba(217,119,6,.10);border-color:rgba(217,119,6,.16)}
  .otmcv-score.good{color:#047857;background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.16)}
  .otmcv-pct{display:inline-flex;align-items:center;justify-content:center;min-width:5.1rem;padding:.14rem .34rem;border-radius:.42rem;text-align:center;font-weight:700;font-size:.9rem;color:#334155;background:rgba(148,163,184,.10);border:1px solid rgba(148,163,184,.14);position:relative}
  .otmcv-pct.zero,.otmcv-score.zero{opacity:.78}
  .otmcv-table tbody td:nth-child(4) .otmcv-pct,.otmcv-table tbody td:nth-child(5) .otmcv-score{color:#2563eb}
  .otmcv-table tbody td:nth-child(6) .otmcv-pct,.otmcv-table tbody td:nth-child(7) .otmcv-score{color:#7c3aed}
  .otmcv-table tbody td:nth-child(8) .otmcv-pct,.otmcv-table tbody td:nth-child(9) .otmcv-score{color:#16a34a}
  .otmcv-table tbody td:nth-child(10) .otmcv-pct,.otmcv-table tbody td:nth-child(11) .otmcv-score{color:#d97706}
  .otmcv-table tbody td:nth-child(12) .otmcv-pct,.otmcv-table tbody td:nth-child(13) .otmcv-score{color:#0891b2}
  .otmcv-row-nodata td{background:rgba(248,250,252,.75)!important}
  .otmcv-row-cert td{box-shadow:none}
  .otmcv-mini{font-size:.73rem;color:#64748b}
  .otmcv-status{display:flex;flex-direction:column;align-items:center;gap:.3rem}
  .otmcv-status .badge{font-size:.78rem}
</style>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-calculator me-2"></i>OTM Certificate Results</div>
            <div class="small text-muted">View certificate-only subject scores calculated from certificate percentages in <code>otm_results</code> (missing cert = 0 for that subject).</div>
          </div>
          <div class="d-flex flex-wrap gap-2 small">
            <span class="otmcv-chip"><i class="bi bi-shield-check"></i> View only</span>
            <?php if ($selectedExam): ?><span class="otmcv-chip"><i class="bi bi-calendar2-event"></i> <?= h(otmcv_exam_label($selectedExam)) ?></span><?php endif; ?>
          </div>
        </div>

        <?php if (!$schemaReady): ?>
          <div class="alert alert-warning mb-3">
            <div class="fw-semibold mb-1">Schema not ready for certificate-calculated OTM view.</div>
            <ul class="mb-0 small">
              <?php if (!$schemaOk): ?><li>Table <code>otm_results</code> not found.</li><?php endif; ?>
              <?php if ($schemaOk && !$schemaVersionOk): ?><li><code>otm_results</code> missing certificate/final/generated score columns.</li><?php endif; ?>
              <?php if (!$hasPupils): ?><li>Table <code>pupils</code> not found.</li><?php endif; ?>
              <?php if (!$hasOtmMajor): ?><li>Table <code>otm_major</code> not found.</li><?php endif; ?>
              <?php if (!$hasOtmSubjects): ?><li>Table <code>otm_subjects</code> not found.</li><?php endif; ?>
              <?php if (!$hasStudyYear): ?><li>Table <code>study_year</code> not found.</li><?php endif; ?>
              <?php if (!$hasOtmExams || ($hasOtmExams && !$hasOtmExamsSchema)): ?><li>Table <code>otm_exams</code> missing/outdated.</li><?php endif; ?>
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
              <?php foreach ($examOptions as $eo): $eid=(int)$eo['id']; ?>
                <option value="<?= $eid ?>" <?= $otmExamId === $eid ? 'selected' : '' ?>><?= h(otmcv_exam_label($eo)) ?><?= ((int)($eo['is_active'] ?? 1) !== 1) ? ' [inactive]' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 col-xl-2">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" maxlength="60" value="<?= h_attr($q) ?>" placeholder="Surname / name">
          </div>
          <div class="col-md-1 col-xl-2">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="onlyCert" name="only_cert" value="1" <?= $onlyCert ? 'checked' : '' ?>>
              <label class="form-check-label" for="onlyCert">Only cert</label>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary flex-grow-1" name="load" value="1"><i class="bi bi-search me-1"></i>Load</button>
              <a class="btn btn-outline-secondary" href="otm_cert_results.php"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
          </div>
        </form>

        <?php if ($issues): ?>
          <div class="alert alert-warning mt-3 mb-0 small">Complete filters first: <?= h(implode(', ', $issues)) ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-table me-2"></i>Certificate-based Score Calculation</div>
            <div class="small text-muted">
              <?php if ($loadReady): ?><?= (int)$summary['rows'] ?> pupils loaded.<?php else: ?>Select class and OTM exam, then click Load.<?php endif; ?>
            </div>
          </div>
          <?php if ($loadReady): ?>
            <div class="d-flex flex-wrap gap-2 small">
              <span class="otmcv-chip"><strong>With cert %:</strong> <?= (int)$summary['with_any_cert'] ?></span>
              <span class="otmcv-chip"><strong>Rows:</strong> <?= (int)$summary['rows'] ?></span>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($loadReady): ?>
          <div class="row g-2 mb-3">
            <div class="col-sm-6 col-lg-3"><div class="otmcv-metric"><div class="label">Avg Cert Total</div><div class="value"><?= h(otmcv_fmt($summary['avg_cert_total'])) ?></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="otmcv-metric"><div class="label">Max Cert Total</div><div class="value"><?= h(otmcv_fmt($summary['max_cert_total'])) ?></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="otmcv-metric"><div class="label">Coverage (Cert)</div><div class="value"><?= (int)$summary['rows'] > 0 ? h(number_format(((int)$summary['with_any_cert'] / max(1,(int)$summary['rows'])) * 100, 1)) . '%' : '—' ?></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="otmcv-metric"><div class="label">Exam</div><div class="value" style="font-size:.95rem;line-height:1.15"><?= h($selectedExam ? (string)$selectedExam['exam_title'] : '—') ?></div><div class="otmcv-sub mt-1"><?= h($selectedExam ? (string)$selectedExam['exam_date'] : '') ?></div></div></div>
          </div>
        <?php endif; ?>

        <?php if (!$loadReady): ?>
          <div class="alert alert-info mb-0">Choose <strong>Class</strong> and <strong>OTM Exam</strong> to view certificate-only scores.</div>
        <?php elseif (!$rows): ?>
          <div class="alert alert-warning mb-0">No results found for the selected class/filter/exam.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle otmcv-table">
              <thead class="table-light">
                <tr>
                  <th rowspan="2">#</th>
                  <th rowspan="2">Pupil</th>
                  <th rowspan="2" class="otmcv-center">Majors</th>
                  <th colspan="2" class="otmcv-center otmcv-group g-major1">Asosiy fan 1</th>
                  <th colspan="2" class="otmcv-center otmcv-group g-major2">Asosiy fan 2</th>
                  <th colspan="2" class="otmcv-center otmcv-group g-ona">Ona tili</th>
                  <th colspan="2" class="otmcv-center otmcv-group g-math">Matematika</th>
                  <th colspan="2" class="otmcv-center otmcv-group g-tarix">Tarix</th>
                  <th rowspan="2" class="otmcv-center">Cert Total</th>
                  <th rowspan="2" class="otmcv-center">Status</th>
                </tr>
                <tr>
                  <th class="otmcv-center">%</th><th class="otmcv-center">Score</th>
                  <th class="otmcv-center">%</th><th class="otmcv-center">Score</th>
                  <th class="otmcv-center">%</th><th class="otmcv-center">Score</th>
                  <th class="otmcv-center">%</th><th class="otmcv-center">Score</th>
                  <th class="otmcv-center">%</th><th class="otmcv-center">Score</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $i => $r): ?>
                  <?php
                    $hasRow = !empty($r['otm_row_id']);
                    $rowClass = !$hasRow ? 'otmcv-row-nodata' : (!empty($r['_has_any_cert']) ? 'otmcv-row-cert' : '');
                    $certTotal = isset($r['_cert_total']) && $r['_cert_total'] !== null ? (float)$r['_cert_total'] : null;
                    $certTotalCls = 'bad';
                    if ($certTotal !== null && $certTotal >= 15) $certTotalCls = 'good';
                    elseif ($certTotal !== null && $certTotal >= 5) $certTotalCls = 'warn';
                    $m1Pct = $hasRow ? (float)($r['major1_certificate_percent'] ?? 0) : null;
                    $m2Pct = $hasRow ? (float)($r['major2_certificate_percent'] ?? 0) : null;
                    $onaPct = $hasRow ? (float)($r['mandatory_ona_tili_certificate_percent'] ?? 0) : null;
                    $mathPct = $hasRow ? (float)($r['mandatory_matematika_certificate_percent'] ?? 0) : null;
                    $tarixPct = $hasRow ? (float)($r['mandatory_uzb_tarix_certificate_percent'] ?? 0) : null;
                    $m1Cert = $hasRow ? (float)($r['major1_certificate_score'] ?? 0) : null;
                    $m2Cert = $hasRow ? (float)($r['major2_certificate_score'] ?? 0) : null;
                    $onaCert = $hasRow ? (float)($r['mandatory_ona_tili_certificate_score'] ?? 0) : null;
                    $mathCert = $hasRow ? (float)($r['mandatory_matematika_certificate_score'] ?? 0) : null;
                    $tarixCert = $hasRow ? (float)($r['mandatory_uzb_tarix_certificate_score'] ?? 0) : null;
                  ?>
                  <tr class="<?= h_attr($rowClass) ?>">
                    <td><?= $i + 1 ?></td>
                    <td>
                      <div class="otmcv-name"><?= h(trim((string)$r['surname'] . ' ' . (string)$r['name'])) ?></div>
                      <div class="otmcv-sub"><?= h((string)$r['class_code']) ?></div>
                    </td>
                    <td class="otmcv-center">
                      <div class="otmcv-major"><?= h(((string)($r['major1_name'] ?? '—')) . ' / ' . ((string)($r['major2_name'] ?? '—'))) ?></div>
                    </td>
                    <td class="otmcv-center"><span class="otmcv-pct<?= $m1Pct !== null && abs((float)$m1Pct) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_pct($m1Pct)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-score<?= $m1Cert !== null && abs((float)$m1Cert) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_fmt($m1Cert)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-pct<?= $m2Pct !== null && abs((float)$m2Pct) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_pct($m2Pct)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-score<?= $m2Cert !== null && abs((float)$m2Cert) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_fmt($m2Cert)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-pct<?= $onaPct !== null && abs((float)$onaPct) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_pct($onaPct)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-score<?= $onaCert !== null && abs((float)$onaCert) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_fmt($onaCert)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-pct<?= $mathPct !== null && abs((float)$mathPct) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_pct($mathPct)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-score<?= $mathCert !== null && abs((float)$mathCert) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_fmt($mathCert)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-pct<?= $tarixPct !== null && abs((float)$tarixPct) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_pct($tarixPct)) ?></span></td>
                    <td class="otmcv-center"><span class="otmcv-score<?= $tarixCert !== null && abs((float)$tarixCert) < 0.001 ? " zero" : "" ?>"><?= h(otmcv_fmt($tarixCert)) ?></span></td>
                    <td class="otmcv-center"><?php if ($certTotal !== null): ?><span class="otmcv-score <?= h_attr($certTotalCls) ?>"><?= h(otmcv_fmt($certTotal)) ?></span><?php else: ?>—<?php endif; ?></td>
                    <td class="otmcv-center">
                      <div class="otmcv-status">
                      <?php if (!$hasRow): ?>
                        <span class="badge text-bg-secondary">No result</span>
                      <?php elseif (!empty($r['_has_any_cert'])): ?>
                        <span class="badge text-bg-success">Cert applied</span>
                        <div class="otmcv-mini"><?= h((string)($r['updated_at'] ?? '')) ?></div>
                      <?php else: ?>
                        <span class="badge text-bg-warning text-dark">No cert %</span>
                        <div class="otmcv-mini"><?= h((string)($r['updated_at'] ?? '')) ?></div>
                      <?php endif; ?>
                      </div>
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
