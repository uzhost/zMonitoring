<?php
// admin/certificates.php - Certificates CRUD (linked to pupils + optional certificate_subjects)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();

$canWrite = in_array(admin_role(), ['admin', 'superadmin'], true);
verify_csrf('csrf');

const CERT_TYPES = ['xalqaro', 'milliy'];
const CERT_STATUSES = ['active', 'expired', 'revoked'];
const CERT_FILE_MAX_BYTES = 5 * 1024 * 1024; // 5 MB

function normalize_cert_type(string $v): string
{
    $v = mb_strtolower(trim($v), 'UTF-8');
    return in_array($v, CERT_TYPES, true) ? $v : '';
}

function post_str(string $key, int $maxLen): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (is_int($v)) return $v;
    if (!is_string($v) || !preg_match('/^\d+$/', trim($v))) return $default;
    return (int)$v;
}

function get_str(string $key, int $maxLen = 120): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function get_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (is_int($v)) return $v;
    if (!is_string($v) || !preg_match('/^\d+$/', trim($v))) return $default;
    return (int)$v;
}

function post_float(string $key): ?float
{
    $v = $_POST[$key] ?? null;
    if (!is_string($v)) return null;
    $v = trim(str_replace(',', '.', $v));
    if ($v === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $v)) return null;
    return (float)$v;
}

function normalize_date(?string $raw): ?string
{
    if (!is_string($raw)) return null;
    $v = trim($raw);
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $dt = DateTime::createFromFormat('Y-m-d', $v);
        return $dt ? $dt->format('Y-m-d') : null;
    }
    $v = str_replace('T', ' ', $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $v);
    return $dt ? $dt->format('Y-m-d') : null;
}

function to_date_value(?string $db): string
{
    if (!is_string($db) || $db === '') return '';
    return substr($db, 0, 10);
}

function set_cert_flash(string $type, string $msg): void
{
    $_SESSION['certificates_flash'] = ['type' => $type, 'msg' => $msg];
}

function take_cert_flash(): ?array
{
    $f = $_SESSION['certificates_flash'] ?? null;
    unset($_SESSION['certificates_flash']);
    return is_array($f) ? $f : null;
}

function table_exists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare("
      SELECT 1
      FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = :name
      LIMIT 1
    ");
    $st->execute([':name' => $name]);
    return (bool)$st->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name
      LIMIT 1
    ");
    $st->execute([':table_name' => $table, ':column_name' => $column]);
    return (bool)$st->fetchColumn();
}

function remove_cert_file(?string $webPath): void
{
    if (!is_string($webPath) || $webPath === '') return;
    if (!str_starts_with($webPath, '/assets/uploads/certificates/')) return;

    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($webPath, '/\\');
    if (is_file($full)) {
        @unlink($full);
    }
}

function store_cert_file(array $file): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'File upload failed.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > CERT_FILE_MAX_BYTES) {
        return ['ok' => false, 'msg' => 'File must be between 1 byte and 5 MB.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'msg' => 'Uploaded file is invalid.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'msg' => 'Only PDF, JPG, PNG, and WEBP files are allowed.'];
    }

    $uploadDir = dirname(__DIR__) . '/assets/uploads/certificates';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'msg' => 'Cannot create upload directory.'];
    }

    $name = date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $dest = $uploadDir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'msg' => 'Failed to save uploaded file.'];
    }

    return ['ok' => true, 'path' => '/assets/uploads/certificates/' . $name];
}

function cert_is_pdf(?string $path): bool
{
    if (!is_string($path) || $path === '') return false;
    $p = parse_url($path, PHP_URL_PATH);
    if (!is_string($p) || $p === '') $p = $path;
    $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));
    return $ext === 'pdf';
}

function pupil_label(array $p): string
{
    $name = trim((string)($p['surname'] ?? '') . ' ' . (string)($p['name'] ?? '') . ' ' . (string)($p['middle_name'] ?? ''));
    if ($name !== '') return $name;
    return (string)($p['student_login'] ?? '-');
}

