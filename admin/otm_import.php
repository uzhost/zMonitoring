<?php
// admin/otm_import.php - Bulk import OTM repetition/mock results from XLSX/CSV (PhpSpreadsheet)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

session_start_secure();
require_admin();

$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);

const OTMI_CTX_KEY = 'otm_results_import_ctx_v1';
const OTMI_MAX_UPLOAD_BYTES = 10_000_000; // 10MB
const OTMI_CTX_TTL = 3600; // 1 hour
const OTMI_FILE_TTL = 6 * 3600; // 6 hours
const OTMI_PREVIEW_LIMIT = 120;

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
    if ($q) $url .= '?' . http_build_query($q);
    header('Location: ' . $url);
    exit;
}

function otmi_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1"
    );
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function otmi_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1"
    );
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function otmi_tmp_dir(): string
{
    $base = rtrim(sys_get_temp_dir(), '/');
    if ($base === '' || $base === '/') {
        throw new RuntimeException('Invalid temp directory.');
    }
    $dir = $base . '/emonitoring_otm_imports';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create import temp dir.');
    }

    $now = time();
    foreach (glob($dir . '/otm_*.{xlsx,xls,csv}', GLOB_BRACE) ?: [] as $f) {
        $mt = @filemtime($f);
        if ($mt !== false && ($now - $mt) > OTMI_FILE_TTL) {
            @unlink($f);
        }
    }
    return $dir;
}

function otmi_is_allowed_upload(string $name, string $tmpPath): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) return false;

    if (class_exists(finfo::class)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
        $allowed = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'text/plain',
            'application/csv',
            'application/octet-stream',
            'application/zip',
        ];
        if ($mime !== '' && !in_array($mime, $allowed, true)) return false;
    }

    return true;
}

function otmi_norm_header(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/^\xEF\xBB\xBF/u', '', $s) ?? $s;
    if ($s === '') return '';
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(['`', "'", '’', 'ʻ', '“', '”'], '', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s) ?? $s;
    return trim($s, '_');
}

function otmi_map_header_alias(string $header): string
{
    $h = otmi_norm_header($header);
    $map = [
        'pupil_id' => 'pupil_id',
        'pupil' => 'pupil_id',
        'student_id' => 'pupil_id',
        'oquvchi_id' => 'pupil_id',
        'id' => 'pupil_id',

        'major1' => 'major1',
        'major_1' => 'major1',
        'fan1' => 'major1',
        'fan_1' => 'major1',
        'fan_1_togri' => 'major1',

        'major2' => 'major2',
        'major_2' => 'major2',
        'fan2' => 'major2',
        'fan_2' => 'major2',
        'fan_2_togri' => 'major2',

        'mandatory1' => 'mandatory1',
        'mandatory_1' => 'mandatory1',
        'ona_tili' => 'mandatory1',
        'ona_tili_va_adabiyot' => 'mandatory1',
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
        'ozbekiston_tarixi' => 'mandatory3',
        'm_tarix' => 'mandatory3',

        'exam_id' => 'exam_id',
        'otm_exam_id' => 'exam_id',
    ];
    return $map[$h] ?? $h;
}

function otmi_read_sheet_assoc(string $path): array
{
    $reader = IOFactory::createReaderForFile($path);
    if ($reader instanceof Csv) {
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setEscapeCharacter('\\');
        $reader->setInputEncoding('UTF-8');
    }
    $reader->setReadDataOnly(true);
    $book = $reader->load($path);
    $sheet = $book->getActiveSheet();

    $highestRow = (int)$sheet->getHighestRow();
    $highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    if ($highestRow < 1 || $highestColIdx < 1) {
        return [[], []];
    }

    $headers = [];
    $seen = [];
    for ($c = 1; $c <= $highestColIdx; $c++) {
        $raw = (string)$sheet->getCell([$c, 1])->getValue();
        $key = otmi_map_header_alias($raw);
        if ($key === '') $key = 'col_' . $c;
        if (isset($seen[$key])) {
            $seen[$key]++;
            $key .= '_' . $seen[$key];
        } else {
            $seen[$key] = 1;
        }
        $headers[$c] = $key;
    }

    $rows = [];
    for ($r = 2; $r <= $highestRow; $r++) {
        $assoc = [];
        $allEmpty = true;
        for ($c = 1; $c <= $highestColIdx; $c++) {
            $cell = $sheet->getCell([$c, $r]);
            $val = $cell->getCalculatedValue();
            if ($val === null || $val === '') $val = $cell->getValue();
            if (is_float($val) || is_int($val)) {
                $s = (string)$val;
                if (str_contains($s, '.')) {
                    $s = rtrim(rtrim(number_format((float)$val, 4, '.', ''), '0'), '.');
                }
                $val = $s;
            }
            $val = trim((string)$val);
            if ($val !== '') $allEmpty = false;
            $assoc[$headers[$c]] = $val;
        }
        if ($allEmpty) continue;
        $assoc['__rownum'] = $r;
        $rows[] = $assoc;
    }

    return [$headers, $rows];
}

