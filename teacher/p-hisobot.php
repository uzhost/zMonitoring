<?php
// teacher/hisobot.php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_role('viewer');

/**
 * Baholash ranglari (max ballga nisbatan foiz):
 *  <46%  => qizil (past)
 *  <66%  => sariq (qoniqarli)
 *  <86%  => ko‘k (yaxshi)
 * >=86%  => yashil (a’lo)
 */
function score_badge_class(?float $score, int $maxPoints = 40): string
{
    if ($score === null) return 'text-bg-secondary-subtle border text-secondary-emphasis';
    if ($maxPoints <= 0) $maxPoints = 40;

    $pct = ($score / $maxPoints) * 100.0;
    if ($pct < 46.0) return 'text-bg-danger';
    if ($pct < 66.0) return 'text-bg-warning text-dark';
    if ($pct < 86.0) return 'text-bg-primary';
    return 'text-bg-success';
}

function diff_badge_class(?float $diff): string
{
    if ($diff === null) return 'text-bg-secondary-subtle border text-secondary-emphasis';
    if ($diff > 0) return 'text-bg-success';
    if ($diff < 0) return 'text-bg-danger';
    return 'text-bg-secondary';
}

function diff_icon(?float $diff): string
{
    if ($diff === null) return '<span class="diff-icon text-muted" title="Ma’lumot yo‘q">—</span>';
    if ($diff > 0) return '<span class="diff-icon text-success" title="O‘sish">▲</span>';
    if ($diff < 0) return '<span class="diff-icon text-danger" title="Pasayish">▼</span>';
    return '<span class="diff-icon text-muted" title="O‘zgarishsiz">→</span>';
}

function fmt_score(?float $v): string
{
    if ($v === null) return '—';
    $s = number_format($v, 2, '.', '');
    return (str_ends_with($s, '.00')) ? substr($s, 0, -3) : rtrim(rtrim($s, '0'), '.');
}

function safe_int(mixed $v, int $default = 0): int
{
    if (is_int($v)) return $v;
    if (is_string($v) && preg_match('/^\d+$/', $v)) return (int)$v;
    return $default;
}

function exam_name_only(?array $e, int $fallbackId = 0): string
{
    if (!$e) return $fallbackId > 0 ? ('Imtihon #' . $fallbackId) : '—';
    $name = trim((string)($e['exam_name'] ?? ''));
    return $name !== '' ? $name : ('Imtihon #' . (int)($e['id'] ?? $fallbackId));
}

function calc_avgs(array $rows): array
{
    $sum1 = 0.0; $cnt1 = 0;
    $sum2 = 0.0; $cnt2 = 0;
    $sumd = 0.0; $cntd = 0;

    foreach ($rows as $r) {
        $s1 = $r['score1'];
        $s2 = $r['score2'];

        if ($s1 !== null) { $sum1 += $s1; $cnt1++; }
        if ($s2 !== null) { $sum2 += $s2; $cnt2++; }
        if ($s1 !== null && $s2 !== null) { $sumd += ($s2 - $s1); $cntd++; }
    }

    return [
        'avg1' => $cnt1 ? ($sum1 / $cnt1) : null,
        'avg2' => $cnt2 ? ($sum2 / $cnt2) : null,
        'avgd' => $cntd ? ($sumd / $cntd) : null,
        'n'    => count($rows),
        'c1'   => $cnt1,
        'c2'   => $cnt2,
        'cd'   => $cntd,
    ];
}

/* -----------------------------
   Filtr ma’lumotlari
----------------------------- */

