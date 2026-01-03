<?php
// admin/subject_import.php — Import subjects from CSV/XLSX, preview + validate + upsert

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

session_start_secure();
require_role('admin');

$page_title = 'Import Subjects';
require __DIR__ . '/header.php';

const IMPORT_SESSION_KEY = 'subjects_import_ctx_v1';
const MAX_UPLOAD_BYTES = 10_000_000;
const PREVIEW_LIMIT = 50;

function hnorm(string $h): string
{
    $h = trim(mb_strtolower($h));
    $h = preg_replace('/\s+/u', '_', $h) ?? $h;
    $h = preg_replace('/[^a-z0-9_]+/u', '', $h) ?? $h;
    return $h;
}

function read_assoc(string $path): array
{
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);

    $ss = $reader->load($path);
    $sh = $ss->getActiveSheet();

    $hr = (int)$sh->getHighestRow();
    $hc = Coordinate::columnIndexFromString($sh->getHighestColumn());

    $headers = [];
    $seen = [];
    for ($c = 1; $c <= $hc; $c++) {
        $raw = (string)$sh->getCellByColumnAndRow($c, 1)->getValue();
        $key = hnorm($raw);
        $key = $key !== '' ? $key : 'col_' . $c;
        if (!isset($seen[$key])) $seen[$key] = 1;
        else { $seen[$key]++; $key .= '_' . $seen[$key]; }
        $headers[$c] = $key;
    }

    $rows = [];
    for ($r = 2; $r <= $hr; $r++) {
        $row = [];
        $empty = true;
        for ($c = 1; $c <= $hc; $c++) {
            $k = $headers[$c];
            $v = $sh->getCellByColumnAndRow($c, $r)->getCalculatedValue();
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') $empty = false;
            $row[$k] = $v;
        }
        if ($empty) continue;
        $rows[] = $row;
    }
    return [$headers, $rows];
}

function pickv(array $row, array $keys): string
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $v = is_string($row[$k]) ? trim($row[$k]) : (string)$row[$k];
            if ($v !== '') return $v;
        }
    }
    return '';
}

function validate_subjects(array $rows): array
{
    $clean = [];
    $errors = [];

    foreach ($rows as $i => $row) {
        $rowNum = $i + 2;

        $code = pickv($row, ['code', 'subject_code', 'abbr']);
        $name = pickv($row, ['name', 'subject_name', 'title']);
        $max  = pickv($row, ['max_points', 'max', 'points', 'total']);

        $rowErr = [];

        if ($code === '') $rowErr[] = 'Missing code';
        if ($name === '') $rowErr[] = 'Missing name';

        if ($code !== '' && mb_strlen($code) > 30) $rowErr[] = 'code too long (max 30)';
        if ($name !== '' && mb_strlen($name) > 120) $rowErr[] = 'name too long (max 120)';

        $maxInt = 40;
        if ($max !== '') {
            if (!preg_match('/^\d+$/', $max)) $rowErr[] = 'max_points must be an integer';
            else {
                $maxInt = (int)$max;
                if ($maxInt < 1 || $maxInt > 40) $rowErr[] = 'max_points must be 1..40';
            }
        }

        if ($rowErr) {
            $errors[] = ['row' => $rowNum, 'errors' => $rowErr, 'data' => $row];
            continue;
        }

        $clean[] = [
            'code' => mb_strtolower($code),
            'name' => $name,
            'max_points' => $maxInt,
        ];
    }

    // duplicates by code inside file
    $seen = [];
    foreach ($clean as $idx => $r) {
        $c = $r['code'];
        if (isset($seen[$c])) {
            $errors[] = ['row' => $idx + 2, 'errors' => ['Duplicate code in file: ' . $c], 'data' => $r];
        } else $seen[$c] = true;
    }

    return [$clean, $errors];
}

function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/exam_admin_imports';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

