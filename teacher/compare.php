<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

session_start_secure();
require_role('viewer');

/**
 * Highlight rule (percent of max points):
 *  <46%  => danger
 *  <66%  => warning
 *  <86%  => primary
 * >=86%  => success
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
    if ($diff === null) return '<span class="diff-icon text-muted" title="No data">—</span>';
    if ($diff > 0) return '<span class="diff-icon text-success" title="Increase">▲</span>';
    if ($diff < 0) return '<span class="diff-icon text-danger" title="Decrease">▼</span>';
    return '<span class="diff-icon text-muted" title="No change">→</span>';
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
    if (!$e) return $fallbackId > 0 ? ('Exam #' . $fallbackId) : '—';
    $name = trim((string)($e['exam_name'] ?? ''));
    return $name !== '' ? $name : ('Exam #' . (int)($e['id'] ?? $fallbackId));
}

/* -----------------------------
   Load filter options
----------------------------- */

$classes  = $pdo->query("SELECT DISTINCT class_code FROM pupils ORDER BY class_code")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT id, name, max_points FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$exams    = $pdo->query("SELECT id, academic_year, term, exam_name, exam_date FROM exams ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$classCodes = array_map(static fn($r) => (string)$r['class_code'], $classes);

// Defaults
$defaultClass = $classCodes[0] ?? '';
$defaultExam2 = isset($exams[0]) ? (int)$exams[0]['id'] : 0;
$defaultExam1 = isset($exams[1]) ? (int)$exams[1]['id'] : 0;

// Read GET
$classCode   = isset($_GET['class_code']) ? trim((string)$_GET['class_code']) : $defaultClass;
$subjectPick = isset($_GET['subject_id']) ? trim((string)$_GET['subject_id']) : 'all';
$exam1Id     = isset($_GET['exam1_id']) ? safe_int($_GET['exam1_id'], $defaultExam1) : $defaultExam1;
$exam2Id     = isset($_GET['exam2_id']) ? safe_int($_GET['exam2_id'], $defaultExam2) : $defaultExam2;

if ($classCode === '' || !in_array($classCode, $classCodes, true)) {
    $classCode = $defaultClass;
}

$subjectId = null; // null => all
if ($subjectPick !== 'all') {
    $tmp = safe_int($subjectPick, 0);
    $subjectId = $tmp > 0 ? $tmp : null;
}

// Avoid identical exams (keep UX predictable)
if ($exam1Id > 0 && $exam2Id > 0 && $exam1Id === $exam2Id) {
    $exam1Id = 0;
}

$examById = [];
foreach ($exams as $e) $examById[(int)$e['id']] = $e;

$exam1Name = exam_name_only($exam1Id > 0 ? ($examById[$exam1Id] ?? null) : null, $exam1Id);
$exam2Name = exam_name_only($exam2Id > 0 ? ($examById[$exam2Id] ?? null) : null, $exam2Id);

/* -----------------------------
   Prepared statement (positional placeholders)
----------------------------- */

$stmtGroup = $pdo->prepare("
    SELECT
        p.id AS pupil_id,
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
      AND p.class_group = ?
    ORDER BY p.surname, p.name, p.id
");

function fetch_group_rows(PDOStatement $stmtGroup, string $classCode, int $classGroup, int $subjectId, int $exam1Id, int $exam2Id): array
{
    $e1 = $exam1Id > 0 ? $exam1Id : -1;
    $e2 = $exam2Id > 0 ? $exam2Id : -2;

    $stmtGroup->execute([$subjectId, $e1, $subjectId, $e2, $classCode, $classGroup]);
    $rows = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['score1'] = ($r['score1'] === null) ? null : (float)$r['score1'];
        $r['score2'] = ($r['score2'] === null) ? null : (float)$r['score2'];
    }
    unset($r);

    return $rows;
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
    ];
}

/* -----------------------------
   Show taken subjects only (for "All subjects")
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
              AND p.class_group IN (1,2)
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
   Render (header/footer)
----------------------------- */

require __DIR__ . '/header.php';
?>

