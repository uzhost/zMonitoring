<?php
// admin/otm_results.php - Enter OTM results (2 majors + 3 mandatory) per pupil attempt

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_admin();

$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);
const OTMR_DRAFT_SESSION_KEY = 'otm_results_form_draft_v1';

function otm_flash(string $type, string $msg): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function otm_redirect(array $q = []): void
{
    $url = '/admin/otm_results.php';
    if ($q) $url .= '?' . http_build_query($q);
    header('Location: ' . $url);
    exit;
}

function otm_get_str(string $key, int $maxLen = 120): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function otm_get_int(string $key, int $min = 0, int $max = PHP_INT_MAX): int
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    $n = (int)$v;
    if ($n < $min || $n > $max) return 0;
    return $n;
}

function otm_get_kind(string $key = 'otm_kind'): string
{
    $v = strtolower(otm_get_str($key, 20));
    return in_array($v, ['mock', 'repetition'], true) ? $v : 'mock';
}

function otm_valid_date(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

function otm_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = :t
        LIMIT 1
    ");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function otm_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :t
          AND column_name = :c
        LIMIT 1
    ");
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function otm_parse_optional_uint(mixed $raw, int $min, int $max): array
{
    $s = is_string($raw) ? trim($raw) : '';
    if ($s === '') return ['blank' => true, 'value' => null, 'error' => null];
    if (!preg_match('/^\d+$/', $s)) {
        return ['blank' => false, 'value' => null, 'error' => "must be an integer {$min}..{$max}"];
    }
    $v = (int)$s;
    if ($v < $min || $v > $max) {
        return ['blank' => false, 'value' => null, 'error' => "must be between {$min} and {$max}"];
    }
    return ['blank' => false, 'value' => $v, 'error' => null];
}

function otm_parse_optional_percent(mixed $raw): array
{
    $s = is_string($raw) ? trim(str_replace(',', '.', $raw)) : '';
    if ($s === '') return ['blank' => true, 'value' => null, 'error' => null];
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $s)) {
        return ['blank' => false, 'value' => null, 'error' => 'must be a number 0..100 (max 2 decimals)'];
    }
    $v = (float)$s;
    if ($v < 0 || $v > 100) {
        return ['blank' => false, 'value' => null, 'error' => 'must be between 0 and 100'];
    }
    return ['blank' => false, 'value' => number_format($v, 2, '.', ''), 'error' => null];
}

function otm_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}

function otm_major_pair_code(?string $major1Name, ?string $major2Name): string
{
    $code = otm_initial((string)$major1Name) . otm_initial((string)$major2Name);
    return $code !== '' ? $code : '—';
}

function otm_keep_query(
    string $classCode,
    int $otmExamId,
    int $studyYearId,
    string $kind,
    string $examTitle,
    string $examDate,
    int $attemptNo,
    string $q
): array {
    return [
        'class_code' => $classCode,
        'otm_exam_id' => $otmExamId > 0 ? $otmExamId : '',
        'study_year_id' => $studyYearId > 0 ? $studyYearId : '',
        'otm_kind' => $kind,
        'exam_title' => $examTitle,
        'exam_date' => $examDate,
        'attempt_no' => $attemptNo > 0 ? $attemptNo : 1,
        'q' => $q,
    ];
}

function otm_draft_key(
    string $classCode,
    int $studyYearId,
    string $kind,
    string $examTitle,
    string $examDate,
    int $attemptNo,
    string $q
): string {
    return implode('|', [
        $classCode,
        (string)$studyYearId,
        $kind,
        $examTitle,
        $examDate,
        (string)$attemptNo,
        $q,
    ]);
}

function otm_store_draft_state(string $ctxKey, array $rowValues, array $rowErrors): void
{
    if ($ctxKey === '') return;
    if (!isset($_SESSION[OTMR_DRAFT_SESSION_KEY]) || !is_array($_SESSION[OTMR_DRAFT_SESSION_KEY])) {
        $_SESSION[OTMR_DRAFT_SESSION_KEY] = [];
    }
    $_SESSION[OTMR_DRAFT_SESSION_KEY][$ctxKey] = [
        'ts' => time(),
        'rows' => $rowValues,
        'errors' => $rowErrors,
    ];
}

function otm_take_draft_state(string $ctxKey): array
{
    if ($ctxKey === '') return ['rows' => [], 'errors' => []];
    $all = $_SESSION[OTMR_DRAFT_SESSION_KEY] ?? null;
    if (!is_array($all) || !isset($all[$ctxKey]) || !is_array($all[$ctxKey])) {
        return ['rows' => [], 'errors' => []];
    }
    $state = $all[$ctxKey];
    unset($_SESSION[OTMR_DRAFT_SESSION_KEY][$ctxKey]);
    if (empty($_SESSION[OTMR_DRAFT_SESSION_KEY])) unset($_SESSION[OTMR_DRAFT_SESSION_KEY]);
    return [
        'rows' => (isset($state['rows']) && is_array($state['rows'])) ? $state['rows'] : [],
        'errors' => (isset($state['errors']) && is_array($state['errors'])) ? $state['errors'] : [],
    ];
}

function otm_clear_draft_state(string $ctxKey): void
{
    if ($ctxKey === '') return;
    if (!isset($_SESSION[OTMR_DRAFT_SESSION_KEY]) || !is_array($_SESSION[OTMR_DRAFT_SESSION_KEY])) return;
    unset($_SESSION[OTMR_DRAFT_SESSION_KEY][$ctxKey]);
    if (empty($_SESSION[OTMR_DRAFT_SESSION_KEY])) unset($_SESSION[OTMR_DRAFT_SESSION_KEY]);
}

// ----------------------- State (GET) -----------------------
$classCode = otm_get_str('class_code', 30);
$otmExamId = otm_get_int('otm_exam_id', 1);
$studyYearId = otm_get_int('study_year_id', 1);
$otmKind = otm_get_kind('otm_kind');
$examTitle = otm_get_str('exam_title', 120);
$examDate = otm_get_str('exam_date', 10);
$attemptNo = otm_get_int('attempt_no', 1, 20);
if ($attemptNo <= 0) $attemptNo = 1;
$q = otm_get_str('q', 60);
$loadRequested = isset($_GET['load_grid']) && (string)$_GET['load_grid'] === '1';

// ----------------------- Options -----------------------
$classOptions = [];
$otmSubjectOptions = [];
$otmSubjectMap = [];
$otmExamOptions = [];
$otmExamSelected = null;
$studyYearOptions = [];
$recentSessions = [];
$rows = [];
$draftRowsByPid = [];
$rowErrorsByPid = [];
$enteredCount = 0;
$rowsNoMajors = 0;
$rowsInvalidMajors = 0;

$schemaOk = false;
$schemaVersionOk = false;
$hasOtmResultsExamId = false;
$hasStudyYearTable = false;
$hasOtmMajorTable = false;
$hasOtmSubjectsTable = false;
$hasOtmExamsTable = false;
$hasOtmExamsSchema = false;