function otmi_parse_uint_cell(mixed $raw, int $min, int $max, string $label): array
{
    $s = is_string($raw) ? trim($raw) : '';
    if ($s === '') return ['ok' => false, 'value' => null, 'error' => $label . ' is required'];
    if (!preg_match('/^\d+$/', $s)) return ['ok' => false, 'value' => null, 'error' => $label . ' must be an integer'];
    $v = (int)$s;
    if ($v < $min || $v > $max) return ['ok' => false, 'value' => null, 'error' => $label . " must be {$min}..{$max}"];
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
    $sy = trim((string)($e['year_code'] ?? ''));
    if ($sy === '') $sy = '#' . (int)($e['study_year_id'] ?? 0);
    return $sy . ' · ' . $kindLabel . ' · ' . (string)$e['exam_title'] . ' · ' . (string)$e['exam_date'] . ' · #' . (int)$e['attempt_no'];
}

function otmi_ctx_store(array $ctx): void
{
    $_SESSION[OTMI_CTX_KEY] = $ctx;
}

function otmi_ctx_get(): ?array
{
    $ctx = $_SESSION[OTMI_CTX_KEY] ?? null;
    return is_array($ctx) ? $ctx : null;
}

function otmi_ctx_clear(): void
{
    $ctx = otmi_ctx_get();
    if ($ctx && !empty($ctx['path']) && is_string($ctx['path']) && is_file($ctx['path'])) {
        @unlink($ctx['path']);
    }
    unset($_SESSION[OTMI_CTX_KEY]);
}

$page_title = 'OTM Results Import';

$pageErrors = [];
$flashLocal = null;
$preview = null;
$defaultExamId = isset($_GET['default_otm_exam_id']) ? max(0, (int)$_GET['default_otm_exam_id']) : 0;

$schemaOk = false;
$schemaVersionOk = false;
$hasOtmResultsExamId = false;
$hasPupilsTable = false;
$hasOtmMajorTable = false;
$hasOtmExamsTable = false;
$hasOtmExamsSchema = false;
$hasStudyYearTable = false;

$examOptions = [];
$examMap = [];
$subjectMap = [];

