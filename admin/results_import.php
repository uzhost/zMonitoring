<?php
// admin/results_import.php — Import results (long format) from CSV/XLSX with DECIMAL scores end-to-end (preview + validate + resolve + upsert)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

session_start_secure();
require_role('admin');

$page_title = 'Import Results (Decimals)';
require __DIR__ . '/header.php';

const IMPORT_SESSION_KEY = 'results_import_ctx_v3';

// Upload / preview
const MAX_UPLOAD_BYTES   = 20_000_000; // 20MB
const PREVIEW_LIMIT      = 50;

// Your open_basedir-safe path
const TMP_IMPORT_DIR     = '/var/www/cefr/data/www/zangiota.chsb.uz/tmp';
const TMP_PREFIX         = 'exam_admin_imports_';
const CTX_TTL_SECONDS    = 3600;        // 1 hour
const FILE_TTL_SECONDS   = 6 * 3600;    // 6 hours

// Score policy (DECIMALS=1 allows 27.5). If you want 2 decimals set to 2 and adjust schema.
const SCORE_MIN          = 0.0;
const SCORE_MAX          = 40.0;
const SCORE_DECIMALS     = 1;

function nhead(string $h): string
{
    $h = trim(mb_strtolower($h));
    $h = preg_replace('/\s+/u', '_', $h) ?? $h;
    $h = preg_replace('/[^a-z0-9_]+/u', '', $h) ?? $h;
    return $h;
}

function ensure_import_dir_and_cleanup(): string
{
    $base = rtrim(TMP_IMPORT_DIR, '/');
    if ($base === '' || $base === '/' || $base === '/tmp') {
        throw new RuntimeException('Unsafe TMP_IMPORT_DIR.');
    }

    if (!is_dir($base)) {
        if (!@mkdir($base, 0750, true) && !is_dir($base)) {
            throw new RuntimeException('Could not create TMP_IMPORT_DIR.');
        }
    }

    $dir = $base . '/' . TMP_PREFIX . 'results';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create results import directory.');
        }
    }

    $now = time();
    $files = glob($dir . '/results_*.{csv,xlsx,xls}', GLOB_BRACE) ?: [];
    foreach ($files as $f) {
        $mtime = @filemtime($f);
        if ($mtime !== false && ($now - $mtime) > FILE_TTL_SECONDS) {
            @unlink($f);
        }
    }

    return $dir;
}

function is_allowed_upload(string $name, string $tmpPath): bool
{
    $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: '';

    $okMimes = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    return in_array($mime, $okMimes, true);
}

function pick_s(array $row, array $keys): string
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $v = is_string($row[$k]) ? trim($row[$k]) : (string)$row[$k];
            if ($v !== '') return $v;
        }
    }
    return '';
}

function parse_date(?string $s): ?string
{
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

/**
 * Normalize score string:
 * - Accepts "27.5" or "27,5" or "27" or "27.0"
 * - Returns float
 */
function parse_score_decimal(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '') return null;

    // Replace comma decimal separator with dot
    $s = str_replace(',', '.', $raw);

    // Remove accidental spaces around dot
    $s = preg_replace('/\s+/u', '', $s) ?? $s;

    // Must be numeric
    if (!preg_match('/^\d+(?:\.\d+)?$/', $s)) {
        return null;
    }

    return (float)$s;
}

/**
 * Enforce score range and decimal precision.
 * If SCORE_DECIMALS=1, allow 0..40 with 0 or 1 decimal (e.g., 27.5).
 */
function validate_score_precision(float $score): bool
{
    if ($score < SCORE_MIN || $score > SCORE_MAX) return false;

    // Check decimal places by string formatting
    if (SCORE_DECIMALS <= 0) {
        return abs($score - round($score)) < 1e-9;
    }

    // Allow up to SCORE_DECIMALS decimals (not more)
    $s = rtrim(rtrim(number_format($score, 10, '.', ''), '0'), '.'); // canonical
    $pos = strpos($s, '.');
    if ($pos === false) return true;

    $dec = strlen(substr($s, $pos + 1));
    return $dec <= SCORE_DECIMALS;
}

