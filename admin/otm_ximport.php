<?php
// admin/otm_ximport.php - Bulk import OTM results (2 majors + 3 mandatory) from pasted Excel/CSV rows

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

function otmi_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function otmi_redirect(array $q = []): void
{
    $url = '/admin/otm_import.php';
    if ($q) {
        $url .= '?' . http_build_query($q);
    }
    header('Location: ' . $url);
    exit;
}

function otmi_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("\n        SELECT 1\n        FROM information_schema.tables\n        WHERE table_schema = DATABASE() AND table_name = :t\n        LIMIT 1\n    ");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function otmi_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("\n        SELECT 1\n        FROM information_schema.columns\n        WHERE table_schema = DATABASE()\n          AND table_name = :t\n          AND column_name = :c\n        LIMIT 1\n    ");
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function otmi_norm_header(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/^\xEF\xBB\xBF/u', '', $s) ?? $s; // BOM
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(['`', "'", '’', 'ʻ', '“', '”'], '', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s) ?? $s;
    $s = trim($s, '_');
    return $s;
}

function otmi_parse_line(string $line): array
{
    if (str_contains($line, "\t")) {
        return array_map(static fn($v) => trim((string)$v), explode("\t", $line));
    }

    $comma = substr_count($line, ',');
    $semi = substr_count($line, ';');
    $delim = $semi > $comma ? ';' : ',';

    return array_map(static fn($v) => trim((string)$v), str_getcsv($line, $delim));
}

function otmi_header_key_for_index(int $idx): string
{
    return match ($idx) {
        0 => 'pupil_id',
        1 => 'major1',
        2 => 'major2',
        3 => 'mandatory1',
        4 => 'mandatory2',
        5 => 'mandatory3',
        6 => 'exam_id',
        default => 'col_' . ($idx + 1),
    };
}

function otmi_map_header_alias(string $header): string
{
    $h = otmi_norm_header($header);

    $map = [
        'pupil_id' => 'pupil_id',
        'pupil' => 'pupil_id',
        'id' => 'pupil_id',
        'oquvchi_id' => 'pupil_id',
        'student_id' => 'pupil_id',

        'major1' => 'major1',
        'major_1' => 'major1',
        'fan1' => 'major1',
        'fan_1' => 'major1',

        'major2' => 'major2',
        'major_2' => 'major2',
        'fan2' => 'major2',
        'fan_2' => 'major2',

        'mandatory1' => 'mandatory1',
        'mandatory_1' => 'mandatory1',
        'ona_tili' => 'mandatory1',
        'm_ona_tili' => 'mandatory1',

        'mandatory2' => 'mandatory2',
        'mandatory_2' => 'mandatory2',
        'matematika' => 'mandatory2',
        'm_matematika' => 'mandatory2',
        'math' => 'mandatory2',

        'mandatory3' => 'mandatory3',
        'mandatory_3' => 'mandatory3',
        'tarix' => 'mandatory3',
        'uzb_tarix' => 'mandatory3',
        'm_tarix' => 'mandatory3',
        'history' => 'mandatory3',

        'exam_id' => 'exam_id',
        'otm_exam_id' => 'exam_id',
    ];

    return $map[$h] ?? $h;
}

function otmi_parse_grid(string $raw, bool $hasHeader): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(explode("\n", $raw), static fn($l) => trim($l) !== ''));
    if (!$lines) {
        return ['headers' => [], 'rows' => [], 'error' => 'Paste data is empty.'];
    }

    $headers = [];
    $start = 0;

    if ($hasHeader) {
        $rawHeaders = otmi_parse_line($lines[0]);
        foreach ($rawHeaders as $cell) {
            $headers[] = otmi_map_header_alias((string)$cell);
        }
        $start = 1;
    }

    $rows = [];
    for ($i = $start; $i < count($lines); $i++) {
        $cells = otmi_parse_line($lines[$i]);
        if (!$cells) continue;

        if (!$hasHeader && !$headers) {
            for ($c = 0; $c < count($cells); $c++) {
                $headers[$c] = otmi_header_key_for_index($c);
            }
        }
        if ($hasHeader && !$headers) {
            return ['headers' => [], 'rows' => [], 'error' => 'Header row is empty.'];
        }

        $assoc = [];
        $maxCols = max(count($headers), count($cells));
        for ($c = 0; $c < $maxCols; $c++) {
            $k = $headers[$c] ?? otmi_header_key_for_index($c);
            $assoc[$k] = trim((string)($cells[$c] ?? ''));
        }
        $assoc['__line'] = $i + 1;
        $rows[] = $assoc;
    }

    return ['headers' => $headers, 'rows' => $rows, 'error' => null];
}

