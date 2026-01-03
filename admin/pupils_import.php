<?php
// admin/pupils_import.php — Import pupils from XLSX/CSV (PhpSpreadsheet), preview + validate + upsert (hardened + UI)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

session_start_secure();
require_role('admin');

$page_title = 'Import Pupils';
require __DIR__ . '/header.php';

const IMPORT_SESSION_KEY = 'pupils_import_ctx_v2';
const MAX_UPLOAD_BYTES   = 15_000_000; // 15 MB
const PREVIEW_LIMIT      = 50;

// Use the user-requested open_basedir-safe path:
const TMP_IMPORT_DIR     = '/var/www/cefr/data/www/zangiota.chsb.uz/tmp';
const TMP_PREFIX         = 'exam_admin_imports_';
const CTX_TTL_SECONDS    = 3600;        // 1 hour session context
const FILE_TTL_SECONDS   = 6 * 3600;    // 6 hours cleanup TTL

function normalize_header(string $h): string
{
    $h = trim(mb_strtolower($h));
    $h = preg_replace('/\s+/u', '_', $h) ?? $h;
    $h = preg_replace('/[^a-z0-9_]+/u', '', $h) ?? $h;
    return $h;
}

function is_allowed_upload(string $name, string $tmpPath): bool
{
    $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) return false;

    // Basic MIME sniffing. MIME is not authoritative, but helps catch obvious junk.
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

/**
 * Ensure tmp dir exists, and create a dedicated subdir for imports.
 * Also garbage-collect stale files.
 */
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

    // Dedicated per-feature subdir (cleaner + safer)
    $dir = $base . '/' . TMP_PREFIX . 'pupils';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create import directory.');
        }
    }

    // Cleanup stale files
    $now = time();
    $files = glob($dir . '/pupils_*.{csv,xlsx,xls}', GLOB_BRACE) ?: [];
    foreach ($files as $f) {
        $mtime = @filemtime($f);
        if ($mtime !== false && ($now - $mtime) > FILE_TTL_SECONDS) {
            @unlink($f);
        }
    }

    return $dir;
}

/**
 * Read active sheet into [headers, rows]
 * rows are associative arrays by normalized header.
 */
function read_sheet_assoc(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Uploaded file is missing or not readable: ' . $path);
    }

    // Identify by content where possible, not only extension
    $reader = IOFactory::createReaderForFile($path);

    // CSV needs explicit settings in real-world school exports
    if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
        $reader->setDelimiter(',');          // if your CSV uses ; change to ';'
        $reader->setEnclosure('"');
        $reader->setEscapeCharacter('\\');
        $reader->setInputEncoding('UTF-8');  // if Windows-1251/ANSI, adjust later
    }

    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();

    $highestRow = (int)$sheet->getHighestRow();
    $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    // Row 1 headers
    $rawHeaders = [];
    for ($c = 1; $c <= $highestColIndex; $c++) {
        // PhpSpreadsheet v5+: getCell([col,row]) is supported
        $raw = (string)$sheet->getCell([$c, 1])->getValue();
        $rawHeaders[$c] = normalize_header($raw);
    }

    // Ensure unique header keys
    $headers = [];
    $seen = [];
    foreach ($rawHeaders as $c => $h) {
        $h = $h !== '' ? $h : 'col_' . $c;
        if (!isset($seen[$h])) {
            $seen[$h] = 1;
            $headers[$c] = $h;
        } else {
            $seen[$h]++;
            $headers[$c] = $h . '_' . $seen[$h];
        }
    }

    $rows = [];
    for ($r = 2; $r <= $highestRow; $r++) {
        $row = [];
        $allEmpty = true;

        for ($c = 1; $c <= $highestColIndex; $c++) {
            $key = $headers[$c];

            // Use calculated value (handles numeric formats); fallback to raw value
            $cell = $sheet->getCell([$c, $r]);
            $val = $cell->getCalculatedValue();
            if ($val === null || $val === '') {
                $val = $cell->getValue();
            }

            if (is_string($val)) {
                $val = trim($val);
            }

            if ($val !== null && $val !== '') {
                $allEmpty = false;
            }

            $row[$key] = $val;
        }

        if ($allEmpty) {
            continue;
        }

        $row['__rownum'] = $r; // original spreadsheet row number
        $rows[] = $row;
    }

    return [$headers, $rows];
}

