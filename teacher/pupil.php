<?php
// teacher/pupil.php - Pupil profile and results view for teacher portal (read-only)

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_guard.php';

const PASS_SCORE = 18.4;
const GOOD_SCORE = 26.4;
const EXCELLENT_SCORE = 34.4;

function eh(mixed $v): string
{
    return h((string)($v ?? ''));
}

function qint(string $key): int
{
    return (int)($_GET[$key] ?? 0);
}

function qstr(string $key, int $maxLen = 80): string
{
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '') return '';
    return mb_substr($v, 0, $maxLen, 'UTF-8');
}

function score_badge(float $score): string
{
    if ($score < PASS_SCORE) return 'text-bg-danger';
    if ($score < GOOD_SCORE) return 'text-bg-warning text-dark';
    if ($score < EXCELLENT_SCORE) return 'text-bg-primary';
    return 'text-bg-success';
}

function delta_badge(?float $delta): array
{
    if ($delta === null) return ['cls' => 'text-bg-secondary', 'txt' => '-', 'ic' => 'bi-dash'];
    if ($delta > 0.00001) return ['cls' => 'text-bg-success', 'txt' => '+' . number_format($delta, 2), 'ic' => 'bi-arrow-up-right'];
    if ($delta < -0.00001) return ['cls' => 'text-bg-danger', 'txt' => number_format($delta, 2), 'ic' => 'bi-arrow-down-right'];
    return ['cls' => 'text-bg-secondary', 'txt' => '0.00', 'ic' => 'bi-dash'];
}

function exam_label(array $e): string
{
    $ay = (string)($e['academic_year'] ?? '');
    $t = ($e['term'] === null || $e['term'] === '') ? '-' : (string)$e['term'];
    $nm = (string)($e['exam_name'] ?? '');
    $d = (string)($e['exam_date'] ?? '');
    return trim($ay . ' T' . $t . ' - ' . $nm . ($d !== '' ? ' (' . $d . ')' : ''));
}

function url_with(string $base, array $params, array $overrides = []): string
{
    $q = $params;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return $base . ($q ? ('?' . http_build_query($q)) : '');
}

function class_rank_for_exam(PDO $pdo, int $pupilId, string $classCode, int $examId): array
{
    $st = $pdo->prepare("
      SELECT p.id AS pupil_id, SUM(r.score) AS total_score
      FROM results r
      JOIN pupils p ON p.id = r.pupil_id
      WHERE p.class_code = :class_code
        AND r.exam_id = :exam_id
      GROUP BY p.id
      ORDER BY total_score DESC, p.id ASC
    ");
    $st->execute([':class_code' => $classCode, ':exam_id' => $examId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $count = count($rows);
    if ($count === 0) return ['rank' => null, 'count' => 0];

    $rank = null;
    $prevScore = null;
    $currentRank = 0;
    $position = 0;

    foreach ($rows as $r) {
        $position++;
        $score = (float)$r['total_score'];
        if ($prevScore === null || abs($score - $prevScore) > 0.00001) {
            $currentRank = $position;
        }
        if ((int)$r['pupil_id'] === $pupilId) {
            $rank = $currentRank;
            break;
        }
        $prevScore = $score;
    }

    return ['rank' => $rank, 'count' => $count];
}

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/teacher/pupil.php');
$teacherBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
$teacherBase = ($teacherBase === '') ? '/teacher' : $teacherBase;
$selfUrl = $teacherBase . '/pupil.php';
$dashboardUrl = $teacherBase . '/dashboard.php';
$reportsUrl = $teacherBase . '/reports.php';

$inputId = max(0, qint('id'));
$inputLogin = qstr('student_login', 40);
if ($inputLogin !== '' && !preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $inputLogin)) {
    $inputLogin = '';
}

$filterYear = qstr('year', 16);
$filterExamId = max(0, qint('exam_id'));

$errors = [];
$pupil = null;
$baseParams = [];

if ($inputId > 0) {
    $st = $pdo->prepare("
      SELECT id, student_login, surname, name, middle_name, class_code, track
      FROM pupils
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $inputId]);
    $pupil = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pupil) $errors[] = 'Pupil not found for the provided ID.';
} elseif ($inputLogin !== '') {
    $st = $pdo->prepare("
      SELECT id, student_login, surname, name, middle_name, class_code, track
      FROM pupils
      WHERE student_login = :student_login
      LIMIT 1
    ");
    $st->execute([':student_login' => $inputLogin]);
    $pupil = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pupil) $errors[] = 'Pupil not found for the provided student login.';
}