/**
 * Read active sheet to associative rows.
 * PhpSpreadsheet 5.x compatible: getCell([$c,$r]).
 */
function read_assoc_rows(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('File is missing or unreadable.');
    }

    $reader = IOFactory::createReaderForFile($path);

    if ($reader instanceof Csv) {
        // If your CSV uses semicolons, setDelimiter(';')
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setEscapeCharacter('\\');
        $reader->setInputEncoding('UTF-8'); // change to 'Windows-1251' if needed
    }

    $reader->setReadDataOnly(true);
    $ss = $reader->load($path);
    $sh = $ss->getActiveSheet();

    $hr = (int)$sh->getHighestRow();
    $hc = Coordinate::columnIndexFromString($sh->getHighestColumn());

    $headers = [];
    $seen = [];
    for ($c = 1; $c <= $hc; $c++) {
        $raw = (string)$sh->getCell([$c, 1])->getValue();
        $k = nhead($raw);
        $k = $k !== '' ? $k : 'col_' . $c;
        if (!isset($seen[$k])) $seen[$k] = 1;
        else { $seen[$k]++; $k .= '_' . $seen[$k]; }
        $headers[$c] = $k;
    }

    $rows = [];
    for ($r = 2; $r <= $hr; $r++) {
        $row = [];
        $empty = true;

        for ($c = 1; $c <= $hc; $c++) {
            $k = $headers[$c];
            $cell = $sh->getCell([$c, $r]);
            $v = $cell->getCalculatedValue();
            if ($v === null || $v === '') $v = $cell->getValue();
            $v = is_string($v) ? trim($v) : $v;

            if ($v !== null && $v !== '') $empty = false;
            $row[$k] = $v;
        }

        if ($empty) continue;
        $row['__rownum'] = $r;
        $rows[] = $row;
    }

    return [$headers, $rows];
}

/**
 * Validate file row shape & values (score as float).
 * Subject resolution: subject_id OR subject_code (subject_name NOT used for matching).
 */
function validate_rows(array $rows): array
{
    $clean = [];
    $errors = [];

    foreach ($rows as $i => $row) {
        $rowNum = (int)($row['__rownum'] ?? ($i + 2));

        $pupilId      = pick_s($row, ['pupil_id', 'student_id']);
        $studentLogin = pick_s($row, ['student_login', 'login', 'username']);

        $subjectId    = pick_s($row, ['subject_id']);
        $subjectCode  = pick_s($row, ['subject_code', 'code']);

        $academicYear = pick_s($row, ['academic_year', 'year']);
        $term         = pick_s($row, ['term', 'semester']);
        $examName     = pick_s($row, ['exam_name', 'exam']);
        $examDateRaw  = pick_s($row, ['exam_date', 'date']);

        $scoreRaw     = pick_s($row, ['score', 'points', 'ball']);

        $rowErr = [];

        if ($pupilId === '' && $studentLogin === '') $rowErr[] = 'Missing pupil_id or student_login';
        if ($subjectId === '' && $subjectCode === '') $rowErr[] = 'Missing subject_id or subject_code';
        if ($academicYear === '') $rowErr[] = 'Missing academic_year';
        if ($examName === '') $rowErr[] = 'Missing exam_name';

        if ($academicYear !== '' && !preg_match('/^\d{4}\/\d{4}$/', $academicYear)) {
            $rowErr[] = 'academic_year must be like 2025/2026';
        }

        $examDate = null;
        if ($examDateRaw !== '') {
            $examDate = parse_date($examDateRaw);
            if ($examDate === null) $rowErr[] = 'Invalid exam_date (use YYYY-MM-DD or DD.MM.YYYY)';
        }

        $termInt = null;
        if ($term !== '') {
            if (!preg_match('/^\d+$/', $term)) $rowErr[] = 'term must be integer';
            else {
                $termInt = (int)$term;
                if ($termInt < 1 || $termInt > 4) $rowErr[] = 'term must be 1..4';
            }
        }

        if ($scoreRaw === '') $rowErr[] = 'Missing score';

        $score = null;
        if ($scoreRaw !== '') {
            $parsed = parse_score_decimal($scoreRaw);
            if ($parsed === null) {
                $rowErr[] = 'score must be a number (e.g., 27.5 or 27,5) in range 0..40';
            } else {
                $score = $parsed;
                if (!validate_score_precision($score)) {
                    $rowErr[] = 'score must be 0..40 and max ' . SCORE_DECIMALS . ' decimal place(s)';
                }
            }
        }

        if ($rowErr) {
            $errors[] = ['row' => $rowNum, 'errors' => $rowErr, 'data' => $row];
            continue;
        }

        $clean[] = [
            '__row'         => $rowNum,
            'pupil_id'      => ($pupilId !== '' && ctype_digit($pupilId)) ? (int)$pupilId : null,
            'student_login' => $studentLogin !== '' ? mb_strtolower($studentLogin) : null,

            'subject_id'    => ($subjectId !== '' && ctype_digit($subjectId)) ? (int)$subjectId : null,
            'subject_code'  => $subjectCode !== '' ? mb_strtoupper($subjectCode) : null,

            'academic_year' => $academicYear,
            'term'          => $termInt,
            'exam_name'     => $examName,
            'exam_date'     => $examDate,

            'score'         => (float)$score,
        ];
    }

    return [$clean, $errors];
}

