<?php
// teachers/certificates.php - Read-only certificates view for teacher portal
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
$tguard_allowed_methods = ['GET', 'HEAD'];
$tguard_allowed_levels = [1, 2, 3];
$tguard_login_path = '/teachers/login.php';
$tguard_fallback_path = '/teachers/certificates.php';
$tguard_require_active = true;
require_once __DIR__ . '/../inc/tauth.php';
require_once __DIR__ . '/_tguard.php';

function eh(mixed $v): string
{
    return h((string)($v ?? ''));
}

function gs(string $key, int $maxLen = 120): string
{
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '') return '';
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function gi(string $key): int
{
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '' || !preg_match('/^\d+$/', $v)) return 0;
    return (int)$v;
}

function url_with(array $overrides = []): string
{
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '' || $v === 0) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return '/teachers/certificates.php' . ($q ? ('?' . http_build_query($q)) : '');
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
      WHERE table_schema = DATABASE()
        AND table_name = :table_name
        AND column_name = :column_name
      LIMIT 1
    ");
    $st->execute([':table_name' => $table, ':column_name' => $column]);
    return (bool)$st->fetchColumn();
}

function cert_is_pdf(?string $path): bool
{
    if (!is_string($path) || $path === '') return false;
    $p = parse_url($path, PHP_URL_PATH);
    if (!is_string($p) || $p === '') $p = $path;
    $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));
    return $ext === 'pdf';
}

function pupil_name(array $r): string
{
    $name = trim((string)($r['surname'] ?? '') . ' ' . (string)($r['pupil_name'] ?? '') . ' ' . (string)($r['middle_name'] ?? ''));
    return $name !== '' ? $name : ((string)($r['student_login'] ?? '-'));
}

$teacherCtx = (isset($GLOBALS['tguard_current_teacher']) && is_array($GLOBALS['tguard_current_teacher']))
    ? $GLOBALS['tguard_current_teacher']
    : ((isset($_SESSION['teacher']) && is_array($_SESSION['teacher'])) ? $_SESSION['teacher'] : []);
$teacherLevel = (int)($teacherCtx['level'] ?? teacher_level());
$teacherClassId = (int)($teacherCtx['class_id'] ?? teacher_class_id());
$teacherClassCode = trim((string)($teacherCtx['class_code'] ?? ''));

// Level 1 can view all classes. Level 2/3 are restricted to own class.
$restrictToOwnClass = in_array($teacherLevel, [AUTH_LEVEL_ADMIN, AUTH_LEVEL_TEACHER], true);
$scopeBroken = $restrictToOwnClass && ($teacherClassId <= 0 && $teacherClassCode === '');
$scopeLabel = null;
if ($restrictToOwnClass && !$scopeBroken) {
    $scopeLabel = $teacherClassCode !== '' ? $teacherClassCode : ('Class #' . $teacherClassId);
}

$typeOptions = ['xalqaro', 'milliy'];
$statusOptions = ['active', 'expired', 'revoked'];

$schemaReady = table_exists($pdo, 'certificates');
$hasSubjectLookup = table_exists($pdo, 'certificate_subjects');
$schemaIssue = '';
$certFileColumn = null;

if ($schemaReady) {
    if (column_exists($pdo, 'certificates', 'certificate_file')) {
        $certFileColumn = 'certificate_file';
    } elseif (column_exists($pdo, 'certificates', 'certificate_image')) {
        $certFileColumn = 'certificate_image';
    }

    $required = [
        'pupil_id',
        'subject',
        'name',
        'type',
        'serial_number',
        'level',
        'percentage',
        'issued_time',
        'expire_time',
        'status',
    ];
    if ($certFileColumn === null) {
        $required[] = 'certificate_file';
    } else {
        $required[] = $certFileColumn;
    }

    $missing = [];
    foreach ($required as $col) {
        if (!column_exists($pdo, 'certificates', $col)) {
            $missing[] = $col;
        }
    }
    if ($missing) {
        $schemaReady = false;
        $schemaIssue = 'Missing columns in `certificates`: ' . implode(', ', $missing) . '.';
    }
}