$classes  = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT id, name, max_points FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$exams    = $pdo->query("SELECT id, academic_year, term, exam_name, exam_date FROM exams ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$classCodes = array_map(static fn($r) => (string)$r['class_code'], $classes);

// Defaultlar
$defaultClass = $classCodes[0] ?? '';
$defaultExam2 = isset($exams[0]) ? (int)$exams[0]['id'] : 0;
$defaultExam1 = isset($exams[1]) ? (int)$exams[1]['id'] : 0;

// GET
$classCode   = isset($_GET['class_code']) ? trim((string)$_GET['class_code']) : $defaultClass;
$subjectPick = isset($_GET['subject_id']) ? trim((string)$_GET['subject_id']) : 'all';
$exam1Id     = isset($_GET['exam1_id']) ? safe_int($_GET['exam1_id'], $defaultExam1) : $defaultExam1;
$exam2Id     = isset($_GET['exam2_id']) ? safe_int($_GET['exam2_id'], $defaultExam2) : $defaultExam2;

if ($classCode === '' || !in_array($classCode, $classCodes, true)) {
    $classCode = $defaultClass;
}

$subjectId = null; // null => barcha fanlar
if ($subjectPick !== 'all') {
    $tmp = safe_int($subjectPick, 0);
    $subjectId = $tmp > 0 ? $tmp : null;
}

// Bir xil imtihon tanlansa – 1-imtihonni bo‘shatamiz (UX)
if ($exam1Id > 0 && $exam2Id > 0 && $exam1Id === $exam2Id) {
    $exam1Id = 0;
}

$examById = [];
foreach ($exams as $e) $examById[(int)$e['id']] = $e;

$exam1Name = exam_name_only($exam1Id > 0 ? ($examById[$exam1Id] ?? null) : null, $exam1Id);
$exam2Name = exam_name_only($exam2Id > 0 ? ($examById[$exam2Id] ?? null) : null, $exam2Id);

/* -----------------------------
   FULL CLASS (guruhga ajratmasdan)
----------------------------- */

$stmtClass = $pdo->prepare("
    SELECT
        p.id AS pupil_id,
        p.class_group,
        p.surname,
        p.name,
        r1.score AS score1,
        r2.score AS score2
    FROM pupils p
    LEFT JOIN results r1
        ON r1.pupil_id = p.id AND r1.subject_id = ? AND r1.exam_id = ?
    LEFT JOIN results r2
        ON r2.pupil_id = p.id AND r2.subject_id = ? AND r2.exam_id = ?
    WHERE p.class_code = ?
    ORDER BY p.surname, p.name, p.id
");

function fetch_class_rows(PDOStatement $stmtClass, string $classCode, int $subjectId, int $exam1Id, int $exam2Id): array
{
    // Exam tanlanmagan holatda LEFT JOIN barqarorligi uchun manfiy id
    $e1 = $exam1Id > 0 ? $exam1Id : -1;
    $e2 = $exam2Id > 0 ? $exam2Id : -2;

    $stmtClass->execute([$subjectId, $e1, $subjectId, $e2, $classCode]);
    $rows = $stmtClass->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['class_group'] = ($r['class_group'] === null) ? null : (int)$r['class_group'];
        $r['score1'] = ($r['score1'] === null) ? null : (float)$r['score1'];
        $r['score2'] = ($r['score2'] === null) ? null : (float)$r['score2'];
    }
    unset($r);

    return $rows;
}

/* -----------------------------
   “Barcha fanlar” tanlanganda faqat mavjud natijalar asosida fanlarni ko‘rsatish
----------------------------- */

$subjectsById = [];
foreach ($subjects as $s) {
    $subjectsById[(int)$s['id']] = [
        'id' => (int)$s['id'],
        'name' => (string)$s['name'],
        'max_points' => (int)$s['max_points'],
    ];
}

$subjectsToShow = [];

if ($subjectId !== null) {
    if (isset($subjectsById[$subjectId])) {
        $subjectsToShow[] = $subjectsById[$subjectId];
    } else {
        $subjectId = null;
    }
}

if ($subjectId === null) {
    $examIds = [];
    if ($exam1Id > 0) $examIds[] = $exam1Id;
    if ($exam2Id > 0 && $exam2Id !== $exam1Id) $examIds[] = $exam2Id;

    if (!$examIds) {
        foreach ($subjectsById as $s) $subjectsToShow[] = $s;
    } else {
        $in = implode(',', array_fill(0, count($examIds), '?'));
        $stmtTaken = $pdo->prepare("
            SELECT DISTINCT r.subject_id
            FROM results r
            JOIN pupils p ON p.id = r.pupil_id
            WHERE p.class_code = ?
              AND r.exam_id IN ($in)
        ");
        $stmtTaken->execute(array_merge([$classCode], $examIds));
        $takenIds = $stmtTaken->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($takenIds as $sid) {
            $sid = (int)$sid;
            if (isset($subjectsById[$sid])) $subjectsToShow[] = $subjectsById[$sid];
        }

        usort($subjectsToShow, static fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    }
}

/* -----------------------------
   Render
----------------------------- */
require __DIR__ . '/p-header.php';
?>

<style>
  .mono { font-variant-numeric: tabular-nums; font-feature-settings: "tnum" 1; }

  .subject-card { border-radius: 16px; overflow: hidden; }
  .subject-header { background: linear-gradient(180deg, rgba(0,0,0,.02), rgba(0,0,0,0)); }

  /* Sticky header (faqat ekranda) */
  @media screen {
    .sticky-head thead th{
      position: sticky;
      top: 0;
      z-index: 2;
      background: #f8f9fa;
      border-bottom: 1px solid rgba(0,0,0,.12);
      white-space: nowrap;
    }
  }

  .compare-table { table-layout: fixed; width: 100%; }

  /* Ustunlar */
  .g-col{ width: 6% !important; min-width: 52px !important; text-align:center; }
  .name-col{ width: 34% !important; min-width: 200px !important; }
  .score-col{ width: 14% !important; }
  .diff-col { width: 18% !important; }

  /* Farq ustuni markazda */
  th.diff-col, td.diff-cell { text-align: center !important; }

  /* Score ustunlari ham markazda (o‘rtacha satrda “siljish” bartaraf etiladi) */
  th.score-col, td.score-cell { 
  text-align: center !important;
    }

  .pupil-name{
    display:block;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .compare-table td, .compare-table th{
    padding-top: .20rem;
    padding-bottom: .20rem;
    vertical-align: middle;
  }

  td.score-cell, td.diff-cell{
    padding-left: .25rem !important;
    padding-right: .25rem !important;
  }

  .score-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width: 3.5rem;
    height: 1.85rem;
    font-size: 0.95rem;
    line-height: 1;
    font-weight: 800;
    border-radius: 999px !important;
    padding: 0 .7rem !important;
  }

  /* O‘rtacha satr sonlari ham pill kengligida markazda turishi uchun */
  .avg-num{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width: 3.5rem;     /* .score-pill bilan bir xil */
    height: 1.90rem;       /* ekran uchun optik barqarorlik */
    font-weight: 800;
  }

  .diff-wrap{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:.5rem;
    white-space: nowrap;
    width: 100%;
  }
  .diff-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width: 1.25rem;
    height: 1.25rem;
    font-size: 1.00rem;
    line-height: 1;
    font-weight: 900;
  }
  .diff-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width: 4.4rem;
    height: 1.90rem;
    font-size: 0.95rem;
    line-height: 1;
    font-weight: 800;
    border-radius: 999px !important;
    padding: 0 .8rem !important;
  }

  .table-hover tbody tr:hover{
    background: rgba(13,110,253,.04);
  }

  /* Print-friendly (hisobot ko‘rinishi) */
  @media print {
    @page { margin: 10mm; }

    body { background: #fff !important; }
    .container-fluid { padding: 0 !important; }

    /* Ilova header/footer/UI elementlari bosmada chiqmasin */
    .no-print, nav, .navbar, header, footer, .footer,
    .btn, .form-select, .form-label, .breadcrumb,
    .offcanvas, .toast-container, .modal { display: none !important; }

    .print-only { display: block !important; }

    /* Jadval sarlavhasi har sahifada takrorlansin */
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
    tr { break-inside: avoid; page-break-inside: avoid; }

    /* Sticky / scroll bekor */
    .sticky-head thead th.score-col{
      text-align: center !important;
    }
    .table-responsive { overflow: visible !important; border: none !important; }

    /* Hisobotga xos ko‘rinish */
    .card, .subject-card {
      box-shadow: none !important;
      border: 1px solid rgba(0,0,0,.35) !important;
      border-radius: 10px !important;
    }
    .card-header { background: #fff !important; }

    .compare-table { border-collapse: collapse !important; }
    .compare-table th, .compare-table td {
      padding: .10rem .18rem !important;
      border: 1px solid rgba(0,0,0,.22) !important;
    }

    .score-pill, .diff-pill, .avg-num {
      height: 1.45rem !important;
      font-size: .82rem !important;
    }

    /* Ranglar xiralashmasin */
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    /* Sahifa bo‘linishi */
    .subject-card { break-inside: avoid; page-break-inside: avoid; }
    .page-break { break-after: page; page-break-after: always; }
  }

  .print-only { display:none; }

  /* Bosma sarlavha dizayni */
  .print-title { font-size: 15px; font-weight: 800; letter-spacing: .2px; }
  .print-meta { font-size: 12px; color: #444; }
  .print-kpi { font-size: 12px; }
</style>

<div class="container-fluid py-4">

  <!-- EKRAN: sahifa sarlavhasi -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <h1 class="h4 mb-0">
        <i class="bi bi-clipboard-data me-2"></i>Sinf kesimidagi taqqoslama tahlil
      </h1>
      <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">
        <i class="bi bi-people me-1"></i><?= h($classCode) ?>
      </span>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="badge text-bg-light border text-dark">
        <i class="bi bi-journal-text me-1"></i><?= h($exam1Name) ?>
      </span>
      <span class="text-muted">vs</span>
      <span class="badge text-bg-light border text-dark">
        <i class="bi bi-journal-text me-1"></i><?= h($exam2Name) ?>
      </span>

      <button type="button" class="btn btn-outline-dark ms-2" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Chop etish
      </button>
    </div>
  </div>

  <!-- FILTRLAR -->
  <div class="card shadow-sm mb-3 no-print">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="hisobot.php">

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Sinf</label>
          <select name="class_code" class="form-select">
            <?php foreach ($classCodes as $cc): ?>
              <option value="<?= h_attr($cc) ?>"<?= $cc === $classCode ? ' selected' : '' ?>><?= h($cc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Fan</label>
          <select name="subject_id" class="form-select">
            <option value="all"<?= ($subjectId === null) ? ' selected' : '' ?>>Barcha fanlar (faqat mavjud natijalar)</option>
            <?php foreach ($subjects as $s): ?>
              <?php $sid = (int)$s['id']; ?>
              <option value="<?= $sid ?>"<?= ($subjectId !== null && $sid === $subjectId) ? ' selected' : '' ?>>
                <?= h($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">1-imtihon</label>
          <select name="exam1_id" class="form-select">
            <option value="0">—</option>
            <?php foreach ($exams as $e): ?>
              <?php $id = (int)$e['id']; ?>
              <option value="<?= $id ?>"<?= ($id === $exam1Id) ? ' selected' : '' ?>>
                <?= h(exam_name_only($e, $id)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">2-imtihon</label>
          <select name="exam2_id" class="form-select">
            <option value="0">—</option>
            <?php foreach ($exams as $e): ?>
              <?php $id = (int)$e['id']; ?>
              <option value="<?= $id ?>"<?= ($id === $exam2Id) ? ' selected' : '' ?>>
                <?= h(exam_name_only($e, $id)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 d-flex flex-wrap gap-2 mt-2">
          <button class="btn btn-primary">
            <i class="bi bi-funnel me-1"></i>Qo‘llash
          </button>
          <a class="btn btn-outline-secondary" href="hisobot.php">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Qayta tiklash
          </a>

          <div class="ms-auto text-muted small d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold">Me’zon (foiz):</span>
            <span class="badge text-bg-danger">&lt;46%</span>
            <span class="badge text-bg-warning text-dark">&lt;66%</span>
            <span class="badge text-bg-primary">&lt;86%</span>
            <span class="badge text-bg-success">&ge;86%</span>
            <span class="ms-2">Farq: ▲/▼/→</span>
          </div>
        </div>

      </form>
    </div>
  </div>

  <?php if ($classCode === '' || !$subjectsToShow): ?>
    <div class="alert alert-warning">Ko‘rsatish uchun ma’lumot topilmadi.</div>
  <?php else: ?>

    <?php foreach ($subjectsToShow as $idx => $sub): ?>
      <?php
        $sid = (int)$sub['id'];
        $sname = (string)$sub['name'];
        $maxPoints = (int)$sub['max_points'];
        if ($maxPoints <= 0) $maxPoints = 40;

        $rows = fetch_class_rows($stmtClass, $classCode, $sid, $exam1Id, $exam2Id);
        $avgs = calc_avgs($rows);

        $avgDiffStr = ($avgs['avgd'] === null) ? '—' : (($avgs['avgd'] > 0 ? '+' : '') . fmt_score((float)$avgs['avgd']));
      ?>

      <!-- PRINT: sarlavha (har bir fan bo‘limi oldidan) -->
      <div class="print-only mb-2">
        <div class="print-title">Sinf: <?= h($classCode) ?> — fanlar kesimida tahlil</div>
        <div class="print-meta">
          <?= h($exam1Name) ?> va <?= h($exam2Name) ?> qiyosiy tahlili.
          Fan: <?= h($sname) ?>.
        </div>
        <div class="print-kpi mt-1">
          O‘quvchilar soni: <span class="fw-semibold"><?= (int)$avgs['n'] ?></span>.
          O‘rtacha ball: <?= h($exam1Name) ?> — <span class="fw-semibold"><?= h(fmt_score($avgs['avg1'])) ?></span>,
          <?= h($exam2Name) ?> — <span class="fw-semibold"><?= h(fmt_score($avgs['avg2'])) ?></span>,
          Farq — <span class="fw-semibold"><?= h($avgDiffStr) ?></span>.
        </div>
        <hr class="my-2">
      </div>

      <!-- EKRAN: kartochka -->
      <div class="card subject-card shadow-sm mb-3">
        <div class="card-header subject-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge text-bg-dark"><i class="bi bi-book me-1"></i><?= h($sname) ?></span>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">Maks. <?= h((string)$maxPoints) ?></span>
            <span class="text-muted small ms-1">(<?= (int)$avgs['n'] ?> nafar)</span>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge text-bg-light border text-dark mono"><?= h($exam1Name) ?>: <span class="fw-semibold"><?= h(fmt_score($avgs['avg1'])) ?></span></span>
            <span class="badge text-bg-light border text-dark mono"><?= h($exam2Name) ?>: <span class="fw-semibold"><?= h(fmt_score($avgs['avg2'])) ?></span></span>
            <span class="badge text-bg-light border text-dark mono">
              Farq:
              <?= diff_icon($avgs['avgd']) ?>
              <span class="fw-semibold"><?= h($avgDiffStr) ?></span>
            </span>
          </div>
        </div>

        <div class="card-body">
          <div class="table-responsive border rounded-3 bg-white">
            <table class="table table-sm table-hover align-middle mb-0 sticky-head compare-table">
              <thead>
                <tr>
                  <th class="name-col">O‘quvchi (familiya, ism)</th>
                 <th class="score-col text-center"><?= h($exam1Name) ?></th>
                 <th class="score-col text-center"><?= h($exam2Name) ?></th>
                  <th class="diff-col text-center">Farq</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="5" class="text-muted">Ushbu fan bo‘yicha o‘quvchilar ro‘yxati topilmadi.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $s1 = $r['score1'];
                      $s2 = $r['score2'];
                      $diff = ($s1 !== null && $s2 !== null) ? ($s2 - $s1) : null;
                      $pupilName = trim((string)$r['surname'] . ' ' . (string)$r['name']);
                      $g = $r['class_group'];
                    ?>
                    <tr>
                      <td class="fw-medium">
                        <span class="pupil-name" title="<?= h_attr($pupilName) ?>"><?= h($pupilName) ?></span>
                      </td>

                      <td class="mono score-cell">
                        <span class="badge score-pill <?= h_attr(score_badge_class($s1, $maxPoints)) ?> mono">
                          <?= h(fmt_score($s1)) ?>
                        </span>
                      </td>

                      <td class="mono score-cell">
                        <span class="badge score-pill <?= h_attr(score_badge_class($s2, $maxPoints)) ?> mono">
                          <?= h(fmt_score($s2)) ?>
                        </span>
                      </td>

                      <td class="mono diff-cell">
                        <span class="diff-wrap">
                          <?= diff_icon($diff) ?>
                          <span class="badge diff-pill <?= h_attr(diff_badge_class($diff)) ?> mono">
                            <?= $diff === null ? '—' : h(($diff > 0 ? '+' : '') . fmt_score($diff)) ?>
                          </span>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <!-- O‘rtacha satr: avg1/avg2 ham optik markazda -->
                  <tr class="table-light text-center">
                    
                    <td class="fw-semibold text-center">O‘rtacha ko‘rsatkich</td>
                    <td class="mono fw-semibold score-cell text-center">
                      <span class="avg-num mono"><?= h(fmt_score($avgs['avg1'])) ?></span>
                    </td>
                    <td class="mono fw-semibold score-cell text-center">
                      <span class="avg-num mono"><?= h(fmt_score($avgs['avg2'])) ?></span>
                    </td>
                    <td class="mono fw-semibold diff-cell text-center">
                      <span class="diff-wrap justify-content-center">
                        <?= diff_icon($avgs['avgd']) ?>
                        <span class="badge diff-pill <?= h_attr(diff_badge_class($avgs['avgd'])) ?> mono">
                          <?= h($avgDiffStr) ?>
                        </span>
                      </span>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php if ($idx < count($subjectsToShow) - 1): ?>
        <div class="page-break"></div>
      <?php endif; ?>

    <?php endforeach; ?>

  <?php endif; ?>

</div>

<?php require __DIR__ . '/p-footer.php'; ?>