function otmi_parse_uint_cell(mixed $raw, int $min, int $max, string $label): array
{
    $s = is_string($raw) ? trim($raw) : '';
    if ($s === '') {
        return ['ok' => false, 'value' => null, 'error' => $label . ' is required'];
    }
    if (!preg_match('/^\d+$/', $s)) {
        return ['ok' => false, 'value' => null, 'error' => $label . ' must be an integer'];
    }
    $v = (int)$s;
    if ($v < $min || $v > $max) {
        return ['ok' => false, 'value' => null, 'error' => $label . " must be {$min}..{$max}"];
    }
    return ['ok' => true, 'value' => $v, 'error' => null];
}

function otmi_parse_optional_exam_id(mixed $raw): array
{
    $s = is_string($raw) ? trim($raw) : '';
    if ($s === '') return ['ok' => true, 'value' => null, 'error' => null];
    if (!preg_match('/^\d+$/', $s)) return ['ok' => false, 'value' => null, 'error' => 'exam_id must be an integer'];
    $v = (int)$s;
    if ($v <= 0) return ['ok' => false, 'value' => null, 'error' => 'exam_id must be > 0'];
    return ['ok' => true, 'value' => $v, 'error' => null];
}

function otmi_build_in_clause(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (!$ids) return ['', []];
    return [implode(',', array_fill(0, count($ids), '?')), $ids];
}

function otmi_exam_label(array $e): string
{
    $kind = (string)($e['otm_kind'] ?? '');
    $kindLabel = $kind === 'repetition' ? 'Repetition' : ($kind === 'mock' ? 'Mock' : ucfirst($kind));
    $sy = (string)($e['year_code'] ?? ('#' . (int)($e['study_year_id'] ?? 0)));
    return trim($sy . ' · ' . $kindLabel . ' · ' . (string)($e['exam_title'] ?? '') . ' · ' . (string)($e['exam_date'] ?? '') . ' · #' . (int)($e['attempt_no'] ?? 1));
}

$pageErrors = [];
$previewRows = [];
$previewSummary = null;
$rawInput = '';
$hasHeader = true;
$defaultExamId = 0;
$action = 'preview';

$schemaOk = false;
$schemaVersionOk = false;
$hasOtmResultsExamId = false;
$hasOtmExamsTable = false;
$hasOtmExamsSchema = false;
$hasOtmMajorTable = false;
$hasPupilsTable = false;
$hasStudyYearTable = false;

$examOptions = [];
$examMap = [];
$subjectMap = [];