$examRows = [];
$yearOptions = [];
$examsFiltered = [];
$selectedExam = null;
$selectedExamId = 0;
$prevExam = null;
$subjectRows = [];
$timelineLabels = [];
$timelineTotals = [];
$compareLabels = [];
$compareNow = [];
$comparePrev = [];
$hasComparison = false;

$totalNow = null;
$avgNow = null;
$subjectCount = 0;
$passCount = 0;
$passRate = null;
$deltaTotal = null;
$deltaAvg = null;
$classRank = ['rank' => null, 'count' => 0];

if ($pupil) {
    $pupilId = (int)$pupil['id'];
    $baseParams = ['id' => $pupilId];

    $st = $pdo->prepare("
      SELECT
        e.id,
        e.academic_year,
        e.term,
        e.exam_name,
        e.exam_date,
        COUNT(*) AS n_subjects,
        SUM(r.score) AS total_score,
        AVG(r.score) AS avg_score
      FROM results r
      JOIN exams e ON e.id = r.exam_id
      WHERE r.pupil_id = :pupil_id
      GROUP BY e.id, e.academic_year, e.term, e.exam_name, e.exam_date
      ORDER BY e.academic_year DESC, COALESCE(e.exam_date,'0000-00-00') DESC, e.id DESC
    ");
    $st->execute([':pupil_id' => $pupilId]);
    $examRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($examRows as $e) {
        $y = (string)($e['academic_year'] ?? '');
        if ($y !== '') $yearOptions[$y] = true;
    }
    $yearOptions = array_keys($yearOptions);
    rsort($yearOptions, SORT_NATURAL);

    if ($filterYear !== '' && !in_array($filterYear, $yearOptions, true)) {
        $filterYear = '';
    }

    foreach ($examRows as $e) {
        if ($filterYear === '' || (string)$e['academic_year'] === $filterYear) {
            $examsFiltered[] = $e;
        }
    }

    if ($filterExamId > 0) {
        foreach ($examsFiltered as $e) {
            if ((int)$e['id'] === $filterExamId) {
                $selectedExamId = $filterExamId;
                break;
            }
        }
    }
    if ($selectedExamId <= 0 && !empty($examsFiltered)) {
        $selectedExamId = (int)$examsFiltered[0]['id'];
    }

    if ($selectedExamId > 0) {
        foreach ($examRows as $i => $e) {
            if ((int)$e['id'] === $selectedExamId) {
                $selectedExam = $e;
                if (isset($examRows[$i + 1])) $prevExam = $examRows[$i + 1];
                break;
            }
        }
    }

    if ($selectedExamId > 0) {
        $prevScores = [];
        if ($prevExam) {
            $pst = $pdo->prepare("
              SELECT subject_id, score
              FROM results
              WHERE pupil_id = :pupil_id AND exam_id = :exam_id
            ");
            $pst->execute([':pupil_id' => $pupilId, ':exam_id' => (int)$prevExam['id']]);
            foreach (($pst->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                $prevScores[(int)$r['subject_id']] = (float)$r['score'];
            }
        }

        $sst = $pdo->prepare("
          SELECT s.id AS subject_id, s.code, s.name, s.max_points, r.score
          FROM results r
          JOIN subjects s ON s.id = r.subject_id
          WHERE r.pupil_id = :pupil_id
            AND r.exam_id = :exam_id
          ORDER BY s.name ASC
        ");
        $sst->execute([':pupil_id' => $pupilId, ':exam_id' => $selectedExamId]);
        $subjectRows = $sst->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $subjectCount = count($subjectRows);
        foreach ($subjectRows as &$r) {
            $score = (float)$r['score'];
            $maxPoints = (float)$r['max_points'];
            $sid = (int)$r['subject_id'];
            $prev = $prevScores[$sid] ?? null;
            $delta = ($prev === null) ? null : ($score - $prev);
            $pct = ($maxPoints > 0) ? (($score / $maxPoints) * 100.0) : null;

            $r['delta'] = $delta;
            $r['prev_score'] = $prev;
            $r['pct'] = $pct;
            $r['band'] = score_badge($score);
            if ($score >= PASS_SCORE) $passCount++;
        }
        unset($r);

        foreach ($subjectRows as $r) {
            $compareLabels[] = (string)$r['name'];
            $compareNow[] = (float)$r['score'];
            $comparePrev[] = isset($r['prev_score']) ? $r['prev_score'] : null;
            if ($r['prev_score'] !== null) $hasComparison = true;
        }

        $totalNow = (float)($selectedExam['total_score'] ?? 0.0);
        $avgNow = (float)($selectedExam['avg_score'] ?? 0.0);
        $passRate = ($subjectCount > 0) ? ($passCount / $subjectCount) : null;

        if ($prevExam) {
            $totalPrev = (float)($prevExam['total_score'] ?? 0.0);
            $avgPrev = (float)($prevExam['avg_score'] ?? 0.0);
            $deltaTotal = $totalNow - $totalPrev;
            $deltaAvg = $avgNow - $avgPrev;
        }

        $classRank = class_rank_for_exam($pdo, $pupilId, (string)$pupil['class_code'], $selectedExamId);
    }

    $chrono = array_reverse($examRows);
    foreach ($chrono as $e) {
        $timelineLabels[] = exam_label($e);
        $timelineTotals[] = (float)($e['total_score'] ?? 0.0);
    }
}

$page_title = 'Pupil Profile';
require_once __DIR__ . '/header.php';
?>

<style nonce="<?= eh($cspNonce ?? ($_SESSION['csp_nonce'] ?? '')) ?>">
  .top-section-card {
    border: 1px solid rgba(15,23,42,.08);
    border-radius: .8rem;
    background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.98));
  }
  .top-section-card .card-body { padding: 1.15rem 1.25rem; }
  .hero-subtitle { color: rgba(30,41,59,.75); }
  .action-btn {
    border-radius: .7rem;
    padding: .55rem .95rem;
  }
  .form-label-tight {
    font-size: .78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .045em;
    color: rgba(30,41,59,.72);
    margin-bottom: .35rem;
  }
  .form-control-soft,
  .form-select-soft {
    border-color: rgba(148,163,184,.45);
    background: #fff;
    border-radius: .65rem;
  }
  .form-control-soft:focus,
  .form-select-soft:focus {
    border-color: rgba(37,99,235,.45);
    box-shadow: 0 0 0 .2rem rgba(37,99,235,.12);
  }
  .person-name {
    font-size: 1.95rem;
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -.01em;
  }
  .pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-top: .55rem;
  }
  .soft-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border: 1px solid rgba(148,163,184,.45);
    border-radius: 999px;
    padding: .24rem .6rem;
    font-size: .78rem;
    color: rgba(30,41,59,.85);
    background: rgba(248,250,252,.9);
  }
  .exam-pill {
    margin-top: .6rem;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 1px solid rgba(59,130,246,.32);
    background: rgba(59,130,246,.09);
    color: #1d4ed8;
    border-radius: 999px;
    padding: .3rem .68rem;
    font-size: .8rem;
    font-weight: 600;
  }
  .rank-card {
    min-width: 150px;
    border: 1px solid rgba(15,23,42,.08);
    border-radius: .8rem;
    background: linear-gradient(135deg, rgba(219,234,254,.7), rgba(240,249,255,.75));
    padding: .8rem .9rem;
  }
  .rank-label {
    font-size: .74rem;
    text-transform: uppercase;
    letter-spacing: .055em;
    color: rgba(30,41,59,.68);
  }
  .rank-value {
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.1;
    color: #0f172a;
  }
  .filter-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .7rem;
    margin-bottom: .65rem;
  }
  .filter-title {
    font-size: .95rem;
    font-weight: 700;
    color: #0f172a;
  }
  .scope-pill {
    border: 1px solid rgba(99,102,241,.3);
    background: rgba(99,102,241,.08);
    color: #3730a3;
    border-radius: 999px;
    padding: .2rem .58rem;
    font-size: .74rem;
    font-weight: 600;
  }
  .results-card {
    border: 1px solid rgba(15,23,42,.08);
    border-radius: .85rem;
    background: linear-gradient(180deg, rgba(255,255,255,.99), rgba(248,250,252,.98));
  }
  .results-title {
    font-size: 1.08rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: .01em;
  }
  .results-subtitle {
    font-size: .84rem;
    color: rgba(30,41,59,.66);
  }
  .results-scope-pill {
    border: 1px solid rgba(59,130,246,.32);
    background: rgba(59,130,246,.1);
    color: #1d4ed8;
    border-radius: 999px;
    padding: .25rem .62rem;
    font-size: .74rem;
    font-weight: 600;
  }
  .results-table {
    --bs-table-bg: transparent;
    margin-bottom: 0;
  }
  .results-table thead th {
    background: rgba(241,245,249,.72);
    color: rgba(15,23,42,.84);
    font-size: .76rem;
    text-transform: uppercase;
    letter-spacing: .055em;
    border-bottom: 1px solid rgba(148,163,184,.42);
    padding-top: .6rem;
    padding-bottom: .6rem;
  }
  .results-table tbody td {
    border-color: rgba(148,163,184,.36);
    padding-top: .58rem;
    padding-bottom: .58rem;
    vertical-align: middle;
  }
  .results-table tbody tr:hover {
    background: rgba(241,245,249,.58);
  }
  .subject-cell { min-width: 230px; }
  .subject-name {
    font-weight: 650;
    color: #0f172a;
    line-height: 1.15;
  }
  .subject-code {
    display: inline-block;
    margin-top: .2rem;
    padding: .12rem .42rem;
    border-radius: 999px;
    border: 1px solid rgba(148,163,184,.42);
    font-size: .7rem;
    letter-spacing: .04em;
    color: rgba(51,65,85,.88);
    text-transform: uppercase;
    background: rgba(248,250,252,.92);
  }
  .score-badge {
    min-width: 4.9rem;
    text-align: center;
    border-radius: .58rem;
    padding: .34rem .62rem;
    font-size: .9rem;
    font-weight: 700;
  }
  .max-pill {
    display: inline-block;
    min-width: 2.7rem;
    text-align: center;
    padding: .24rem .52rem;
    border-radius: 999px;
    border: 1px solid rgba(148,163,184,.36);
    background: rgba(248,250,252,.88);
    color: rgba(30,41,59,.9);
    font-size: .82rem;
  }
  .percent-stack {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: .42rem;
  }
  .percent-bar {
    width: 74px;
    height: .38rem;
    border-radius: 999px;
    background: rgba(148,163,184,.3);
    overflow: hidden;
  }
  .percent-bar > span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #38bdf8, #2563eb);
  }
  .delta-badge {
    min-width: 5.25rem;
    text-align: center;
    border-radius: .58rem;
    padding: .34rem .56rem;
    font-weight: 700;
  }
  .kpi-title { font-size: .82rem; color: rgba(0,0,0,.58); }
  .kpi-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
  .kpi-card {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(15,23,42,.06);
    transition: transform .18s ease, box-shadow .18s ease;
  }
  .kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 .55rem 1.25rem rgba(15,23,42,.1) !important;
  }
  .kpi-card::before {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
    background: var(--kpi-accent, #0d6efd);
  }
  .kpi-total { --kpi-accent: linear-gradient(90deg, #2563eb, #3b82f6); }
  .kpi-average { --kpi-accent: linear-gradient(90deg, #0284c7, #06b6d4); }
  .kpi-pass { --kpi-accent: linear-gradient(90deg, #16a34a, #22c55e); }
  .kpi-bands { --kpi-accent: linear-gradient(90deg, #f59e0b, #ef4444); }
  .kpi-meta { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }
  .kpi-stat-note { font-size: .78rem; color: rgba(0,0,0,.56); }
  .pass-meter {
    height: .5rem;
    border-radius: 999px;
    background: rgba(15,23,42,.08);
    overflow: hidden;
    margin-top: .6rem;
  }
  .pass-meter > span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #22c55e, #16a34a);
  }
  .band-grid { display: flex; flex-wrap: wrap; gap: .45rem; }
  .band-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border-radius: 999px;
    padding: .28rem .65rem;
    font-size: .78rem;
    font-weight: 600;
    border: 1px solid transparent;
  }
  .band-fail { background: rgba(244,63,94,.12); color: #be123c; border-color: rgba(244,63,94,.3); }
  .band-pass { background: rgba(245,158,11,.16); color: #b45309; border-color: rgba(245,158,11,.35); }
  .band-good { background: rgba(59,130,246,.14); color: #1d4ed8; border-color: rgba(59,130,246,.35); }
  .band-excellent { background: rgba(34,197,94,.14); color: #15803d; border-color: rgba(34,197,94,.35); }
  .mono { font-variant-numeric: tabular-nums; }
  .filter-chip { border-radius: 999px; text-decoration: none; }
  .table thead th { white-space: nowrap; }
  .chart-wrap { position: relative; height: 260px; }
  .chart-wrap-lg { position: relative; height: 300px; }
</style>

<div class="container-fluid py-3">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
      <div class="hero-subtitle mt-1">View pupil information, selected-exam breakdown, and trend.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-secondary action-btn" href="<?= eh($dashboardUrl) ?>"><i class="bi bi-arrow-left me-1"></i> Back to dashboard</a>
      <?php if ($pupil): ?>
        <?php
          $repParams = ['class_code' => (string)$pupil['class_code']];
          if ($filterYear !== '') $repParams['academic_year'] = $filterYear;
          $toReports = $reportsUrl . '?' . http_build_query($repParams);
        ?>
        <a class="btn btn-primary action-btn" href="<?= eh($toReports) ?>"><i class="bi bi-graph-up-arrow me-1"></i> Open reports</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm top-section-card mb-3">
    <div class="card-body">
      <form class="row g-2 g-md-3 align-items-end" method="get" action="<?= eh($selfUrl) ?>">
        <div class="col-12 col-sm-6 col-md-5">
          <label class="form-label form-label-tight">Student login</label>
          <input class="form-control form-control-soft" name="student_login" value="<?= eh($inputLogin) ?>" placeholder="e.g., 10A-023" autocomplete="off">
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <label class="form-label form-label-tight">Pupil ID (optional)</label>
          <input class="form-control form-control-soft" name="id" value="<?= $inputId > 0 ? (int)$inputId : '' ?>" inputmode="numeric" pattern="[0-9]*">
        </div>
        <div class="col-12 col-md-auto d-grid">
          <button class="btn btn-primary action-btn"><i class="bi bi-search me-1"></i> Find pupil</button>
        </div>
      </form>
      <?php if ($errors): ?>
        <div class="alert alert-danger mt-3 mb-0"><?= eh(implode(' ', $errors)) ?></div>
      <?php elseif (!$pupil): ?>
        <div class="text-muted small mt-3">Enter `student_login` or `id` to load a pupil profile.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($pupil): ?>
    <?php
      $fullName = trim((string)$pupil['surname'] . ' ' . (string)$pupil['name'] . ' ' . (string)($pupil['middle_name'] ?? ''));
      $selectedExamLabel = $selectedExam ? exam_label($selectedExam) : 'No exam selected';
      $deltaTotalBadge = delta_badge($deltaTotal);
      $deltaAvgBadge = delta_badge($deltaAvg);
      $passRatePct = $passRate === null ? null : ($passRate * 100.0);
      $passRateBadgeClass = 'text-bg-secondary';
      $passRateBadgeText = 'No data';
      if ($passRatePct !== null) {
        if ($passRatePct >= 75.0) {
          $passRateBadgeClass = 'text-bg-success';
          $passRateBadgeText = 'Strong';
        } elseif ($passRatePct >= 50.0) {
          $passRateBadgeClass = 'text-bg-warning text-dark';
          $passRateBadgeText = 'Moderate';
        } else {
          $passRateBadgeClass = 'text-bg-danger';
          $passRateBadgeText = 'At risk';
        }
      }
      $baseWithPupil = ['id' => (int)$pupil['id']];
      if ($inputLogin !== '') $baseWithPupil['student_login'] = $inputLogin;
      if ($filterYear !== '') $baseWithPupil['year'] = $filterYear;
      if ($selectedExamId > 0) $baseWithPupil['exam_id'] = $selectedExamId;
    ?>

    <div class="card border-0 shadow-sm top-section-card mb-3">
      <div class="card-body d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div class="flex-grow-1">
          <div class="person-name"><?= eh($fullName !== '' ? $fullName : 'Unnamed pupil') ?></div>
          <div class="pill-row">
            <span class="soft-pill"><i class="bi bi-person-badge"></i> <?= eh((string)$pupil['student_login']) ?></span>
            <span class="soft-pill"><i class="bi bi-people"></i> Class <?= eh((string)$pupil['class_code']) ?></span>
            <span class="soft-pill"><i class="bi bi-shield-check"></i> <?= eh((string)($pupil['track'] ?? '-')) ?></span>
          </div>
          <div class="exam-pill"><i class="bi bi-calendar-event"></i> <?= eh($selectedExamLabel) ?></div>
        </div>
        <?php if ($selectedExamId > 0): ?>
          <div class="rank-card text-end">
            <div class="rank-label">Class rank</div>
            <div class="rank-value">
              <?php if ($classRank['rank'] !== null): ?>
                <?= eh((string)$classRank['rank']) ?> / <?= eh((string)$classRank['count']) ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm top-section-card mb-3">
      <div class="card-body">
        <div class="filter-card-header">
          <div class="filter-title">Filter exams</div>
          <?php if ($selectedExam): ?>
            <span class="scope-pill"><i class="bi bi-pin-angle me-1"></i><?= eh(exam_label($selectedExam)) ?></span>
          <?php endif; ?>
        </div>
        <form class="row g-2 g-md-3 align-items-end" method="get" action="<?= eh($selfUrl) ?>">
          <input type="hidden" name="id" value="<?= (int)$pupil['id'] ?>">
          <?php if ($inputLogin !== ''): ?><input type="hidden" name="student_login" value="<?= eh($inputLogin) ?>"><?php endif; ?>

          <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label form-label-tight">Academic year</label>
            <select class="form-select form-select-soft" name="year">
              <option value="">All</option>
              <?php foreach ($yearOptions as $y): ?>
                <option value="<?= eh($y) ?>" <?= $y === $filterYear ? 'selected' : '' ?>><?= eh($y) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-sm-6 col-md-6">
            <label class="form-label form-label-tight">Exam</label>
            <select class="form-select form-select-soft" name="exam_id">
              <option value="0">Latest in selected scope</option>
              <?php foreach ($examsFiltered as $e): ?>
                <?php $eid = (int)$e['id']; ?>
                <option value="<?= $eid ?>" <?= $eid === $selectedExamId ? 'selected' : '' ?>><?= eh(exam_label($e)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-auto d-grid">
            <button class="btn btn-primary action-btn"><i class="bi bi-funnel me-1"></i> Apply</button>
          </div>

          <div class="col-12 col-md-auto d-grid">
            <a class="btn btn-outline-secondary action-btn" href="<?= eh(url_with($selfUrl, ['id' => (int)$pupil['id'], 'student_login' => $inputLogin])) ?>">
              Reset filters
            </a>
          </div>
        </form>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100 kpi-card kpi-total">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <div class="kpi-title">Total score</div>
              <span class="badge text-bg-primary-subtle border text-primary-emphasis">Overall</span>
            </div>
            <div class="kpi-value mono"><?= $totalNow === null ? '-' : eh(number_format($totalNow, 2)) ?></div>
            <div class="kpi-meta mt-2">
              <span class="badge <?= eh($deltaTotalBadge['cls']) ?>">
                <i class="bi <?= eh($deltaTotalBadge['ic']) ?> me-1"></i><?= eh($deltaTotalBadge['txt']) ?>
              </span>
              <span class="kpi-stat-note">vs previous exam</span>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100 kpi-card kpi-average">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <div class="kpi-title">Average score</div>
              <span class="badge text-bg-info-subtle border text-info-emphasis">Per subject</span>
            </div>
            <div class="kpi-value mono"><?= $avgNow === null ? '-' : eh(number_format($avgNow, 2)) ?></div>
            <div class="kpi-meta mt-2">
              <span class="badge <?= eh($deltaAvgBadge['cls']) ?>">
                <i class="bi <?= eh($deltaAvgBadge['ic']) ?> me-1"></i><?= eh($deltaAvgBadge['txt']) ?>
              </span>
              <span class="kpi-stat-note">vs previous exam</span>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100 kpi-card kpi-pass">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <div class="kpi-title">Pass rate</div>
              <span class="badge <?= eh($passRateBadgeClass) ?>"><?= eh($passRateBadgeText) ?></span>
            </div>
            <div class="kpi-value mono"><?= $passRate === null ? '-' : eh(number_format($passRate * 100, 1)) . '%' ?></div>
            <div class="kpi-stat-note mt-2">Subjects passed: <?= eh((string)$passCount) ?> / <?= eh((string)$subjectCount) ?></div>
            <?php if ($passRatePct !== null): ?>
              <div class="pass-meter" role="progressbar" aria-label="Pass rate meter">
                <span style="width: <?= eh(number_format(max(0.0, min(100.0, $passRatePct)), 1, '.', '')) ?>%;"></span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100 kpi-card kpi-bands">
          <div class="card-body">
            <div class="kpi-title">Performance bands</div>
            <div class="kpi-stat-note mt-1 mb-2">Threshold badges</div>
            <div class="band-grid">
              <span class="band-chip band-fail"><i class="bi bi-x-circle-fill"></i>&lt; <?= eh(number_format(PASS_SCORE, 1)) ?></span>
              <span class="band-chip band-pass"><i class="bi bi-exclamation-triangle-fill"></i><?= eh(number_format(PASS_SCORE, 1)) ?> - &lt; <?= eh(number_format(GOOD_SCORE, 1)) ?></span>
              <span class="band-chip band-good"><i class="bi bi-check-circle-fill"></i><?= eh(number_format(GOOD_SCORE, 1)) ?> - &lt; <?= eh(number_format(EXCELLENT_SCORE, 1)) ?></span>
              <span class="band-chip band-excellent"><i class="bi bi-stars"></i>&gt;= <?= eh(number_format(EXCELLENT_SCORE, 1)) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100 results-card">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between mb-2">
              <div>
                <div class="results-title">Subject results</div>
                <div class="results-subtitle">Selected exam with delta vs previous</div>
              </div>
              <?php if ($selectedExam): ?>
                <span class="results-scope-pill"><?= eh(exam_label($selectedExam)) ?></span>
              <?php endif; ?>
            </div>

            <?php if (!$selectedExam || !$subjectRows): ?>
              <div class="text-muted">No subject rows for this pupil in the selected scope.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle results-table">
                  <thead>
                    <tr>
                      <th>Subject</th>
                      <th class="text-end">Score</th>
                      <th class="text-end">Max</th>
                      <th class="text-end">Percent</th>
                      <th class="text-end">Delta</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($subjectRows as $r): ?>
                      <?php $db = delta_badge(isset($r['delta']) ? (float)$r['delta'] : null); ?>
                      <?php $pctVal = isset($r['pct']) ? max(0.0, min(100.0, (float)$r['pct'])) : null; ?>
                      <tr>
                        <td class="subject-cell text-truncate">
                          <div class="subject-name text-truncate"><?= eh((string)$r['name']) ?></div>
                          <?php if (!empty($r['code'])): ?><span class="subject-code"><?= eh((string)$r['code']) ?></span><?php endif; ?>
                        </td>
                        <td class="text-end">
                          <span class="badge score-badge <?= eh((string)$r['band']) ?> mono"><?= eh(number_format((float)$r['score'], 2)) ?></span>
                        </td>
                        <td class="text-end mono"><span class="max-pill"><?= eh(number_format((float)$r['max_points'], 0)) ?></span></td>
                        <td class="text-end mono">
                          <?php if ($pctVal === null): ?>
                            -
                          <?php else: ?>
                            <span class="percent-stack">
                              <span><?= eh(number_format($pctVal, 1)) ?>%</span>
                              <span class="percent-bar" aria-hidden="true">
                                <span style="width: <?= eh(number_format($pctVal, 1, '.', '')) ?>%;"></span>
                              </span>
                            </span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <span class="badge delta-badge <?= eh($db['cls']) ?> mono"><i class="bi <?= eh($db['ic']) ?> me-1"></i><?= eh($db['txt']) ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-5">
        <div class="row g-3">
          <div class="col-12">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-2">
                  <div>
                    <div class="fw-semibold">Exam trend</div>
                    <div class="text-muted small">Total score across all available exams</div>
                  </div>
                </div>
                <div class="chart-wrap">
                  <canvas id="pupilTrendChart" aria-label="Pupil trend chart"></canvas>
                </div>
                <?php if (count($examRows) <= 1): ?>
                  <div class="text-muted small mt-2">Not enough exams to show a trend.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-2">
                  <div>
                    <div class="fw-semibold">Comparison</div>
                    <div class="text-muted small">Selected exam vs previous by subject</div>
                  </div>
                  <?php if ($selectedExam): ?>
                    <span class="badge text-bg-secondary-subtle border text-secondary-emphasis"><?= eh(exam_label($selectedExam)) ?></span>
                  <?php endif; ?>
                </div>
                <div class="chart-wrap-lg">
                  <canvas id="subjectCompareChart" aria-label="Subject comparison chart"></canvas>
                </div>
                <?php if (!$prevExam || !$hasComparison): ?>
                  <div id="compareNoData" class="text-muted small mt-2">
                    No previous exam data available for subject comparison.
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($pupil): ?>
<script nonce="<?= eh($cspNonce ?? ($_SESSION['csp_nonce'] ?? '')) ?>">
(function () {
  const labels = <?= json_encode($timelineLabels, JSON_UNESCAPED_UNICODE) ?>;
  const totals = <?= json_encode($timelineTotals, JSON_UNESCAPED_UNICODE) ?>;
  const selectedExamId = <?= json_encode($selectedExamId, JSON_UNESCAPED_UNICODE) ?>;
  const examIdsChrono = <?= json_encode(array_map(static fn($e) => (int)$e['id'], array_reverse($examRows)), JSON_UNESCAPED_UNICODE) ?>;
  const compareLabels = <?= json_encode($compareLabels, JSON_UNESCAPED_UNICODE) ?>;
  const compareNow = <?= json_encode($compareNow, JSON_UNESCAPED_UNICODE) ?>;
  const comparePrev = <?= json_encode($comparePrev, JSON_UNESCAPED_UNICODE) ?>;
  const selectedExamLabel = <?= json_encode($selectedExam ? exam_label($selectedExam) : 'Selected exam', JSON_UNESCAPED_UNICODE) ?>;
  const previousExamLabel = <?= json_encode($prevExam ? exam_label($prevExam) : 'Previous exam', JSON_UNESCAPED_UNICODE) ?>;

  function draw() {
    if (!window.Chart) return;
    const trendEl = document.getElementById('pupilTrendChart');
    if (trendEl && Array.isArray(totals) && totals.length > 0) {
      const selectedIdx = examIdsChrono.findIndex((x) => Number(x) === Number(selectedExamId));
      const pointBg = totals.map((_, i) => (selectedIdx >= 0 && i === selectedIdx) ? 'rgba(13,110,253,1)' : 'rgba(13,110,253,0.65)');
      const pointRadius = totals.map((_, i) => (selectedIdx >= 0 && i === selectedIdx) ? 6 : 3);

      new Chart(trendEl, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'Total score',
            data: totals,
            tension: 0.25,
            borderWidth: 2,
            pointBackgroundColor: pointBg,
            pointRadius
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: true }, tooltip: { mode: 'index', intersect: false } },
          interaction: { mode: 'index', intersect: false },
          scales: { y: { beginAtZero: true, suggestedMax: 40 } }
        }
      });
    }

    const compareEl = document.getElementById('subjectCompareChart');
    const compareNoDataEl = document.getElementById('compareNoData');
    if (compareEl) {
      const hasPrev = Array.isArray(comparePrev) && comparePrev.some((v) => v !== null);
      const hasLabels = Array.isArray(compareLabels) && compareLabels.length > 0;
      if (!hasPrev || !hasLabels) {
        compareEl.classList.add('d-none');
        if (compareNoDataEl) compareNoDataEl.classList.remove('d-none');
      } else {
        compareEl.classList.remove('d-none');
        if (compareNoDataEl) compareNoDataEl.classList.add('d-none');

        // Latest exam bar colors by change vs previous exam:
        // increase -> greenish, decline -> pinkish red, equal/missing -> neutral blue.
        const latestBg = compareNow.map((now, i) => {
          const prev = comparePrev[i];
          if (prev === null || typeof prev === 'undefined') return 'rgba(59,130,246,0.72)';
          if (Number(now) > Number(prev)) return 'rgba(34,197,94,0.78)';
          if (Number(now) < Number(prev)) return 'rgba(244,114,182,0.78)';
          return 'rgba(59,130,246,0.72)';
        });
        const latestBorder = compareNow.map((now, i) => {
          const prev = comparePrev[i];
          if (prev === null || typeof prev === 'undefined') return 'rgba(37,99,235,1)';
          if (Number(now) > Number(prev)) return 'rgba(22,163,74,1)';
          if (Number(now) < Number(prev)) return 'rgba(219,39,119,1)';
          return 'rgba(37,99,235,1)';
        });

        new Chart(compareEl, {
          type: 'bar',
          data: {
            labels: compareLabels,
            datasets: [
              {
                // Previous exam first => left-side bar
                label: previousExamLabel,
                data: comparePrev,
                backgroundColor: 'rgba(148,163,184,0.70)',
                borderColor: 'rgba(100,116,139,1)',
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 26,
              },
              {
                // Latest exam second => right-side bar
                label: selectedExamLabel,
                data: compareNow,
                backgroundColor: latestBg,
                borderColor: latestBorder,
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 26,
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: true },
              tooltip: {
                callbacks: {
                  label: (ctx) => {
                    const y = ctx.parsed.y;
                    return y === null ? `${ctx.dataset.label}: -` : `${ctx.dataset.label}: ${Number(y).toFixed(2)}`;
                  }
                }
              }
            },
            scales: {
              x: { ticks: { maxRotation: 45, minRotation: 0 } },
              y: { beginAtZero: true, suggestedMax: 40 }
            }
          }
        });
      }
    }
  }

  function ensureChart() {
    if (window.Chart) { draw(); return; }
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    s.onload = draw;
    s.async = true;
    document.head.appendChild(s);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureChart);
  } else {
    ensureChart();
  }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