try {
    $schemaOk = otmi_table_exists($pdo, 'otm_results');
    $hasPupilsTable = otmi_table_exists($pdo, 'pupils');
    $hasOtmMajorTable = otmi_table_exists($pdo, 'otm_major');
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
            otmi_column_exists($pdo, 'otm_results', 'mandatory_uzb_tarix_correct') &&
            $hasOtmResultsExamId;
    }

    if ($hasOtmExamsTable) {
        $hasOtmExamsSchema =
            otmi_column_exists($pdo, 'otm_exams', 'study_year_id') &&
            otmi_column_exists($pdo, 'otm_exams', 'otm_kind') &&
            otmi_column_exists($pdo, 'otm_exams', 'exam_title') &&
            otmi_column_exists($pdo, 'otm_exams', 'exam_date') &&
            otmi_column_exists($pdo, 'otm_exams', 'attempt_no');
    }

    if (otmi_table_exists($pdo, 'otm_subjects')) {
        $stSub = $pdo->query('SELECT id, code, name FROM otm_subjects');
        foreach (($stSub ? $stSub->fetchAll(PDO::FETCH_ASSOC) : []) as $s) {
            $subjectMap[(int)$s['id']] = $s;
        }
    }

    if ($hasOtmExamsTable && $hasOtmExamsSchema) {
        $examOptions = $pdo->query("\n            SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n            FROM otm_exams e\n            LEFT JOIN study_year sy ON sy.id = e.study_year_id\n            ORDER BY e.is_active DESC, e.exam_date DESC, e.exam_title ASC, e.attempt_no DESC, e.id DESC\n            LIMIT 300\n        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($examOptions as $e) {
            $examMap[(int)$e['id']] = $e;
        }
    }
} catch (Throwable $e) {
    $pageErrors[] = 'Failed to load OTM import dependencies.';
    error_log('[OTM_IMPORT] init failed: ' . $e->getMessage());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');
    $action = (string)($_POST['action'] ?? 'preview');
    $defaultExamId = max(0, (int)($_POST['default_otm_exam_id'] ?? 0));

    if ($action === 'reset_ctx') {
        otmi_ctx_clear();
        $flashLocal = ['type' => 'secondary', 'msg' => 'Import context cleared.'];
    }

    if (in_array($action, ['preview', 'import'], true)) {
        if (!$schemaOk) $pageErrors[] = 'Table otm_results does not exist.';
        if (!$schemaVersionOk) $pageErrors[] = 'otm_results schema is missing required OTM result columns or otm_exam_id.';
        if (!$hasPupilsTable) $pageErrors[] = 'Table pupils does not exist.';
        if (!$hasOtmMajorTable) $pageErrors[] = 'Table otm_major does not exist.';
        if (!$hasStudyYearTable) $pageErrors[] = 'Table study_year does not exist.';
        if (!$hasOtmExamsTable || !$hasOtmExamsSchema) $pageErrors[] = 'Table otm_exams is missing or outdated.';

        if ($action === 'import' && !$canWrite) {
            $pageErrors[] = 'Only admin/superadmin can import.';
        }

        if (!$pageErrors) {
            $ctx = null;
            $uploadPath = '';
            if ($action === 'preview') {
                $f = $_FILES['file'] ?? null;
                if (!is_array($f) || !isset($f['tmp_name'])) {
                    $pageErrors[] = 'Please choose a file.';
                } elseif (!is_uploaded_file((string)$f['tmp_name'])) {
                    $pageErrors[] = 'Upload failed (invalid uploaded file).';
                } elseif ((int)($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $pageErrors[] = 'Upload failed (PHP error ' . (int)($f['error'] ?? 0) . ').';
                } elseif ((int)($f['size'] ?? 0) <= 0 || (int)($f['size'] ?? 0) > OTMI_MAX_UPLOAD_BYTES) {
                    $pageErrors[] = 'File is too large or empty (max 10MB).';
                } elseif (!otmi_is_allowed_upload((string)$f['name'], (string)$f['tmp_name'])) {
                    $pageErrors[] = 'Invalid file type. Use XLSX/XLS/CSV.';
                } else {
                    try {
                        $dir = otmi_tmp_dir();
                        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                        $token = bin2hex(random_bytes(16));
                        $uploadPath = $dir . '/otm_' . $token . '.' . $ext;
                        if (!move_uploaded_file((string)$f['tmp_name'], $uploadPath)) {
                            $pageErrors[] = 'Could not move uploaded file to server temp storage.';
                        } else {
                            $ctx = [
                                'token' => $token,
                                'path' => $uploadPath,
                                'name' => (string)$f['name'],
                                'size' => (int)$f['size'],
                                'created' => time(),
                            ];
                            otmi_ctx_store($ctx);
                        }
                    } catch (Throwable $e) {
                        $pageErrors[] = 'Server could not prepare import workspace.';
                        error_log('[OTM_IMPORT] temp setup failed: ' . $e->getMessage());
                    }
                }
            } else {
                $ctx = otmi_ctx_get();
                if (!$ctx || empty($ctx['token']) || empty($ctx['path'])) {
                    $pageErrors[] = 'Import session expired. Preview again.';
                } elseif ((time() - (int)($ctx['created'] ?? 0)) > OTMI_CTX_TTL) {
                    $pageErrors[] = 'Import session expired (older than 1 hour). Preview again.';
                } else {
                    $uploadPath = (string)$ctx['path'];
                    if (!is_file($uploadPath)) {
                        $pageErrors[] = 'Uploaded file is missing. Preview again.';
                    }
                }
            }

            if (!$pageErrors && $uploadPath !== '') {
                try {
                    [$headers, $sheetRows] = otmi_read_sheet_assoc($uploadPath);
                    if (!$sheetRows) {
                        $pageErrors[] = 'No data rows found in the uploaded file.';
                    } else {
                        foreach (['pupil_id','major1','major2','mandatory1','mandatory2','mandatory3'] as $col) {
                            if (!in_array($col, array_values($headers), true)) {
                                $pageErrors[] = 'Missing required column: ' . $col;
                            }
                        }
                    }

                    if (!$pageErrors) {
                        $requestedPupilIds = [];
                        $requestedExamIds = [];
                        if ($defaultExamId > 0) $requestedExamIds[] = $defaultExamId;

                        foreach ($sheetRows as $r) {
                            if (!empty($r['pupil_id']) && preg_match('/^\d+$/', (string)$r['pupil_id'])) {
                                $requestedPupilIds[] = (int)$r['pupil_id'];
                            }
                            if (!empty($r['exam_id']) && preg_match('/^\d+$/', (string)$r['exam_id'])) {
                                $requestedExamIds[] = (int)$r['exam_id'];
                            }
                        }

                        [$pin, $pparams] = otmi_build_in_clause($requestedPupilIds);
                        $pupilMap = [];
                        $majorMap = [];
                        if ($pin !== '') {
                            $stP = $pdo->prepare("\n                                SELECT p.id, p.surname, p.name, p.class_code,\n                                       om.major1_subject_id, om.major2_subject_id\n                                FROM pupils p\n                                LEFT JOIN otm_major om ON om.pupil_id = p.id AND om.is_active = 1\n                                WHERE p.id IN ($pin)\n                            ");
                            $stP->execute($pparams);
                            foreach (($stP->fetchAll(PDO::FETCH_ASSOC) ?: []) as $pr) {
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
                            [$ein, $eparams] = otmi_build_in_clause($requestedExamIds);
                            if ($ein !== '') {
                                $stE = $pdo->prepare("\n                                    SELECT e.id, e.study_year_id, e.otm_kind, e.exam_title, e.exam_date, e.attempt_no, e.is_active, sy.year_code\n                                    FROM otm_exams e\n                                    LEFT JOIN study_year sy ON sy.id = e.study_year_id\n                                    WHERE e.id IN ($ein)\n                                ");
                                $stE->execute($eparams);
                                $examRows = [];
                                foreach (($stE->fetchAll(PDO::FETCH_ASSOC) ?: []) as $er) {
                                    $examRows[(int)$er['id']] = $er;
                                }
                            }
                        }

                        $normalizedRows = [];
                        $validCount = 0;
                        $errorCount = 0;
                        $insertCount = 0;
                        $updateCount = 0;

                        $existStmt = $pdo->prepare("SELECT id FROM otm_results WHERE pupil_id = :pid AND otm_exam_id = :eid LIMIT 1");

                        foreach ($sheetRows as $row) {
                            $line = (int)($row['__rownum'] ?? 0);
                            $errs = [];

                            $pid = otmi_parse_uint_cell($row['pupil_id'] ?? '', 1, PHP_INT_MAX, 'pupil_id');
                            $m1 = otmi_parse_uint_cell($row['major1'] ?? '', 0, 30, 'major1');
                            $m2 = otmi_parse_uint_cell($row['major2'] ?? '', 0, 30, 'major2');
                            $o1 = otmi_parse_uint_cell($row['mandatory1'] ?? '', 0, 10, 'mandatory1');
                            $mth = otmi_parse_uint_cell($row['mandatory2'] ?? '', 0, 10, 'mandatory2');
                            $t3 = otmi_parse_uint_cell($row['mandatory3'] ?? '', 0, 10, 'mandatory3');
                            $examCell = otmi_parse_optional_exam_id($row['exam_id'] ?? '');

                            foreach ([$pid, $m1, $m2, $o1, $mth, $t3, $examCell] as $part) {
                                if (!($part['ok'] ?? false) && ($part['error'] ?? null) !== null) {
                                    $errs[] = (string)$part['error'];
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
                                    $errs[] = 'active otm_major not assigned';
                                } elseif ($major1SubjectId === $major2SubjectId) {
                                    $errs[] = 'otm_major invalid (same subjects)';
                                }
                            }

                            $exam = $resolvedExamId > 0 ? ($examRows[$resolvedExamId] ?? null) : null;
                            if ($resolvedExamId > 0 && !$exam) {
                                $errs[] = 'exam_id not found in otm_exams';
                            }

                            $isValid = !$errs;
                            if ($isValid) {
                                $validCount++;
                                $existStmt->execute([':pid' => $resolvedPid, ':eid' => $resolvedExamId]);
                                if ($existStmt->fetchColumn()) $updateCount++; else $insertCount++;
                            } else {
                                $errorCount++;
                            }

                            $normalizedRows[] = [
                                'line' => $line,
                                'pupil_id' => $resolvedPid,
                                'exam_id' => $resolvedExamId,
                                'major1_correct' => (int)($m1['value'] ?? 0),
                                'major2_correct' => (int)($m2['value'] ?? 0),
                                'mandatory_ona_tili_correct' => (int)($o1['value'] ?? 0),
                                'mandatory_matematika_correct' => (int)($mth['value'] ?? 0),
                                'mandatory_uzb_tarix_correct' => (int)($t3['value'] ?? 0),
                                'pupil' => $pupil,
                                'exam' => $exam,
                                'major1_subject_id' => $major1SubjectId,
                                'major2_subject_id' => $major2SubjectId,
                                'errors' => $errs,
                                'is_valid' => $isValid,
                            ];
                        }

                        $preview = [
                            'headers' => $headers,
                            'rows' => $normalizedRows,
                            'rows_total' => count($normalizedRows),
                            'valid_total' => $validCount,
                            'errors_total' => $errorCount,
                            'would_insert' => $insertCount,
                            'would_update' => $updateCount,
                            'ctx' => $ctx ?? otmi_ctx_get(),
                        ];

                        if ($action === 'preview') {
                            if ($errorCount > 0) {
                                $flashLocal = ['type' => 'warning', 'msg' => 'Preview loaded with validation errors. Fix file data before importing.'];
                            } else {
                                $flashLocal = ['type' => 'success', 'msg' => 'Preview loaded successfully. Ready for dry-run/import.'];
                            }
                        }

                        if ($action === 'import') {
                            $dryRun = !empty($_POST['dry_run']);
                            if ($errorCount > 0) {
                                $pageErrors[] = 'Import blocked: fix invalid rows first.';
                            } elseif ($dryRun) {
                                $flashLocal = ['type' => 'info', 'msg' => 'Dry run OK. Would insert ' . $insertCount . ', update ' . $updateCount . '.'];
                            } else {
                                $insertExamCol = ' otm_exam_id,';
                                $insertExamVal = ' :otm_exam_id,';
                                $updateExamSet = "\n            otm_exam_id = new.otm_exam_id,";

                                $up = $pdo->prepare("\n                                    INSERT INTO otm_results (\n                                        pupil_id, study_year_id, otm_kind, exam_title, exam_date, attempt_no,{$insertExamCol}\n                                        major1_subject_id, major2_subject_id,\n                                        major1_correct, major2_correct,\n                                        mandatory_ona_tili_correct, mandatory_matematika_correct, mandatory_uzb_tarix_correct,\n                                        major1_certificate_percent, major2_certificate_percent,\n                                        mandatory_ona_tili_certificate_percent, mandatory_matematika_certificate_percent, mandatory_uzb_tarix_certificate_percent,\n                                        created_by, updated_by\n                                    )\n                                    VALUES (\n                                        :pupil_id, :study_year_id, :otm_kind, :exam_title, :exam_date, :attempt_no,{$insertExamVal}\n                                        :major1_subject_id, :major2_subject_id,\n                                        :major1_correct, :major2_correct,\n                                        :mandatory_ona_tili_correct, :mandatory_matematika_correct, :mandatory_uzb_tarix_correct,\n                                        :major1_certificate_percent, :major2_certificate_percent,\n                                        :mandatory_ona_tili_certificate_percent, :mandatory_matematika_certificate_percent, :mandatory_uzb_tarix_certificate_percent,\n                                        :created_by, :updated_by\n                                    ) AS new\n                                    ON DUPLICATE KEY UPDATE{$updateExamSet}\n                                        study_year_id = new.study_year_id,\n                                        major1_subject_id = new.major1_subject_id,\n                                        major2_subject_id = new.major2_subject_id,\n                                        major1_correct = new.major1_correct,\n                                        major2_correct = new.major2_correct,\n                                        mandatory_ona_tili_correct = new.mandatory_ona_tili_correct,\n                                        mandatory_matematika_correct = new.mandatory_matematika_correct,\n                                        mandatory_uzb_tarix_correct = new.mandatory_uzb_tarix_correct,\n                                        major1_certificate_percent = new.major1_certificate_percent,\n                                        major2_certificate_percent = new.major2_certificate_percent,\n                                        mandatory_ona_tili_certificate_percent = new.mandatory_ona_tili_certificate_percent,\n                                        mandatory_matematika_certificate_percent = new.mandatory_matematika_certificate_percent,\n                                        mandatory_uzb_tarix_certificate_percent = new.mandatory_uzb_tarix_certificate_percent,\n                                        updated_by = new.updated_by\n                                ");

                                $adminId = admin_id();
                                try {
                                    $pdo->beginTransaction();
                                    $saved = 0;
                                    foreach ($normalizedRows as $nr) {
                                        if (empty($nr['is_valid'])) continue;
                                        $exam = (array)$nr['exam'];
                                        $params = [
                                            ':pupil_id' => (int)$nr['pupil_id'],
                                            ':study_year_id' => (int)$exam['study_year_id'],
                                            ':otm_kind' => (string)$exam['otm_kind'],
                                            ':exam_title' => (string)$exam['exam_title'],
                                            ':exam_date' => (string)$exam['exam_date'],
                                            ':attempt_no' => (int)$exam['attempt_no'],
                                            ':otm_exam_id' => (int)$nr['exam_id'],
                                            ':major1_subject_id' => (int)$nr['major1_subject_id'],
                                            ':major2_subject_id' => (int)$nr['major2_subject_id'],
                                            ':major1_correct' => (int)$nr['major1_correct'],
                                            ':major2_correct' => (int)$nr['major2_correct'],
                                            ':mandatory_ona_tili_correct' => (int)$nr['mandatory_ona_tili_correct'],
                                            ':mandatory_matematika_correct' => (int)$nr['mandatory_matematika_correct'],
                                            ':mandatory_uzb_tarix_correct' => (int)$nr['mandatory_uzb_tarix_correct'],
                                            ':major1_certificate_percent' => null,
                                            ':major2_certificate_percent' => null,
                                            ':mandatory_ona_tili_certificate_percent' => null,
                                            ':mandatory_matematika_certificate_percent' => null,
                                            ':mandatory_uzb_tarix_certificate_percent' => null,
                                            ':created_by' => ($adminId > 0 ? $adminId : null),
                                            ':updated_by' => ($adminId > 0 ? $adminId : null),
                                        ];
                                        $up->execute($params);
                                        $saved++;
                                    }
                                    $pdo->commit();
                                    otmi_ctx_clear();
                                    otmi_flash('success', 'Imported ' . $saved . ' rows. Inserts: ' . $insertCount . ', updates: ' . $updateCount . '.');
                                    otmi_redirect(['default_otm_exam_id' => $defaultExamId > 0 ? $defaultExamId : '']);
                                } catch (Throwable $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $pageErrors[] = 'Import failed due to a database/server error. See logs.';
                                    error_log('[OTM_IMPORT] save failed: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $pageErrors[] = 'Could not read spreadsheet. Verify file format and headers.';
                    error_log('[OTM_IMPORT] read failed: ' . $e->getMessage());
                }
            }
        }
    }
}

$ctxBadge = null;
$ctx = otmi_ctx_get();
if ($ctx && !empty($ctx['created'])) {
    $age = time() - (int)$ctx['created'];
    $ctxBadge = [
        'file' => (string)($ctx['name'] ?? basename((string)($ctx['path'] ?? ''))),
        'age' => max(0, $age),
        'expires' => max(0, OTMI_CTX_TTL - $age),
        'token' => (string)($ctx['token'] ?? ''),
    ];
}

require __DIR__ . '/header.php';
?>
<style nonce="<?= h_attr($_SESSION['csp_nonce'] ?? '') ?>">
  .otmi-card { border: 1px solid rgba(15,23,42,.08); border-radius: 16px; }
  .otmi-paste-note code { font-size: .86em; }
  .otmi-chip { border-radius: 999px; border: 1px solid rgba(15,23,42,.10); background: #fff; padding: .2rem .55rem; }
  .otmi-table td, .otmi-table th { vertical-align: middle; }
  .otmi-err { max-width: 360px; white-space: normal; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h3 class="mb-1">OTM Results Import</h3>
    <div class="text-muted">Import OTM results from XLSX/CSV using PhpSpreadsheet (preview, dry-run, then import).</div>
  </div>
  <div class="d-flex flex-wrap gap-2 small">
    <span class="otmi-chip">Required columns: <code>pupil_id</code>, <code>major1</code>, <code>major2</code>, <code>mandatory1</code>, <code>mandatory2</code>, <code>mandatory3</code></span>
    <span class="otmi-chip">Optional: <code>exam_id</code> / <code>otm_exam_id</code></span>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-5">
    <div class="card otmi-card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
          <div>
            <h5 class="card-title mb-1"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload</h5>
            <div class="text-muted small">Preview validates all rows before import.</div>
          </div>
          <?php if ($ctxBadge): ?>
            <div class="text-end small">
              <span class="badge text-bg-light border">Active context</span>
              <div class="text-muted mt-1"><?= h($ctxBadge['file']) ?></div>
            </div>
          <?php else: ?>
            <span class="badge text-bg-secondary">No context</span>
          <?php endif; ?>
        </div>

        <?php if ($flashLocal): ?>
          <div class="alert alert-<?= h($flashLocal['type']) ?> py-2"><?= h($flashLocal['msg']) ?></div>
        <?php endif; ?>

        <?php if ($pageErrors): ?>
          <div class="alert alert-danger py-2">
            <ul class="mb-0 ps-3">
              <?php foreach ($pageErrors as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
          <?= csrf_field('csrf') ?>
          <input type="hidden" name="action" value="preview">

          <div>
            <label class="form-label">Default OTM Exam</label>
            <select class="form-select" name="default_otm_exam_id">
              <option value="0">Select exam (or provide exam_id column)</option>
              <?php foreach ($examOptions as $eo): ?>
                <?php $eid = (int)$eo['id']; ?>
                <option value="<?= $eid ?>" <?= $defaultExamId === $eid ? 'selected' : '' ?>><?= h(otmi_exam_label($eo)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">If file has no <code>exam_id</code> column, selected exam will be applied to all rows.</div>
          </div>

          <div>
            <label class="form-label">Upload XLSX / XLS / CSV</label>
            <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
          </div>

          <div class="otmi-paste-note small text-muted">
            Header aliases supported: <code>Fan 1</code>/<code>Fan 2</code>, <code>Ona tili</code>, <code>Matematika</code>, <code>Tarix</code>, <code>otm_exam_id</code>.
          </div>

          <button class="btn btn-primary"><i class="bi bi-eye me-1"></i>Preview &amp; Validate</button>
        </form>

        <hr>

        <form method="post" class="vstack gap-2">
          <?= csrf_field('csrf') ?>
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="default_otm_exam_id" value="<?= (int)$defaultExamId ?>">

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="dryRun" name="dry_run" value="1" checked>
            <label class="form-check-label" for="dryRun">Dry run (validate + counts, no DB write)</label>
          </div>

          <button class="btn btn-success" <?= $ctxBadge && $canWrite ? '' : 'disabled' ?>><i class="bi bi-database-add me-1"></i>Run Import</button>
          <div class="small text-muted">Uses active preview context. Re-upload if context expired.</div>
        </form>

        <form method="post" class="mt-3">
          <?= csrf_field('csrf') ?>
          <input type="hidden" name="action" value="reset_ctx">
          <button class="btn btn-outline-secondary btn-sm" <?= $ctxBadge ? '' : 'disabled' ?>>
            <i class="bi bi-trash3 me-1"></i>Clear import context
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card otmi-card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <h5 class="card-title mb-0"><i class="bi bi-clipboard-data me-2"></i>Preview</h5>
          <?php if ($preview): ?>
            <div class="d-flex flex-wrap gap-2 small">
              <span class="badge text-bg-light border">Rows: <?= (int)$preview['rows_total'] ?></span>
              <span class="badge text-bg-success">Valid: <?= (int)$preview['valid_total'] ?></span>
              <span class="badge text-bg-danger">Errors: <?= (int)$preview['errors_total'] ?></span>
              <span class="badge text-bg-light border">Would insert: <?= (int)$preview['would_insert'] ?></span>
              <span class="badge text-bg-light border">Would update: <?= (int)$preview['would_update'] ?></span>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$preview): ?>
          <div class="text-muted">Upload a spreadsheet to see parsed rows, validation errors, and dry-run counts.</div>
        <?php else: ?>
          <div class="small text-muted mb-2">Parsed headers: <?= h(implode(', ', array_values((array)$preview['headers']))) ?></div>
          <div class="table-responsive">
            <table class="table table-sm table-bordered otmi-table">
              <thead class="table-light">
                <tr>
                  <th>Row</th>
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
                <?php foreach (array_slice((array)$preview['rows'], 0, OTMI_PREVIEW_LIMIT) as $r): ?>
                  <?php $p = is_array($r['pupil'] ?? null) ? $r['pupil'] : null; ?>
                  <?php $e = is_array($r['exam'] ?? null) ? $r['exam'] : null; ?>
                  <tr class="<?= !empty($r['is_valid']) ? '' : 'table-danger' ?>">
                    <td><?= (int)($r['line'] ?? 0) ?></td>
                    <td>
                      <?php if ($p): ?>
                        <div class="fw-semibold"><?= h(trim(((string)$p['surname']) . ' ' . ((string)$p['name']))) ?></div>
                        <div class="small text-muted">ID: <?= (int)($r['pupil_id'] ?? 0) ?></div>
                      <?php else: ?>
                        <span class="small text-muted">ID: <?= (int)($r['pupil_id'] ?? 0) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= h($p['class_code'] ?? '') ?></td>
                    <td>
                      <?php if ($e): ?>
                        <div class="fw-semibold small">#<?= (int)($r['exam_id'] ?? 0) ?> · <?= h((string)$e['exam_title']) ?></div>
                        <div class="small text-muted"><?= h((string)$e['exam_date']) ?> · <?= h((string)$e['otm_kind']) ?></div>
                      <?php else: ?>
                        <span class="small text-muted">#<?= (int)($r['exam_id'] ?? 0) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int)($r['major1_correct'] ?? 0) ?></td>
                    <td class="text-end"><?= (int)($r['major2_correct'] ?? 0) ?></td>
                    <td class="text-end"><?= (int)($r['mandatory_ona_tili_correct'] ?? 0) ?></td>
                    <td class="text-end"><?= (int)($r['mandatory_matematika_correct'] ?? 0) ?></td>
                    <td class="text-end"><?= (int)($r['mandatory_uzb_tarix_correct'] ?? 0) ?></td>
                    <td class="otmi-err">
                      <?php if (!empty($r['is_valid'])): ?>
                        <?php $m1s = $subjectMap[(int)($r['major1_subject_id'] ?? 0)] ?? null; ?>
                        <?php $m2s = $subjectMap[(int)($r['major2_subject_id'] ?? 0)] ?? null; ?>
                        <span class="badge text-bg-success">OK</span>
                        <div class="small text-muted mt-1">Majors: <?= h(($m1s['name'] ?? '#'.(int)($r['major1_subject_id'] ?? 0)) . ' / ' . ($m2s['name'] ?? '#'.(int)($r['major2_subject_id'] ?? 0))) ?></div>
                      <?php else: ?>
                        <div class="small text-danger"><?= h(implode('; ', array_map(static fn($x) => (string)$x, (array)($r['errors'] ?? [])))) ?></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (count((array)$preview['rows']) > OTMI_PREVIEW_LIMIT): ?>
            <div class="small text-muted">Showing first <?= OTMI_PREVIEW_LIMIT ?> rows of <?= count((array)$preview['rows']) ?>.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