try {
    $schemaOk = otm_table_exists($pdo, 'otm_results');
    $hasStudyYearTable = otm_table_exists($pdo, 'study_year');
    $hasOtmMajorTable = otm_table_exists($pdo, 'otm_major');
    $hasOtmSubjectsTable = otm_table_exists($pdo, 'otm_subjects');
    $hasOtmExamsTable = otm_table_exists($pdo, 'otm_exams');

    if ($schemaOk) {
        $hasOtmResultsExamId = otm_column_exists($pdo, 'otm_results', 'otm_exam_id');
        $schemaVersionOk =
            otm_column_exists($pdo, 'otm_results', 'major1_subject_id') &&
            otm_column_exists($pdo, 'otm_results', 'major2_subject_id') &&
            otm_column_exists($pdo, 'otm_results', 'major1_correct') &&
            otm_column_exists($pdo, 'otm_results', 'mandatory_ona_tili_correct') &&
            otm_column_exists($pdo, 'otm_results', 'major1_certificate_percent') &&
            otm_column_exists($pdo, 'otm_results', 'total_score') &&
            otm_column_exists($pdo, 'otm_results', 'total_score_withcert');
    }

    if ($hasOtmExamsTable) {
        $hasOtmExamsSchema =
            otm_column_exists($pdo, 'otm_exams', 'study_year_id') &&
            otm_column_exists($pdo, 'otm_exams', 'otm_kind') &&
            otm_column_exists($pdo, 'otm_exams', 'exam_title') &&
            otm_column_exists($pdo, 'otm_exams', 'exam_date') &&
            otm_column_exists($pdo, 'otm_exams', 'attempt_no') &&
            otm_column_exists($pdo, 'otm_exams', 'is_active');
    }

    $classOptions = $pdo->query("
        SELECT class_code, COUNT(*) AS cnt
        FROM pupils p
        WHERE class_code IS NOT NULL AND class_code <> ''
        GROUP BY class_code
        ORDER BY class_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($hasOtmSubjectsTable) {
        $otmSubjectOptions = $pdo->query("
            SELECT id, code, name, is_active, sort_order
            FROM otm_subjects
            ORDER BY is_active DESC, sort_order ASC, name ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($otmSubjectOptions as $s) {
            $otmSubjectMap[(int)$s['id']] = $s;
        }
    }

    if ($hasStudyYearTable) {
        $studyYearOptions = $pdo->query("
            SELECT id, year_code
            FROM study_year
            ORDER BY year_code DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($hasOtmExamsTable && $hasOtmExamsSchema) {
        $otmExamOptions = $pdo->query("
            SELECT
              e.id,
              e.study_year_id,
              e.otm_kind,
              e.exam_title,
              e.exam_date,
              e.attempt_no,
              e.is_active,
              sy.year_code
            FROM otm_exams e
            LEFT JOIN study_year sy ON sy.id = e.study_year_id
            ORDER BY e.is_active DESC, e.exam_date DESC, e.exam_title ASC, e.attempt_no DESC, e.id DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($otmExamId > 0 && $hasOtmExamsTable && $hasOtmExamsSchema) {
        $stExam = $pdo->prepare("
            SELECT id, study_year_id, otm_kind, exam_title, exam_date, attempt_no, is_active
            FROM otm_exams
            WHERE id = :id
            LIMIT 1
        ");
        $stExam->execute([':id' => $otmExamId]);
        $otmExamSelected = $stExam->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($otmExamSelected) {
            $studyYearId = (int)$otmExamSelected['study_year_id'];
            $otmKind = (string)$otmExamSelected['otm_kind'];
            $examTitle = (string)$otmExamSelected['exam_title'];
            $examDate = (string)$otmExamSelected['exam_date'];
            $attemptNo = (int)$otmExamSelected['attempt_no'];
            if ($attemptNo <= 0) $attemptNo = 1;
        } else {
            $otmExamId = 0;
            otm_flash('warning', 'Selected OTM exam was not found.');
        }
    }

    if ($schemaOk && $schemaVersionOk && $hasStudyYearTable) {
        $recentSql = "
            SELECT
              " . ($hasOtmResultsExamId ? "MAX(r.otm_exam_id)" : "NULL") . " AS otm_exam_id,
              r.otm_kind,
              r.exam_title,
              r.exam_date,
              r.attempt_no,
              r.study_year_id,
              sy.year_code AS study_year_code,
              r.major1_subject_id,
              r.major2_subject_id,
              s1.name AS major1_subject_name,
              s1.code AS major1_subject_code,
              s2.name AS major2_subject_name,
              s2.code AS major2_subject_code,
              COUNT(*) AS n
            FROM otm_results r
            LEFT JOIN study_year sy ON sy.id = r.study_year_id
            INNER JOIN otm_subjects s1 ON s1.id = r.major1_subject_id
            INNER JOIN otm_subjects s2 ON s2.id = r.major2_subject_id
            GROUP BY
              r.otm_kind, r.exam_title, r.exam_date, r.attempt_no,
              r.study_year_id, sy.year_code,
              r.major1_subject_id, r.major2_subject_id,
              s1.name, s1.code, s2.name, s2.code
            ORDER BY r.exam_date DESC, r.exam_title DESC, r.attempt_no DESC
            LIMIT 3
        ";
        $recentSessions = $pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    otm_flash('danger', 'Failed to load OTM results options.');
    error_log('[OTM_RESULTS] options load failed: ' . $e->getMessage());
}

// ----------------------- Save (POST) -----------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf('csrf');

    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    if (!$schemaOk) {
        otm_flash('danger', 'Table otm_results does not exist. Run otm_results.sql first.');
        otm_redirect();
    }
    if (!$schemaVersionOk) {
        otm_flash('danger', 'Table otm_results exists but is not using the new 2-major/3-mandatory schema.');
        otm_redirect();
    }
    if (!$hasStudyYearTable) {
        otm_flash('danger', 'Table study_year does not exist. OTM results require study_year.');
        otm_redirect();
    }
    if (!$hasOtmMajorTable) {
        otm_flash('danger', 'Table otm_major does not exist. Create majors first.');
        otm_redirect();
    }
    if (!$hasOtmSubjectsTable) {
        otm_flash('danger', 'Table otm_subjects does not exist. Create OTM subjects first.');
        otm_redirect();
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'save_scores') {
        otm_flash('danger', 'Unknown action.');
        otm_redirect();
    }

    $classCode = trim((string)($_POST['class_code'] ?? ''));
    $otmExamId = (int)($_POST['otm_exam_id'] ?? 0);
    $studyYearId = (int)($_POST['study_year_id'] ?? 0);
    $otmKind = strtolower(trim((string)($_POST['otm_kind'] ?? 'mock')));
    $examTitle = trim((string)($_POST['exam_title'] ?? ''));
    $examDate = trim((string)($_POST['exam_date'] ?? ''));
    $attemptNo = (int)($_POST['attempt_no'] ?? 1);
    $q = trim((string)($_POST['q'] ?? ''));

    $returnQ = otm_keep_query(
        $classCode,
        $otmExamId,
        $studyYearId,
        $otmKind,
        $examTitle,
        $examDate,
        max(1, $attemptNo),
        $q
    );

    if ($otmExamId > 0) {
        if (!$hasOtmExamsTable || !$hasOtmExamsSchema) {
            otm_flash('danger', 'Selected exam requires table otm_exams, but it is missing or outdated.');
            otm_redirect($returnQ);
        }
        $stExam = $pdo->prepare("
            SELECT id, study_year_id, otm_kind, exam_title, exam_date, attempt_no
            FROM otm_exams
            WHERE id = :id
            LIMIT 1
        ");
        $stExam->execute([':id' => $otmExamId]);
        $examRow = $stExam->fetch(PDO::FETCH_ASSOC);
        if (!$examRow) {
            otm_flash('danger', 'Selected OTM exam was not found.');
            otm_redirect($returnQ);
        }
        $studyYearId = (int)$examRow['study_year_id'];
        $otmKind = (string)$examRow['otm_kind'];
        $examTitle = (string)$examRow['exam_title'];
        $examDate = (string)$examRow['exam_date'];
        $attemptNo = (int)$examRow['attempt_no'];
        if ($attemptNo <= 0) $attemptNo = 1;
        $returnQ = otm_keep_query(
            $classCode,
            $otmExamId,
            $studyYearId,
            $otmKind,
            $examTitle,
            $examDate,
            $attemptNo,
            $q
        );
    }
    $ctxKey = otm_draft_key($classCode, $studyYearId, $otmKind, $examTitle, $examDate, max(1, $attemptNo), $q);

    $errs = [];
    if ($classCode === '') $errs[] = 'Class is required.';
    if ($studyYearId <= 0) $errs[] = 'Study year is required.';
    if ($examTitle === '' || mb_strlen($examTitle, 'UTF-8') > 120) $errs[] = 'Exam title is required (max 120 chars).';
    if (!in_array($otmKind, ['mock', 'repetition'], true)) $errs[] = 'OTM type must be mock or repetition.';
    if ($examDate === '' || !otm_valid_date($examDate)) $errs[] = 'Exam date is required and must be valid (YYYY-MM-DD).';
    if ($attemptNo < 1 || $attemptNo > 20) $errs[] = 'Attempt number must be between 1 and 20.';

    if ($errs) {
        otm_flash('danger', implode(' ', $errs));
        otm_redirect($returnQ);
    }

    $resolvedOtmExamId = null;
    if ($hasOtmResultsExamId) {
        if ($otmExamId > 0) {
            $resolvedOtmExamId = $otmExamId;
        } elseif ($hasOtmExamsTable && $hasOtmExamsSchema) {
            // Best-effort auto-link when manual exam fields match an otm_exams row.
            $stFindExam = $pdo->prepare("
                SELECT id
                FROM otm_exams
                WHERE study_year_id = :study_year_id
                  AND otm_kind = :otm_kind
                  AND exam_title = :exam_title
                  AND exam_date = :exam_date
                  AND attempt_no = :attempt_no
                ORDER BY id DESC
                LIMIT 1
            ");
            $stFindExam->execute([
                ':study_year_id' => $studyYearId,
                ':otm_kind' => $otmKind,
                ':exam_title' => $examTitle,
                ':exam_date' => $examDate,
                ':attempt_no' => $attemptNo,
            ]);
            $foundExamId = (int)$stFindExam->fetchColumn();
            $resolvedOtmExamId = $foundExamId > 0 ? $foundExamId : null;
        }
    }

    $stSy = $pdo->prepare('SELECT id FROM study_year WHERE id = :id LIMIT 1');
    $stSy->execute([':id' => $studyYearId]);
    if (!(int)$stSy->fetchColumn()) {
        otm_flash('danger', 'Selected study year not found.');
        otm_redirect($returnQ);
    }

    $pupilSql = "
        SELECT
            p.id,
            om.major1_subject_id AS otm_major1_subject_id,
            om.major2_subject_id AS otm_major2_subject_id
        FROM pupils p
        LEFT JOIN otm_major om
          ON om.pupil_id = p.id
         AND om.is_active = 1
        WHERE p.class_code = :class_code
    ";
    $pupilParams = [':class_code' => $classCode];
    if ($q !== '') {
        $pupilSql .= " AND (p.surname LIKE :q OR p.name LIKE :q)";
        $pupilParams[':q'] = '%' . $q . '%';
    }
    $pupilSql .= " ORDER BY p.surname ASC, p.name ASC, p.id ASC";

    $stP = $pdo->prepare($pupilSql);
    $stP->execute($pupilParams);
    $pupilMajorRows = $stP->fetchAll(PDO::FETCH_ASSOC);
    if (!$pupilMajorRows) {
        otm_flash('warning', 'No pupils found for the selected class/filter.');
        otm_redirect($returnQ);
    }
    $pupilIds = [];
    $pupilMajors = [];
    foreach ($pupilMajorRows as $row) {
        $pid = (int)$row['id'];
        $pupilIds[] = $pid;
        $pupilMajors[$pid] = [
            'otm_major1_subject_id' => isset($row['otm_major1_subject_id']) ? (int)$row['otm_major1_subject_id'] : 0,
            'otm_major2_subject_id' => isset($row['otm_major2_subject_id']) ? (int)$row['otm_major2_subject_id'] : 0,
        ];
    }

    $postedRows = $_POST['rows'] ?? [];
    if (!is_array($postedRows)) $postedRows = [];

    $insertExamCol = $hasOtmResultsExamId ? " otm_exam_id," : "";
    $insertExamVal = $hasOtmResultsExamId ? " :otm_exam_id," : "";
    $updateExamSet = $hasOtmResultsExamId ? "\n            otm_exam_id = new.otm_exam_id," : "";
    $up = $pdo->prepare("
        INSERT INTO otm_results (
            pupil_id, study_year_id, otm_kind, exam_title, exam_date, attempt_no,{$insertExamCol}
            major1_subject_id, major2_subject_id,
            major1_correct, major2_correct,
            mandatory_ona_tili_correct, mandatory_matematika_correct, mandatory_uzb_tarix_correct,
            major1_certificate_percent, major2_certificate_percent,
            mandatory_ona_tili_certificate_percent, mandatory_matematika_certificate_percent, mandatory_uzb_tarix_certificate_percent,
            created_by, updated_by
        )
        VALUES (
            :pupil_id, :study_year_id, :otm_kind, :exam_title, :exam_date, :attempt_no,{$insertExamVal}
            :major1_subject_id, :major2_subject_id,
            :major1_correct, :major2_correct,
            :mandatory_ona_tili_correct, :mandatory_matematika_correct, :mandatory_uzb_tarix_correct,
            :major1_certificate_percent, :major2_certificate_percent,
            :mandatory_ona_tili_certificate_percent, :mandatory_matematika_certificate_percent, :mandatory_uzb_tarix_certificate_percent,
            :created_by, :updated_by
        ) AS new
        ON DUPLICATE KEY UPDATE{$updateExamSet}
            study_year_id = new.study_year_id,
            major1_subject_id = new.major1_subject_id,
            major2_subject_id = new.major2_subject_id,
            major1_correct = new.major1_correct,
            major2_correct = new.major2_correct,
            mandatory_ona_tili_correct = new.mandatory_ona_tili_correct,
            mandatory_matematika_correct = new.mandatory_matematika_correct,
            mandatory_uzb_tarix_correct = new.mandatory_uzb_tarix_correct,
            major1_certificate_percent = new.major1_certificate_percent,
            major2_certificate_percent = new.major2_certificate_percent,
            mandatory_ona_tili_certificate_percent = new.mandatory_ona_tili_certificate_percent,
            mandatory_matematika_certificate_percent = new.mandatory_matematika_certificate_percent,
            mandatory_uzb_tarix_certificate_percent = new.mandatory_uzb_tarix_certificate_percent,
            updated_by = new.updated_by
    ");

    $saved = 0;
    $skippedBlank = 0;
    $skippedNoMajors = 0;
    $skippedInvalidMajors = 0;
    $errorRows = 0;
    $rowErrorsByPidSave = [];
    $draftRowsByPidSave = [];
    $adminId = admin_id();

    foreach ($pupilIds as $pid) {
        $r = $postedRows[(string)$pid] ?? ($postedRows[$pid] ?? []);
        if (!is_array($r)) $r = [];

        $pm = $pupilMajors[$pid] ?? null;
        $otmMajor1Id = (int)($pm['otm_major1_subject_id'] ?? 0);
        $otmMajor2Id = (int)($pm['otm_major2_subject_id'] ?? 0);

        $m1c = otm_parse_optional_uint($r['major1_correct'] ?? '', 0, 30);
        $m2c = otm_parse_optional_uint($r['major2_correct'] ?? '', 0, 30);
        $otc = otm_parse_optional_uint($r['mandatory_ona_tili_correct'] ?? '', 0, 10);
        $mmc = otm_parse_optional_uint($r['mandatory_matematika_correct'] ?? '', 0, 10);
        $mhc = otm_parse_optional_uint($r['mandatory_uzb_tarix_correct'] ?? '', 0, 10);

        $m1p = otm_parse_optional_percent($r['major1_certificate_percent'] ?? '');
        $m2p = otm_parse_optional_percent($r['major2_certificate_percent'] ?? '');
        $otp = otm_parse_optional_percent($r['mandatory_ona_tili_certificate_percent'] ?? '');
        $mmp = otm_parse_optional_percent($r['mandatory_matematika_certificate_percent'] ?? '');
        $mhp = otm_parse_optional_percent($r['mandatory_uzb_tarix_certificate_percent'] ?? '');

        $fieldErrs = [];
        if ($m1c['error'] !== null) $fieldErrs['major1_correct'] = $m1c['error'];
        if ($m2c['error'] !== null) $fieldErrs['major2_correct'] = $m2c['error'];
        if ($otc['error'] !== null) $fieldErrs['mandatory_ona_tili_correct'] = $otc['error'];
        if ($mmc['error'] !== null) $fieldErrs['mandatory_matematika_correct'] = $mmc['error'];
        if ($mhc['error'] !== null) $fieldErrs['mandatory_uzb_tarix_correct'] = $mhc['error'];
        if ($m1p['error'] !== null) $fieldErrs['major1_certificate_percent'] = $m1p['error'];
        if ($m2p['error'] !== null) $fieldErrs['major2_certificate_percent'] = $m2p['error'];
        if ($otp['error'] !== null) $fieldErrs['mandatory_ona_tili_certificate_percent'] = $otp['error'];
        if ($mmp['error'] !== null) $fieldErrs['mandatory_matematika_certificate_percent'] = $mmp['error'];
        if ($mhp['error'] !== null) $fieldErrs['mandatory_uzb_tarix_certificate_percent'] = $mhp['error'];

        $allBlank =
            $m1c['blank'] && $m2c['blank'] && $otc['blank'] && $mmc['blank'] && $mhc['blank'] &&
            $m1p['blank'] && $m2p['blank'] && $otp['blank'] && $mmp['blank'] && $mhp['blank'];

        if ($allBlank) {
            $skippedBlank++;
            continue;
        }

        if ($otmMajor1Id <= 0 || $otmMajor2Id <= 0) {
            $skippedNoMajors++;
            continue;
        }
        if ($otmMajor1Id === $otmMajor2Id) {
            $skippedInvalidMajors++;
            $fieldErrs['_major'] = 'Major 1 and Major 2 in otm_major must be different.';
        }

        if ($fieldErrs) {
            $errorRows++;
            $rowErrorsByPidSave[$pid] = $fieldErrs;
            $draftRowsByPidSave[$pid] = [
                'major1_correct' => (string)($r['major1_correct'] ?? ''),
                'major2_correct' => (string)($r['major2_correct'] ?? ''),
                'mandatory_ona_tili_correct' => (string)($r['mandatory_ona_tili_correct'] ?? ''),
                'mandatory_matematika_correct' => (string)($r['mandatory_matematika_correct'] ?? ''),
                'mandatory_uzb_tarix_correct' => (string)($r['mandatory_uzb_tarix_correct'] ?? ''),
                'major1_certificate_percent' => (string)($r['major1_certificate_percent'] ?? ''),
                'major2_certificate_percent' => (string)($r['major2_certificate_percent'] ?? ''),
                'mandatory_ona_tili_certificate_percent' => (string)($r['mandatory_ona_tili_certificate_percent'] ?? ''),
                'mandatory_matematika_certificate_percent' => (string)($r['mandatory_matematika_certificate_percent'] ?? ''),
                'mandatory_uzb_tarix_certificate_percent' => (string)($r['mandatory_uzb_tarix_certificate_percent'] ?? ''),
            ];
            continue;
        }

        try {
            $paramsUp = [
                ':pupil_id' => $pid,
                ':study_year_id' => $studyYearId,
                ':otm_kind' => $otmKind,
                ':exam_title' => $examTitle,
                ':exam_date' => $examDate,
                ':attempt_no' => $attemptNo,
                ':major1_subject_id' => $otmMajor1Id,
                ':major2_subject_id' => $otmMajor2Id,
                ':major1_correct' => ($m1c['value'] ?? 0),
                ':major2_correct' => ($m2c['value'] ?? 0),
                ':mandatory_ona_tili_correct' => ($otc['value'] ?? 0),
                ':mandatory_matematika_correct' => ($mmc['value'] ?? 0),
                ':mandatory_uzb_tarix_correct' => ($mhc['value'] ?? 0),
                ':major1_certificate_percent' => $m1p['value'],
                ':major2_certificate_percent' => $m2p['value'],
                ':mandatory_ona_tili_certificate_percent' => $otp['value'],
                ':mandatory_matematika_certificate_percent' => $mmp['value'],
                ':mandatory_uzb_tarix_certificate_percent' => $mhp['value'],
                ':created_by' => ($adminId > 0 ? $adminId : null),
                ':updated_by' => ($adminId > 0 ? $adminId : null),
            ];
            if ($hasOtmResultsExamId) {
                $paramsUp[':otm_exam_id'] = $resolvedOtmExamId;
            }
            $up->execute($paramsUp);
            $saved++;
        } catch (Throwable $e) {
            $errorRows++;
            $rowErrorsByPidSave[$pid] = ['_row' => 'Save failed. Check data/schema.'];
            $draftRowsByPidSave[$pid] = [
                'major1_correct' => (string)($r['major1_correct'] ?? ''),
                'major2_correct' => (string)($r['major2_correct'] ?? ''),
                'mandatory_ona_tili_correct' => (string)($r['mandatory_ona_tili_correct'] ?? ''),
                'mandatory_matematika_correct' => (string)($r['mandatory_matematika_correct'] ?? ''),
                'mandatory_uzb_tarix_correct' => (string)($r['mandatory_uzb_tarix_correct'] ?? ''),
                'major1_certificate_percent' => (string)($r['major1_certificate_percent'] ?? ''),
                'major2_certificate_percent' => (string)($r['major2_certificate_percent'] ?? ''),
                'mandatory_ona_tili_certificate_percent' => (string)($r['mandatory_ona_tili_certificate_percent'] ?? ''),
                'mandatory_matematika_certificate_percent' => (string)($r['mandatory_matematika_certificate_percent'] ?? ''),
                'mandatory_uzb_tarix_certificate_percent' => (string)($r['mandatory_uzb_tarix_certificate_percent'] ?? ''),
            ];
            error_log('[OTM_RESULTS] row save failed for pupil ' . $pid . ': ' . $e->getMessage());
        }
    }

    if ($errorRows > 0) {
        otm_store_draft_state($ctxKey, $draftRowsByPidSave, $rowErrorsByPidSave);
        $flashType = $saved > 0 ? 'warning' : 'danger';
        otm_flash(
            $flashType,
            "Saved: {$saved}. Skipped (blank): {$skippedBlank}. Skipped (no majors): {$skippedNoMajors}. Invalid majors: {$skippedInvalidMajors}. Rows with errors: {$errorRows}. Fix highlighted fields and save again."
        );
        otm_redirect($returnQ);
    }

    otm_clear_draft_state($ctxKey);
    otm_flash(
        'success',
        "OTM results saved: {$saved}. Skipped (blank): {$skippedBlank}. Skipped (no majors): {$skippedNoMajors}. Invalid majors: {$skippedInvalidMajors}."
    );
    otm_redirect($returnQ);
}

// ----------------------- Load rows for entry grid -----------------------
$loadContextReady = (
    $schemaOk &&
    $schemaVersionOk &&
    $hasStudyYearTable &&
    $hasOtmMajorTable &&
    $hasOtmSubjectsTable &&
    $classCode !== '' &&
    $studyYearId > 0 &&
    $examTitle !== '' &&
    $examDate !== '' &&
    otm_valid_date($examDate)
);

if ($loadContextReady) {
    try {
        $ctxKey = otm_draft_key($classCode, $studyYearId, $otmKind, $examTitle, $examDate, $attemptNo, $q);
        $draftState = otm_take_draft_state($ctxKey);
        $draftRowsByPid = is_array($draftState['rows'] ?? null) ? $draftState['rows'] : [];
        $rowErrorsByPid = is_array($draftState['errors'] ?? null) ? $draftState['errors'] : [];

        $sql = "
            SELECT
              p.id,
              p.surname,
              p.name,
              om.id AS otm_major_row_id,
              om.major1_subject_id AS otm_major1_subject_id,
              om.major2_subject_id AS otm_major2_subject_id,
              os1.code AS otm_major1_code,
              os1.name AS otm_major1_name,
              os2.code AS otm_major2_code,
              os2.name AS otm_major2_name
            FROM pupils p
            LEFT JOIN otm_major om
              ON om.pupil_id = p.id
             AND om.is_active = 1
            LEFT JOIN otm_subjects os1
              ON os1.id = om.major1_subject_id
            LEFT JOIN otm_subjects os2
              ON os2.id = om.major2_subject_id
            WHERE p.class_code = :class_code
        ";
        $params = [':class_code' => $classCode];
        if ($q !== '') {
            $sql .= " AND (p.surname LIKE :q OR p.name LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= " ORDER BY p.surname ASC, p.name ASC, p.id ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $resSql = "
            SELECT r.*
            FROM otm_results r
            INNER JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code = :class_code
        ";
        $resParams = [
            ':class_code' => $classCode,
        ];
        if ($hasOtmResultsExamId && $otmExamId > 0) {
            $resSql .= " AND (r.otm_exam_id = :otm_exam_id";
            $resSql .= " OR (r.otm_exam_id IS NULL";
            $resSql .= " AND r.study_year_id = :study_year_id";
            $resSql .= " AND r.otm_kind = :otm_kind";
            $resSql .= " AND r.exam_title = :exam_title";
            $resSql .= " AND r.exam_date = :exam_date";
            $resSql .= " AND r.attempt_no = :attempt_no";
            $resSql .= "))";
            $resParams[':otm_exam_id'] = $otmExamId;
            $resParams[':study_year_id'] = $studyYearId;
            $resParams[':otm_kind'] = $otmKind;
            $resParams[':exam_title'] = $examTitle;
            $resParams[':exam_date'] = $examDate;
            $resParams[':attempt_no'] = $attemptNo;
        } else {
            $resSql .= "
              AND r.study_year_id = :study_year_id
              AND r.otm_kind = :otm_kind
              AND r.exam_title = :exam_title
              AND r.exam_date = :exam_date
              AND r.attempt_no = :attempt_no
            ";
            $resParams[':study_year_id'] = $studyYearId;
            $resParams[':otm_kind'] = $otmKind;
            $resParams[':exam_title'] = $examTitle;
            $resParams[':exam_date'] = $examDate;
            $resParams[':attempt_no'] = $attemptNo;
        }
        if ($q !== '') {
            $resSql .= " AND (p.surname LIKE :q OR p.name LIKE :q)";
            $resParams[':q'] = '%' . $q . '%';
        }
        $stRes = $pdo->prepare($resSql);
        $stRes->execute($resParams);
        $existingRows = $stRes->fetchAll(PDO::FETCH_ASSOC);

        $existingIndex = [];
        foreach ($existingRows as $er) {
            $k = (int)$er['pupil_id'] . '|' . (int)$er['major1_subject_id'] . '|' . (int)$er['major2_subject_id'];
            $existingIndex[$k] = $er;
        }

        foreach ($rows as &$row) {
            $otm1 = (int)($row['otm_major1_subject_id'] ?? 0);
            $otm2 = (int)($row['otm_major2_subject_id'] ?? 0);
            $row['_major_pair_code'] = otm_major_pair_code((string)($row['otm_major1_name'] ?? ''), (string)($row['otm_major2_name'] ?? ''));
            $row['_major_title'] = trim((string)($row['otm_major1_name'] ?? '') . (($otm1 > 0 && $otm2 > 0) ? ' / ' : '') . (string)($row['otm_major2_name'] ?? ''));
            $row['_majors_ready'] = false;
            $row['_major_state'] = 'missing';

            if ($otm1 > 0 && $otm2 > 0) {
                if ($otm1 !== $otm2) {
                    $row['_majors_ready'] = true;
                    $row['_major_state'] = 'ok';
                } else {
                    $row['_major_state'] = 'invalid';
                }
            }

            $row['otm_row_id'] = null;
            $row['major1_correct'] = null;
            $row['major2_correct'] = null;
            $row['mandatory_ona_tili_correct'] = null;
            $row['mandatory_matematika_correct'] = null;
            $row['mandatory_uzb_tarix_correct'] = null;
            $row['major1_certificate_percent'] = null;
            $row['major2_certificate_percent'] = null;
            $row['mandatory_ona_tili_certificate_percent'] = null;
            $row['mandatory_matematika_certificate_percent'] = null;
            $row['mandatory_uzb_tarix_certificate_percent'] = null;
            $row['total_score'] = null;
            $row['total_score_withcert'] = null;

            if ($row['_majors_ready']) {
                $key = (int)$row['id'] . '|' . $otm1 . '|' . $otm2;
                if (isset($existingIndex[$key])) {
                    $er = $existingIndex[$key];
                    $row['otm_row_id'] = $er['id'];
                    $row['major1_correct'] = $er['major1_correct'];
                    $row['major2_correct'] = $er['major2_correct'];
                    $row['mandatory_ona_tili_correct'] = $er['mandatory_ona_tili_correct'];
                    $row['mandatory_matematika_correct'] = $er['mandatory_matematika_correct'];
                    $row['mandatory_uzb_tarix_correct'] = $er['mandatory_uzb_tarix_correct'];
                    $row['major1_certificate_percent'] = $er['major1_certificate_percent'];
                    $row['major2_certificate_percent'] = $er['major2_certificate_percent'];
                    $row['mandatory_ona_tili_certificate_percent'] = $er['mandatory_ona_tili_certificate_percent'];
                    $row['mandatory_matematika_certificate_percent'] = $er['mandatory_matematika_certificate_percent'];
                    $row['mandatory_uzb_tarix_certificate_percent'] = $er['mandatory_uzb_tarix_certificate_percent'];
                    $row['total_score'] = $er['total_score'];
                    $row['total_score_withcert'] = $er['total_score_withcert'];
                    $enteredCount++;
                }
            } elseif ($otm1 <= 0 || $otm2 <= 0) {
                $rowsNoMajors++;
            } else {
                $rowsInvalidMajors++;
            }
        }
        unset($row);
    } catch (Throwable $e) {
        $rows = [];
        $enteredCount = 0;
        $rowsNoMajors = 0;
        $rowsInvalidMajors = 0;
        otm_flash('danger', 'Failed to load OTM results grid. Check otm_results/otm_major schema after migration.');
        error_log('[OTM_RESULTS] grid load failed: ' . $e->getMessage());
    }
}

$loadContextIssues = [];

if ($loadRequested && $schemaOk && $schemaVersionOk && $hasStudyYearTable && $hasOtmMajorTable && $hasOtmSubjectsTable && !$loadContextReady) {
    if ($classCode === '') $loadContextIssues[] = 'Class';
    if ($studyYearId <= 0) $loadContextIssues[] = 'Study Year';
    if ($examTitle === '') $loadContextIssues[] = 'Exam Title';
    if ($examDate === '') $loadContextIssues[] = 'Exam Date';
    elseif (!otm_valid_date($examDate)) $loadContextIssues[] = 'Exam Date (invalid)';
}

$page_title = 'OTM Results';
require __DIR__ . '/header.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <?php $examLocked = ($otmExamId > 0 && is_array($otmExamSelected)); ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-journal-check me-2"></i>OTM Results Entry</div>
            <div class="small text-muted">One row per pupil attempt: 2 majors + 3 mandatory subjects, with optional certificate percentages.</div>
          </div>
        </div>

        <div class="alert alert-light border small mb-3">
          <div class="fw-semibold mb-1">Scoring</div>
          <div>Major 1: <code>30 × 3.1 = 93</code>, Major 2: <code>30 × 2.1 = 63</code></div>
          <div>Mandatory: Ona tili / Matematika / O'zb Tarix each <code>10 × 1.1 = 11</code></div>
          <div>If certificate % is entered, `total_score_withcert` uses certificate points for that subject; `total_score` remains exam-only.</div>
        </div>

        <?php if (!$schemaOk || !$schemaVersionOk || !$hasStudyYearTable || !$hasOtmMajorTable || !$hasOtmSubjectsTable): ?>
          <div class="alert alert-warning mb-3">
            <?php if (!$schemaOk): ?>
              <div class="fw-semibold">Table `otm_results` was not found.</div>
              <div class="small">Run the SQL in <code>otm_results.sql</code> first, then reload this page.</div>
            <?php elseif (!$schemaVersionOk): ?>
              <div class="fw-semibold">`otm_results` schema is outdated.</div>
              <div class="small">Apply the new SQL version with major/mandatory columns and certificate percentages.</div>
            <?php elseif (!$hasOtmSubjectsTable): ?>
              <div class="fw-semibold">Table `otm_subjects` was not found.</div>
              <div class="small">Create and populate <code>otm_subjects</code> first.</div>
            <?php elseif (!$hasOtmMajorTable): ?>
              <div class="fw-semibold">Table `otm_major` was not found.</div>
              <div class="small">Create <code>otm_major</code> and assign majors before entering OTM results.</div>
            <?php else: ?>
              <div class="fw-semibold">Table `study_year` was not found.</div>
              <div class="small">Create/populate <code>study_year</code> before using OTM results.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($loadContextIssues): ?>
          <div class="alert alert-warning mb-3">
            <div class="fw-semibold">Grid not loaded yet.</div>
            <div class="small">Please complete/fix: <?= h(implode(', ', $loadContextIssues)) ?>.</div>
          </div>
        <?php endif; ?>

        <?php if ($hasOtmExamsTable && !$hasOtmExamsSchema): ?>
          <div class="alert alert-warning mb-3">
            <div class="fw-semibold">`otm_exams` schema is outdated.</div>
            <div class="small">Run <code>otm_exams.sql</code> and reload this page to load exams from the exam list.</div>
          </div>
        <?php endif; ?>

        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-2">
            <label class="form-label">Class <span class="text-danger">*</span></label>
            <select name="class_code" class="form-select" required>
              <option value="">Select class</option>
              <?php foreach ($classOptions as $c): $cc = (string)$c['class_code']; ?>
                <option value="<?= h_attr($cc) ?>" <?= $classCode === $cc ? 'selected' : '' ?>>
                  <?= h($cc) ?> (<?= (int)$c['cnt'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Study Year <span class="text-danger">*</span></label>
            <select name="study_year_id" class="form-select" required<?= $examLocked ? ' disabled' : '' ?>>
              <option value="">Select year</option>
              <?php foreach ($studyYearOptions as $sy): $syId = (int)$sy['id']; ?>
                <option value="<?= $syId ?>" <?= $studyYearId === $syId ? 'selected' : '' ?>>
                  <?= h($sy['year_code']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($examLocked): ?><input type="hidden" name="study_year_id" value="<?= (int)$studyYearId ?>"><?php endif; ?>
          </div>

          <div class="col-md-2">
            <label class="form-label">Type</label>
            <select name="otm_kind" class="form-select"<?= $examLocked ? ' disabled' : '' ?>>
              <option value="mock" <?= $otmKind === 'mock' ? 'selected' : '' ?>>Mock</option>
              <option value="repetition" <?= $otmKind === 'repetition' ? 'selected' : '' ?>>Repetition</option>
            </select>
            <?php if ($examLocked): ?><input type="hidden" name="otm_kind" value="<?= h_attr($otmKind) ?>"><?php endif; ?>
          </div>

          <div class="col-md-3">
            <label class="form-label">Exam (from `otm_exams`)</label>
            <select name="otm_exam_id" class="form-select">
              <option value="">Manual entry</option>
              <?php foreach ($otmExamOptions as $eo): ?>
                <?php
                  $eid = (int)$eo['id'];
                  $lbl = trim((string)($eo['year_code'] ?? ''));
                  $lbl .= ($lbl !== '' ? ' | ' : '') . ucfirst((string)$eo['otm_kind']);
                  $lbl .= ' | ' . (string)$eo['exam_title'];
                  $lbl .= ' | ' . (string)$eo['exam_date'];
                  $lbl .= ' | #' . (int)$eo['attempt_no'];
                  if (isset($eo['is_active']) && (int)$eo['is_active'] !== 1) $lbl .= ' [inactive]';
                ?>
                <option value="<?= $eid ?>" <?= $otmExamId === $eid ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Exam Title <span class="text-danger">*</span></label>
            <input type="text" name="exam_title" class="form-control" maxlength="120" value="<?= h_attr($examTitle) ?>" placeholder="e.g., OTM Trial #1" required<?= $examLocked ? ' readonly' : '' ?>>
          </div>

          <div class="col-md-2">
            <label class="form-label">Exam Date <span class="text-danger">*</span></label>
            <input type="date" name="exam_date" class="form-control" value="<?= h_attr($examDate) ?>" required<?= $examLocked ? ' readonly' : '' ?>>
          </div>

          <div class="col-md-1">
            <label class="form-label">Attempt</label>
            <input type="number" name="attempt_no" min="1" max="20" class="form-control" value="<?= (int)$attemptNo ?>"<?= $examLocked ? ' readonly' : '' ?>>
          </div>

          <div class="col-md-3">
            <label class="form-label">Search pupils</label>
            <input type="text" name="q" class="form-control" maxlength="60" value="<?= h_attr($q) ?>" placeholder="Surname / name">
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary" name="load_grid" value="1">
              <i class="bi bi-search me-1"></i>Load Grid
            </button>
            <a class="btn btn-outline-secondary" href="otm_results.php">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </a>
          </div>

          <div class="col-12">
            <div class="form-text">
              Majors are loaded per pupil from <code>otm_major</code> (OTM Major Assignment page).
              <?php if ($hasOtmExamsTable && $hasOtmExamsSchema): ?>
                Choose an exam in <code>otm_exams</code> to auto-fill type/title/date/attempt.
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($schemaOk && $schemaVersionOk && $hasStudyYearTable && $recentSessions): ?>
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="fw-semibold mb-2"><i class="bi bi-clock-history me-2"></i>Recent OTM Sessions</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Type</th>
                  <th>Title</th>
                  <th>Date</th>
                  <th>Attempt</th>
                  <th>Majors</th>
                  <th>Year</th>
                  <th class="text-end">Rows</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentSessions as $rs): ?>
                  <?php
                    $linkParams = [
                        'otm_kind' => (string)$rs['otm_kind'],
                        'exam_title' => (string)$rs['exam_title'],
                        'exam_date' => (string)$rs['exam_date'],
                        'attempt_no' => (int)$rs['attempt_no'],
                        'study_year_id' => (int)($rs['study_year_id'] ?? 0),
                    ];
                    $rsExamId = (int)($rs['otm_exam_id'] ?? 0);
                    if ($rsExamId > 0) $linkParams['otm_exam_id'] = $rsExamId;
                    $link = 'otm_results.php?' . http_build_query($linkParams);
                  ?>
                  <tr>
                    <td><span class="badge <?= ((string)$rs['otm_kind'] === 'mock') ? 'text-bg-primary' : 'text-bg-warning text-dark' ?>"><?= h($rs['otm_kind']) ?></span></td>
                    <td class="fw-semibold"><?= h($rs['exam_title']) ?></td>
                    <td><?= h($rs['exam_date']) ?></td>
                    <td><?= (int)$rs['attempt_no'] ?></td>
                    <td class="small">
                      <div><?= h($rs['major1_subject_name']) ?><?= !empty($rs['major1_subject_code']) ? ' (' . h($rs['major1_subject_code']) . ')' : '' ?></div>
                      <div><?= h($rs['major2_subject_name']) ?><?= !empty($rs['major2_subject_code']) ? ' (' . h($rs['major2_subject_code']) . ')' : '' ?></div>
                    </td>
                    <td><?= h($rs['study_year_code'] ?? '') ?></td>
                    <td class="text-end"><?= (int)$rs['n'] ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= h_attr($link) ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Select a class after opening a session to edit that class.</div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <div class="fw-semibold"><i class="bi bi-table me-2"></i>Entry Grid</div>
            <div class="small text-muted">
              <?php if ($rows): ?>
                <?= count($rows) ?> pupils loaded, <?= (int)$enteredCount ?> already entered for this session.
              <?php else: ?>
                Choose class, study year, title and date, then click "Load Grid".
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!$schemaOk || !$schemaVersionOk || !$hasStudyYearTable || !$hasOtmMajorTable || !$hasOtmSubjectsTable): ?>
          <div class="text-muted">Schema not ready yet.</div>
        <?php elseif (!$loadContextReady): ?>
          <div class="alert alert-info mb-0">
            Fill in <strong>Class</strong>, <strong>Study Year</strong>, <strong>Exam Title</strong>, and <strong>Exam Date</strong> to load the grid. Majors will be taken from <code>otm_major</code>.
          </div>
        <?php elseif (!$rows): ?>
          <div class="alert alert-warning mb-0">No pupils found for the selected class/filter.</div>
        <?php else: ?>
          <form method="post">
            <?= csrf_field('csrf') ?>
            <input type="hidden" name="action" value="save_scores">
            <input type="hidden" name="class_code" value="<?= h_attr($classCode) ?>">
            <input type="hidden" name="otm_exam_id" value="<?= (int)$otmExamId ?>">
            <input type="hidden" name="study_year_id" value="<?= (int)$studyYearId ?>">
            <input type="hidden" name="otm_kind" value="<?= h_attr($otmKind) ?>">
            <input type="hidden" name="exam_title" value="<?= h_attr($examTitle) ?>">
            <input type="hidden" name="exam_date" value="<?= h_attr($examDate) ?>">
            <input type="hidden" name="attempt_no" value="<?= (int)$attemptNo ?>">
            <input type="hidden" name="q" value="<?= h_attr($q) ?>">

            <div class="d-flex flex-wrap gap-3 mb-2">
              <div class="small">
                <span class="text-muted">Session:</span>
                <span class="fw-semibold"><?= h(ucfirst($otmKind)) ?></span> /
                <span class="fw-semibold"><?= h($examTitle) ?></span> /
                <span class="fw-semibold"><?= h($examDate) ?></span> /
                Attempt <?= (int)$attemptNo ?>
              </div>
              <div class="small text-muted">Blank rows are skipped (no DB update).</div>
            </div>

            <?php if ($rowsNoMajors > 0 || $rowsInvalidMajors > 0): ?>
              <div class="alert alert-warning small py-2">
                <?php if ($rowsNoMajors > 0): ?>
                  <div><?= (int)$rowsNoMajors ?> pupil(s) have no OTM major assignment and cannot be saved until majors are set in <code>otm_major.php</code>.</div>
                <?php endif; ?>
                <?php if ($rowsInvalidMajors > 0): ?>
                  <div><?= (int)$rowsInvalidMajors ?> pupil(s) have invalid OTM majors (Major 1 and Major 2 are the same).</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div id="otm-results-grid-ui" class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <div class="small text-muted">
                View mode: show only correct-answer inputs or certificate % inputs to reduce horizontal scrolling.
              </div>
              <div class="btn-group btn-group-sm" role="group" aria-label="OTM input view mode" id="otmViewModeGroup">
                <button type="button" class="btn btn-outline-secondary active" data-otm-view-mode="all" aria-pressed="true">All</button>
                <button type="button" class="btn btn-outline-secondary" data-otm-view-mode="results" aria-pressed="false">Correct Answers</button>
                <button type="button" class="btn btn-outline-secondary" data-otm-view-mode="cert" aria-pressed="false">Certificate %</button>
              </div>
            </div>

            <div class="table-responsive" id="otmResultsGridWrap">
              <table class="table table-sm table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>ID</th>
                    <th>Pupil</th>
                    <th>Majors</th>
                    <th class="text-center" data-otm-col-group="results">M1<br><span class="small text-muted">Correct</span></th>
                    <th class="text-center" data-otm-col-group="cert">M1<br><span class="small text-muted">Cert %</span></th>
                    <th class="text-center" data-otm-col-group="results">M2<br><span class="small text-muted">Correct</span></th>
                    <th class="text-center" data-otm-col-group="cert">M2<br><span class="small text-muted">Cert %</span></th>
                    <th class="text-center" data-otm-col-group="results">Ona tili<br><span class="small text-muted">Correct</span></th>
                    <th class="text-center" data-otm-col-group="cert">Ona tili<br><span class="small text-muted">Cert %</span></th>
                    <th class="text-center" data-otm-col-group="results">Math<br><span class="small text-muted">Correct</span></th>
                    <th class="text-center" data-otm-col-group="cert">Math<br><span class="small text-muted">Cert %</span></th>
                    <th class="text-center" data-otm-col-group="results">Tarix<br><span class="small text-muted">Correct</span></th>
                    <th class="text-center" data-otm-col-group="cert">Tarix<br><span class="small text-muted">Cert %</span></th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Total+Cert</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $pid = (int)$r['id'];
                      $fullName = trim((string)$r['surname'] . ' ' . (string)$r['name']);
                      $hasRow = !empty($r['otm_row_id']);
                      $majorsReady = !empty($r['_majors_ready']);
                      $inputsDisabled = $majorsReady ? '' : ' disabled';
                      $majorState = (string)($r['_major_state'] ?? 'missing');
                      $draftRow = (isset($draftRowsByPid[$pid]) && is_array($draftRowsByPid[$pid])) ? $draftRowsByPid[$pid] : [];
                      $fieldErrors = (isset($rowErrorsByPid[$pid]) && is_array($rowErrorsByPid[$pid])) ? $rowErrorsByPid[$pid] : [];
                      $rowClass = $fieldErrors ? 'table-warning' : '';

                      $valInt = static function (array $row, array $draft, string $key) use ($hasRow): string {
                          if (array_key_exists($key, $draft)) return trim((string)$draft[$key]);
                          if (!$hasRow) return '';
                          return (string)(int)($row[$key] ?? 0);
                      };
                      $valPct = static function (array $row, array $draft, string $key) use ($hasRow): string {
                          if (array_key_exists($key, $draft)) return trim((string)$draft[$key]);
                          if (!$hasRow || $row[$key] === null || $row[$key] === '') return '';
                          return number_format((float)$row[$key], 2, '.', '');
                      };
                      $inputClass = static function (string $fieldKey, string $base) use ($fieldErrors): string {
                          return $base . (isset($fieldErrors[$fieldKey]) ? ' is-invalid' : '');
                      };
                      $fieldErr = static function (string $fieldKey) use ($fieldErrors): string {
                          return isset($fieldErrors[$fieldKey]) ? (string)$fieldErrors[$fieldKey] : '';
                      };
                    ?>
                    <tr class="<?= $rowClass ?>">
                      <td class="text-muted"><?= $pid ?></td>
                      <td><div class="fw-semibold"><?= h($fullName) ?></div></td>
                      <td class="small">
                        <div>
                          <span class="badge text-bg-light border text-dark" title="<?= h_attr((string)($r['_major_title'] ?? 'No majors assigned')) ?>">
                            <?= h((string)($r['_major_pair_code'] ?? '—')) ?>
                          </span>
                        </div>
                        <?php if (!empty($r['otm_major1_name']) || !empty($r['otm_major2_name'])): ?>
                          <div class="text-muted"><?= h((string)($r['otm_major1_name'] ?? '')) ?><?= (!empty($r['otm_major1_name']) && !empty($r['otm_major2_name'])) ? ' / ' : '' ?><?= h((string)($r['otm_major2_name'] ?? '')) ?></div>
                        <?php endif; ?>
                        <?php if ($majorState === 'missing'): ?>
                          <div class="text-danger">No majors in `otm_major`</div>
                        <?php elseif ($majorState === 'invalid'): ?>
                          <div class="text-danger">Invalid majors in `otm_major` (same subjects)</div>
                        <?php endif; ?>
                        <?php if ($fieldErr('_major') !== ''): ?>
                          <div class="text-danger"><?= h($fieldErr('_major')) ?></div>
                        <?php endif; ?>
                        <?php if ($fieldErr('_row') !== ''): ?>
                          <div class="text-danger"><?= h($fieldErr('_row')) ?></div>
                        <?php endif; ?>
                      </td>

                      <td data-otm-col-group="results">
                        <input type="number" class="<?= h_attr($inputClass('major1_correct', 'form-control form-control-sm text-end')) ?>" min="0" max="30" step="1" name="rows[<?= $pid ?>][major1_correct]" value="<?= h_attr($valInt($r, $draftRow, 'major1_correct')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('major1_correct') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('major1_correct')) ?></div><?php endif; ?>
                      </td>
                      <td data-otm-col-group="cert">
                        <input type="number" class="<?= h_attr($inputClass('major1_certificate_percent', 'form-control form-control-sm text-end')) ?>" min="0" max="100" step="0.01" name="rows[<?= $pid ?>][major1_certificate_percent]" value="<?= h_attr($valPct($r, $draftRow, 'major1_certificate_percent')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('major1_certificate_percent') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('major1_certificate_percent')) ?></div><?php endif; ?>
                      </td>

                      <td data-otm-col-group="results">
                        <input type="number" class="<?= h_attr($inputClass('major2_correct', 'form-control form-control-sm text-end')) ?>" min="0" max="30" step="1" name="rows[<?= $pid ?>][major2_correct]" value="<?= h_attr($valInt($r, $draftRow, 'major2_correct')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('major2_correct') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('major2_correct')) ?></div><?php endif; ?>
                      </td>
                      <td data-otm-col-group="cert">
                        <input type="number" class="<?= h_attr($inputClass('major2_certificate_percent', 'form-control form-control-sm text-end')) ?>" min="0" max="100" step="0.01" name="rows[<?= $pid ?>][major2_certificate_percent]" value="<?= h_attr($valPct($r, $draftRow, 'major2_certificate_percent')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('major2_certificate_percent') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('major2_certificate_percent')) ?></div><?php endif; ?>
                      </td>

                      <td data-otm-col-group="results">
                        <input type="number" class="<?= h_attr($inputClass('mandatory_ona_tili_correct', 'form-control form-control-sm text-end')) ?>" min="0" max="10" step="1" name="rows[<?= $pid ?>][mandatory_ona_tili_correct]" value="<?= h_attr($valInt($r, $draftRow, 'mandatory_ona_tili_correct')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('mandatory_ona_tili_correct') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('mandatory_ona_tili_correct')) ?></div><?php endif; ?>
                      </td>
                      <td data-otm-col-group="cert">
                        <input type="number" class="<?= h_attr($inputClass('mandatory_ona_tili_certificate_percent', 'form-control form-control-sm text-end')) ?>" min="0" max="100" step="0.01" name="rows[<?= $pid ?>][mandatory_ona_tili_certificate_percent]" value="<?= h_attr($valPct($r, $draftRow, 'mandatory_ona_tili_certificate_percent')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('mandatory_ona_tili_certificate_percent') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('mandatory_ona_tili_certificate_percent')) ?></div><?php endif; ?>
                      </td>

                      <td data-otm-col-group="results">
                        <input type="number" class="<?= h_attr($inputClass('mandatory_matematika_correct', 'form-control form-control-sm text-end')) ?>" min="0" max="10" step="1" name="rows[<?= $pid ?>][mandatory_matematika_correct]" value="<?= h_attr($valInt($r, $draftRow, 'mandatory_matematika_correct')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('mandatory_matematika_correct') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('mandatory_matematika_correct')) ?></div><?php endif; ?>
                      </td>
                      <td data-otm-col-group="cert">
                        <input type="number" class="<?= h_attr($inputClass('mandatory_matematika_certificate_percent', 'form-control form-control-sm text-end')) ?>" min="0" max="100" step="0.01" name="rows[<?= $pid ?>][mandatory_matematika_certificate_percent]" value="<?= h_attr($valPct($r, $draftRow, 'mandatory_matematika_certificate_percent')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('mandatory_matematika_certificate_percent') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('mandatory_matematika_certificate_percent')) ?></div><?php endif; ?>
                      </td>

                      <td data-otm-col-group="results">
                        <input type="number" class="<?= h_attr($inputClass('mandatory_uzb_tarix_correct', 'form-control form-control-sm text-end')) ?>" min="0" max="10" step="1" name="rows[<?= $pid ?>][mandatory_uzb_tarix_correct]" value="<?= h_attr($valInt($r, $draftRow, 'mandatory_uzb_tarix_correct')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('mandatory_uzb_tarix_correct') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('mandatory_uzb_tarix_correct')) ?></div><?php endif; ?>
                      </td>
                      <td data-otm-col-group="cert">
                        <input type="number" class="<?= h_attr($inputClass('mandatory_uzb_tarix_certificate_percent', 'form-control form-control-sm text-end')) ?>" min="0" max="100" step="0.01" name="rows[<?= $pid ?>][mandatory_uzb_tarix_certificate_percent]" value="<?= h_attr($valPct($r, $draftRow, 'mandatory_uzb_tarix_certificate_percent')) ?>"<?= $inputsDisabled ?>>
                        <?php if ($fieldErr('mandatory_uzb_tarix_certificate_percent') !== ''): ?><div class="invalid-feedback d-block small"><?= h($fieldErr('mandatory_uzb_tarix_certificate_percent')) ?></div><?php endif; ?>
                      </td>

                      <td class="text-end fw-semibold"><?= $hasRow ? number_format((float)$r['total_score'], 2) : '' ?></td>
                      <td class="text-end fw-semibold"><?= $hasRow ? number_format((float)$r['total_score_withcert'], 2) : '' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary"<?= !$canWrite ? ' disabled' : '' ?>>
                <i class="bi bi-save me-1"></i>Save OTM Results
              </button>
              <a class="btn btn-outline-secondary" href="<?= h_attr('otm_results.php?' . http_build_query(otm_keep_query($classCode, $otmExamId, $studyYearId, $otmKind, $examTitle, $examDate, $attemptNo, $q))) ?>">
                <i class="bi bi-arrow-repeat me-1"></i>Reload
              </a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const wrap = document.getElementById('otmResultsGridWrap');
  const group = document.getElementById('otmViewModeGroup');
  if (!wrap || !group) return;

  const STORAGE_KEY = 'otm_results_view_mode_v1';
  const buttons = Array.from(group.querySelectorAll('[data-otm-view-mode]'));
  const cols = () => Array.from(wrap.querySelectorAll('[data-otm-col-group]'));

  function applyMode(mode) {
    const normalized = (mode === 'results' || mode === 'cert') ? mode : 'all';
    cols().forEach((el) => {
      const groupName = el.getAttribute('data-otm-col-group');
      const hide = normalized !== 'all' && groupName !== normalized;
      el.classList.toggle('d-none', hide);
    });
    buttons.forEach((btn) => {
      const active = btn.getAttribute('data-otm-view-mode') === normalized;
      btn.classList.toggle('active', active);
      btn.classList.toggle('btn-outline-secondary', !active);
      btn.classList.toggle('btn-primary', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    try { localStorage.setItem(STORAGE_KEY, normalized); } catch (e) {}
  }

  group.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-otm-view-mode]');
    if (!btn) return;
    e.preventDefault();
    applyMode(btn.getAttribute('data-otm-view-mode') || 'all');
  });

  let initial = 'all';
  try {
    initial = localStorage.getItem(STORAGE_KEY) || 'all';
  } catch (e) {}
  applyMode(initial);
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