function format_age(int $seconds): string
{
    if ($seconds < 0) $seconds = 0;
    if ($seconds < 60) return $seconds . 's';
    $m = intdiv($seconds, 60);
    if ($m < 60) return $m . 'm';
    $h = intdiv($m, 60);
    return $h . 'h';
}

/**
 * Ensure DB schema supports decimal scores.
 * - If results.score is integer, block import and show SQL to run.
 */
function check_results_score_schema(PDO $pdo): array
{
    $st = $pdo->prepare("
        SELECT DATA_TYPE, NUMERIC_SCALE, COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'results'
          AND COLUMN_NAME = 'score'
        LIMIT 1
    ");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'msg' => 'Could not inspect results.score column in INFORMATION_SCHEMA.'];
    }

    $dt = strtolower((string)$row['DATA_TYPE']);
    $scale = $row['NUMERIC_SCALE'] !== null ? (int)$row['NUMERIC_SCALE'] : null;

    if (SCORE_DECIMALS <= 0) {
        // integer is OK
        return ['ok' => true, 'msg' => 'Score schema OK (integer policy).'];
    }

    // Need DECIMAL/NUMERIC with scale >= SCORE_DECIMALS
    if (in_array($dt, ['decimal', 'numeric'], true) && $scale !== null && $scale >= SCORE_DECIMALS) {
        return ['ok' => true, 'msg' => 'Score schema OK (decimal supported).'];
    }

    $target = (SCORE_DECIMALS === 1) ? 'DECIMAL(4,1)' : 'DECIMAL(5,2)';
    $sql = "ALTER TABLE results\n"
         . "  MODIFY COLUMN score {$target} NOT NULL,\n"
         . "  DROP CHECK chk_results_score,\n"
         . "  ADD CONSTRAINT chk_results_score CHECK (score >= 0 AND score <= 40);";

    return [
        'ok' => false,
        'msg' => "Your results.score is currently {$row['COLUMN_TYPE']} (not compatible with decimal scores).\nRun this SQL first:\n\n{$sql}"
    ];
}

$flash = null;
$preview = null;
$ctx = $_SESSION[IMPORT_SESSION_KEY] ?? null;