<style>
  .mono { font-variant-numeric: tabular-nums; font-feature-settings: "tnum" 1; }

  /* Subject card */
  .subject-card { border-radius: 16px; overflow: hidden; }
  .subject-header{
    background: linear-gradient(180deg, rgba(0,0,0,.02), rgba(0,0,0,0));
  }

  /* Group container */
  .group-strip{
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 12px;
    background: #fff;
    padding: .70rem .80rem;
  }
  .mini-kpi{
    display:flex;
    gap:.5rem;
    flex-wrap:wrap;
    align-items:center;
    justify-content:flex-end;
  }
  .mini-kpi .pill{
    border: 1px solid rgba(0,0,0,.10);
    border-radius: 999px;
    padding: .25rem .5rem;
    background: #f8f9fa;
  }

  /* Sticky header */
  .sticky-head thead th{
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8f9fa;
    border-bottom: 1px solid rgba(0,0,0,.12);
    white-space: nowrap;
  }

  /* Fixed layout table so column widths are respected (reduces name width effectively) */
  .compare-table{
    table-layout: fixed;
    width: 100%;
  }

  /* Decrease name width ~50% */
  .name-col{
    width: 28% !important;
    min-width: 180px !important;
    text-align: center;
  }
  .score-col{ width: 12% !important; }
  .diff-col { width: 16% !important; }

  .pupil-name{
    display:block;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 1.00rem;
    text-align: left;
    padding-left: 1.5rem; /* 👈 visual centering */
  }

  /* Dense analytics padding */
  .compare-table td,
  .compare-table th{
    padding-top: .20rem;
    padding-bottom: .20rem;
  }
  td.score-cell, td.diff-cell{
    padding-left: .25rem !important;
    padding-right: .25rem !important;
  }

  /* Score pills (uniform size + larger font) */
  .score-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width: 3.5rem;
    height: 1.90rem;
    font-size: 0.95rem;
    line-height: 1;
    font-weight: 800;
    border-radius: 999px !important;
    padding: 0 .7rem !important;
  }

  /* Difference cell: icon outside colored pill, fixed width */
  .diff-wrap{
    display:inline-flex;
    align-items:center;
    gap:.5rem;
    white-space: nowrap;
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

  /* Small UX polish */
  .table-hover tbody tr:hover{
    background: rgba(13,110,253,.04);
  }
</style>

<div class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <h1 class="h4 mb-0"><i class="bi bi-columns-gap me-2"></i>Group Comparison</h1>
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
    </div>
  </div>

  <!-- Filters -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="compare.php">

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Class</label>
          <select name="class_code" class="form-select">
            <?php foreach ($classCodes as $cc): ?>
              <option value="<?= h_attr($cc) ?>"<?= $cc === $classCode ? ' selected' : '' ?>><?= h($cc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Subject</label>
          <select name="subject_id" class="form-select">
            <option value="all"<?= ($subjectId === null) ? ' selected' : '' ?>>All subjects (taken only)</option>
            <?php foreach ($subjects as $s): ?>
              <?php $sid = (int)$s['id']; ?>
              <option value="<?= $sid ?>"<?= ($subjectId !== null && $sid === $subjectId) ? ' selected' : '' ?>>
                <?= h($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Exam 1</label>
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
          <label class="form-label mb-1">Exam 2</label>
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
            <i class="bi bi-funnel me-1"></i>Apply
          </button>
          <a class="btn btn-outline-secondary" href="compare.php">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
          </a>

          <div class="ms-auto text-muted small d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold">Legend:</span>
            <span class="badge text-bg-danger">&lt;46%</span>
            <span class="badge text-bg-warning text-dark">&lt;66%</span>
            <span class="badge text-bg-primary">&lt;86%</span>
            <span class="badge text-bg-success">&ge;86%</span>
            <span class="ms-2">Δ: ▲/▼/→</span>
          </div>
        </div>

      </form>
    </div>
  </div>

  <?php if ($classCode === '' || !$subjectsToShow): ?>
    <div class="alert alert-warning">No data to display.</div>
  <?php else: ?>

    <?php foreach ($subjectsToShow as $sub): ?>
      <?php
        $sid = (int)$sub['id'];
        $sname = (string)$sub['name'];
        $maxPoints = (int)$sub['max_points'];
        if ($maxPoints <= 0) $maxPoints = 40;

        $g1Rows = fetch_group_rows($stmtGroup, $classCode, 1, $sid, $exam1Id, $exam2Id);
        $g2Rows = fetch_group_rows($stmtGroup, $classCode, 2, $sid, $exam1Id, $exam2Id);

        $g1Avgs = calc_avgs($g1Rows);
        $g2Avgs = calc_avgs($g2Rows);
      ?>

      <div class="card subject-card shadow-sm mb-3">
        <div class="card-header subject-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge text-bg-dark"><i class="bi bi-book me-1"></i><?= h($sname) ?></span>
            <span class="badge text-bg-secondary-subtle border text-secondary-emphasis">Max <?= h((string)$maxPoints) ?></span>
          </div>
          <div class="text-muted small">Group 1 above Group 2</div>
        </div>

        <div class="card-body">
          <!-- Group 1 -->
          <div class="group-strip mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
              <div class="fw-semibold">
                <?= h($classCode) ?> · Group 1
                <span class="text-muted small ms-2">(<?= (int)$g1Avgs['n'] ?> pupils)</span>
              </div>

              <div class="mini-kpi">
                <span class="pill mono">Avg <?= h($exam1Name) ?>: <span class="fw-semibold"><?= h(fmt_score($g1Avgs['avg1'])) ?></span></span>
                <span class="pill mono">Avg <?= h($exam2Name) ?>: <span class="fw-semibold"><?= h(fmt_score($g1Avgs['avg2'])) ?></span></span>
                <span class="pill mono">
                  Δ:
                  <?= diff_icon($g1Avgs['avgd']) ?>
                  <span class="fw-semibold"><?= h($g1Avgs['avgd'] === null ? '—' : (($g1Avgs['avgd'] > 0 ? '+' : '') . fmt_score((float)$g1Avgs['avgd']))) ?></span>
                </span>
              </div>
            </div>

            <div class="table-responsive border rounded-3 bg-white" style="max-height: 80vh;">
              <table class="table table-sm table-hover align-middle mb-0 sticky-head compare-table">
                <thead>
                  <tr>
                    <th class="name-col">Pupil</th>
                    <th class="score-col"><?= h($exam1Name) ?></th>
                    <th class="score-col"><?= h($exam2Name) ?></th>
                    <th class="diff-col">Δ</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$g1Rows): ?>
                    <tr><td colspan="4" class="text-muted">No pupils found in Group 1.</td></tr>
                  <?php else: ?>
                    <?php foreach ($g1Rows as $r): ?>
                      <?php
                        $s1 = $r['score1'];
                        $s2 = $r['score2'];
                        $diff = ($s1 !== null && $s2 !== null) ? ($s2 - $s1) : null;
                        $pupilName = trim((string)$r['surname'] . ' ' . (string)$r['name']);
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

                    <tr class="table-light">
                      <td class="fw-semibold">Average</td>
                      <td class="mono fw-semibold score-cell"><?= h(fmt_score($g1Avgs['avg1'])) ?></td>
                      <td class="mono fw-semibold score-cell"><?= h(fmt_score($g1Avgs['avg2'])) ?></td>
                      <td class="mono fw-semibold diff-cell">
                        <span class="diff-wrap">
                          <?= diff_icon($g1Avgs['avgd']) ?>
                          <span class="badge diff-pill <?= h_attr(diff_badge_class($g1Avgs['avgd'])) ?> mono">
                            <?= $g1Avgs['avgd'] === null ? '—' : h(($g1Avgs['avgd'] > 0 ? '+' : '') . fmt_score((float)$g1Avgs['avgd'])) ?>
                          </span>
                        </span>
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Group 2 -->
          <div class="group-strip">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
              <div class="fw-semibold">
                <?= h($classCode) ?> · Group 2
                <span class="text-muted small ms-2">(<?= (int)$g2Avgs['n'] ?> pupils)</span>
              </div>

              <div class="mini-kpi">
                <span class="pill mono">Avg <?= h($exam1Name) ?>: <span class="fw-semibold"><?= h(fmt_score($g2Avgs['avg1'])) ?></span></span>
                <span class="pill mono">Avg <?= h($exam2Name) ?>: <span class="fw-semibold"><?= h(fmt_score($g2Avgs['avg2'])) ?></span></span>
                <span class="pill mono">
                  Δ:
                  <?= diff_icon($g2Avgs['avgd']) ?>
                  <span class="fw-semibold"><?= h($g2Avgs['avgd'] === null ? '—' : (($g2Avgs['avgd'] > 0 ? '+' : '') . fmt_score((float)$g2Avgs['avgd']))) ?></span>
                </span>
              </div>
            </div>

            <div class="table-responsive border rounded-3 bg-white" style="max-height: 80vh;">
              <table class="table table-sm table-hover align-middle mb-0 sticky-head compare-table">
                <thead>
                  <tr>
                    <th class="name-col">Pupil</th>
                    <th class="score-col"><?= h($exam1Name) ?></th>
                    <th class="score-col"><?= h($exam2Name) ?></th>
                    <th class="diff-col">Δ</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$g2Rows): ?>
                    <tr><td colspan="4" class="text-muted">No pupils found in Group 2.</td></tr>
                  <?php else: ?>
                    <?php foreach ($g2Rows as $r): ?>
                      <?php
                        $s1 = $r['score1'];
                        $s2 = $r['score2'];
                        $diff = ($s1 !== null && $s2 !== null) ? ($s2 - $s1) : null;
                        $pupilName = trim((string)$r['surname'] . ' ' . (string)$r['name']);
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

                    <tr class="table-light">
                      <td class="fw-semibold">Average</td>
                      <td class="mono fw-semibold score-cell"><?= h(fmt_score($g2Avgs['avg1'])) ?></td>
                      <td class="mono fw-semibold score-cell"><?= h(fmt_score($g2Avgs['avg2'])) ?></td>
                      <td class="mono fw-semibold diff-cell">
                        <span class="diff-wrap">
                          <?= diff_icon($g2Avgs['avgd']) ?>
                          <span class="badge diff-pill <?= h_attr(diff_badge_class($g2Avgs['avgd'])) ?> mono">
                            <?= $g2Avgs['avgd'] === null ? '—' : h(($g2Avgs['avgd'] > 0 ? '+' : '') . fmt_score((float)$g2Avgs['avgd'])) ?>
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
      </div>

    <?php endforeach; ?>

  <?php endif; ?>

  <div class="text-muted small mt-3">
    Δ = Exam 2 − Exam 1. ▲ increase, ▼ decrease, → no change. Missing scores show “—”.
  </div>

</div>

<?php require __DIR__ . '/footer.php'; ?>