$schemaReady = table_exists($pdo, 'certificates');
$hasSubjectLookup = table_exists($pdo, 'certificate_subjects');
$schemaIssue = '';
$certFileColumn = null;

if ($schemaReady) {
    if (column_exists($pdo, 'certificates', 'certificate_file')) {
        $certFileColumn = 'certificate_file';
    } elseif (column_exists($pdo, 'certificates', 'certificate_image')) {
        // Backward compatibility for older schema.
        $certFileColumn = 'certificate_image';
    }

    $requiredCols = [
        'pupil_id',
        'subject',
        'name',
        'type',
        'serial_number',
        'level',
        'percentage',
        'issued_time',
        'expire_time',
    ];
    if ($certFileColumn !== null) {
        $requiredCols[] = $certFileColumn;
    } else {
        $requiredCols[] = 'certificate_file';
    }
    $missing = [];
    foreach ($requiredCols as $col) {
        if (!column_exists($pdo, 'certificates', $col)) {
            $missing[] = $col;
        }
    }
    if ($missing) {
        $schemaReady = false;
        $schemaIssue = 'Missing columns in `certificates`: ' . implode(', ', $missing) . '.';
    }
}

$pupilOptions = $pdo->query("
  SELECT id, student_login, surname, name, middle_name, class_code
  FROM pupils
  ORDER BY class_code ASC, surname ASC, name ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$classOptions = [];
$pupilClassById = [];
foreach ($pupilOptions as $p) {
    $cc = trim((string)($p['class_code'] ?? ''));
    if ($cc !== '') $classOptions[$cc] = true;
    $pupilClassById[(int)$p['id']] = $cc;
}
$classOptions = array_keys($classOptions);
sort($classOptions, SORT_NATURAL | SORT_FLAG_CASE);

$certSubjectOptions = [];
$subjectMap = [];
if ($hasSubjectLookup) {
    $certSubjectOptions = $pdo->query("
      SELECT id, code, name
      FROM certificate_subjects
      WHERE is_active = 1
      ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($certSubjectOptions as $s) {
        $subjectMap[(int)$s['id']] = (string)$s['name'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canWrite) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
    if (!$schemaReady) {
        set_cert_flash('danger', 'Certificates schema is not ready. Run tools/sql/2026-02-19_certificates.sql first.');
        header('Location: /admin/certificates.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $action = is_string($action) ? $action : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $id = post_int('id', 0);
            if ($action === 'update' && $id <= 0) {
                set_cert_flash('danger', 'Invalid certificate ID.');
                header('Location: /admin/certificates.php');
                exit;
            }

            $pupilId = post_int('pupil_id', 0);
            $certSubjectId = post_int('certificate_subject_id', 0);
            $certSubjectId = $certSubjectId > 0 ? $certSubjectId : null;
            $subject = ($certSubjectId !== null && isset($subjectMap[$certSubjectId])) ? (string)$subjectMap[$certSubjectId] : '';
            $name = post_str('name', 120);
            $type = normalize_cert_type(post_str('type', 20));
            $serial = post_str('serial_number', 80);
            $level = post_str('level', 60);
            $level = $level === '' ? null : $level;
            $percentage = post_float('percentage');
            $issuedDate = normalize_date(post_str('issued_date', 20));
            if ($issuedDate === null) {
                // Backward compatibility in case old UI is cached.
                $issuedDate = normalize_date(post_str('issued_time', 30));
            }
            $expireDate = normalize_date(post_str('expire_date', 20));
            if ($expireDate === null && post_str('expire_date', 20) === '') {
                $expireDate = null;
            } elseif ($expireDate === null) {
                $expireDate = normalize_date(post_str('expire_time', 30));
            }
            $issued = $issuedDate === null ? null : ($issuedDate . ' 00:00:00');
            $expire = $expireDate === null ? null : ($expireDate . ' 00:00:00');
            $status = post_str('status', 20);
            if (!in_array($status, CERT_STATUSES, true)) $status = 'active';
            $notes = post_str('notes', 500);
            $notes = $notes === '' ? null : $notes;
            $removeFile = (($_POST['remove_file'] ?? '') === '1') || (($_POST['remove_image'] ?? '') === '1');

            if ($pupilId <= 0) throw new RuntimeException('Pupil is required.');
            if ($certSubjectId === null || $subject === '') {
                throw new RuntimeException('Certificate subject is required.');
            }
            if ($name === '' || $serial === '') {
                throw new RuntimeException('Certificate name and serial number are required.');
            }
            if ($type === '') {
                throw new RuntimeException('Type must be either `xalqaro` or `milliy`.');
            }
            if ($percentage === null || $percentage < 0.0 || $percentage > 100.0) {
                throw new RuntimeException('Percentage must be between 0 and 100.');
            }
            if ($issuedDate === null || $issued === null) {
                throw new RuntimeException('Issued date is required.');
            }
            if ($expireDate !== null && $issuedDate !== null && strtotime($expireDate) < strtotime($issuedDate)) {
                throw new RuntimeException('Expire date must be after issued date.');
            }

            $filePath = null;
            if ($action === 'update') {
                $stOld = $pdo->prepare("SELECT {$certFileColumn} AS certificate_file FROM certificates WHERE id = :id LIMIT 1");
                $stOld->execute([':id' => $id]);
                $old = $stOld->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('Certificate not found.');
                $filePath = (string)($old['certificate_file'] ?? '');
                if ($filePath === '') $filePath = null;
            }

            if ($removeFile && $filePath !== null) {
                remove_cert_file($filePath);
                $filePath = null;
            }

            $uploadInput = null;
            if (isset($_FILES['certificate_file']) && is_array($_FILES['certificate_file'])) {
                $uploadInput = $_FILES['certificate_file'];
            } elseif (isset($_FILES['certificate_image']) && is_array($_FILES['certificate_image'])) {
                // Backward compatibility for older form field name.
                $uploadInput = $_FILES['certificate_image'];
            }

            if ($uploadInput !== null) {
                $uploadErr = (int)($uploadInput['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadErr !== UPLOAD_ERR_NO_FILE) {
                    $saved = store_cert_file($uploadInput);
                    if (!$saved['ok']) {
                        throw new RuntimeException((string)$saved['msg']);
                    }
                    $newPath = (string)$saved['path'];
                    if ($filePath !== null && $filePath !== '' && $filePath !== $newPath) {
                        remove_cert_file($filePath);
                    }
                    $filePath = $newPath;
                }
            }

            if ($action === 'create') {
                $st = $pdo->prepare("
                  INSERT INTO certificates
                    (pupil_id, subject, name, `type`, serial_number, `level`, percentage, {$certFileColumn},
                     issued_time, expire_time, certificate_subject_id, status, notes)
                  VALUES
                    (:pupil_id, :subject, :name, :type, :serial_number, :level, :percentage, :certificate_file,
                     :issued_time, :expire_time, :certificate_subject_id, :status, :notes)
                ");
                $st->execute([
                    ':pupil_id' => $pupilId,
                    ':subject' => $subject,
                    ':name' => $name,
                    ':type' => $type,
                    ':serial_number' => $serial,
                    ':level' => $level,
                    ':percentage' => $percentage,
                    ':certificate_file' => $filePath,
                    ':issued_time' => $issued,
                    ':expire_time' => $expire,
                    ':certificate_subject_id' => $certSubjectId,
                    ':status' => $status,
                    ':notes' => $notes,
                ]);
                set_cert_flash('success', 'Certificate created.');
                header('Location: /admin/certificates.php');
                exit;
            }

            $st = $pdo->prepare("
              UPDATE certificates
              SET pupil_id = :pupil_id,
                  subject = :subject,
                  name = :name,
                  `type` = :type,
                  serial_number = :serial_number,
                  `level` = :level,
                  percentage = :percentage,
                  {$certFileColumn} = :certificate_file,
                  issued_time = :issued_time,
                  expire_time = :expire_time,
                  certificate_subject_id = :certificate_subject_id,
                  status = :status,
                  notes = :notes
              WHERE id = :id
            ");
            $st->execute([
                ':id' => $id,
                ':pupil_id' => $pupilId,
                ':subject' => $subject,
                ':name' => $name,
                ':type' => $type,
                ':serial_number' => $serial,
                ':level' => $level,
                ':percentage' => $percentage,
                ':certificate_file' => $filePath,
                ':issued_time' => $issued,
                ':expire_time' => $expire,
                ':certificate_subject_id' => $certSubjectId,
                ':status' => $status,
                ':notes' => $notes,
            ]);

            set_cert_flash('success', 'Certificate updated.');
            header('Location: /admin/certificates.php');
            exit;
        }

        if ($action === 'delete') {
            $id = post_int('id', 0);
            if ($id <= 0) throw new RuntimeException('Invalid certificate ID.');

            $st = $pdo->prepare("SELECT {$certFileColumn} AS certificate_file FROM certificates WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Certificate not found.');
            $file = (string)($row['certificate_file'] ?? '');

            $del = $pdo->prepare("DELETE FROM certificates WHERE id = :id");
            $del->execute([':id' => $id]);

            if ($file !== '') {
                remove_cert_file($file);
            }
            set_cert_flash('success', 'Certificate deleted.');
            header('Location: /admin/certificates.php');
            exit;
        }

        set_cert_flash('danger', 'Unknown action.');
        header('Location: /admin/certificates.php');
        exit;
    } catch (Throwable $e) {
        $msg = 'Error: ' . $e->getMessage();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            $msg = 'Duplicate serial for this certificate name or another constraint conflict.';
        }
        set_cert_flash('danger', $msg);
        header('Location: /admin/certificates.php');
        exit;
    }
}

$q = get_str('q', 120);
$fType = get_str('type', 20);
if (!in_array($fType, CERT_TYPES, true)) $fType = '';
$fStatus = get_str('status', 20);
if (!in_array($fStatus, CERT_STATUSES, true)) $fStatus = '';
$fClassCode = trim(get_str('class_code', 30));
if ($fClassCode !== '' && !in_array($fClassCode, $classOptions, true)) $fClassCode = '';
$fPupilId = get_int('pupil_id', 0);
$page = max(1, get_int('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(c.subject LIKE :q OR c.name LIKE :q OR c.serial_number LIKE :q OR c.level LIKE :q OR p.student_login LIKE :q OR p.surname LIKE :q OR p.name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($fType !== '') {
    $where[] = "c.type = :type";
    $params[':type'] = $fType;
}
if ($fStatus !== '') {
    $where[] = "c.status = :status";
    $params[':status'] = $fStatus;
}
if ($fClassCode !== '') {
    $where[] = "p.class_code = :class_code";
    $params[':class_code'] = $fClassCode;
}
if ($fPupilId > 0) {
    $where[] = "c.pupil_id = :pupil_id";
    $params[':pupil_id'] = $fPupilId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$total = 0;
if ($schemaReady) {
    $subjectSelect = $hasSubjectLookup ? 'cs.name AS cert_subject_name,' : 'NULL AS cert_subject_name,';
    $subjectJoin = $hasSubjectLookup ? 'LEFT JOIN certificate_subjects cs ON cs.id = c.certificate_subject_id' : '';

    $stCount = $pdo->prepare("
      SELECT COUNT(*) AS c
      FROM certificates c
      JOIN pupils p ON p.id = c.pupil_id
      $whereSql
    ");
    foreach ($params as $k => $v) {
        $stCount->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stCount->execute();
    $total = (int)($stCount->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $st = $pdo->prepare("
      SELECT
        c.id, c.pupil_id, c.subject, c.name, c.type, c.serial_number, c.level, c.percentage,
        c.{$certFileColumn} AS certificate_file, c.issued_time, c.expire_time, c.status, c.notes, c.certificate_subject_id,
        $subjectSelect
        p.student_login, p.surname, p.name AS pupil_name, p.middle_name, p.class_code
      FROM certificates c
      JOIN pupils p ON p.id = c.pupil_id
      $subjectJoin
      $whereSql
      ORDER BY c.issued_time DESC, c.id DESC
      LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pages = (int)max(1, (int)ceil($total / $perPage));
$flash = take_cert_flash();

$editId = get_int('edit', 0);
$editRow = null;
if ($schemaReady && $editId > 0) {
    $stEdit = $pdo->prepare("
      SELECT
        c.id, c.pupil_id, c.subject, c.name, c.type, c.serial_number, c.level, c.percentage,
        c.{$certFileColumn} AS certificate_file, c.issued_time, c.expire_time, c.status, c.notes, c.certificate_subject_id
      FROM certificates c
      WHERE c.id = :id
      LIMIT 1
    ");
    $stEdit->execute([':id' => $editId]);
    $editRow = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
}

$baseQuery = [];
if ($q !== '') $baseQuery['q'] = $q;
if ($fType !== '') $baseQuery['type'] = $fType;
if ($fStatus !== '') $baseQuery['status'] = $fStatus;
if ($fClassCode !== '') $baseQuery['class_code'] = $fClassCode;
if ($fPupilId > 0) $baseQuery['pupil_id'] = $fPupilId;

$mkUrl = static function (array $overrides = []) use ($baseQuery): string {
    $qv = $baseQuery;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '' || $v === 0) {
            unset($qv[$k]);
        } else {
            $qv[$k] = $v;
        }
    }
    return '/admin/certificates.php' . ($qv ? ('?' . http_build_query($qv)) : '');
};

$formData = [
    'id' => 0,
    'class_code' => '',
    'pupil_id' => 0,
    'certificate_subject_id' => 0,
    'name' => '',
    'type' => 'xalqaro',
    'serial_number' => '',
    'level' => '',
    'percentage' => '',
    'certificate_file' => '',
    'issued_date' => date('Y-m-d'),
    'expire_date' => '',
    'status' => 'active',
    'notes' => '',
];

if ($editRow) {
    $editPupilId = (int)$editRow['pupil_id'];
    $editType = normalize_cert_type((string)($editRow['type'] ?? ''));
    $formData = [
        'id' => (int)$editRow['id'],
        'class_code' => (string)($pupilClassById[$editPupilId] ?? ''),
        'pupil_id' => $editPupilId,
        'certificate_subject_id' => (int)($editRow['certificate_subject_id'] ?? 0),
        'name' => (string)$editRow['name'],
        'type' => $editType !== '' ? $editType : 'xalqaro',
        'serial_number' => (string)$editRow['serial_number'],
        'level' => (string)($editRow['level'] ?? ''),
        'percentage' => number_format((float)$editRow['percentage'], 2, '.', ''),
        'certificate_file' => (string)($editRow['certificate_file'] ?? ''),
        'issued_date' => to_date_value((string)$editRow['issued_time']),
        'expire_date' => to_date_value((string)($editRow['expire_time'] ?? '')),
        'status' => (string)($editRow['status'] ?? 'active'),
        'notes' => (string)($editRow['notes'] ?? ''),
    ];
}

$page_title = 'Certificates';
$page_subtitle = 'Manage pupil certificates and subject OTM percentage mapping.';
require __DIR__ . '/header.php';
?>

<style nonce="<?= h($cspNonce ?? ($_SESSION['csp_nonce'] ?? '')) ?>">
  .cert-thumb {
    width: 54px;
    height: 54px;
    object-fit: cover;
    border-radius: .5rem;
    border: 1px solid rgba(0,0,0,.12);
  }
  .cert-file-pill { border-radius: .5rem; font-size: .75rem; }
  .mono { font-variant-numeric: tabular-nums; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Certificates</h1>
    <div class="text-muted small">Manage pupil certificates and subject OTM percentage mapping.</div>
  </div>
  <?php if ($canWrite): ?>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="<?= h($mkUrl()) ?>"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset view</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!$schemaReady): ?>
  <div class="alert alert-warning">
    <div class="fw-semibold mb-1">Certificates schema is not ready.</div>
    <div>Run <code>tools/sql/2026-02-19_certificates.sql</code> in your MySQL database.</div>
    <?php if ($schemaIssue !== ''): ?><div class="small mt-1"><?= h($schemaIssue) ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="alert alert-<?= h((string)($flash['type'] ?? 'info')) ?> alert-dismissible fade show" role="alert">
    <?= h((string)($flash['msg'] ?? '')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if ($schemaReady): ?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">
      <?= $editRow ? 'Edit certificate' : 'Add certificate' ?>
    </div>
    <form method="post" action="/admin/certificates.php" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?>
        <input type="hidden" name="id" value="<?= (int)$formData['id'] ?>">
      <?php endif; ?>

      <div class="col-12 col-lg-3">
        <label class="form-label">Class</label>
        <select class="form-select" id="formClassCode" name="class_code">
          <option value="">All classes</option>
          <?php foreach ($classOptions as $cc): ?>
            <option value="<?= h($cc) ?>" <?= (string)$formData['class_code'] === (string)$cc ? 'selected' : '' ?>><?= h($cc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-5">
        <label class="form-label">Pupil</label>
        <select class="form-select" id="formPupilId" name="pupil_id" required>
          <option value="">Select pupil</option>
          <?php foreach ($pupilOptions as $p): ?>
            <?php $pid = (int)$p['id']; ?>
            <?php $pClassCode = trim((string)($p['class_code'] ?? '')); ?>
            <option value="<?= $pid ?>" data-class-code="<?= h($pClassCode) ?>" <?= $pid === (int)$formData['pupil_id'] ? 'selected' : '' ?>>
              <?= h(pupil_label($p)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label">Certificate subject</label>
        <select class="form-select" name="certificate_subject_id" required>
          <option value="0">Select subject</option>
          <?php foreach ($certSubjectOptions as $s): ?>
            <?php $sid = (int)$s['id']; ?>
            <option value="<?= $sid ?>" <?= $sid === (int)$formData['certificate_subject_id'] ? 'selected' : '' ?>>
              <?= h((string)$s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label">Certificate name</label>
        <input type="text" class="form-control" name="name" maxlength="120" required value="<?= h((string)$formData['name']) ?>" placeholder="IELTS / SAT / CEFR">
      </div>

      <div class="col-6 col-md-4 col-lg-2">
        <label class="form-label">Type</label>
        <select class="form-select" name="type" required>
          <?php
            $typeCurrent = normalize_cert_type((string)($formData['type'] ?? ''));
            if ($typeCurrent === '') $typeCurrent = 'xalqaro';
          ?>
          <option value="<?= h($typeCurrent) ?>" selected><?= h($typeCurrent) ?></option>
          <?php foreach (CERT_TYPES as $t): ?>
            <?php if ($t === $typeCurrent) continue; ?>
            <option value="<?= h($t) ?>"><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-4 col-lg-3">
        <label class="form-label">Serial number</label>
        <input type="text" class="form-control" name="serial_number" maxlength="80" required value="<?= h((string)$formData['serial_number']) ?>">
      </div>

      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label">Level (daraja)</label>
        <input type="text" class="form-control" name="level" maxlength="60" value="<?= h((string)$formData['level']) ?>" placeholder="B2 / C1 / 7.5">
      </div>

      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label">Percentage</label>
        <input type="number" class="form-control mono" name="percentage" min="0" max="100" step="0.01" required value="<?= h((string)$formData['percentage']) ?>" placeholder="0-100">
      </div>

      <div class="col-12 col-md-3 col-lg-2">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <?php foreach (CERT_STATUSES as $st): ?>
            <option value="<?= h($st) ?>" <?= $st === (string)$formData['status'] ? 'selected' : '' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label">Issued date</label>
        <input type="date" class="form-control mono" name="issued_date" required value="<?= h((string)$formData['issued_date']) ?>">
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label">Expire date</label>
        <input type="date" class="form-control mono" name="expire_date" value="<?= h((string)$formData['expire_date']) ?>">
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label">Certificate file (PDF/JPG/PNG/WEBP, max 5 MB)</label>
        <input type="file" class="form-control" name="certificate_file" accept="application/pdf,image/jpeg,image/png,image/webp">
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label">Notes</label>
        <textarea class="form-control" name="notes" rows="2" maxlength="500"><?= h((string)$formData['notes']) ?></textarea>
      </div>

      <?php if ($editRow && (string)$formData['certificate_file'] !== ''): ?>
        <div class="col-12 d-flex align-items-center gap-3">
          <?php $isPdfPreview = cert_is_pdf((string)$formData['certificate_file']); ?>
          <a href="<?= h((string)$formData['certificate_file']) ?>" target="_blank" rel="noopener">
            <?php if ($isPdfPreview): ?>
              <span class="badge text-bg-danger cert-file-pill"><i class="bi bi-file-earmark-pdf me-1"></i>PDF file</span>
            <?php else: ?>
              <img src="<?= h((string)$formData['certificate_file']) ?>" alt="Certificate file" class="cert-thumb">
            <?php endif; ?>
          </a>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="remove_file" name="remove_file">
            <label class="form-check-label" for="remove_file">Remove current file</label>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2">
        <?php if ($canWrite): ?>
          <button class="btn btn-primary">
            <i class="bi bi-save me-1"></i> <?= $editRow ? 'Update certificate' : 'Create certificate' ?>
          </button>
        <?php endif; ?>
        <?php if ($editRow): ?>
          <a class="btn btn-outline-secondary" href="<?= h($mkUrl(['edit' => null])) ?>">Cancel edit</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="/admin/certificates.php">
      <div class="col-12 col-md-4">
        <label class="form-label">Search</label>
        <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Serial, name, subject, pupil login/name">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Type</label>
        <select class="form-select" name="type">
          <option value="">All</option>
          <?php foreach (CERT_TYPES as $t): ?>
            <option value="<?= h($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="">All</option>
          <?php foreach (CERT_STATUSES as $st): ?>
            <option value="<?= h($st) ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Class</label>
        <select class="form-select" id="filterClassCode" name="class_code">
          <option value="">All classes</option>
          <?php foreach ($classOptions as $cc): ?>
            <option value="<?= h($cc) ?>" <?= $fClassCode === $cc ? 'selected' : '' ?>><?= h($cc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Pupil</label>
        <select class="form-select" id="filterPupilId" name="pupil_id">
          <option value="0">All pupils</option>
          <?php foreach ($pupilOptions as $p): ?>
            <?php $pid = (int)$p['id']; ?>
            <?php $pClassCode = trim((string)($p['class_code'] ?? '')); ?>
            <option value="<?= $pid ?>" data-class-code="<?= h($pClassCode) ?>" <?= $pid === $fPupilId ? 'selected' : '' ?>><?= h(pupil_label($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-auto">
        <button class="btn btn-outline-secondary" type="submit">Filter</button>
        <a class="btn btn-outline-link" href="/admin/certificates.php">Reset</a>
      </div>
      <div class="col-12 col-md-auto ms-md-auto text-muted small">
        Total: <span class="fw-semibold"><?= (int)$total ?></span>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:80px;">ID</th>
            <th style="min-width:220px;">Pupil</th>
            <th style="min-width:210px;">Certificate</th>
            <th style="min-width:150px;">Subject</th>
            <th class="text-end" style="width:90px;">%</th>
            <th style="width:130px;">Level</th>
            <th style="width:170px;">Issued</th>
            <th style="width:170px;">Expires</th>
            <th style="width:84px;">File</th>
            <th style="width:110px;">Status</th>
            <th class="text-end" style="width:170px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="11" class="text-center text-muted py-4">No certificates found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $cid = (int)$r['id'];
              $typeCls = ((string)$r['type'] === 'xalqaro') ? 'text-bg-primary' : 'text-bg-secondary';
              $status = (string)($r['status'] ?? 'active');
              $statusCls = 'text-bg-success';
              if ($status === 'expired') $statusCls = 'text-bg-warning text-dark';
              if ($status === 'revoked') $statusCls = 'text-bg-danger';
              $pupilName = trim((string)$r['surname'] . ' ' . (string)$r['pupil_name'] . ' ' . (string)($r['middle_name'] ?? ''));
              $editUrl = $mkUrl(['edit' => $cid, 'page' => $page]);
            ?>
            <tr>
              <td class="text-muted"><?= $cid ?></td>
              <td>
                <div class="fw-semibold"><?= h($pupilName !== '' ? $pupilName : (string)$r['student_login']) ?></div>
                <div class="small text-muted"><?php if (!empty($r['class_code'])): ?><?= h((string)$r['class_code']) ?><?php else: ?>-<?php endif; ?></div>
              </td>
              <td>
                <div class="fw-semibold"><?= h((string)$r['name']) ?></div>
                <div class="small text-muted mono"><?= h((string)$r['serial_number']) ?></div>
                <div class="mt-1"><span class="badge <?= h($typeCls) ?>"><?= h((string)$r['type']) ?></span></div>
              </td>
              <td>
                <div><?= h((string)$r['subject']) ?></div>
                <?php if (!empty($r['cert_subject_name'])): ?>
                  <div class="small text-muted">Lookup: <?= h((string)$r['cert_subject_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end mono"><?= h(number_format((float)$r['percentage'], 2)) ?></td>
              <td><?= h((string)($r['level'] ?? '-')) ?></td>
              <td class="small mono"><?= h(substr((string)$r['issued_time'], 0, 10)) ?></td>
              <td class="small mono"><?= !empty($r['expire_time']) ? h(substr((string)$r['expire_time'], 0, 10)) : '-' ?></td>
              <td>
                <?php if (!empty($r['certificate_file'])): ?>
                  <?php $isPdfRow = cert_is_pdf((string)$r['certificate_file']); ?>
                  <a href="<?= h((string)$r['certificate_file']) ?>" target="_blank" rel="noopener">
                    <?php if ($isPdfRow): ?>
                      <span class="badge text-bg-danger cert-file-pill"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</span>
                    <?php else: ?>
                      <img src="<?= h((string)$r['certificate_file']) ?>" alt="Certificate file" class="cert-thumb">
                    <?php endif; ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= h($statusCls) ?>"><?= h($status) ?></span></td>
              <td class="text-end">
                <div class="d-inline-flex gap-1">
                  <a class="btn btn-sm btn-outline-primary" href="<?= h($editUrl) ?>">
                    <i class="bi bi-pencil-square me-1"></i> Edit
                  </a>
                  <?php if ($canWrite): ?>
                    <form method="post" action="/admin/certificates.php" onsubmit="return confirm('Delete this certificate?');">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $cid ?>">
                      <button class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash me-1"></i> Delete
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($pages > 1): ?>
  <nav class="mt-3" aria-label="Certificates pagination">
    <ul class="pagination pagination-sm mb-0">
      <?php
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
      ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= h($mkUrl(['page' => $prev])) ?>">Prev</a>
      </li>
      <li class="page-item disabled"><span class="page-link">Page <?= (int)$page ?> / <?= (int)$pages ?></span></li>
      <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= h($mkUrl(['page' => $next])) ?>">Next</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
<?php endif; ?>

<script nonce="<?= h($cspNonce ?? ($_SESSION['csp_nonce'] ?? '')) ?>">
(function () {
  function bindClassPupil(classId, pupilId) {
    var classEl = document.getElementById(classId);
    var pupilEl = document.getElementById(pupilId);
    if (!classEl || !pupilEl) return;

    function refresh() {
      var classCode = (classEl.value || '').trim();
      var current = pupilEl.value;
      var keep = false;

      for (var i = 0; i < pupilEl.options.length; i++) {
        var opt = pupilEl.options[i];
        if (i === 0) {
          opt.hidden = false;
          opt.disabled = false;
          continue;
        }
        var oc = (opt.getAttribute('data-class-code') || '').trim();
        var show = (classCode === '' || oc === classCode);
        opt.hidden = !show;
        opt.disabled = !show;
        if (show && opt.value === current) keep = true;
      }

      if (!keep && current !== '' && current !== '0') {
        pupilEl.value = pupilEl.options[0] ? pupilEl.options[0].value : '';
      }
    }

    classEl.addEventListener('change', refresh);
    refresh();
  }

  bindClassPupil('formClassCode', 'formPupilId');
  bindClassPupil('filterClassCode', 'filterPupilId');
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
