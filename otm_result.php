<?php
// Public OTM result viewer (no auth) - pupil_id based lookup

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function qstr(string $key, int $maxLen = 100): string {
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (mb_strlen($v, 'UTF-8') > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return $v;
}

function qint(string $key, int $min = 1, int $max = PHP_INT_MAX): int {
    $v = $_GET[$key] ?? '';
    if (!is_string($v) || !preg_match('/^\d+$/', $v)) return 0;
    $n = (int)$v;
    if ($n < $min || $n > $max) return 0;
    return $n;
}

function fmt_num($v, int $dec = 2): string {
    if ($v === null || $v === '') return '—';
    return number_format((float)$v, $dec, '.', '');
}

function fmt_pct($v): string {
    if ($v === null || $v === '') return '—';
    $n = (float)$v;
    if (abs($n - round($n)) < 0.00001) {
        return number_format($n, 0, '.', '') . '%';
    }
    return number_format($n, 2, '.', '') . '%';
}

function exam_label(array $r): string {
    $title = trim((string)($r['exam_title'] ?? ''));
    $date = trim((string)($r['exam_date'] ?? ''));
    $parts = [];
    if ($title !== '') $parts[] = $title;
    if ($date !== '') $parts[] = $date;
    return implode(' · ', $parts);
}

$pupilId = qint('pupil_id', 1);
$load = isset($_GET['load']) && (string)$_GET['load'] === '1';

$errors = [];
$pupil = null;
$examRows = [];
$current = null;
$rankPos = null;
$rankTotal = null;
$certOnlyTotal = null;
$certAppliedCount = 0;

try {
    if ($load) {
        if ($pupilId <= 0) {
            $errors[] = "O'quvchi ID kiriting.";
        }
    }

    if ($load && !$errors) {
        $stPupil = $pdo->prepare(
            "SELECT id, surname, name, class_code FROM pupils WHERE id = :id LIMIT 1"
        );
        $stPupil->execute([':id' => $pupilId]);
        $pupil = $stPupil->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$pupil) {
            $errors[] = "Bunday ID bilan o'quvchi topilmadi.";
        }
    }

    if ($pupil) {
        $sql = "
            SELECT
                r.id AS otm_result_id,
                r.otm_exam_id,
                r.updated_at,
                r.major1_correct, r.major2_correct,
                r.mandatory_ona_tili_correct, r.mandatory_matematika_correct, r.mandatory_uzb_tarix_correct,
                r.major1_score, r.major2_score,
                r.mandatory_ona_tili_score, r.mandatory_matematika_score, r.mandatory_uzb_tarix_score,
                r.major1_certificate_percent, r.major2_certificate_percent,
                r.mandatory_ona_tili_certificate_percent, r.mandatory_matematika_certificate_percent, r.mandatory_uzb_tarix_certificate_percent,
                r.major1_certificate_score, r.major2_certificate_score,
                r.mandatory_ona_tili_certificate_score, r.mandatory_matematika_certificate_score, r.mandatory_uzb_tarix_certificate_score,
                r.total_score, r.total_score_withcert,
                e.exam_title, e.exam_date, e.otm_kind, e.attempt_no,
                sy.year_code,
                os1.name AS major1_name,
                os2.name AS major2_name
            FROM otm_results r
            LEFT JOIN otm_exams e ON e.id = r.otm_exam_id
            LEFT JOIN study_year sy ON sy.id = e.study_year_id
            LEFT JOIN otm_major om ON om.pupil_id = r.pupil_id AND om.is_active = 1
            LEFT JOIN otm_subjects os1 ON os1.id = om.major1_subject_id
            LEFT JOIN otm_subjects os2 ON os2.id = om.major2_subject_id
            WHERE r.pupil_id = :pupil_id
            ORDER BY e.exam_date DESC, r.otm_exam_id DESC, r.id DESC
        ";
        $stRows = $pdo->prepare($sql);
        $stRows->execute([':pupil_id' => (int)$pupil['id']]);
        $examRows = $stRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$examRows) {
            $errors[] = "Bu o'quvchi uchun OTM natijasi topilmadi.";
        } else {
            $current = $examRows[0];

            $certVals = [
                $current['major1_certificate_score'],
                $current['major2_certificate_score'],
                $current['mandatory_ona_tili_certificate_score'],
                $current['mandatory_matematika_certificate_score'],
                $current['mandatory_uzb_tarix_certificate_score'],
            ];
            $certPcts = [
                $current['major1_certificate_percent'],
                $current['major2_certificate_percent'],
                $current['mandatory_ona_tili_certificate_percent'],
                $current['mandatory_matematika_certificate_percent'],
                $current['mandatory_uzb_tarix_certificate_percent'],
            ];
            $certOnlyTotal = 0.0;
            foreach ($certVals as $cv) { $certOnlyTotal += ($cv !== null ? (float)$cv : 0.0); }
            foreach ($certPcts as $cp) { if ($cp !== null) $certAppliedCount++; }

            $stRank = $pdo->prepare("
                SELECT r.pupil_id, r.total_score_withcert, r.total_score
                FROM otm_results r
                INNER JOIN pupils p2 ON p2.id = r.pupil_id
                WHERE p2.class_code = :class_code AND r.otm_exam_id = :exam_id
                ORDER BY COALESCE(r.total_score_withcert, r.total_score) DESC, r.total_score DESC, r.pupil_id ASC
            ");
            $stRank->execute([
                ':class_code' => (string)$pupil['class_code'],
                ':exam_id' => (int)$current['otm_exam_id'],
            ]);
            $rankRows = $stRank->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rankTotal = count($rankRows);
            foreach ($rankRows as $idx => $rr) {
                if ((int)$rr['pupil_id'] === (int)$pupil['id']) {
                    $rankPos = $idx + 1;
                    break;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[OTM_RESULT_PUBLIC] ' . $e->getMessage());
    $errors[] = "Sahifani yuklashda xatolik yuz berdi.";
}

?><!doctype html>
<html lang="uz">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OTM natijasi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    body{
      background:
        radial-gradient(1100px 500px at 10% -10%, rgba(37,99,235,.07), transparent 60%),
        radial-gradient(900px 420px at 100% 0%, rgba(16,185,129,.06), transparent 62%),
        #f3f6fb;
    }
    .otmpr-shell{max-width:1200px;margin:0 auto;padding:1rem .75rem 2rem}
    .otmpr-hero{border:1px solid rgba(15,23,42,.08);border-radius:1rem;background:linear-gradient(180deg,#fff,#f8fafc);box-shadow:0 10px 24px rgba(2,6,23,.06);padding:1rem}
    .otmpr-title{font-weight:800;color:#0f172a;letter-spacing:.01em}
    .otmpr-sub{color:#64748b}
    .otmpr-chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid rgba(15,23,42,.1);border-radius:999px;background:#fff;padding:.25rem .6rem;font-size:.82rem}
    .otmpr-badge{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.18rem .55rem;border:1px solid rgba(15,23,42,.10);background:#fff;font-size:.78rem;line-height:1;color:#334155}
    .otmpr-badge.class{border-color:rgba(37,99,235,.22);background:rgba(37,99,235,.08);color:#1d4ed8;font-weight:700}
    .otmpr-head-meta{display:flex;flex-wrap:wrap;align-items:center;gap:.45rem .5rem;margin-top:.2rem}
    .otmpr-major-wrap{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:.35rem .4rem}
    .otmpr-major-label{font-size:.78rem;color:#64748b;font-weight:600;margin-right:.1rem}
    .otmpr-major-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.22rem .58rem;font-size:.78rem;font-weight:700;border:1px solid rgba(15,23,42,.10);background:#fff;color:#0f172a}
    .otmpr-major-pill.f1{border-color:rgba(37,99,235,.22);background:rgba(37,99,235,.08);color:#1d4ed8}
    .otmpr-major-pill.f2{border-color:rgba(124,58,237,.22);background:rgba(124,58,237,.08);color:#6d28d9}
    .otmpr-section-head-center{display:flex;justify-content:center;align-items:center;text-align:center;margin-bottom:.2rem}
    .otmpr-card{border:1px solid rgba(15,23,42,.08);border-radius:1rem;background:#fff;box-shadow:0 8px 22px rgba(2,6,23,.05)}
    .otmpr-card .card-body{padding:1rem}
    .otmpr-metric{border:1px solid rgba(15,23,42,.08);border-radius:.95rem;background:linear-gradient(180deg,#fff,#f8fafc);padding:.8rem .9rem;min-height:108px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;position:relative;overflow:hidden}
    .otmpr-metric::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:rgba(148,163,184,.35)}
    .otmpr-metric .lbl{font-size:.73rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:700}
    .otmpr-metric .val{font-size:1.28rem;font-weight:900;color:#0f172a;line-height:1.1;font-variant-numeric:tabular-nums;margin-top:.15rem}
    .otmpr-metric .sub{font-size:.79rem;color:#64748b;margin-top:.22rem;line-height:1.2}
    .otmpr-metric.kpi-base::before{background:rgba(217,119,6,.45)}
    .otmpr-metric.kpi-cert::before{background:rgba(16,185,129,.45)}
    .otmpr-metric.kpi-rank::before{background:rgba(37,99,235,.42)}
    .otmpr-metric.kpi-certonly::before{background:rgba(124,58,237,.38)}
    .otmpr-metric.kpi-base .val{color:#b45309}
    .otmpr-metric.kpi-cert .val{color:#047857}
    .otmpr-metric.kpi-rank .val{color:#1d4ed8}
    .otmpr-metric.kpi-certonly .val{color:#6d28d9}
    .otmpr-table{border-color:rgba(15,23,42,.1)}
    .otmpr-table th,.otmpr-table td{vertical-align:middle;border-color:rgba(15,23,42,.08)}
    .otmpr-table thead th{background:#f3f4f6;font-weight:700}
    .otmpr-table thead tr:first-child th{font-size:.9rem}
    .otmpr-table thead tr:nth-child(2) th{font-size:.75rem;text-transform:uppercase;color:#475569;letter-spacing:.02em}
    .otmpr-table tbody tr:nth-child(odd) td{background:#fff}
    .otmpr-table tbody tr:nth-child(even) td{background:#f8fafc}
    .otmpr-table tbody tr:hover td{background:rgba(15,23,42,.03)!important}
    .otmpr-name{font-weight:700;color:#0f172a;line-height:1.2}
    .otmpr-subline{font-size:.8rem;color:#64748b}
    .otmpr-center{text-align:center}
    .otmpr-pct,.otmpr-num{display:inline-flex;align-items:center;justify-content:center;min-width:4.5rem;padding:.16rem .34rem;border:1px solid rgba(15,23,42,.08);border-radius:.45rem;background:#fff;font-variant-numeric:tabular-nums;font-weight:700}
    .otmpr-pct{min-width:5.2rem;color:#334155;background:rgba(148,163,184,.08)}
    .otmpr-num{color:#0f172a;background:rgba(15,23,42,.04)}
    .otmpr-num.total{min-width:5.8rem;font-weight:800}
    .otmpr-num.total.base{color:#b45309;background:rgba(217,119,6,.09);border-color:rgba(217,119,6,.15)}
    .otmpr-num.total.cert{color:#047857;background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.15)}
    .otmpr-exam-list .list-group-item{display:flex;justify-content:space-between;align-items:center;gap:.75rem}
    .otmpr-exam-list .meta{font-size:.8rem;color:#64748b}
    .otmpr-empty{padding:1.2rem;border:1px dashed rgba(15,23,42,.15);border-radius:.8rem;background:#fff}
    .otmpr-kv{display:grid;grid-template-columns:1fr auto;gap:.35rem .6rem;align-items:center}
    .otmpr-kv .k{color:#64748b;font-size:.85rem}
    .otmpr-kv .v{font-weight:700;color:#0f172a;font-variant-numeric:tabular-nums}
    .otmpr-cert-summary{display:grid;grid-template-columns:1fr;gap:.55rem}
    .otmpr-cert-summary .rowx{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.65rem;align-items:center;padding:.55rem .65rem;border:1px solid rgba(15,23,42,.07);border-radius:.75rem;background:linear-gradient(180deg,#fff,#fafbfc)}
    .otmpr-cert-summary .k{font-size:.84rem;color:#64748b}
    .otmpr-cert-summary .v{font-size:1rem;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums}
    .otmpr-cert-table-wrap{border:1px solid rgba(15,23,42,.08);border-radius:.8rem;overflow:hidden;background:#fff}
    .otmpr-cert-table{width:100%;border-collapse:separate;border-spacing:0}
    .otmpr-cert-table th,.otmpr-cert-table td{padding:.56rem .7rem;border-bottom:1px solid rgba(15,23,42,.07);vertical-align:middle}
    .otmpr-cert-table thead th{background:#f3f4f6;font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:700}
    .otmpr-cert-table tbody tr:last-child td{border-bottom:0}
    .otmpr-cert-table tbody td:first-child{font-size:.88rem;font-weight:600;color:#0f172a}
    .otmpr-cert-table td.pct{font-size:.84rem;color:#475569;font-variant-numeric:tabular-nums;text-align:center;white-space:nowrap}
    .otmpr-cert-table td.score{font-size:.86rem;font-weight:800;color:#047857;font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
    .otmpr-cert-table td.zero{color:#94a3b8}
    .otmpr-action-btn{padding:.52rem .95rem;font-weight:700}
    .otmpr-reset-btn{padding:.52rem .8rem}
    .otmpr-subject-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.7rem}
    .otmpr-subject-card{border:1px solid rgba(15,23,42,.08);border-radius:.85rem;background:linear-gradient(180deg,#fff,#fafbfc);overflow:hidden}
    .otmpr-subject-head{padding:.55rem .7rem;border-bottom:1px solid rgba(15,23,42,.08);font-weight:700;color:#0f172a;display:flex;align-items:center;justify-content:space-between;gap:.5rem}
    .otmpr-subject-head .tag{font-size:.72rem;color:#64748b;font-weight:600}
    .otmpr-subject-body{padding:.55rem}
    .otmpr-grid-table{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.9fr) minmax(0,.9fr);gap:.35rem .4rem;align-items:center}
    .otmpr-grid-table .gh{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#64748b;padding:0 .15rem .15rem}
    .otmpr-grid-table .gh.center{text-align:center}
    .otmpr-grid-table .gl{font-size:.8rem;font-weight:600;color:#334155;padding:0 .15rem}
    .otmpr-grid-table .gc{text-align:center}
    .otmpr-subject-card .otmpr-num,.otmpr-subject-card .otmpr-pct{min-width:unset;width:100%;padding:.14rem .25rem;font-size:.88rem}
    .otmpr-subject-card.f1 .otmpr-num,.otmpr-subject-card.f1 .otmpr-pct{color:#2563eb}
    .otmpr-subject-card.f2 .otmpr-num,.otmpr-subject-card.f2 .otmpr-pct{color:#7c3aed}
    .otmpr-subject-card.ona .otmpr-num,.otmpr-subject-card.ona .otmpr-pct{color:#16a34a}
    .otmpr-subject-card.math .otmpr-num,.otmpr-subject-card.math .otmpr-pct{color:#d97706}
    .otmpr-subject-card.tarix .otmpr-num,.otmpr-subject-card.tarix .otmpr-pct{color:#0891b2}
    .otmpr-subject-card .otmpr-pct{background:rgba(255,255,255,.92)}
    .otmpr-subject-card.f1 .otmpr-subject-head{border-bottom-color:rgba(37,99,235,.20)}
    .otmpr-subject-card.f2 .otmpr-subject-head{border-bottom-color:rgba(124,58,237,.20)}
    .otmpr-subject-card.ona .otmpr-subject-head{border-bottom-color:rgba(22,163,74,.20)}
    .otmpr-subject-card.math .otmpr-subject-head{border-bottom-color:rgba(217,119,6,.20)}
    .otmpr-subject-card.tarix .otmpr-subject-head{border-bottom-color:rgba(14,165,233,.20)}
    .otmpr-subject-card.f1 .otmpr-subject-head{box-shadow:inset 0 -2px 0 rgba(37,99,235,.18)}
    .otmpr-subject-card.f2 .otmpr-subject-head{box-shadow:inset 0 -2px 0 rgba(124,58,237,.16)}
    .otmpr-subject-card.ona .otmpr-subject-head{box-shadow:inset 0 -2px 0 rgba(22,163,74,.16)}
    .otmpr-subject-card.math .otmpr-subject-head{box-shadow:inset 0 -2px 0 rgba(217,119,6,.16)}
    .otmpr-subject-card.tarix .otmpr-subject-head{box-shadow:inset 0 -2px 0 rgba(14,165,233,.16)}
    @media (max-width: 991.98px){
      .otmpr-hero{padding:.85rem}
      .otmpr-card .card-body{padding:.85rem}
      .otmpr-metric .val{font-size:1.08rem}
      .otmpr-table thead tr:first-child th{font-size:.82rem}
    }
  </style>
</head>
<body>
  <div class="otmpr-shell">
    <div class="otmpr-hero mb-3">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-mortarboard-fill text-primary"></i>
            <div class="otmpr-title h4 mb-0">OTM natijasini ko'rish</div>
          </div>
          <div class="otmpr-sub">O'quvchi ID raqamini kiriting. Natija mavjud bo'lsa ko'rsatiladi.</div>
        </div>
        <div class="d-flex flex-wrap gap-2 small">
          <span class="otmpr-chip"><i class="bi bi-shield-check"></i> Kirish talab qilinmaydi</span>
          <span class="otmpr-chip"><i class="bi bi-person-vcard"></i> Qidiruv: O'quvchi ID</span>
        </div>
      </div>
    </div>

    <div class="otmpr-card mb-3">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-12 col-md-4 col-lg-3">
            <label class="form-label">O'quvchi ID <span class="text-danger">*</span></label>
            <input type="number" min="1" step="1" name="pupil_id" class="form-control form-control-lg" value="<?= $pupilId > 0 ? h((string)$pupilId) : '' ?>" placeholder="Masalan: 1234" required>
          </div>
          <div class="col-12 col-md-8 col-lg-9">
            <div class="otmpr-subline mb-2">Oxirgi mavjud OTM natijasi avtomatik ko'rsatiladi.</div>
            <div class="otmpr-chip"><i class="bi bi-clock-history"></i> Eng so'nggi natija</div>
          </div>
          <div class="col-12 col-md-4 col-lg-3">
            <div class="d-flex gap-2">
              <button type="submit" name="load" value="1" class="btn btn-primary otmpr-action-btn flex-grow-1"><i class="bi bi-search me-1"></i>Ko'rsatish</button>
              <a href="otm_result.php" class="btn btn-outline-secondary otmpr-reset-btn"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
          </div>
        </form>

        <?php if ($errors): ?>
          <div class="alert alert-warning mt-3 mb-0">
            <ul class="mb-0 ps-3">
              <?php foreach ($errors as $err): ?>
                <li><?= h($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($pupil && $current): ?>
      <?php
        $fullName = trim((string)$pupil['surname'] . ' ' . (string)$pupil['name']);
        $majorPair = trim(((string)($current['major1_name'] ?? '')) . ' / ' . ((string)($current['major2_name'] ?? '')),' /');
      ?>
      <div class="row g-3">
        <div class="col-12 col-xl-8">
          <div class="otmpr-card h-100">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                  <div class="otmpr-name h5 mb-1"><?= h($fullName !== '' ? $fullName : ((string)$pupil['id'])) ?></div>
                  <div class="otmpr-head-meta">
                    <span class="otmpr-subline">ID: <?= (int)$pupil['id'] ?></span>
                    <span class="otmpr-badge class"><i class="bi bi-mortarboard"></i> Sinf: <?= h((string)$pupil['class_code']) ?></span>
                  </div>
                  <div class="otmpr-subline mt-1">Imtihon: <?= h(exam_label($current)) ?></div>
                </div>
                <?php if ($majorPair !== ''): ?>
                  <div class="otmpr-major-wrap">
                    <span class="otmpr-major-label"><i class="bi bi-book me-1"></i>Asosiy fanlar:</span>
                    <?php if (!empty($current['major1_name'])): ?><span class="otmpr-major-pill f1"><?= h((string)$current['major1_name']) ?></span><?php endif; ?>
                    <?php if (!empty($current['major2_name'])): ?><span class="otmpr-major-pill f2"><?= h((string)$current['major2_name']) ?></span><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                  <div class="otmpr-metric kpi-base">
                    <div class="lbl">Jami ball</div>
                    <div class="val"><?= h(fmt_num($current['total_score'])) ?></div>
                    <div class="sub">Sertifikatsiz</div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="otmpr-metric kpi-cert">
                    <div class="lbl">Jami ball</div>
                    <div class="val"><?= h(fmt_num($current['total_score_withcert'])) ?></div>
                    <div class="sub">Sertifikat bilan</div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="otmpr-metric kpi-rank">
                    <div class="lbl">Sinfdagi o'rni</div>
                    <div class="val"><?= $rankPos !== null ? h((string)$rankPos) : '—' ?></div>
                    <div class="sub"><?= $rankTotal !== null ? ('/' . h((string)$rankTotal) . " o'quvchi") : 'Reyting topilmadi' ?></div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="otmpr-metric kpi-certonly">
                    <div class="lbl">Sertifikat bali</div>
                    <div class="val"><?= $certOnlyTotal !== null ? h(fmt_num($certOnlyTotal)) : '—' ?></div>
                    <div class="sub">Faqat sertifikat bo'yicha</div>
                  </div>
                </div>
              </div>

              <?php
                $subjectCards = [
                  ['key' => 'f1', 'title' => 'Fan 1', 'label' => (string)($current['major1_name'] ?? ''), 'correct' => $current['major1_correct'], 'cert_pct' => $current['major1_certificate_percent'], 'score' => $current['major1_score'], 'cert_score' => $current['major1_certificate_score']],
                  ['key' => 'f2', 'title' => 'Fan 2', 'label' => (string)($current['major2_name'] ?? ''), 'correct' => $current['major2_correct'], 'cert_pct' => $current['major2_certificate_percent'], 'score' => $current['major2_score'], 'cert_score' => $current['major2_certificate_score']],
                  ['key' => 'ona', 'title' => 'Ona tili', 'label' => '', 'correct' => $current['mandatory_ona_tili_correct'], 'cert_pct' => $current['mandatory_ona_tili_certificate_percent'], 'score' => $current['mandatory_ona_tili_score'], 'cert_score' => $current['mandatory_ona_tili_certificate_score']],
                  ['key' => 'math', 'title' => 'Matematika', 'label' => '', 'correct' => $current['mandatory_matematika_correct'], 'cert_pct' => $current['mandatory_matematika_certificate_percent'], 'score' => $current['mandatory_matematika_score'], 'cert_score' => $current['mandatory_matematika_certificate_score']],
                  ['key' => 'tarix', 'title' => 'Tarix', 'label' => '', 'correct' => $current['mandatory_uzb_tarix_correct'], 'cert_pct' => $current['mandatory_uzb_tarix_certificate_percent'], 'score' => $current['mandatory_uzb_tarix_score'], 'cert_score' => $current['mandatory_uzb_tarix_certificate_score']],
                ];
              ?>
              <div class="otmpr-section-head-center mb-2">
                <div class="fw-semibold">Fanlar kesimida natija</div>
              </div>
              <div class="otmpr-subject-grid">
                <?php foreach ($subjectCards as $sc): ?>
                  <div class="otmpr-subject-card <?= h($sc['key']) ?>">
                    <div class="otmpr-subject-head">
                      <span><?= h($sc['title']) ?></span>
                      <?php if (trim((string)$sc['label']) !== ''): ?><span class="tag"><?= h((string)$sc['label']) ?></span><?php endif; ?>
                    </div>
                    <div class="otmpr-subject-body">
                      <div class="otmpr-grid-table">
                        <div class="gh"></div>
                        <div class="gh center">Imtihon</div>
                        <div class="gh center">Sert.</div>

                        <div class="gl">To'g'ri javob</div>
                        <div class="gc"><span class="otmpr-num"><?= h(fmt_num($sc['correct'], 0)) ?></span></div>
                        <div class="gc"><span class="otmpr-pct"><?= h(fmt_pct($sc['cert_pct'])) ?></span></div>

                        <div class="gl">Ball</div>
                        <div class="gc"><span class="otmpr-num"><?= h(fmt_num($sc['score'])) ?></span></div>
                        <div class="gc"><span class="otmpr-num"><?= h(fmt_num($sc['cert_score'])) ?></span></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-4">
          <div class="otmpr-card h-100">
            <div class="card-body d-flex flex-column gap-3">
              <div>
                <div class="fw-semibold mb-2">Sertifikat ma'lumotlari</div>
                <div class="otmpr-cert-summary mb-2">
                  <div class="rowx">
                    <div class="k">Sertifikat kiritilgan fanlar</div>
                    <div class="v"><?= h((string)$certAppliedCount) ?>/5</div>
                  </div>
                  <div class="rowx">
                    <div class="k">Sertifikat bo'yicha jami ball</div>
                    <div class="v"><?= $certOnlyTotal !== null ? h(fmt_num($certOnlyTotal)) : '—' ?></div>
                  </div>
                </div>
              </div>

              <div class="otmpr-cert-table-wrap">
                <table class="otmpr-cert-table">
                  <thead>
                    <tr>
                      <th>Fan</th>
                      <th class="text-center">Foiz</th>
                      <th class="text-end">Ball</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td>Fan 1</td>
                      <td class="pct<?= $current['major1_certificate_percent'] === null ? ' zero' : '' ?>"><?= h(fmt_pct($current['major1_certificate_percent'])) ?></td>
                      <td class="score<?= $current['major1_certificate_score'] === null ? ' zero' : '' ?>"><?= h(fmt_num($current['major1_certificate_score'])) ?></td>
                    </tr>
                    <tr>
                      <td>Fan 2</td>
                      <td class="pct<?= $current['major2_certificate_percent'] === null ? ' zero' : '' ?>"><?= h(fmt_pct($current['major2_certificate_percent'])) ?></td>
                      <td class="score<?= $current['major2_certificate_score'] === null ? ' zero' : '' ?>"><?= h(fmt_num($current['major2_certificate_score'])) ?></td>
                    </tr>
                    <tr>
                      <td>Ona tili</td>
                      <td class="pct<?= $current['mandatory_ona_tili_certificate_percent'] === null ? ' zero' : '' ?>"><?= h(fmt_pct($current['mandatory_ona_tili_certificate_percent'])) ?></td>
                      <td class="score<?= $current['mandatory_ona_tili_certificate_score'] === null ? ' zero' : '' ?>"><?= h(fmt_num($current['mandatory_ona_tili_certificate_score'])) ?></td>
                    </tr>
                    <tr>
                      <td>Matematika</td>
                      <td class="pct<?= $current['mandatory_matematika_certificate_percent'] === null ? ' zero' : '' ?>"><?= h(fmt_pct($current['mandatory_matematika_certificate_percent'])) ?></td>
                      <td class="score<?= $current['mandatory_matematika_certificate_score'] === null ? ' zero' : '' ?>"><?= h(fmt_num($current['mandatory_matematika_certificate_score'])) ?></td>
                    </tr>
                    <tr>
                      <td>Tarix</td>
                      <td class="pct<?= $current['mandatory_uzb_tarix_certificate_percent'] === null ? ' zero' : '' ?>"><?= h(fmt_pct($current['mandatory_uzb_tarix_certificate_percent'])) ?></td>
                      <td class="score<?= $current['mandatory_uzb_tarix_certificate_score'] === null ? ' zero' : '' ?>"><?= h(fmt_num($current['mandatory_uzb_tarix_certificate_score'])) ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="otmpr-subline">Izoh: Sertifikat bo'lmasa mos fan uchun sertifikat ustuni bo'sh (—) bo'ladi.</div>
            </div>
          </div>
        </div>
      </div>
    <?php elseif ($load && !$errors): ?>
      <div class="otmpr-card">
        <div class="card-body">
          <div class="otmpr-empty text-center text-muted">
            <i class="bi bi-search me-1"></i> Natija ko'rsatish uchun O'quvchi ID kiriting.
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
