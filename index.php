<?php
declare(strict_types=1);

// Public landing page (Zangiota Exam Analytics)
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

$schoolName = 'Zangiota tuman ixtisoslashtirilgan maktabi';
$year = (int)date('Y');

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
$resultsUrl = ($basePath === '' ? '' : $basePath) . '/results.php';

$cspNonce = base64_encode(random_bytes(16));
$csp = [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'none'",
    "img-src 'self' https: data:",
    "style-src 'self' https: 'unsafe-inline'",
    "font-src 'self' https: data:",
    "script-src 'self' https://cdn.jsdelivr.net 'nonce-{$cspNonce}'",
    "connect-src 'self'",
];
header('Content-Security-Policy: ' . implode('; ', $csp));

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="uz">
<head>
  <meta charset="utf-8">
  <title><?= h($schoolName) ?> - Natijalar va tahlil</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Zangiota tuman ixtisoslashtirilgan maktabi - oʻquvchilar imtihon natijalari, fanlar kesimida taqqoslash va tahlil, dinamika va koʻrsatkichlar.">
  <meta name="theme-color" content="#0d6efd">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet" crossorigin="anonymous">
  <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --brand: #0d6efd;
      --ink: #0b1220;
      --muted: #667085;
    }
    html, body { height: 100%; }
    body {
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color: var(--ink);
      background:
        radial-gradient(1100px 600px at 15% 10%, rgba(13,110,253,.16), transparent 60%),
        radial-gradient(900px 520px at 90% 18%, rgba(32,201,151,.14), transparent 55%),
        radial-gradient(900px 540px at 55% 90%, rgba(255,193,7,.12), transparent 58%),
        linear-gradient(180deg, #f8fafc, #eef2f7);
    }
    .skip-link {
      position: absolute;
      left: -9999px;
      top: auto;
      width: 1px;
      height: 1px;
      overflow: hidden;
    }
    .skip-link:focus {
      left: 1rem;
      top: 1rem;
      width: auto;
      height: auto;
      padding: .6rem .9rem;
      background: #fff;
      border-radius: .6rem;
      z-index: 1000;
      box-shadow: 0 6px 20px rgba(2, 6, 23, .2);
    }
    .hero {
      position: relative;
      overflow: hidden;
      border-radius: 1.5rem;
      background: linear-gradient(135deg, rgba(255,255,255,.85), rgba(255,255,255,.62));
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border: 1px solid rgba(15, 23, 42, .08);
      box-shadow: 0 20px 45px rgba(2, 6, 23, .10);
    }
    .hero::before {
      content: "";
      position: absolute;
      inset: -2px;
      background:
        radial-gradient(560px 280px at 10% 0%, rgba(13,110,253,.22), transparent 55%),
        radial-gradient(520px 260px at 92% 10%, rgba(32,201,151,.18), transparent 55%),
        radial-gradient(520px 260px at 50% 110%, rgba(255,193,7,.18), transparent 55%);
      opacity: .9;
      pointer-events: none;
      filter: saturate(1.08);
    }
    .hero > * { position: relative; z-index: 1; }
    .badge-soft {
      border: 1px solid rgba(13,110,253,.18);
      background: rgba(13,110,253,.08);
      color: #0b5ed7;
    }
    .lead-muted { color: var(--muted); }
    .btn-cta {
      border-radius: 999px;
      padding: .85rem 1.15rem;
      box-shadow: 0 10px 25px rgba(13,110,253,.25);
    }
    .btn-cta:hover { transform: translateY(-1px); }
    .btn-ghost {
      border-radius: 999px;
      border: 1px solid rgba(15, 23, 42, .12);
      background: rgba(255,255,255,.55);
    }
    .feature-card {
      height: 100%;
      border: 1px solid rgba(15, 23, 42, .08);
      background: rgba(255,255,255,.72);
      border-radius: 1rem;
      box-shadow: 0 14px 28px rgba(2, 6, 23, .06);
    }
    .icon-pill {
      width: 46px;
      height: 46px;
      border-radius: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(13,110,253,.10);
      border: 1px solid rgba(13,110,253,.16);
      color: #0b5ed7;
    }
    .stat {
      border: 1px solid rgba(15, 23, 42, .08);
      background: rgba(255,255,255,.65);
      border-radius: 1rem;
    }
    .divider {
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(15,23,42,.18), transparent);
    }
    footer a { color: inherit; }
    .tiny { font-size: .92rem; }
    a:focus-visible, button:focus-visible {
      outline: 3px solid rgba(13,110,253,.35);
      outline-offset: 3px;
      border-radius: .6rem;
    }
  </style>