$flash = null;
$preview = null;
$ctx = $_SESSION[IMPORT_SESSION_KEY] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $flash = ['type' => 'danger', 'msg' => 'Please choose a file to upload.'];
        } else {
            $f = $_FILES['file'];
            if ((int)$f['size'] > MAX_UPLOAD_BYTES) {
                $flash = ['type' => 'danger', 'msg' => 'File is too large (max 10 MB).'];
            } else {
                $token = bin2hex(random_bytes(16));
                $ext = mb_strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                    $flash = ['type' => 'danger', 'msg' => 'Invalid file type. Upload CSV or XLSX.'];
                } else {
                    $dest = tmpdir() . '/subjects_' . $token . '.' . $ext;
                    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
                        $flash = ['type' => 'danger', 'msg' => 'Upload failed.'];
                    } else {
                        try {
                            [, $rows] = read_assoc($dest);
                            [$clean, $errors] = validate_subjects($rows);

                            $preview = [
                                'file' => basename($dest),
                                'rows_total' => count($rows),
                                'valid_total' => count($clean),
                                'errors_total' => count($errors),
                                'errors' => array_slice($errors, 0, 25),
                                'sample' => array_slice($clean, 0, PREVIEW_LIMIT),
                            ];

                            $_SESSION[IMPORT_SESSION_KEY] = ['token' => $token, 'path' => $dest, 'created' => time()];
                            $ctx = $_SESSION[IMPORT_SESSION_KEY];
                        } catch (Throwable $e) {
                            $flash = ['type' => 'danger', 'msg' => 'Could not read spreadsheet.'];
                        }
                    }
                }
            }
        }
    }

    if ($action === 'import') {
        $token = (string)($_POST['token'] ?? '');
        if (!is_array($ctx) || empty($ctx['token']) || !hash_equals((string)$ctx['token'], $token) || empty($ctx['path'])) {
            $flash = ['type' => 'danger', 'msg' => 'Import session expired. Please preview again.'];
        } else {
            $path = (string)$ctx['path'];
            if (!is_file($path)) {
                $flash = ['type' => 'danger', 'msg' => 'Uploaded file is missing. Please preview again.'];
            } else {
                $dryRun = !empty($_POST['dry_run']);
                try {
                    [, $rows] = read_assoc($path);
                    [$clean, $errors] = validate_subjects($rows);

                    if ($errors) {
                        $flash = ['type' => 'danger', 'msg' => 'Fix validation errors before importing.'];
                        $preview = [
                            'file' => basename($path),
                            'rows_total' => count($rows),
                            'valid_total' => count($clean),
                            'errors_total' => count($errors),
                            'errors' => array_slice($errors, 0, 25),
                            'sample' => array_slice($clean, 0, PREVIEW_LIMIT),
                        ];
                    } else {
                        // Count inserts vs updates by code
                        $codes = array_values(array_unique(array_map(fn($r) => $r['code'], $clean)));
                        $existing = [];
                        if ($codes) {
                            $in = implode(',', array_fill(0, count($codes), '?'));
                            $st = $pdo->prepare("SELECT code, id FROM subjects WHERE code IN ($in)");
                            $st->execute($codes);
                            foreach ($st->fetchAll() as $r) $existing[(string)$r['code']] = (int)$r['id'];
                        }

                        $toInsert = 0;
                        $toUpdate = 0;
                        foreach ($clean as $r) {
                            if (isset($existing[$r['code']])) $toUpdate++;
                            else $toInsert++;
                        }

                        if ($dryRun) {
                            $flash = ['type' => 'info', 'msg' => "Dry run OK. Would insert {$toInsert}, update {$toUpdate}."];
                        } else {
                            $pdo->beginTransaction();

                            $sql = "
                                INSERT INTO subjects (code, name, max_points)
                                VALUES (:code, :name, :max_points)
                                ON DUPLICATE KEY UPDATE
                                    name = VALUES(name),
                                    max_points = VALUES(max_points)
                            ";
                            $q = $pdo->prepare($sql);

                            foreach ($clean as $r) {
                                $q->execute([
                                    'code' => $r['code'],
                                    'name' => $r['name'],
                                    'max_points' => $r['max_points'],
                                ]);
                            }

                            $pdo->commit();
                            @unlink($path);
                            unset($_SESSION[IMPORT_SESSION_KEY]);

                            $flash = ['type' => 'success', 'msg' => "Imported successfully. Inserted {$toInsert}, updated {$toUpdate}."];
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $flash = ['type' => 'danger', 'msg' => 'Import failed due to a server error.'];
                }
            }
        }
    }
}

$csrf = csrf_token();
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title mb-3"><i class="bi bi-book me-2"></i>Subjects Import</h5>

        <?php if ($flash): ?>
          <div class="alert alert-<?= h($flash['type']) ?> mb-3"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="preview">

          <div>
            <label class="form-label">Upload CSV/XLSX</label>
            <input type="file" name="file" class="form-control" accept=".csv,.xlsx,.xls" required>
            <div class="form-text">
              Expected columns: code, name, max_points (optional; default 40).
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
          <input type="hidden" name="token" value="<?= h(is_array($ctx) ? (string)($ctx['token'] ?? '') : '') ?>">

          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="dryRun2" name="dry_run" checked>
            <label class="form-check-label" for="dryRun2">
              Dry run (do not write to DB)
            </label>
          </div>

          <button class="btn btn-success" <?= empty($ctx['token']) ? 'disabled' : '' ?>>
            <i class="bi bi-database-add me-1"></i> Run Import
          </button>

          <div class="small text-muted">
            Upsert key: <code>subjects.code</code>.
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-clipboard-data me-2"></i>Preview</h6>

        <?php if (!$preview): ?>
          <div class="text-muted">Upload a file to see preview and validation results.</div>
        <?php else: ?>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge text-bg-secondary">Rows: <?= (int)$preview['rows_total'] ?></span>
            <span class="badge text-bg-success">Valid: <?= (int)$preview['valid_total'] ?></span>
            <span class="badge text-bg-danger">Errors: <?= (int)$preview['errors_total'] ?></span>
            <span class="badge text-bg-light border">File: <?= h((string)$preview['file']) ?></span>
          </div>

          <?php if (!empty($preview['errors'])): ?>
            <div class="alert alert-danger">
              <div class="fw-semibold mb-2">Validation errors (showing first <?= count($preview['errors']) ?>):</div>
              <ul class="mb-0">
                <?php foreach ($preview['errors'] as $e): ?>
                  <li><span class="fw-semibold">Row <?= (int)$e['row'] ?>:</span> <?= h(implode('; ', (array)$e['errors'])) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if (!empty($preview['sample'])): ?>
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th class="text-end">Max</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['sample'] as $r): ?>
                    <tr>
                      <td><code><?= h((string)$r['code']) ?></code></td>
                      <td><?= h((string)$r['name']) ?></td>
                      <td class="text-end"><?= (int)$r['max_points'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