try {
    $schemaOk = otmi_table_exists($pdo, 'otm_results');
    $hasOtmMajorTable = otmi_table_exists($pdo, 'otm_major');
    $hasPupilsTable = otmi_table_exists($pdo, 'pupils');
    $hasOtmExamsTable = otmi_table_exists($pdo, 'otm_exams');
    $hasStudyYearTable = otmi_table_exists($pdo, 'study_year');

    if ($schemaOk) {
        $hasOtmResultsExamId = otmi_column_exists($pdo, 'otm_results', 'otm_exam_id');
        $schemaVersionOk =
            otmi_column_exists($pdo, 'otm_results', 'major1_subject_id') &&
            otmi_column_exists($pdo, 'otm_results', 'major2_subject_id') &&
            otmi_column_exists($pdo, 'otm_results', 'major1_correct') &&
            otmi_column_exists($pdo, 'otm_results', 'major2_correct') &&
            otmi_column_exists($pdo, 'otm_results', 'mandatory_ona_tili_correct') &&
            otmi_column_exists($pdo, 'otm_results', 'mandatory_matematika_correct') &&
            otmi_column_exists($pdo, 'otm_results', 'mandatory_uzb_tarix_correct');
    }

    if ($hasOtmExamsTable) {
        $hasOtmExamsSchema =
            otmi_column_exists($pdo, 'otm_exams', 'study_year_id') &&
            otmi_column_exists($pdo, 'otm_exams', 'otm_kind') &&
            otmi_column_exists($pdo, 'otm_exams', 'exam_title') &&
            otmi_column_exists($pdo, 'otm_exams', 'exam_date') &&
            otmi_column_exists($pdo, 'otm_exams', 'attempt_no');
    }

    if ($hasOtmExamsTable && $hasOtmExamsSchema) {
        $examOptions = $pdo->query("\n            SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n            FROM otm_exams e\n            LEFT JOIN study_year sy ON sy.id = e.study_year_id\n            ORDER BY e.is_active DESC, e.exam_date DESC, e.exam_title ASC, e.attempt_no DESC, e.id DESC\n            LIMIT 300\n        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($examOptions as $e) {
            $examMap[(int)$e['id']] = $e;
        }
    }

    if (otmi_table_exists($pdo, 'otm_subjects')) {
        $subs = $pdo->query("SELECT id, code, name FROM otm_subjects")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($subs as $s) {
            $subjectMap[(int)$s['id']] = $s;
        }
    }
} catch (Throwable $e) {
    $pageErrors[] = 'Failed to load OTM import options/schema.';
    error_log('[OTM_IMPORT] init failed: ' . $e->getMessage());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');

    $action = (string)($_POST['action'] ?? 'preview');
    $rawInput = (string)($_POST['raw_data'] ?? '');
    $hasHeader = isset($_POST['has_header']);
    $defaultExamId = (int)($_POST['default_otm_exam_id'] ?? 0);

    if (!in_array($action, ['preview', 'import'], true)) {
        $action = 'preview';
    }

    if ($action === 'import' && !$canWrite) {
        $pageErrors[] = 'Only admin/superadmin can run import.';
    }

    if (!$schemaOk) $pageErrors[] = 'Table otm_results does not exist.';
    if (!$schemaVersionOk) $pageErrors[] = 'otm_results schema is not the expected 2-major/3-mandatory version.';
    if (!$hasPupilsTable) $pageErrors[] = 'Table pupils does not exist.';
    if (!$hasOtmMajorTable) $pageErrors[] = 'Table otm_major does not exist.';
    if (!$hasStudyYearTable) $pageErrors[] = 'Table study_year does not exist.';
    if (!$hasOtmExamsTable || !$hasOtmExamsSchema) $pageErrors[] = 'Table otm_exams is missing or outdated.';

    if (!$pageErrors) {
        $parsed = otmi_parse_grid($rawInput, $hasHeader);
        if ($parsed['error']) {
            $pageErrors[] = (string)$parsed['error'];
        } else {
            $rows = $parsed['rows'];
            if (!$rows) {
                $pageErrors[] = 'No data rows found.';
            } else {
                // Ensure required columns exist when header mode is on.
                if ($hasHeader) {
                    $requiredCols = ['pupil_id','major1','major2','mandatory1','mandatory2','mandatory3'];
                    foreach ($requiredCols as $req) {
                        if (!in_array($req, $parsed['headers'], true)) {
                            $pageErrors[] = 'Missing required column: ' . $req;
                        }
                    }
                }
            }
        }
    }

    if (!$pageErrors) {
        $parsedRows = $parsed['rows'];
        $requestedPupilIds = [];
        $requestedExamIds = [];
        if ($defaultExamId > 0) $requestedExamIds[] = $defaultExamId;

        foreach ($parsedRows as $r) {
            if (!empty($r['pupil_id']) && preg_match('/^\d+$/', (string)$r['pupil_id'])) {
                $requestedPupilIds[] = (int)$r['pupil_id'];
            }
            $rowExamCell = $r['exam_id'] ?? '';
            if (is_string($rowExamCell) && preg_match('/^\d+$/', trim($rowExamCell))) {
                $requestedExamIds[] = (int)trim($rowExamCell);
            }
        }

        [$pupilIn, $pupilParams] = otmi_build_in_clause($requestedPupilIds);
        $pupilMap = [];
        $majorMap = [];

        if ($pupilIn !== '') {
            $st = $pdo->prepare("\n                SELECT p.id, p.surname, p.name, p.class_code,\n                       om.major1_subject_id, om.major2_subject_id\n                FROM pupils p\n                LEFT JOIN otm_major om ON om.pupil_id = p.id AND om.is_active = 1\n                WHERE p.id IN ($pupilIn)\n            ");
            $st->execute($pupilParams);
            foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $pr) {
                $pid = (int)$pr['id'];
                $pupilMap[$pid] = $pr;
                $majorMap[$pid] = [
                    'major1_subject_id' => (int)($pr['major1_subject_id'] ?? 0),
                    'major2_subject_id' => (int)($pr['major2_subject_id'] ?? 0),
                ];
            }
        }

        $examRows = $examMap;
        if ($requestedExamIds) {
            [$examIn, $examParams] = otmi_build_in_clause($requestedExamIds);
            if ($examIn !== '') {
                $stE = $pdo->prepare("\n                    SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n                    FROM otm_exams e\n                    LEFT JOIN study_year sy ON sy.id = e.study_year_id\n                    WHERE e.id IN ($examIn)\n                ");
                $stE->execute($examParams);
                $examRows = [];
                foreach (($stE->fetchAll(PDO::FETCH_ASSOC) ?: []) as $er) {
                    $examRows[(int)$er['id']] = $er;
                }
            }
        }

        $normalizedRows = [];
        $errorCount = 0;
        $validCount = 0;

        foreach ($parsedRows as $row) {
            $line = (int)($row['__line'] ?? 0);
            $errs = [];

            $pid = otmi_parse_uint_cell($row['pupil_id'] ?? '', 1, PHP_INT_MAX, 'pupil_id');
            $m1 = otmi_parse_uint_cell($row['major1'] ?? '', 0, 30, 'major1');
            $m2 = otmi_parse_uint_cell($row['major2'] ?? '', 0, 30, 'major2');
            $mn1 = otmi_parse_uint_cell($row['mandatory1'] ?? '', 0, 10, 'mandatory1');
            $mn2 = otmi_parse_uint_cell($row['mandatory2'] ?? '', 0, 10, 'mandatory2');
            $mn3 = otmi_parse_uint_cell($row['mandatory3'] ?? '', 0, 10, 'mandatory3');
            $examCell = otmi_parse_optional_exam_id($row['exam_id'] ?? '');

            foreach ([$pid, $m1, $m2, $mn1, $mn2, $mn3, $examCell] as $p) {
                if (!($p['ok'] ?? false) && ($p['error'] ?? null) !== null) {
                    $errs[] = (string)$p['error'];
                }
            }

            $resolvedPid = (int)($pid['value'] ?? 0);
            $resolvedExamId = (int)(($examCell['value'] ?? null) ?? ($defaultExamId > 0 ? $defaultExamId : 0));

            if ($resolvedExamId <= 0) {
                $errs[] = 'exam_id is required (column or default exam)';
            }

            $pupil = $resolvedPid > 0 ? ($pupilMap[$resolvedPid] ?? null) : null;
            if ($resolvedPid > 0 && !$pupil) {
                $errs[] = 'pupil not found';
            }

            $maj = $resolvedPid > 0 ? ($majorMap[$resolvedPid] ?? null) : null;
            $major1SubjectId = (int)($maj['major1_subject_id'] ?? 0);
            $major2SubjectId = (int)($maj['major2_subject_id'] ?? 0);
            if ($resolvedPid > 0) {
                if ($major1SubjectId <= 0 || $major2SubjectId <= 0) {
                    $errs[] = 'otm_major (active) not assigned for pupil';
                } elseif ($major1SubjectId === $major2SubjectId) {
                    $errs[] = 'otm_major invalid: major1 and major2 are same';
                }
            }

            $exam = $resolvedExamId > 0 ? ($examRows[$resolvedExamId] ?? null) : null;
            if ($resolvedExamId > 0 && !$exam) {
                $errs[] = 'exam_id not found in otm_exams';
            }

            $isValid = !$errs;
            if ($isValid) $validCount++; else $errorCount++;

            $normalizedRows[] = [
                'line' => $line,
                'raw' => $row,
                'pupil_id' => $resolvedPid,
                'exam_id' => $resolvedExamId,
                'major1_correct' => (int)($m1['value'] ?? 0),
                'major2_correct' => (int)($m2['value'] ?? 0),
                'mandatory_ona_tili_correct' => (int)($mn1['value'] ?? 0),
                'mandatory_matematika_correct' => (int)($mn2['value'] ?? 0),
                'mandatory_uzb_tarix_correct' => (int)($mn3['value'] ?? 0),
                'pupil' => $pupil,
                'exam' => $exam,
                'major1_subject_id' => $major1SubjectId,
                'major2_subject_id' => $major2SubjectId,
                'errors' => $errs,
                'is_valid' => $isValid,
            ];
        }

        $previewRows = $normalizedRows;
        $previewSummary = [
            'total' => count($normalizedRows),
            'valid' => $validCount,
            'errors' => $errorCount,
            'headers' => $parsed['headers'],
        ];

        if ($action === 'import') {
            if ($errorCount > 0) {
                $pageErrors[] = 'Import blocked: fix invalid rows first.';
            } else {
                $insertExamCol = $hasOtmResultsExamId ? ' otm_exam_id,' : '';
                $insertExamVal = $hasOtmResultsExamId ? ' :otm_exam_id,' : '';
                $updateExamSet = $hasOtmResultsExamId ? "\n                        otm_exam_id = new.otm_exam_id," : '';

                $up = $pdo->prepare("\n                    INSERT INTO otm_results (\n                        pupil_id, study_year_id, otm_kind, exam_title, exam_date, attempt_no,{$insertExamCol}\n                        major1_subject_id, major2_subject_id,\n                        major1_correct, major2_correct,\n                        mandatory_ona_tili_correct, mandatory_matematika_correct, mandatory_uzb_tarix_correct,\n                        major1_certificate_percent, major2_certificate_percent,\n                        mandatory_ona_tili_certificate_percent, mandatory_matematika_certificate_percent, mandatory_uzb_tarix_certificate_percent,\n                        created_by, updated_by\n                    )\n                    VALUES (\n                        :pupil_id, :study_year_id, :otm_kind, :exam_title, :exam_date, :attempt_no,{$insertExamVal}\n                        :major1_subject_id, :major2_subject_id,\n                        :major1_correct, :major2_correct,\n                        :mandatory_ona_tili_correct, :mandatory_matematika_correct, :mandatory_uzb_tarix_correct,\n                        :major1_certificate_percent, :major2_certificate_percent,\n                        :mandatory_ona_tili_certificate_percent, :mandatory_matematika_certificate_percent, :mandatory_uzb_tarix_certificate_percent,\n                        :created_by, :updated_by\n                    ) AS new\n                    ON DUPLICATE KEY UPDATE{$updateExamSet}\n                        study_year_id = new.study_year_id,\n                        major1_subject_id = new.major1_subject_id,\n                        major2_subject_id = new.major2_subject_id,\n                        major1_correct = new.major1_correct,\n                        major2_correct = new.major2_correct,\n                        mandatory_ona_tili_correct = new.mandatory_ona_tili_correct,\n                        mandatory_matematika_correct = new.mandatory_matematika_correct,\n                        mandatory_uzb_tarix_correct = new.mandatory_uzb_tarix_correct,\n                        major1_certificate_percent = new.major1_certificate_percent,\n                        major2_certificate_percent = new.major2_certificate_percent,\n                        mandatory_ona_tili_certificate_percent = new.mandatory_ona_tili_certificate_percent,\n                        mandatory_matematika_certificate_percent = new.mandatory_matematika_certificate_percent,\n                        mandatory_uzb_tarix_certificate_percent = new.mandatory_uzb_tarix_certificate_percent,\n                        updated_by = new.updated_by\n                ");

                $saved = 0;
                $adminId = admin_id();
                try {
                    $pdo->beginTransaction();
                    foreach ($previewRows as $pr) {
                        /** @var array<string,mixed> $exam */
                        $exam = (array)$pr['exam'];
                        $params = [
                            ':pupil_id' => (int)$pr['pupil_id'],
                            ':study_year_id' => (int)$exam['study_year_id'],
                            ':otm_kind' => (string)$exam['otm_kind'],
                            ':exam_title' => (string)$exam['exam_title'],
                            ':exam_date' => (string)$exam['exam_date'],
                            ':attempt_no' => (int)$exam['attempt_no'],
                            ':major1_subject_id' => (int)$pr['major1_subject_id'],
                            ':major2_subject_id' => (int)$pr['major2_subject_id'],
                            ':major1_correct' => (int)$pr['major1_correct'],
                            ':major2_correct' => (int)$pr['major2_correct'],
                            ':mandatory_ona_tili_correct' => (int)$pr['mandatory_ona_tili_correct'],
                            ':mandatory_matematika_correct' => (int)$pr['mandatory_matematika_correct'],
                            ':mandatory_uzb_tarix_correct' => (int)$pr['mandatory_uzb_tarix_correct'],
                            ':major1_certificate_percent' => null,
                            ':major2_certificate_percent' => null,
                            ':mandatory_ona_tili_certificate_percent' => null,
                            ':mandatory_matematika_certificate_percent' => null,
                            ':mandatory_uzb_tarix_certificate_percent' => null,
                            ':created_by' => ($adminId > 0 ? $adminId : null),
                            ':updated_by' => ($adminId > 0 ? $adminId : null),
                        ];
                        if ($hasOtmResultsExamId) {
                            $params[':otm_exam_id'] = (int)$pr['exam_id'];
                        }
                        $up->execute($params);
                        $saved++;
                    }
                    $pdo->commit();

                    otmi_flash('success', 'Imported OTM results rows: ' . $saved . '.');
                    otmi_redirect([
                        'default_otm_exam_id' => $defaultExamId > 0 ? $defaultExamId : '',
                    ]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $pageErrors[] = 'Import failed while saving rows.';
                    error_log('[OTM_IMPORT] save failed: ' . $e->getMessage());
                }
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $defaultExamId = isset($_GET['default_otm_exam_id']) ? (int)$_GET['default_otm_exam_id'] : 0;
}

$page_title = 'OTM Results Import';
require __DIR__ . '/header.php';
?>
<style nonce="<?= h_attr($_SESSION['csp_nonce'] ?? '') ?>">
  .otmi-help code { font-size: .86em; }
  .otmi-paste { min-height: 220px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .otmi-compact td, .otmi-compact th { vertical-align: middle; }
  .otmi-chip { border: 1px solid rgba(15,23,42,.12); border-radius: 999px; padding: .22rem .55rem; background: #fff; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h3 class="mb-1">OTM Results Import</h3>
    <div class="text-muted">Paste Excel rows (TSV/CSV) for OTM scores and upsert into <code>otm_results</code>.</div>
  </div>
  <div class="d-flex flex-wrap gap-2 small">
    <span class="otmi-chip">Columns: <code>pupil_id, major1, major2, mandatory1, mandatory2, mandatory3</code></span>
    <span class="otmi-chip">Optional: <code>exam_id</code></span>
  </div>
</div>

<?php if ($pageErrors): ?>
  <div class="alert alert-danger">
    <div class="fw-semibold mb-1">Cannot continue</div>
    <ul class="mb-0">
      <?php foreach ($pageErrors as $e): ?>
        <li><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-5">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <h5 class="card-title mb-2"><i class="bi bi-upload me-2"></i>Paste & Import</h5>
        <div class="text-muted small mb-3">Excel copy-paste works directly (tab-separated). CSV rows also supported.</div>

        <form method="post" class="vstack gap-3">
          <?= csrf_field('csrf') ?>

          <div>
            <label class="form-label">Default OTM Exam (used when row <code>exam_id</code> is blank)</label>
            <select class="form-select" name="default_otm_exam_id">
              <option value="0">Select exam (or provide exam_id column)</option>
              <?php foreach ($examOptions as $eo): ?>
                <?php $eid = (int)$eo['id']; ?>
                <option value="<?= $eid ?>" <?= $defaultExamId === $eid ? 'selected' : '' ?>><?= h(otmi_exam_label($eo)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">For repetition imports, choose the repetition exam here or include <code>exam_id</code> in each row.</div>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="hasHeader" name="has_header" value="1" <?= $hasHeader ? 'checked' : '' ?>>
            <label class="form-check-label" for="hasHeader">First row is header</label>
          </div>

          <div>
            <label class="form-label">Paste data</label>
            <textarea class="form-control otmi-paste" name="raw_data" placeholder="pupil_id\tmajor1\tmajor2\tmandatory1\tmandatory2\tmandatory3\texam_id&#10;101\t30\t30\t10\t8\t10\t12" required><?= h($rawInput) ?></textarea>
          </div>

          <div class="otmi-help small text-muted">
            <div class="mb-1">Accepted header aliases:</div>
            <div><code>fan1</code>/<code>fan2</code>, <code>ona tili</code>, <code>matematika</code>, <code>tarix</code>, <code>otm_exam_id</code>.</div>
            <div>Limits: majors <code>0..30</code>, mandatorys <code>0..10</code>.</div>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" name="action" value="preview">
              <i class="bi bi-eye me-1"></i>Preview & Validate
            </button>
            <button class="btn btn-primary" name="action" value="import" <?= $canWrite ? '' : 'disabled' ?>>
              <i class="bi bi-database-add me-1"></i>Import Rows
            </button>
          </div>

          <?php if (!$canWrite): ?>
            <div class="small text-danger">Your admin role is read-only for import.</div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <h5 class="card-title mb-0"><i class="bi bi-clipboard-data me-2"></i>Preview</h5>
          <?php if ($previewSummary): ?>
            <div class="d-flex flex-wrap gap-2 small">
              <span class="badge text-bg-light border">Rows: <?= (int)$previewSummary['total'] ?></span>
              <span class="badge text-bg-success">Valid: <?= (int)$previewSummary['valid'] ?></span>
              <span class="badge text-bg-danger">Errors: <?= (int)$previewSummary['errors'] ?></span>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$previewSummary): ?>
          <div class="text-muted">Paste rows and click <strong>Preview &amp; Validate</strong>.</div>
        <?php else: ?>
          <div class="small text-muted mb-3">
            <?php if (!empty($previewSummary['headers'])): ?>
              Parsed headers: <?= h(implode(', ', array_map(static fn($x) => (string)$x, (array)$previewSummary['headers']))) ?>
            <?php else: ?>
              Parsed without header row (fixed column order).
            <?php endif; ?>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle otmi-compact">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Pupil</th>
                  <th>Class</th>
                  <th>Exam</th>
                  <th class="text-end">M1</th>
                  <th class="text-end">M2</th>
                  <th class="text-end">Ona</th>
                  <th class="text-end">Math</th>
                  <th class="text-end">Tarix</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php $showRows = array_slice($previewRows, 0, 120); ?>
                <?php foreach ($showRows as $i => $r): ?>
                  <?php $p = is_array($r['pupil']) ? $r['pupil'] : null; ?>
                  <?php $e = is_array($r['exam']) ? $r['exam'] : null; ?>
                  <tr class="<?= !empty($r['is_valid']) ? '' : 'table-danger' ?>">
                    <td class="text-nowrap"><?= (int)($r['line'] ?? ($i + 1)) ?></td>
                    <td>
                      <?php if ($p): ?>
                        <div class="fw-semibold"><?= h(trim(((string)$p['surname']) . ' ' . ((string)$p['name']))) ?></div>
                        <div class="small text-muted">ID: <?= (int)$r['pupil_id'] ?></div>
                      <?php else: ?>
                        <span class="text-muted">ID: <?= (int)$r['pupil_id'] ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= h($p['class_code'] ?? '') ?></td>
                    <td>
                      <?php if ($e): ?>
                        <div class="fw-semibold small">#<?= (int)$r['exam_id'] ?> · <?= h((string)$e['exam_title']) ?></div>
                        <div class="small text-muted"><?= h((string)$e['exam_date']) ?> · <?= h((string)$e['otm_kind']) ?> · <?= h((string)($e['year_code'] ?? '')) ?></div>
                      <?php else: ?>
                        <span class="text-muted">#<?= (int)$r['exam_id'] ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int)$r['major1_correct'] ?></td>
                    <td class="text-end"><?= (int)$r['major2_correct'] ?></td>
                    <td class="text-end"><?= (int)$r['mandatory_ona_tili_correct'] ?></td>
                    <td class="text-end"><?= (int)$r['mandatory_matematika_correct'] ?></td>
                    <td class="text-end"><?= (int)$r['mandatory_uzb_tarix_correct'] ?></td>
                    <td>
                      <?php if (!empty($r['is_valid'])): ?>
                        <?php
                          $m1s = $subjectMap[(int)$r['major1_subject_id']] ?? null;
                          $m2s = $subjectMap[(int)$r['major2_subject_id']] ?? null;
                        ?>
                        <span class="badge text-bg-success">OK</span>
                        <div class="small text-muted mt-1">
                          Majors: <?= h(($m1s['name'] ?? '#'.(int)$r['major1_subject_id']) . ' / ' . ($m2s['name'] ?? '#'.(int)$r['major2_subject_id'])) ?>
                        </div>
                      <?php else: ?>
                        <div class="small text-danger">
                          <?= h(implode('; ', array_map(static fn($x) => (string)$x, (array)$r['errors']))) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if (count($previewRows) > 120): ?>
            <div class="small text-muted">Showing first 120 rows of <?= count($previewRows) ?>.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