// Schema check (always)
$schemaCheck = check_results_score_schema($pdo);
if (!$schemaCheck['ok']) {
    $flash = ['type' => 'danger', 'msg' => 'Schema mismatch: decimal scores are enabled but DB score column is not DECIMAL. See details below.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if (!$schemaCheck['ok'] && $action === 'import') {
        // Hard block DB writes if schema is wrong
        $flash = ['type' => 'danger', 'msg' => 'Import blocked: update the DB schema for decimal scores first.'];
        $action = ''; // stop
    }

    if ($action === 'preview') {
        if (empty($_FILES['file']) || !is_array($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
            $flash = ['type' => 'danger', 'msg' => 'Please choose a file to upload.'];
        } else {
            $f = $_FILES['file'];

            if (!is_uploaded_file((string)$f['tmp_name'])) {
                $flash = ['type' => 'danger', 'msg' => 'Upload failed (not a valid uploaded file).'];
            } elseif (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $flash = ['type' => 'danger', 'msg' => 'Upload failed (PHP error code: ' . (int)$f['error'] . ').'];
            } elseif ((int)($f['size'] ?? 0) > MAX_UPLOAD_BYTES) {
                $flash = ['type' => 'danger', 'msg' => 'File is too large (max 20 MB).'];
            } elseif (!is_allowed_upload((string)$f['name'], (string)$f['tmp_name'])) {
                $flash = ['type' => 'danger', 'msg' => 'Invalid file type. Upload CSV/XLSX.'];
            } else {
                try {
                    $dir = ensure_import_dir_and_cleanup();
                    $token = bin2hex(random_bytes(16));
                    $ext = mb_strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                    $dest = $dir . '/results_' . $token . '.' . $ext;

                    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
                        $flash = ['type' => 'danger', 'msg' => 'Upload failed while moving the file to the server directory.'];
                    } else {
                        try {
                            [, $rows] = read_assoc_rows($dest);
                            [$clean, $errors] = validate_rows($rows);

                            $preview = [
                                'file'        => basename($dest),
                                'rows_total'  => count($rows),
                                'valid_total' => count($clean),
                                'errors_total'=> count($errors),
                                'errors'      => array_slice($errors, 0, 25),
                                'sample'      => array_slice($clean, 0, PREVIEW_LIMIT),
                            ];

                            $_SESSION[IMPORT_SESSION_KEY] = [
                                'token'   => $token,
                                'path'    => $dest,
                                'created' => time(),
                                'name'    => (string)$f['name'],
                                'size'    => (int)$f['size'],
                            ];
                            $ctx = $_SESSION[IMPORT_SESSION_KEY];

                            if ($preview['errors_total'] > 0) {
                                $flash = ['type' => 'warning', 'msg' => 'Preview loaded, but validation errors were found. Fix them before importing.'];
                            } else {
                                $flash = ['type' => 'success', 'msg' => 'Preview loaded successfully. Ready to import.' . ($schemaCheck['ok'] ? '' : ' (DB schema needs update for decimals)')];
                            }
                        } catch (Throwable $e) {
                            @unlink($dest);
                            error_log('Results import preview read error: ' . $e->getMessage());
                            $flash = ['type' => 'danger', 'msg' => 'Could not read spreadsheet. Please verify the file format (see server logs for details).'];
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Results import preview setup error: ' . $e->getMessage());
                    $flash = ['type' => 'danger', 'msg' => 'Server could not prepare import workspace.'];
                }
            }
        }
    }

    if ($action === 'import') {
        $token = (string)($_POST['token'] ?? '');

        if (!is_array($ctx) || empty($ctx['token']) || !hash_equals((string)$ctx['token'], $token) || empty($ctx['path'])) {
            $flash = ['type' => 'danger', 'msg' => 'Import session expired. Please preview again.'];
        } else {
            $age = time() - (int)($ctx['created'] ?? 0);
            if ($age > CTX_TTL_SECONDS) {
                $flash = ['type' => 'danger', 'msg' => 'Import session expired (older than 1 hour). Please preview again.'];
            } else {
                $path = (string)$ctx['path'];
                if (!is_file($path)) {
                    $flash = ['type' => 'danger', 'msg' => 'Uploaded file is missing. Please preview again.'];
                } else {
                    $dryRun = !empty($_POST['dry_run']);

                    try {
                        [, $rows] = read_assoc_rows($path);
                        [$clean, $errors] = validate_rows($rows);

                        if ($errors) {
                            $flash = ['type' => 'danger', 'msg' => 'Fix validation errors before importing.'];
                            $preview = [
                                'file'        => basename($path),
                                'rows_total'  => count($rows),
                                'valid_total' => count($clean),
                                'errors_total'=> count($errors),
                                'errors'      => array_slice($errors, 0, 25),
                                'sample'      => array_slice($clean, 0, PREVIEW_LIMIT),
                            ];
                        } else {
                            // ---- Resolve pupils and subjects ----
                            $pupilByLogin = [];
                            $subjectByCode = [];

                            $logins = [];
                            $subCodes = [];
                            $subjectIds = [];

                            foreach ($clean as $r) {
                                if (!empty($r['student_login'])) $logins[$r['student_login']] = true;
                                if (!empty($r['subject_code'])) $subCodes[$r['subject_code']] = true;
                                if (!empty($r['subject_id'])) $subjectIds[(int)$r['subject_id']] = true;
                            }

                            if ($logins) {
                                $vals = array_keys($logins);
                                $in = implode(',', array_fill(0, count($vals), '?'));
                                $st = $pdo->prepare("SELECT id, student_login FROM pupils WHERE student_login IN ($in)");
                                $st->execute($vals);
                                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                                    $pupilByLogin[(string)$rr['student_login']] = (int)$rr['id'];
                                }
                            }

                            if ($subCodes) {
                                $vals = array_keys($subCodes);
                                $in = implode(',', array_fill(0, count($vals), '?'));
                                $st = $pdo->prepare("SELECT id, code FROM subjects WHERE code IN ($in)");
                                $st->execute($vals);
                                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                                    $subjectByCode[(string)$rr['code']] = (int)$rr['id'];
                                }
                            }

                            if ($subjectIds) {
                                $vals = array_values($subjectIds);
                                $in = implode(',', array_fill(0, count($vals), '?'));
                                $st = $pdo->prepare("SELECT id FROM subjects WHERE id IN ($in)");
                                $st->execute($vals);
                                $ok = [];
                                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) $ok[(int)$rr['id']] = true;
                                foreach ($vals as $id) {
                                    if (!isset($ok[$id])) {
                                        $errors[] = ['row' => 0, 'errors' => ["Unknown subject_id: {$id}"], 'data' => []];
                                    }
                                }
                            }

                            // ---- Resolve rows into IDs ----
                            $resolved = [];
                            foreach ($clean as $r) {
                                $rowNum = (int)$r['__row'];

                                $pid = $r['pupil_id'];
                                if (!$pid && $r['student_login']) {
                                    $pid = $pupilByLogin[$r['student_login']] ?? null;
                                }
                                if (!$pid) {
                                    $errors[] = ['row' => $rowNum, 'errors' => ['Pupil not found (by pupil_id/student_login)'], 'data' => $r];
                                    continue;
                                }

                                $sid = $r['subject_id'];
                                if (!$sid && $r['subject_code']) {
                                    $sid = $subjectByCode[$r['subject_code']] ?? null;
                                }
                                if (!$sid) {
                                    $errors[] = ['row' => $rowNum, 'errors' => ['Subject not found (by subject_id/subject_code)'], 'data' => $r];
                                    continue;
                                }

                                $ekey = $r['academic_year'] . '|' . (string)($r['term'] ?? '') . '|' . $r['exam_name'] . '|' . (string)($r['exam_date'] ?? '');
                                $resolved[] = [
                                    'row'          => $rowNum,
                                    'pupil_id'     => (int)$pid,
                                    'subject_id'   => (int)$sid,
                                    'academic_year'=> $r['academic_year'],
                                    'term'         => $r['term'],
                                    'exam_name'    => $r['exam_name'],
                                    'exam_date'    => $r['exam_date'],
                                    'exam_key'     => $ekey,
                                    'score'        => (float)$r['score'],
                                ];
                            }

                            if ($errors) {
                                $flash = ['type' => 'danger', 'msg' => 'Some rows could not be resolved. Fix and retry.'];
                                $preview = [
                                    'file'        => basename($path),
                                    'rows_total'  => count($rows),
                                    'valid_total' => count($clean),
                                    'errors_total'=> count($errors),
                                    'errors'      => array_slice($errors, 0, 25),
                                    'sample'      => array_slice($clean, 0, PREVIEW_LIMIT),
                                ];
                            } else {
                                // ---- Resolve exams + upsert results in a transaction ----
                                $pdo->beginTransaction();

                                $examCache = [];

                                $findExam = $pdo->prepare("
                                    SELECT id FROM exams
                                    WHERE academic_year = :academic_year
                                      AND (term <=> :term)
                                      AND exam_name = :exam_name
                                      AND (exam_date <=> :exam_date)
                                    LIMIT 1
                                ");
                                $insExam = $pdo->prepare("
                                    INSERT INTO exams (academic_year, term, exam_name, exam_date)
                                    VALUES (:academic_year, :term, :exam_name, :exam_date)
                                ");

                                foreach ($resolved as &$rr) {
                                    $k = $rr['exam_key'];
                                    if (isset($examCache[$k])) {
                                        $rr['exam_id'] = $examCache[$k];
                                        continue;
                                    }

                                    $findExam->execute([
                                        'academic_year' => $rr['academic_year'],
                                        'term' => $rr['term'],
                                        'exam_name' => $rr['exam_name'],
                                        'exam_date' => $rr['exam_date'],
                                    ]);

                                    $eid = (int)($findExam->fetchColumn() ?: 0);
                                    if ($eid <= 0) {
                                        $insExam->execute([
                                            'academic_year' => $rr['academic_year'],
                                            'term' => $rr['term'],
                                            'exam_name' => $rr['exam_name'],
                                            'exam_date' => $rr['exam_date'],
                                        ]);
                                        $eid = (int)$pdo->lastInsertId();
                                    }

                                    $examCache[$k] = $eid;
                                    $rr['exam_id'] = $eid;
                                }
                                unset($rr);

                                // Count insert vs update
                                $check = $pdo->prepare("
                                    SELECT id FROM results
                                    WHERE pupil_id = :pupil_id AND subject_id = :subject_id AND exam_id = :exam_id
                                    LIMIT 1
                                ");

                                $toInsert = 0;
                                $toUpdate = 0;
                                foreach ($resolved as $rr) {
                                    $check->execute([
                                        'pupil_id' => $rr['pupil_id'],
                                        'subject_id' => $rr['subject_id'],
                                        'exam_id' => $rr['exam_id'],
                                    ]);
                                    $exists = (int)($check->fetchColumn() ?: 0);
                                    if ($exists > 0) $toUpdate++;
                                    else $toInsert++;
                                }

                                if ($dryRun) {
                                    $pdo->rollBack();
                                    $flash = ['type' => 'info', 'msg' => "Dry run OK. Would insert {$toInsert}, update {$toUpdate}. (Exams may be created during real import.)"];
                                } else {
                                    // Upsert results (MySQL 8.0+; avoid deprecated VALUES())
                                    $up = $pdo->prepare("
                                        INSERT INTO results (pupil_id, subject_id, exam_id, score)
                                        VALUES (:pupil_id, :subject_id, :exam_id, :score)
                                        AS new
                                        ON DUPLICATE KEY UPDATE
                                            score = new.score
                                    ");

                                    foreach ($resolved as $rr) {
                                        // IMPORTANT: store with required precision
                                        $scoreForDb = number_format((float)$rr['score'], SCORE_DECIMALS, '.', '');
                                        $up->execute([
                                            'pupil_id' => $rr['pupil_id'],
                                            'subject_id' => $rr['subject_id'],
                                            'exam_id' => $rr['exam_id'],
                                            'score' => $scoreForDb,
                                        ]);
                                    }

                                    $pdo->commit();

                                    @unlink($path);
                                    unset($_SESSION[IMPORT_SESSION_KEY]);

                                    $flash = ['type' => 'success', 'msg' => "Imported successfully. Inserted {$toInsert}, updated {$toUpdate}."];
                                }

                                $preview = [
                                    'file'        => basename($path),
                                    'rows_total'  => count($rows),
                                    'valid_total' => count($clean),
                                    'errors_total'=> 0,
                                    'errors'      => [],
                                    'sample'      => array_slice($clean, 0, PREVIEW_LIMIT),
                                    'would_insert'=> $toInsert,
                                    'would_update'=> $toUpdate,
                                ];
                            }
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('Results import failed: ' . $e->getMessage());
                        $flash = ['type' => 'danger', 'msg' => 'Import failed due to a server error. Check server logs for details.'];
                    }
                }
            }
        }
    }

    if ($action === 'reset_ctx') {
        if (is_array($ctx) && !empty($ctx['path']) && is_string($ctx['path']) && is_file($ctx['path'])) {
            @unlink((string)$ctx['path']);
        }
        unset($_SESSION[IMPORT_SESSION_KEY]);
        $ctx = null;
        $preview = null;
        $flash = ['type' => 'secondary', 'msg' => 'Import context cleared.'];
    }
}

$csrf = csrf_token();

$ctxBadge = null;
if (is_array($ctx) && !empty($ctx['token'])) {
    $age = time() - (int)($ctx['created'] ?? 0);
    $expiresIn = CTX_TTL_SECONDS - $age;
    $ctxBadge = [
        'age' => $age,
        'expires_in' => $expiresIn,
        'file' => basename((string)($ctx['path'] ?? '')),
        'name' => (string)($ctx['name'] ?? ''),
        'size' => (int)($ctx['size'] ?? 0),
    ];
}
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
          <div>
            <h5 class="card-title mb-1"><i class="bi bi-clipboard-check me-2"></i>Results Import (Decimals)</h5>
            <div class="text-muted small">Upload → Preview/Validate → Resolve IDs → Import (Upsert)</div>
            <div class="small text-muted mt-1">
              Score policy: <span class="badge text-bg-light border">0–40</span>
              <span class="badge text-bg-light border"><?= (int)SCORE_DECIMALS ?> decimal(s)</span>
            </div>
          </div>

          <?php if ($ctxBadge): ?>
            <div class="text-end">
              <span class="badge text-bg-light border">Active context</span>
              <div class="small text-muted mt-1">
                Age: <?= h(format_age((int)$ctxBadge['age'])) ?> · Expires in: <?= h(format_age((int)$ctxBadge['expires_in'])) ?>
              </div>
            </div>
          <?php else: ?>
            <span class="badge text-bg-secondary">No active context</span>
          <?php endif; ?>
        </div>

        <?php if ($flash): ?>
          <div class="alert alert-<?= h($flash['type']) ?> mb-3"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <?php if (!$schemaCheck['ok']): ?>
          <div class="alert alert-danger">
            <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>DB schema update required</div>
            <div class="small text-muted mb-2">Decimal score imports are enabled, but <code>results.score</code> is not DECIMAL.</div>
            <pre class="mb-0" style="white-space:pre-wrap"><?= h($schemaCheck['msg']) ?></pre>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="preview">

          <div>
            <label class="form-label">Upload CSV/XLSX (long format)</label>
            <input type="file" name="file" class="form-control" accept=".csv,.xlsx,.xls" required>
            <div class="form-text">
              Required per row:
              <code>student_login</code> (or pupil_id),
              <code>subject_code</code> (or subject_id),
              <code>academic_year</code> (YYYY/YYYY),
              <code>exam_name</code>,
              <code>score</code> (0..40, decimals allowed).
              Optional: <code>term</code>, <code>exam_date</code>.
            </div>
          </div>

          <button class="btn btn-primary">
            <i class="bi bi-eye me-1"></i> Preview & Validate
          </button>
        </form>

        <hr>

        <form method="post" class="vstack gap-2">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="token" value="<?= h($ctxBadge ? (string)($ctx['token'] ?? '') : '') ?>">

          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="dryRunR" name="dry_run" checked>
            <label class="form-check-label" for="dryRunR">
              Dry run (do not write to DB)
            </label>
          </div>

          <button class="btn btn-success" <?= ($ctxBadge && $schemaCheck['ok']) ? '' : 'disabled' ?>>
            <i class="bi bi-database-add me-1"></i> Run Import
          </button>

          <div class="small text-muted">
            Upsert key: <code>(pupil_id, subject_id, exam_id)</code>. Exams are auto-created if not found.
          </div>
        </form>

        <form method="post" class="mt-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="reset_ctx">
          <button class="btn btn-outline-secondary btn-sm" <?= $ctxBadge ? '' : 'disabled' ?>>
            <i class="bi bi-trash3 me-1"></i> Clear import context
          </button>
        </form>

        <div class="mt-3 small text-muted">
          Storage path: <code><?= h(TMP_IMPORT_DIR) ?></code>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h6 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Preview</h6>

          <?php if ($preview): ?>
            <div class="d-flex flex-wrap gap-2">
              <span class="badge text-bg-secondary">Rows: <?= (int)$preview['rows_total'] ?></span>
              <span class="badge text-bg-success">Valid: <?= (int)$preview['valid_total'] ?></span>
              <span class="badge text-bg-danger">Errors: <?= (int)$preview['errors_total'] ?></span>
              <span class="badge text-bg-light border">File: <?= h((string)$preview['file']) ?></span>
              <?php if (isset($preview['would_insert'], $preview['would_update'])): ?>
                <span class="badge text-bg-light border">Insert: <?= (int)$preview['would_insert'] ?></span>
                <span class="badge text-bg-light border">Update: <?= (int)$preview['would_update'] ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$preview): ?>
          <div class="text-muted">Upload a file to see preview and validation results.</div>
        <?php else: ?>

          <?php if (!empty($preview['errors'])): ?>
            <div class="alert alert-danger">
              <div class="fw-semibold mb-2">Validation errors (showing first <?= (int)count($preview['errors']) ?>):</div>
              <ul class="mb-0">
                <?php foreach ($preview['errors'] as $e): ?>
                  <li class="mb-1">
                    <span class="fw-semibold">Row <?= (int)$e['row'] ?>:</span>
                    <?= h(implode('; ', (array)$e['errors'])) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if (!empty($preview['sample'])): ?>
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle text-center">
                <thead class="table-light">
                  <tr>
                    <th class="text-center">Row</th>
                    <th class="text-center">Pupil</th>
                    <th class="text-center">Subject</th>
                    <th class="text-center">Year</th>
                    <th class="text-center">Term</th>
                    <th class="text-start">Exam</th>
                    <th class="text-center">Date</th>
                    <th class="text-end">Score</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['sample'] as $r): ?>
                    <tr>
                      <td class="text-center"><span class="badge text-bg-light border"><?= (int)$r['__row'] ?></span></td>
                      <td class="text-center"><?= h((string)($r['pupil_id'] ?? $r['student_login'] ?? '')) ?></td>
                      <td class="text-center"><?= h((string)($r['subject_id'] ?? $r['subject_code'] ?? '')) ?></td>
                      <td class="text-center"><?= h((string)$r['academic_year']) ?></td>
                      <td class="text-center"><?= h((string)($r['term'] ?? '')) ?></td>
                      <td class="text-start"><?= h((string)$r['exam_name']) ?></td>
                      <td class="text-center"><?= h((string)($r['exam_date'] ?? '')) ?></td>
                      <td class="text-end fw-semibold"><?= h(number_format((float)$r['score'], SCORE_DECIMALS, '.', '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="small text-muted mt-2">
              Showing first <?= (int)count($preview['sample']) ?> valid rows (limit <?= (int)PREVIEW_LIMIT ?>).
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