</head>
<body>
  <a class="skip-link" href="#main-content">Asosiy kontentga oʻtish</a>

  <header class="py-3">
    <div class="container">
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span class="icon-pill" aria-hidden="true">
            <i class="bi bi-graph-up-arrow fs-5"></i>
          </span>
          <div>
            <div class="fw-semibold"><?= h($schoolName) ?></div>
            <div class="text-secondary tiny">Natijalar va tahlil platformasi</div>
          </div>
        </div>
        <div class="d-none d-md-flex align-items-center gap-2">
          <span class="badge badge-soft rounded-pill px-3 py-2">
            <i class="bi bi-shield-check me-1"></i> Xavfsiz koʻrish
          </span>
          <a class="btn btn-ghost btn-sm px-3" href="#how">Qanday ishlaydi</a>
        </div>
      </div>
    </div>
  </header>

  <main id="main-content" class="pb-4">
    <div class="container">
      <section class="hero p-4 p-md-5 mb-4" data-aos="fade-up" data-aos-duration="700">
        <div class="row align-items-center g-4">
          <div class="col-lg-7">
            <div class="d-inline-flex align-items-center gap-2 mb-3">
              <span class="badge badge-soft rounded-pill px-3 py-2">
                <i class="bi bi-stars me-1"></i> Tezkor natijalar va tahlil
              </span>
            </div>

            <h1 class="display-6 fw-bold mb-3">
              Imtihon natijalari va fanlar boʻyicha tahlil - barchasi bitta joyda
            </h1>

            <p class="lead lead-muted mb-4">
              Ushbu portal orqali oʻquvchilar natijalarini koʻrishingiz, fanlar kesimida taqqoslashingiz,
              kuchli va rivojlantirish kerak boʻlgan yoʻnalishlarni aniqlashingiz mumkin.
              Natijalar jadval koʻrinishida, trend va taqsimot tahlillari bilan birga taqdim etiladi.
            </p>

            <div class="d-flex flex-column flex-sm-row gap-2">
              <a href="<?= h($resultsUrl) ?>" class="btn btn-primary btn-cta btn-lg">
                <i class="bi bi-person-badge me-2"></i> Natijalarni koʻrish
              </a>
              <a href="#features" class="btn btn-ghost btn-lg">
                <i class="bi bi-bar-chart-line me-2"></i> Imkoniyatlar
              </a>
            </div>

            <div class="mt-4 d-flex flex-wrap gap-3 text-secondary tiny">
              <span><i class="bi bi-check2-circle me-1"></i> Fanlar boʻyicha solishtirish</span>
              <span><i class="bi bi-check2-circle me-1"></i> Oʻsish/pasayish dinamikasi</span>
              <span><i class="bi bi-check2-circle me-1"></i> Filtrlar va qidiruv</span>
              <span><i class="bi bi-check2-circle me-1"></i> Print-friendly koʻrinish</span>
            </div>
          </div>

          <div class="col-lg-5">
            <div class="feature-card p-4" data-aos="zoom-in" data-aos-delay="150" data-aos-duration="650">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="fw-semibold">
                  <i class="bi bi-speedometer2 me-2 text-primary"></i> Qisqa koʻrsatkichlar
                </div>
                <span class="badge text-bg-light border">0-40 ball tizimi</span>
              </div>

              <div class="row g-3">
                <div class="col-6">
                  <div class="stat p-3">
                    <div class="text-secondary tiny mb-1">Trend</div>
                    <div class="fw-bold fs-5">
                      <i class="bi bi-graph-up me-1 text-success"></i> Dinamika
                    </div>
                    <div class="text-secondary tiny">Choraklar boʻyicha</div>
                  </div>
                </div>
                <div class="col-6">
                  <div class="stat p-3">
                    <div class="text-secondary tiny mb-1">Taqqoslash</div>
                    <div class="fw-bold fs-5">
                      <i class="bi bi-diagram-3 me-1 text-primary"></i> Fanlar
                    </div>
                    <div class="text-secondary tiny">Sinflar boʻyicha</div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="stat p-3">
                    <div class="d-flex align-items-center justify-content-between">
                      <div>
                        <div class="text-secondary tiny mb-1">Natijalarni koʻrish uchun</div>
                        <div class="fw-semibold">"Natijalarni koʻrish" tugmasini bosing</div>
                      </div>
                      <div class="icon-pill" aria-hidden="true">
                        <i class="bi bi-arrow-right fs-5"></i>
                      </div>
                    </div>
                    <div class="mt-3 divider"></div>
                    <div class="mt-3 text-secondary tiny">
                      Eslatma: Portal faqat natijalarni koʻrsatish va tahlil qilish uchun moʻljallangan.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section id="features" class="mb-4">
        <div class="row g-3">
          <div class="col-md-4" data-aos="fade-up" data-aos-delay="0">
            <div class="feature-card p-4">
              <div class="icon-pill mb-3" aria-hidden="true"><i class="bi bi-funnel fs-5"></i></div>
              <h2 class="h5 fw-bold mb-2">Kuchli filtrlar</h2>
              <p class="text-secondary mb-0">
                Sinf, fan, imtihon/chorak va boshqa mezonlar boʻyicha natijalarni tez toping va solishtiring.
              </p>
            </div>
          </div>

          <div class="col-md-4" data-aos="fade-up" data-aos-delay="80">
            <div class="feature-card p-4">
              <div class="icon-pill mb-3" aria-hidden="true"><i class="bi bi-activity fs-5"></i></div>
              <h2 class="h5 fw-bold mb-2">Trend va dinamika</h2>
              <p class="text-secondary mb-0">
                Chorak va fanlar boʻyicha oʻsish/pasayish, foiz oʻzgarishlari va progress indikatorlarini koʻring.
              </p>
            </div>
          </div>

          <div class="col-md-4" data-aos="fade-up" data-aos-delay="160">
            <div class="feature-card p-4">
              <div class="icon-pill mb-3" aria-hidden="true"><i class="bi bi-bar-chart-line fs-5"></i></div>
              <h2 class="h5 fw-bold mb-2">Vizual tahlil</h2>
              <p class="text-secondary mb-0">
                Natijalar taqsimoti, fanlar kesimidagi koʻrsatkichlar va umumiy xulosalarni qulay formatda oling.
              </p>
            </div>
          </div>
        </div>
      </section>

      <section id="how" class="feature-card p-4 p-md-5 mb-4" data-aos="fade-up">
        <div class="row align-items-center g-4">
          <div class="col-lg-6">
            <h2 class="h4 fw-bold mb-2">Qanday ishlaydi</h2>
            <p class="text-secondary mb-0">
              Portal oʻquvchilar natijalarini imtihonlar boʻyicha jamlaydi va fanlar kesimida tahlil qiladi.
              Siz kerakli sinfni tanlaysiz, oʻquvchini tanlaysiz va natijalarni jadval hamda tahliliy koʻrinishda koʻrasiz.
            </p>

            <div class="mt-4">
              <div class="d-flex gap-3 align-items-start mb-3">
                <div class="icon-pill" aria-hidden="true"><i class="bi bi-1-circle fs-5"></i></div>
                <div>
                  <div class="fw-semibold">Sahifaga kiring</div>
                  <div class="text-secondary tiny">Natijalar boʻlimi orqali koʻrish rejimida ochiladi.</div>
                </div>
              </div>

              <div class="d-flex gap-3 align-items-start mb-3">
                <div class="icon-pill" aria-hidden="true"><i class="bi bi-2-circle fs-5"></i></div>
                <div>
                  <div class="fw-semibold">Filtrlang va tanlang</div>
                  <div class="text-secondary tiny">Sinf va oʻquvchini tanlang, fan boʻyicha tahlilni koʻring.</div>
                </div>
              </div>

              <div class="d-flex gap-3 align-items-start">
                <div class="icon-pill" aria-hidden="true"><i class="bi bi-3-circle fs-5"></i></div>
                <div>
                  <div class="fw-semibold">Tahlilni oling</div>
                  <div class="text-secondary tiny">Trend, oʻzgarishlar va koʻrsatkichlarni bir sahifada oling.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="p-4 rounded-4 border" style="background: rgba(255,255,255,.65);">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="fw-semibold">
                  <i class="bi bi-info-circle me-2 text-primary"></i> Muhim maʻlumot
                </div>
                <span class="badge text-bg-light border">Qoʻllanma</span>
              </div>

              <ul class="text-secondary mb-0">
                <li class="mb-2">Ballar 0 dan 40 gacha boʻlgan tizimda koʻrsatiladi.</li>
                <li class="mb-2">Natijalar faqat koʻrish va tahlil qilish uchun moʻljallangan.</li>
                <li class="mb-2">Agar natija chiqmasa, sinf tanlovi va oʻquvchi maʻlumotlarini tekshiring.</li>
                <li class="mb-0">Savollar boʻlsa, maktab masʻul xodimiga murojaat qiling.</li>
              </ul>

              <div class="mt-4 d-grid">
                <a href="<?= h($resultsUrl) ?>" class="btn btn-primary btn-lg btn-cta">
                  <i class="bi bi-box-arrow-in-right me-2"></i> Natijalarni koʻrish sahifasiga oʻtish
                </a>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="text-center text-secondary tiny pb-2" data-aos="fade-up" data-aos-delay="80">
        <div class="divider my-4"></div>
        <div class="mb-2">
          &copy; <?= $year ?> <?= h($schoolName) ?>. Barcha huquqlar himoyalangan.
        </div>
        <div>
          Powered by
          <a href="https://uzhost.net" target="_blank" rel="noopener noreferrer" class="text-decoration-none fw-semibold">Uzhost</a>
          & Created by
          <a href="https://MrShahzodbek.t.me" target="_blank" rel="noopener noreferrer" class="text-decoration-none fw-semibold">MrShahzodbek</a>
        </div>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js" crossorigin="anonymous"></script>
  <script nonce="<?= h($cspNonce) ?>">
    AOS.init({
      once: true,
      duration: 700,
      easing: 'ease-out-cubic',
      offset: 60
    });
  </script>
</body>
</html>