$classOptions = [];
$pupilOptions = [];
if ($schemaReady && !$scopeBroken) {
    $pupilSql = "
      SELECT id, student_login, surname, name, middle_name, class_code, class_id
      FROM pupils
    ";
    $pupilParams = [];
    if ($restrictToOwnClass) {
        if ($teacherClassId > 0) {
            $pupilSql .= " WHERE class_id = :scope_class_id";
            $pupilParams[':scope_class_id'] = $teacherClassId;
        } elseif ($teacherClassCode !== '') {
            $pupilSql .= " WHERE class_code = :scope_class_code";
            $pupilParams[':scope_class_code'] = $teacherClassCode;
        }
    }
    $pupilSql .= " ORDER BY class_code ASC, surname ASC, name ASC, id ASC";

    $stPupils = $pdo->prepare($pupilSql);
    foreach ($pupilParams as $k => $v) {
        $stPupils->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stPupils->execute();
    $pupilOptions = $stPupils->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($pupilOptions as $p) {
        $cc = trim((string)($p['class_code'] ?? ''));
        if ($cc !== '') $classOptions[$cc] = true;
    }
    $classOptions = array_keys($classOptions);
    sort($classOptions, SORT_NATURAL | SORT_FLAG_CASE);
}

$q = gs('q', 120);
$fClass = gs('class_code', 30);
if ($fClass !== '' && !in_array($fClass, $classOptions, true)) $fClass = '';
$fPupil = gi('pupil_id');
$allowedPupilIds = [];
foreach ($pupilOptions as $p) {
    $allowedPupilIds[(int)($p['id'] ?? 0)] = true;
}
if ($fPupil > 0 && !isset($allowedPupilIds[$fPupil])) $fPupil = 0;
$fType = gs('type', 20);
if ($fType !== '' && !in_array($fType, $typeOptions, true)) $fType = '';
$fStatus = gs('status', 20);
if ($fStatus !== '' && !in_array($fStatus, $statusOptions, true)) $fStatus = '';
$page = max(1, gi('page'));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$rows = [];
$total = 0;
$pages = 1;

if ($schemaReady && !$scopeBroken) {
    $where = [];
    $params = [];

    if ($restrictToOwnClass) {
        if ($teacherClassId > 0) {
            $where[] = "p.class_id = :scope_class_id";
            $params[':scope_class_id'] = $teacherClassId;
        } elseif ($teacherClassCode !== '') {
            $where[] = "p.class_code = :scope_class_code";
            $params[':scope_class_code'] = $teacherClassCode;
        }
    }

    if ($q !== '') {
        $where[] = "(c.subject LIKE :q OR c.name LIKE :q OR c.serial_number LIKE :q OR c.level LIKE :q OR p.student_login LIKE :q OR p.surname LIKE :q OR p.name LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    if ($fClass !== '') {
        $where[] = "p.class_code = :class_code";
        $params[':class_code'] = $fClass;
    }
    if ($fPupil > 0) {
        $where[] = "c.pupil_id = :pupil_id";
        $params[':pupil_id'] = $fPupil;
    }
    if ($fType !== '') {
        $where[] = "c.type = :type";
        $params[':type'] = $fType;
    }
    if ($fStatus !== '') {
        $where[] = "c.status = :status";
        $params[':status'] = $fStatus;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
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
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $st = $pdo->prepare("
      SELECT
        c.id, c.subject, c.name, c.type, c.serial_number, c.level, c.percentage,
        c.issued_time, c.expire_time, c.status, c.{$certFileColumn} AS certificate_file,
        $subjectSelect
        p.id AS pupil_id, p.student_login, p.surname, p.name AS pupil_name, p.middle_name, p.class_code
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
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$page_title = 'Certificates';
$page_subtitle = 'View pupil certificate records (read-only).';
require_once __DIR__ . '/header.php';
?>

<style nonce="<?= eh($cspNonce ?? ($_SESSION['csp_nonce'] ?? '')) ?>">
  .cert-table thead th {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #5f6b7a;
    font-weight: 700;
    border-bottom-width: 1px;
    background: linear-gradient(180deg, #f9fbff 0%, #f4f7fb 100%);
  }
  .cert-table tbody tr:hover { background-color: #f7fbff; }
  .cert-id {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.1rem;
    padding: .2rem .5rem;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    color: #334155;
    background: #eef2f7;
  }
  .cert-pupil-name {
    color: #0f172a;
    font-weight: 600;
    line-height: 1.25;
  }
  .cert-meta { font-size: .82rem; color: #64748b; }
  .cert-name {
    color: #0f172a;
    font-weight: 600;
    line-height: 1.25;
  }
  .cert-serial {
    display: inline-block;
    margin-top: .2rem;
    padding: .15rem .5rem;
    border-radius: 999px;
    border: 1px dashed #cbd5e1;
    color: #475569;
    background: #f8fafc;
    font-size: .78rem;
  }
  .cert-type-badge {
    display: inline-block;
    margin-top: .35rem;
    padding: .15rem .5rem;
    border-radius: 999px;
    font-size: .73rem;
    font-weight: 700;
    letter-spacing: .02em;
  }
  .cert-type-international { background: #dbeafe; color: #1d4ed8; }
  .cert-type-national { background: #ede9fe; color: #5b21b6; }
  .cert-subject-main { color: #0f172a; font-weight: 600; line-height: 1.25; }
  .cert-subject-alt { font-size: .82rem; color: #64748b; }
  .cert-percentage { color: #0f4aa0; font-weight: 700; }
  .cert-level-pill {
    display: inline-block;
    padding: .16rem .5rem;
    border-radius: .45rem;
    border: 1px solid #d5e3f5;
    background: #f3f8ff;
    color: #194b9f;
    font-weight: 700;
    min-width: 2.4rem;
    text-align: center;
  }
  .cert-date {
    display: inline-block;
    padding: .15rem .45rem;
    border-radius: .4rem;
    font-size: .8rem;
    color: #334155;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
  }
  .cert-status {
    font-size: .73rem;
    border-radius: 999px;
    padding: .33rem .55rem;
    text-transform: uppercase;
    letter-spacing: .03em;
  }
  .cert-status-active { background: #15803d; color: #fff; }
  .cert-status-expired { background: #facc15; color: #111827; }
  .cert-status-revoked { background: #dc2626; color: #fff; }
  .cert-file-link { display: inline-flex; align-items: center; gap: .4rem; }
  .cert-thumb {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: .5rem;
    border: 1px solid #cbd5e1;
    background: #fff;
  }
  .cert-file-pill {
    border-radius: .5rem;
    font-size: .75rem;
    padding: .4rem .55rem;
    background: #dc2626;
    color: #fff;
  }
  .mono { font-variant-numeric: tabular-nums; }
</style>

<?php if (!$schemaReady): ?>
  <div class="alert alert-warning">
    <div class="fw-semibold mb-1">Certificates schema is not ready.</div>
    <div>Ask admin to run <code>tools/sql/2026-02-19_certificates.sql</code>.</div>
    <?php if ($schemaIssue !== ''): ?><div class="small mt-1"><?= eh($schemaIssue) ?></div><?php endif; ?>
  </div>
<?php elseif ($scopeBroken): ?>
  <div class="alert alert-warning">
    <div class="fw-semibold mb-1">Access scope is not configured.</div>
    <div>Your account is limited to own-class data, but no class is assigned to this teacher profile.</div>
    <div class="small mt-1">Ask Super Teacher/Admin to set <code>teachers.class_id</code> for your account.</div>
  </div>
<?php else: ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="/teachers/certificates.php">
        <?php if ($scopeLabel !== null): ?>
          <div class="col-12">
            <span class="badge text-bg-light border text-dark">
              <i class="bi bi-funnel me-1"></i>Scope: <?= eh($scopeLabel) ?>
            </span>
          </div>
        <?php endif; ?>
        <div class="col-12 col-lg-3">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= eh($q) ?>" placeholder="Serial, cert name, pupil">
        </div>

        <div class="col-6 col-md-3 col-lg-2">
          <label class="form-label">Class</label>
          <select class="form-select" id="filterClassCode" name="class_code">
            <option value="">All classes</option>
            <?php foreach ($classOptions as $cc): ?>
              <option value="<?= eh($cc) ?>" <?= $fClass === $cc ? 'selected' : '' ?>><?= eh($cc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3 col-lg-3">
          <label class="form-label">Pupil</label>
          <select class="form-select" id="filterPupilId" name="pupil_id">
            <option value="0">All pupils</option>
            <?php foreach ($pupilOptions as $p): ?>
              <?php
                $pid = (int)$p['id'];
                $pClass = trim((string)($p['class_code'] ?? ''));
                $label = pupil_name([
                  'surname' => $p['surname'] ?? '',
                  'pupil_name' => $p['name'] ?? '',
                  'middle_name' => $p['middle_name'] ?? '',
                  'student_login' => $p['student_login'] ?? '',
                ]);
              ?>
              <option value="<?= $pid ?>" data-class-code="<?= eh($pClass) ?>" <?= $pid === $fPupil ? 'selected' : '' ?>><?= eh($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3 col-lg-2">
          <label class="form-label">Type</label>
          <select class="form-select" name="type">
            <option value="">All</option>
            <?php foreach ($typeOptions as $t): ?>
              <option value="<?= eh($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= eh($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3 col-lg-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="">All</option>
            <?php foreach ($statusOptions as $s): ?>
              <option value="<?= eh($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= eh($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-lg-auto">
          <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
          <a class="btn btn-outline-secondary" href="/teachers/certificates.php">Reset</a>
        </div>

        <div class="col-12 col-lg-auto ms-lg-auto text-muted small">
          Total certificates: <span class="fw-semibold"><?= (int)$total ?></span>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 cert-table">
          <thead class="table-light">
            <tr>
              <th style="width:70px;">ID</th>
              <th style="min-width:230px;">Pupil</th>
              <th style="min-width:220px;">Certificate</th>
              <th style="min-width:140px;">Subject</th>
              <th class="text-end" style="width:90px;">%</th>
              <th style="width:120px;">Level</th>
              <th style="width:120px;">Issued</th>
              <th style="width:120px;">Expire</th>
              <th style="width:110px;">Status</th>
              <th style="width:88px;">File</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No certificates found in this scope.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $type = (string)($r['type'] ?? '');
                $typeCls = ($type === 'xalqaro') ? 'cert-type-international' : 'cert-type-national';
                $status = (string)($r['status'] ?? 'active');
                $statusCls = 'cert-status-active';
                if ($status === 'expired') $statusCls = 'cert-status-expired';
                if ($status === 'revoked') $statusCls = 'cert-status-revoked';
                $isPdf = cert_is_pdf((string)($r['certificate_file'] ?? ''));
                $pupilLabel = pupil_name($r);
                $subjectPrimary = trim((string)($r['subject'] ?? ''));
                $subjectSecondary = trim((string)($r['cert_subject_name'] ?? ''));
                $showSecondarySubject = $subjectSecondary !== ''
                    && mb_strtolower($subjectSecondary, 'UTF-8') !== mb_strtolower($subjectPrimary, 'UTF-8');
              ?>
              <tr>
                <td><span class="cert-id mono"><?= (int)$r['id'] ?></span></td>
                <td>
                  <div class="cert-pupil-name"><?= eh($pupilLabel) ?></div>
                  <div class="cert-meta"><?= !empty($r['class_code']) ? eh((string)$r['class_code']) : '-' ?></div>
                </td>
                <td>
                  <div class="cert-name"><?= eh((string)$r['name']) ?></div>
                  <div class="cert-serial mono"><?= eh((string)$r['serial_number']) ?></div>
                  <span class="cert-type-badge <?= eh($typeCls) ?>"><?= eh($type) ?></span>
                </td>
                <td>
                  <div class="cert-subject-main"><?= eh($subjectPrimary) ?></div>
                  <?php if ($showSecondarySubject): ?>
                    <div class="cert-subject-alt"><?= eh($subjectSecondary) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end"><span class="cert-percentage mono"><?= eh(number_format((float)$r['percentage'], 2)) ?>%</span></td>
                <td><span class="cert-level-pill"><?= eh((string)($r['level'] ?? '-')) ?></span></td>
                <td><span class="cert-date mono"><?= eh(substr((string)$r['issued_time'], 0, 10)) ?></span></td>
                <td><span class="cert-date mono"><?= !empty($r['expire_time']) ? eh(substr((string)$r['expire_time'], 0, 10)) : '-' ?></span></td>
                <td><span class="badge cert-status <?= eh($statusCls) ?>"><?= eh($status) ?></span></td>
                <td>
                  <?php if (!empty($r['certificate_file'])): ?>
                    <a class="cert-file-link" href="<?= eh((string)$r['certificate_file']) ?>" target="_blank" rel="noopener">
                      <?php if ($isPdf): ?>
                        <span class="badge cert-file-pill"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</span>
                      <?php else: ?>
                        <img src="<?= eh((string)$r['certificate_file']) ?>" alt="Certificate file" class="cert-thumb">
                      <?php endif; ?>
                    </a>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
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
        <?php $prev = max(1, $page - 1); $next = min($pages, $page + 1); ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= eh(url_with(['page' => $prev])) ?>">Prev</a></li>
        <li class="page-item disabled"><span class="page-link">Page <?= (int)$page ?> / <?= (int)$pages ?></span></li>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= eh(url_with(['page' => $next])) ?>">Next</a></li>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<script nonce="<?= eh($cspNonce ?? ($_SESSION['csp_nonce'] ?? '')) ?>">
(function () {
  var classEl = document.getElementById('filterClassCode');
  var pupilEl = document.getElementById('filterPupilId');
  if (!classEl || !pupilEl) return;

  function refreshPupils() {
    var classCode = (classEl.value || '').trim();
    var cur = pupilEl.value;
    var keep = false;
    for (var i = 0; i < pupilEl.options.length; i++) {
      var opt = pupilEl.options[i];
      if (i === 0) {
        opt.hidden = false;
        opt.disabled = false;
        continue;
      }
      var oc = (opt.getAttribute('data-class-code') || '').trim();
      var show = classCode === '' || oc === classCode;
      opt.hidden = !show;
      opt.disabled = !show;
      if (show && opt.value === cur) keep = true;
    }
    if (!keep && cur !== '' && cur !== '0') pupilEl.value = '0';
  }

  classEl.addEventListener('change', refreshPupils);
  refreshPupils();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