function pick(array $row, array $candidates): string
{
    foreach ($candidates as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $v = is_string($row[$k]) ? trim($row[$k]) : (string)$row[$k];
            if ($v !== '') return $v;
        }
    }
    return '';
}

function normalize_track(string $t): string
{
    $t0 = trim($t);
    $tLower = mb_strtolower($t0);

    if ($tLower === 'aniq' || $tLower === 'aniq_fanlar' || $tLower === 'aniq fanlar') return 'Aniq fanlar';
    if ($tLower === 'tabiiy' || $tLower === 'tabiiy_fanlar' || $tLower === 'tabiiy fanlar') return 'Tabiiy fanlar';

    if ($t0 === 'Aniq fanlar' || $t0 === 'Tabiiy fanlar') return $t0;

    return $t0; // will fail validation if not accepted
}

function validate_pupil_rows(array $rows): array
{
    $errors = [];
    $clean = [];

    $allowedTracks = ['Aniq fanlar', 'Tabiiy fanlar'];

    foreach ($rows as $i => $row) {
        $rowNum = (int)($row['__rownum'] ?? ($i + 2));

        $surname      = pick($row, ['surname', 'last_name', 'familya']);
        $name         = pick($row, ['name', 'first_name', 'ism']);
        $middle       = pick($row, ['middle_name', 'father_name', 'otchestvo']);
        $classCode    = pick($row, ['class_code', 'class', 'class_name', 'sinf']);
        $track        = normalize_track(pick($row, ['track', 'yonalish', 'stream']));
        $studentLogin = pick($row, ['student_login', 'login', 'username', 'student_id', 'pupil_login']);

        $rowErr = [];

        if ($surname === '') $rowErr[] = 'Missing surname';
        if ($name === '') $rowErr[] = 'Missing name';
        if ($classCode === '') $rowErr[] = 'Missing class_code';
        if ($studentLogin === '') $rowErr[] = 'Missing student_login';
        if ($track === '' || !in_array($track, $allowedTracks, true)) $rowErr[] = 'Invalid track (must be "Aniq fanlar" or "Tabiiy fanlar")';

        if ($studentLogin !== '' && mb_strlen($studentLogin) > 20) $rowErr[] = 'student_login too long (max 20)';
        if ($surname !== '' && mb_strlen($surname) > 50) $rowErr[] = 'surname too long (max 40)';
        if ($name !== '' && mb_strlen($name) > 40) $rowErr[] = 'name too long (max 40)';
        if ($middle !== '' && mb_strlen($middle) > 40) $rowErr[] = 'middle_name too long (max 40)';
        if ($classCode !== '' && mb_strlen($classCode) > 30) $rowErr[] = 'class_code too long (max 30)';

        // normalize login for uniqueness comparisons and DB key
        $studentLoginNorm = $studentLogin !== '' ? mb_strtolower($studentLogin) : '';

        if ($rowErr) {
            $errors[] = ['row' => $rowNum, 'errors' => $rowErr, 'data' => $row];
            continue;
        }

        $clean[] = [
            '__row'         => $rowNum,
            'surname'       => $surname,
            'name'          => $name,
            'middle_name'   => $middle !== '' ? $middle : null,
            'class_code'    => $classCode,
            'track'         => $track,
            'student_login' => $studentLoginNorm,
        ];
    }

    // Detect duplicates within file by student_login (report correct row numbers)
    $seen = [];
    foreach ($clean as $r) {
        $sl = $r['student_login'];
        if (isset($seen[$sl])) {
            $errors[] = [
                'row' => (int)$r['__row'],
                'errors' => ['Duplicate student_login in file: ' . $sl . ' (first seen at row ' . (int)$seen[$sl] . ')'],
                'data' => $r
            ];
        } else {
            $seen[$sl] = (int)$r['__row'];
        }
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

$flash = null;
$preview = null;
$ctx = $_SESSION[IMPORT_SESSION_KEY] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

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
                $flash = ['type' => 'danger', 'msg' => 'File is too large (max 15 MB).'];
            } elseif (!is_allowed_upload((string)$f['name'], (string)$f['tmp_name'])) {
                $flash = ['type' => 'danger', 'msg' => 'Invalid file type. Upload CSV or XLSX.'];
            } else {
                try {
                    $dir = ensure_import_dir_and_cleanup();
                    $token = bin2hex(random_bytes(16));
                    $ext = mb_strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                    $dest = $dir . '/pupils_' . $token . '.' . $ext;

                    // IMPORTANT: dest is open_basedir-safe (inside /var/www/cefr/data)
                    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
                        $flash = ['type' => 'danger', 'msg' => 'Upload failed while moving the file to the server directory.'];
                    } else {
                        try {
                            [$headers, $rows] = read_sheet_assoc($dest);
                            [$clean, $errors] = validate_pupil_rows($rows);

                            $preview = [
                                'file'        => basename($dest),
                                'headers'     => $headers,
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
                                $flash = ['type' => 'success', 'msg' => 'Preview loaded successfully. Ready to import.'];
                            }
                        } catch (Throwable $e) {
                            @unlink($dest);
                            error_log('Pupils import preview read error: ' . $e->getMessage());
                            $flash = ['type' => 'danger', 'msg' => 'Could not read spreadsheet. Please verify the file format.'];
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Pupils import preview setup error: ' . $e->getMessage());
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
                        [, $rows] = read_sheet_assoc($path);
                        [$clean, $errors] = validate_pupil_rows($rows);

                        if ($errors) {
                            $flash = ['type' => 'danger', 'msg' => 'Fix validation errors before importing.'];
                            $preview = [
                                'file'        => basename($path),
                                'headers'     => [],
                                'rows_total'  => count($rows),
                                'valid_total' => count($clean),
                                'errors_total'=> count($errors),
                                'errors'      => array_slice($errors, 0, 25),
                                'sample'      => array_slice($clean, 0, PREVIEW_LIMIT),
                            ];
                        } else {
                            // Pre-fetch existing to count inserts vs updates
                            $logins = array_values(array_unique(array_map(fn($r) => (string)$r['student_login'], $clean)));
                            $existing = [];
                            if ($logins) {
                                $in = implode(',', array_fill(0, count($logins), '?'));
                                $st = $pdo->prepare("SELECT student_login, id FROM pupils WHERE student_login IN ($in)");
                                $st->execute($logins);
                                foreach ($st->fetchAll() as $r) {
                                    $existing[(string)$r['student_login']] = (int)$r['id'];
                                }
                            }

                            $toInsert = 0;
                            $toUpdate = 0;
                            foreach ($clean as $r) {
                                if (isset($existing[$r['student_login']])) $toUpdate++;
                                else $toInsert++;
                            }

                            if ($dryRun) {
                                $flash = ['type' => 'info', 'msg' => "Dry run OK. Would insert {$toInsert}, update {$toUpdate}."];
                            } else {
                                $pdo->beginTransaction();

                                // MySQL 8.0+ safe alias approach (avoids deprecated VALUES())
                                $sql = "
                                    INSERT INTO pupils (name, surname, middle_name, class_code, track, student_login)
                                    VALUES (:name, :surname, :middle_name, :class_code, :track, :student_login)
                                    AS new
                                    ON DUPLICATE KEY UPDATE
                                        name = new.name,
                                        surname = new.surname,
                                        middle_name = new.middle_name,
                                        class_code = new.class_code,
                                        track = new.track,
                                        updated_at = CURRENT_TIMESTAMP
                                ";
                                $ins = $pdo->prepare($sql);

                                foreach ($clean as $r) {
                                    $ins->execute([
                                        'name'          => $r['name'],
                                        'surname'       => $r['surname'],
                                        'middle_name'   => $r['middle_name'],
                                        'class_code'    => $r['class_code'],
                                        'track'         => $r['track'],
                                        'student_login' => $r['student_login'],
                                    ]);
                                }

                                $pdo->commit();

                                // Cleanup file and context
                                @unlink($path);
                                unset($_SESSION[IMPORT_SESSION_KEY]);

                                $flash = ['type' => 'success', 'msg' => "Imported successfully. Inserted {$toInsert}, updated {$toUpdate}."];
                            }

                            // Refresh preview area after import/dry-run to show summary
                            $preview = [
                                'file'        => basename($path),
                                'headers'     => [],
                                'rows_total'  => count($rows),
                                'valid_total' => count($clean),
                                'errors_total'=> 0,
                                'errors'      => [],
                                'sample'      => array_slice($clean, 0, PREVIEW_LIMIT),
                                'would_insert'=> $toInsert,
                                'would_update'=> $toUpdate,
                            ];
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('Pupils import failed: ' . $e->getMessage());
                        $flash = ['type' => 'danger', 'msg' => 'Import failed due to a server error. Check server logs for details.'];
                    }
                }
            }
        }
    }

    if ($action === 'reset_ctx') {
        // Allow admin to reset expired/broken context
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

// Context badge text
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
            <h5 class="card-title mb-1"><i class="bi bi-people me-2"></i>Pupils Import</h5>
            <div class="text-muted small">Upload → Preview/Validate → Import (Upsert by <code>student_login</code>)</div>
          </div>

          <?php if ($ctxBadge): ?>
            <div class="text-end">
              <span class="badge text-bg-light border">
                Active context
              </span>
              <div class="small text-muted mt-1">
                Age: <?= h(format_age((int)$ctxBadge['age'])) ?> ·
                Expires in: <?= h(format_age((int)$ctxBadge['expires_in'])) ?>
              </div>
            </div>
          <?php else: ?>
            <span class="badge text-bg-secondary">No active context</span>
          <?php endif; ?>
        </div>

        <?php if ($flash): ?>
          <div class="alert alert-<?= h($flash['type']) ?> mb-3">
            <?= h($flash['msg']) ?>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="preview">

          <div>
            <label class="form-label">Upload CSV/XLSX</label>
            <input type="file" name="file" class="form-control" accept=".csv,.xlsx,.xls" required>
            <div class="form-text">
              Flexible headers accepted (examples): <code>surname</code>, <code>name</code>, <code>middle_name</code>, <code>class_code</code>, <code>track</code>, <code>student_login</code>.
            </div>
            <div class="form-text">
              Tracks must be exactly: <span class="badge text-bg-light border">Aniq fanlar</span>
              <span class="badge text-bg-light border">Tabiiy fanlar</span>
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
            <input class="form-check-input" type="checkbox" value="1" id="dryRun" name="dry_run" checked>
            <label class="form-check-label" for="dryRun">
              Dry run (do not write to DB)
            </label>
          </div>

          <button class="btn btn-success" <?= $ctxBadge ? '' : 'disabled' ?>>
            <i class="bi bi-database-add me-1"></i> Run Import
          </button>

          <div class="small text-muted">
            Upsert key: <code>pupils.student_login</code>. Existing logins will be updated (name/class/track/middle).
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
              <div class="fw-semibold mb-2">
                Validation errors (showing first <?= (int)count($preview['errors']) ?>)
              </div>
              <ul class="mb-0">
                <?php foreach ($preview['errors'] as $e): ?>
                  <li class="mb-1">
                    <span class="fw-semibold">Row <?= (int)$e['row'] ?>:</span>
                    <?= h(implode('; ', (array)$e['errors'])) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
              <div class="small text-muted mt-2">
                Tip: most failures are missing required fields or invalid <code>track</code>.
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($preview['sample'])): ?>
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle text-center">
                <thead class="table-light">
                  <tr>
                    <th class="text-center">Row</th>
                    <th class="text-start">Surname</th>
                    <th class="text-start">Name</th>
                    <th class="text-start">Middle</th>
                    <th class="text-center">Class</th>
                    <th class="text-center">Track</th>
                    <th class="text-center">Login</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['sample'] as $r): ?>
                    <tr>
                      <td class="text-center"><span class="badge text-bg-light border"><?= (int)$r['__row'] ?></span></td>
                      <td class="text-start"><?= h((string)$r['surname']) ?></td>
                      <td class="text-start"><?= h((string)$r['name']) ?></td>
                      <td class="text-start"><?= h((string)($r['middle_name'] ?? '')) ?></td>
                      <td class="text-center"><?= h((string)$r['class_code']) ?></td>
                      <td class="text-center">
                        <span class="badge <?= ($r['track'] === 'Aniq fanlar') ? 'text-bg-primary' : 'text-bg-success' ?>">
                          <?= h((string)$r['track']) ?>
                        </span>
                      </td>
                      <td class="text-center"><code><?= h((string)$r['student_login']) ?></code></td>
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
