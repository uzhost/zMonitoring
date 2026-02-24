<?php
declare(strict_types=1);

$auth_required = false;
$teacher_is_guest = true;
$show_teacher_nav = false;
$show_page_header = false;
$page_title = 'Random Name Wheel Picker';
require_once __DIR__ . '/../teachers/header.php';
?>


  <style>
    .wn-shell {
      --wn-ink: #0f172a;
      --wn-muted: #64748b;
      --wn-line: rgba(15, 23, 42, 0.1);
      --wn-brand: #0f6efd;
      --wn-brand-2: #06b6d4;
      --wn-danger: #dc3545;
      --wn-success: #198754;
      --wn-surface: rgba(255, 255, 255, 0.86);
      --wn-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
      color: var(--wn-ink);
    }

    .wn-hero {
      border: 1px solid var(--wn-line);
      border-radius: 1.1rem;
      background:
        radial-gradient(circle at 10% 15%, rgba(14, 165, 233, 0.11), transparent 40%),
        radial-gradient(circle at 85% 10%, rgba(59, 130, 246, 0.09), transparent 38%),
        linear-gradient(180deg, rgba(255,255,255,.95), rgba(248,250,252,.92));
      box-shadow: var(--wn-shadow);
      padding: 1rem 1.1rem;
    }

    .wn-title {
      font-size: clamp(1.45rem, 2.4vw, 2rem);
      line-height: 1.05;
      letter-spacing: -0.02em;
      margin: 0;
      font-weight: 800;
    }

    .wn-sub {
      color: var(--wn-muted);
      margin-top: .35rem;
    }

    .wn-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      border: 1px solid var(--wn-line);
      background: rgba(255,255,255,.9);
      border-radius: 999px;
      padding: .3rem .6rem;
      font-size: .8rem;
      color: #334155;
    }

    .wn-grid {
      display: grid;
      grid-template-columns: .88fr 1.52fr;
      gap: 1rem;
      margin-top: 1rem;
    }

    .wn-card {
      border: 1px solid var(--wn-line);
      border-radius: 1rem;
      background: var(--wn-surface);
      box-shadow: var(--wn-shadow);
    }

    .wn-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      border-bottom: 1px solid var(--wn-line);
      padding: .85rem 1rem;
      background: linear-gradient(180deg, rgba(248,250,252,.95), rgba(255,255,255,.92));
      border-radius: 1rem 1rem 0 0;
    }

    .wn-card-title {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-weight: 700;
      margin: 0;
      font-size: 1rem;
    }

    .wn-card-title i {
      color: var(--wn-brand);
    }

    .wn-card-body {
      padding: 1rem;
    }

    .wn-help {
      font-size: .82rem;
      color: var(--wn-muted);
    }

    .wn-textarea {
      min-height: 10rem;
      resize: vertical;
      border-radius: .85rem;
    }

    .wn-actions {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
    }

    .wn-actions .btn {
      border-radius: .8rem;
      font-weight: 600;
    }

    .wn-inline-stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: .5rem;
      margin-top: .75rem;
    }

    .wn-stat {
      border: 1px solid var(--wn-line);
      border-radius: .8rem;
      background: rgba(255,255,255,.92);
      padding: .6rem .7rem;
    }

    .wn-stat-label {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--wn-muted);
      font-weight: 600;
    }

    .wn-stat-value {
      font-size: 1.05rem;
      font-weight: 800;
      margin-top: .2rem;
      color: var(--wn-ink);
    }

    .wn-list-wrap {
      border: 1px solid var(--wn-line);
      border-radius: .9rem;
      background: rgba(255,255,255,.92);
      overflow: hidden;
      margin-top: .9rem;
    }

    .wn-list-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      padding: .65rem .75rem;
      border-bottom: 1px solid var(--wn-line);
      background: rgba(248,250,252,.9);
    }

    .wn-list {
      max-height: 20rem;
      overflow: auto;
      padding: .4rem;
    }

    .wn-item {
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: .6rem;
      padding: .45rem .5rem;
      border-radius: .7rem;
      border: 1px solid transparent;
    }

    .wn-item:nth-child(odd) {
      background: rgba(248,250,252,.55);
    }

    .wn-item:hover {
      border-color: rgba(37,99,235,.15);
      background: rgba(239,246,255,.65);
    }

    .wn-item-index {
      width: 1.7rem;
      height: 1.7rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      background: rgba(15,23,42,.06);
      color: #475569;
      font-size: .78rem;
      font-weight: 700;
    }

    .wn-item-name {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-weight: 600;
    }

    .wn-item .btn {
      border-radius: .65rem;
      padding: .25rem .45rem;
      line-height: 1;
    }

    .wn-empty {
      padding: 1rem;
      color: var(--wn-muted);
      text-align: center;
      font-size: .92rem;
    }

    .wn-wheel-stage {
      display: grid;
      grid-template-columns: 1fr;
      gap: .9rem;
    }

    .wn-wheel-box {
      position: relative;
      border: 1px solid var(--wn-line);
      border-radius: 1rem;
      background:
        radial-gradient(circle at center, rgba(255,255,255,.98), rgba(241,245,249,.94));
      padding: .85rem;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }
    .wn-wheel-box:fullscreen,
    .wn-wheel-box:-webkit-full-screen {
      border: 0;
      border-radius: 0;
      padding: 1.1rem;
      margin: 0;
      width: 100vw;
      height: 100vh;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle at 20% 10%, rgba(59,130,246,.12), transparent 38%),
        radial-gradient(circle at 85% 85%, rgba(16,185,129,.10), transparent 40%),
        #f1f5f9;
    }
    .wn-wheel-box:fullscreen .wn-wheel-frame,
    .wn-wheel-box:-webkit-full-screen .wn-wheel-frame {
      width: min(94vmin, 900px);
      height: min(94vmin, 900px);
      max-width: 94vmin;
      max-height: 94vmin;
      padding: 1rem;
    }
    .wn-wheel-box:fullscreen .wn-wheel-canvas,
    .wn-wheel-box:-webkit-full-screen .wn-wheel-canvas {
      width: 100%;
      max-width: none;
    }

    .wn-wheel-frame {
      position: relative;
      display: grid;
      place-items: center;
      min-height: 40rem;
      border-radius: 1rem;
      background:
        radial-gradient(circle at center, rgba(255,255,255,.96) 0%, rgba(248,250,252,.92) 60%, rgba(226,232,240,.95) 100%);
      padding: .75rem;
      border: 1px solid rgba(15,23,42,.08);
    }

    .wn-pointer {
      position: absolute;
      top: 50%;
      right: -.35rem;
      transform: translateY(-50%);
      width: 0;
      height: 0;
      border-top: 14px solid transparent;
      border-bottom: 14px solid transparent;
      border-right: 60px solid #ef4444;
      border-left: 0;
      z-index: 3;
      filter: drop-shadow(0 3px 3px rgba(15,23,42,.2));
    }

    .wn-wheel-canvas {
      width: min(100%, 36rem);
      aspect-ratio: 1 / 1;
      display: block;
      border-radius: 50%;
      background: #fff;
      border: 1px solid rgba(255,255,255,.92);
      box-shadow:
        0 10px 26px rgba(15,23,42,.12),
        inset 0 0 0 1px rgba(15,23,42,.05);
    }

    .wn-wheel-center {
      position: absolute;
      width: 4.75rem;
      height: 4.75rem;
      border-radius: 50%;
      background: linear-gradient(180deg, #ffffff, #e2e8f0);
      border: 2px solid rgba(15,23,42,.08);
      display: grid;
      place-items: center;
      font-weight: 800;
      color: #0f172a;
      box-shadow: 0 4px 14px rgba(15,23,42,.12);
      z-index: 2;
      cursor: pointer;
      transition: transform .12s ease, box-shadow .18s ease, background .18s ease;
      user-select: none;
    }
    .wn-wheel-center:hover {
      transform: translateY(-1px);
      box-shadow: 0 7px 18px rgba(15,23,42,.16);
      background: linear-gradient(180deg, #ffffff, #dbeafe);
    }
    .wn-wheel-center:active {
      transform: translateY(1px) scale(.98);
    }
    .wn-wheel-center:focus-visible {
      outline: 3px solid rgba(37,99,235,.45);
      outline-offset: 2px;
    }
    .wn-winner-dialog{
      border: 0;
      padding: 0;
      margin: auto;
      border-radius: 1rem;
      width: min(94vw, 38rem);
      max-width: 38rem;
      background: transparent;
      box-shadow: none;
      overflow: visible;
    }
    .wn-winner-dialog[open]{
      animation: wnPopIn .22s ease-out;
    }
    .wn-winner-dialog::backdrop{
      background:
        radial-gradient(circle at 50% 20%, rgba(59,130,246,.15), transparent 45%),
        rgba(15,23,42,.52);
      backdrop-filter: blur(4px);
    }
    .wn-winner-dialog-card{
      position: relative;
      border: 1px solid rgba(15,23,42,.08);
      border-radius: 1rem;
      box-shadow: 0 24px 60px rgba(15,23,42,.22);
      overflow: hidden;
      background:
        radial-gradient(circle at 12% 8%, rgba(59,130,246,.1), transparent 42%),
        radial-gradient(circle at 90% 12%, rgba(16,185,129,.08), transparent 38%),
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.97));
    }
    .wn-winner-dialog-head{
      position: relative;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: .5rem;
      padding: .65rem .75rem 0;
    }
    .wn-winner-dialog-body{
      position: relative;
      z-index: 2;
      padding: .65rem .95rem .8rem;
      text-align: center;
    }
    .wn-winner-dialog-foot{
      position: relative;
      z-index: 2;
      display: flex;
      justify-content: center;
      gap: .6rem;
      padding: 0 .95rem .95rem;
      flex-wrap: wrap;
    }
    .wn-winner-dialog-foot .btn{
      min-width: 10.5rem;
      border-radius: .75rem;
      font-weight: 700;
    }
    .wn-winner-fx{
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
      z-index: 1;
      opacity: .95;
    }
    .wn-winner-badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:.45rem;
      border-radius:999px;
      padding:.35rem .65rem;
      border:1px solid rgba(37,99,235,.14);
      background:rgba(239,246,255,.95);
      color:#1d4ed8;
      font-size:.82rem;
      font-weight:700;
    }
    .wn-winner-name{
      font-size: clamp(1.35rem, 2.4vw, 2rem);
      line-height: 1.06;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -.02em;
      word-break: break-word;
      margin: .2rem 0 0;
    }
    .wn-winner-name-wrap{
      display:grid;
      place-items:center;
      min-height: 5.4rem;
      padding: .5rem .25rem;
      border-radius: .95rem;
      border: 1px solid rgba(37,99,235,.1);
      background:
        linear-gradient(180deg, rgba(239,246,255,.7), rgba(255,255,255,.86));
      box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
    }
    .wn-winner-pill-row{
      display:flex;
      justify-content:center;
      margin-bottom:.55rem;
    }
    .wn-winner-burst{
      animation: wnPulseGlow 1.2s ease-out;
    }
    @keyframes wnPopIn{
      from{ opacity:0; transform: translateY(10px) scale(.98); }
      to{ opacity:1; transform: translateY(0) scale(1); }
    }
    @keyframes wnPulseGlow{
      0%{ box-shadow: 0 0 0 0 rgba(59,130,246,.0), inset 0 1px 0 rgba(255,255,255,.9); }
      35%{ box-shadow: 0 0 0 9px rgba(59,130,246,.10), inset 0 1px 0 rgba(255,255,255,.9); }
      100%{ box-shadow: 0 0 0 0 rgba(59,130,246,.0), inset 0 1px 0 rgba(255,255,255,.9); }
    }
    @media (max-width: 575.98px){
      .wn-winner-dialog{ width: min(96vw, 38rem); }
      .wn-winner-dialog-head,
      .wn-winner-dialog-body{ padding-left: .8rem; padding-right: .8rem; }
      .wn-winner-dialog-foot{ padding-left: .8rem; padding-right: .8rem; }
      .wn-winner-dialog-foot .btn{ min-width: 0; flex: 1 1 100%; }
    }
    .wn-fs-btn[aria-pressed="true"]{
      background: rgba(37,99,235,.12);
      border-color: rgba(37,99,235,.22);
      color: #1d4ed8;
    }

    .wn-control-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .75rem;
    }

    .wn-control-card {
      border: 1px solid var(--wn-line);
      border-radius: .9rem;
      background: rgba(255,255,255,.9);
      padding: .85rem;
    }

    .wn-control-card h3 {
      margin: 0 0 .45rem;
      font-size: .95rem;
      font-weight: 700;
    }

    .wn-result-name {
      font-size: clamp(1.15rem, 2vw, 1.5rem);
      font-weight: 800;
      color: #0f172a;
      line-height: 1.1;
      word-break: break-word;
    }

    .wn-result-meta {
      color: var(--wn-muted);
      font-size: .84rem;
      margin-top: .35rem;
    }

    .wn-history {
      margin: 0;
      padding-left: 1rem;
      color: #334155;
      max-height: 8rem;
      overflow: auto;
      font-size: .88rem;
    }

    .wn-history li + li {
      margin-top: .28rem;
    }

    .wn-toggle {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem 1rem;
      align-items: center;
    }

    .wn-toggle .form-check {
      margin: 0;
    }

    .wn-kbd {
      border: 1px solid var(--wn-line);
      border-bottom-width: 2px;
      border-radius: .35rem;
      padding: .05rem .35rem;
      background: rgba(255,255,255,.9);
      font-size: .75rem;
      color: #475569;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }

    @media (max-width: 1199.98px) {
      .wn-grid {
        grid-template-columns: 1fr;
      }
      .wn-inline-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 767.98px) {
      .wn-card-body,
      .wn-card-head {
        padding-left: .85rem;
        padding-right: .85rem;
      }
      .wn-inline-stats {
        grid-template-columns: 1fr;
      }
      .wn-control-grid {
        grid-template-columns: 1fr;
      }
      .wn-actions .btn {
        flex: 1 1 calc(50% - .25rem);
      }
      .wn-list {
        max-height: 16rem;
      }
    }
  </style>

  <section class="wn-shell">
    <div class="wn-hero">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h1 class="wn-title">Random Name Wheel Picker</h1>
          <p class="wn-sub mb-2">
            Add pupil names, spin the wheel, and remove selected names from the pool for fair next picks.
          </p>
          <div class="d-flex flex-wrap gap-2">
            <span class="wn-chip"><i class="bi bi-shuffle"></i> Fair random pick</span>
            <span class="wn-chip"><i class="bi bi-person-x"></i> Remove winner and continue</span>
            <span class="wn-chip"><i class="bi bi-save"></i> Auto-save in browser</span>
          </div>
        </div>
      </div>
    </div>

    <div class="wn-grid">
      <section class="wn-card" aria-labelledby="wn-names-title">
        <div class="wn-card-head">
          <h2 id="wn-names-title" class="wn-card-title"><i class="bi bi-people"></i> Name Pool</h2>
          <span class="badge text-bg-secondary-subtle border text-secondary-emphasis" id="wnPoolBadge">0 names</span>
        </div>
        <div class="wn-card-body">
          <label for="wnNamesInput" class="form-label fw-semibold mb-1">Add names</label>
          <textarea
            id="wnNamesInput"
            class="form-control wn-textarea"
            placeholder="Paste one name per line&#10;or comma-separated names&#10;&#10;Example:&#10;Ali&#10;Vali&#10;Aziza"
          ></textarea>
          <div class="wn-help mt-2">
            Tip: Names are trimmed. Duplicate names can be skipped automatically.
          </div>

          <div class="wn-actions mt-3">
            <button type="button" class="btn btn-primary" id="wnAddBtn">
              <i class="bi bi-plus-circle me-1"></i> Add names
            </button>
            <button type="button" class="btn btn-outline-secondary" id="wnShuffleBtn">
              <i class="bi bi-shuffle me-1"></i> Shuffle list
            </button>
            <button type="button" class="btn btn-outline-warning" id="wnUndoRemoveBtn" disabled>
              <i class="bi bi-arrow-counterclockwise me-1"></i> Undo remove
            </button>
            <button type="button" class="btn btn-outline-danger" id="wnClearBtn">
              <i class="bi bi-trash3 me-1"></i> Clear all
            </button>
          </div>

          <div class="wn-toggle mt-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="wnDedupeToggle" checked>
              <label class="form-check-label" for="wnDedupeToggle">Skip duplicates</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="wnAutoRemoveToggle">
              <label class="form-check-label" for="wnAutoRemoveToggle">Auto-remove picked name</label>
            </div>
          </div>

          <div class="wn-inline-stats">
            <div class="wn-stat">
              <div class="wn-stat-label">Available</div>
              <div class="wn-stat-value" id="wnCountAvailable">0</div>
            </div>
            <div class="wn-stat">
              <div class="wn-stat-label">Picked</div>
              <div class="wn-stat-value" id="wnCountPicked">0</div>
            </div>
            <div class="wn-stat">
              <div class="wn-stat-label">Removed</div>
              <div class="wn-stat-value" id="wnCountRemoved">0</div>
            </div>
          </div>

          <div class="wn-list-wrap" aria-live="polite">
            <div class="wn-list-toolbar">
              <div class="fw-semibold small">Current names</div>
              <div class="small text-secondary">Click <span class="wn-kbd">x</span> to remove</div>
            </div>
            <div class="wn-list" id="wnList"></div>
          </div>
        </div>
      </section>

      <section class="wn-card" aria-labelledby="wn-wheel-title">
        <div class="wn-card-head">
          <h2 id="wn-wheel-title" class="wn-card-title"><i class="bi bi-disc"></i> Wheel Picker</h2>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="wnFullscreenBtn" aria-pressed="false">
              <i class="bi bi-arrows-fullscreen me-1"></i> Full screen
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="wnResetRoundBtn">
              <i class="bi bi-arrow-repeat me-1"></i> Reset picks
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="wnSpinBtn">
              <i class="bi bi-play-fill me-1"></i> Spin
            </button>
          </div>
        </div>
        <div class="wn-card-body">
          <div class="wn-wheel-stage">
            <div class="wn-wheel-box" id="wnWheelBox">
              <div class="wn-wheel-frame">
                <div class="wn-pointer" aria-hidden="true"></div>
                <canvas id="wnWheelCanvas" class="wn-wheel-canvas" width="560" height="560" aria-label="Name wheel canvas"></canvas>
                <button type="button" class="wn-wheel-center" id="wnCenterSpinBtn" aria-label="Spin wheel">SPIN</button>
              </div>
            </div>

            <div class="wn-control-grid">
              <div class="wn-control-card">
                <h3>Selected name</h3>
                <div id="wnSelectedName" class="wn-result-name">No pick yet</div>
                <div id="wnSelectedMeta" class="wn-result-meta">
                  Add names, then click <strong>Spin</strong>.
                </div>
                <div class="wn-actions mt-3">
                  <button type="button" class="btn btn-outline-danger" id="wnRemoveWinnerBtn" disabled>
                    <i class="bi bi-person-dash me-1"></i> Remove picked name
                  </button>
                  <button type="button" class="btn btn-outline-secondary" id="wnKeepWinnerBtn" disabled>
                    <i class="bi bi-person-check me-1"></i> Keep in pool
                  </button>
                </div>
              </div>

              <div class="wn-control-card">
                <h3>Pick history</h3>
                <ol id="wnHistory" class="wn-history mb-0"></ol>
                <div id="wnHistoryEmpty" class="wn-help">No picks yet.</div>
                <div class="wn-actions mt-3">
                  <button type="button" class="btn btn-outline-secondary" id="wnClearHistoryBtn">
                    <i class="bi bi-eraser me-1"></i> Clear history
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>

  <dialog class="wn-winner-dialog" id="wnWinnerDialog" aria-labelledby="wnWinnerModalLabel">
      <div class="wn-winner-dialog-card">
        <canvas id="wnWinnerFxCanvas" class="wn-winner-fx" width="800" height="500" aria-hidden="true"></canvas>
        <div class="wn-winner-dialog-head">
          <button type="button" class="btn-close" id="wnModalCloseBtn" aria-label="Close"></button>
        </div>
        <div class="wn-winner-dialog-body">
          <div class="wn-winner-pill-row">
            <div class="wn-winner-badge" id="wnWinnerModalLabel"><i class="bi bi-trophy-fill"></i> WINNER</div>
          </div>
          <div class="wn-winner-name-wrap" id="wnWinnerNameWrap">
            <div id="wnWinnerModalName" class="wn-winner-name">—</div>
          </div>
        </div>
        <div class="wn-winner-dialog-foot">
          <button type="button" class="btn btn-outline-secondary" id="wnModalKeepBtn">
            Keep
          </button>
          <button type="button" class="btn btn-outline-danger" id="wnModalRemoveWinnerBtn">
            <i class="bi bi-person-dash me-1"></i> Remove
          </button>
          <button type="button" class="btn btn-primary" id="wnModalSpinAgainBtn">
            <i class="bi bi-arrow-repeat me-1"></i> Spin again
          </button>
        </div>
      </div>
  </dialog>

  <script>
    (() => {
      const STORAGE_KEY = 'wheel_name_picker_state_v1';
      const COLORS = [
        '#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
        '#0ea5e9', '#14b8a6', '#f97316', '#84cc16', '#ec4899'
      ];

      const state = {
        names: [],
        removed: [],
        picks: [],
        selectedName: null,
        dedupe: true,
        autoRemove: false,
        spinning: false,
        wheelRotation: 0,
      };

      const els = {
        namesInput: document.getElementById('wnNamesInput'),
        addBtn: document.getElementById('wnAddBtn'),
        shuffleBtn: document.getElementById('wnShuffleBtn'),
        clearBtn: document.getElementById('wnClearBtn'),
        undoRemoveBtn: document.getElementById('wnUndoRemoveBtn'),
        dedupeToggle: document.getElementById('wnDedupeToggle'),
        autoRemoveToggle: document.getElementById('wnAutoRemoveToggle'),
        poolBadge: document.getElementById('wnPoolBadge'),
        countAvailable: document.getElementById('wnCountAvailable'),
        countPicked: document.getElementById('wnCountPicked'),
        countRemoved: document.getElementById('wnCountRemoved'),
        list: document.getElementById('wnList'),
        spinBtn: document.getElementById('wnSpinBtn'),
        centerSpinBtn: document.getElementById('wnCenterSpinBtn'),
        fullscreenBtn: document.getElementById('wnFullscreenBtn'),
        wheelBox: document.getElementById('wnWheelBox'),
        resetRoundBtn: document.getElementById('wnResetRoundBtn'),
        selectedName: document.getElementById('wnSelectedName'),
        selectedMeta: document.getElementById('wnSelectedMeta'),
        removeWinnerBtn: document.getElementById('wnRemoveWinnerBtn'),
        keepWinnerBtn: document.getElementById('wnKeepWinnerBtn'),
        history: document.getElementById('wnHistory'),
        historyEmpty: document.getElementById('wnHistoryEmpty'),
        clearHistoryBtn: document.getElementById('wnClearHistoryBtn'),
        canvas: document.getElementById('wnWheelCanvas'),
        winnerModalEl: document.getElementById('wnWinnerDialog'),
        winnerModalName: document.getElementById('wnWinnerModalName'),
        winnerNameWrap: document.getElementById('wnWinnerNameWrap'),
        winnerFxCanvas: document.getElementById('wnWinnerFxCanvas'),
        modalCloseBtn: document.getElementById('wnModalCloseBtn'),
        modalKeepBtn: document.getElementById('wnModalKeepBtn'),
        modalRemoveWinnerBtn: document.getElementById('wnModalRemoveWinnerBtn'),
        modalSpinAgainBtn: document.getElementById('wnModalSpinAgainBtn'),
      };

      const ctx = els.canvas.getContext('2d');
      let winnerModal = null;
      let winnerFxCtx = els.winnerFxCanvas ? els.winnerFxCanvas.getContext('2d') : null;
      let winnerFxRaf = 0;
      let winnerFxParticles = [];
      let winnerFxUntil = 0;

      function saveState() {
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify({
            names: state.names,
            removed: state.removed,
            picks: state.picks,
            selectedName: state.selectedName,
            dedupe: state.dedupe,
            autoRemove: state.autoRemove,
            wheelRotation: state.wheelRotation,
          }));
        } catch (e) {
          // Ignore localStorage failures.
        }
      }

      function loadState() {
        try {
          const raw = localStorage.getItem(STORAGE_KEY);
          if (!raw) return;
          const parsed = JSON.parse(raw);
          if (Array.isArray(parsed.names)) state.names = parsed.names.filter(isValidName);
          if (Array.isArray(parsed.removed)) state.removed = parsed.removed.filter(isValidName);
          if (Array.isArray(parsed.picks)) state.picks = parsed.picks.filter(isValidName).slice(0, 50);
          if (typeof parsed.selectedName === 'string' && isValidName(parsed.selectedName)) state.selectedName = parsed.selectedName;
          state.dedupe = !!parsed.dedupe;
          state.autoRemove = !!parsed.autoRemove;
          if (typeof parsed.wheelRotation === 'number' && Number.isFinite(parsed.wheelRotation)) {
            state.wheelRotation = parsed.wheelRotation;
          }
        } catch (e) {
          // Ignore corrupted storage.
        }
      }

      function isValidName(value) {
        return typeof value === 'string' && value.trim() !== '';
      }

      function normalizeName(value) {
        return String(value).replace(/\s+/g, ' ').trim();
      }

      function parseNamesFromInput(raw) {
        return String(raw)
          .split(/[\n,;]+/g)
          .map(normalizeName)
          .filter(Boolean);
      }

      function addNames(inputNames) {
        if (!inputNames.length) return { added: 0, skipped: 0 };
        let added = 0;
        let skipped = 0;
        const existing = new Set(state.names.map((n) => n.toLocaleLowerCase()));
        for (const rawName of inputNames) {
          const name = normalizeName(rawName);
          if (!name) continue;
          const key = name.toLocaleLowerCase();
          if (state.dedupe && existing.has(key)) {
            skipped++;
            continue;
          }
          state.names.push(name);
          existing.add(key);
          added++;
        }
        return { added, skipped };
      }

      function removeNameAt(index, source = 'manual') {
        if (index < 0 || index >= state.names.length) return null;
        const [removedName] = state.names.splice(index, 1);
        if (removedName) {
          state.removed.push(removedName);
          if (state.selectedName === removedName && source !== 'pick-history') {
            state.selectedName = null;
          }
        }
        return removedName || null;
      }

      function removeSelectedWinner() {
        if (!state.selectedName) return false;
        const idx = state.names.findIndex((n) => n === state.selectedName);
        if (idx === -1) return false;
        removeNameAt(idx, 'winner');
        const removedWinner = state.selectedName;
        state.selectedName = null;
        render();
        updateSelectedPanel('Winner removed from pool.', removedWinner);
        return true;
      }

      function undoLastRemoval() {
        if (!state.removed.length) return;
        const name = state.removed.pop();
        if (name && !state.names.includes(name)) {
          state.names.push(name);
        }
        render();
      }

      function shuffleNames() {
        for (let i = state.names.length - 1; i > 0; i--) {
          const j = Math.floor(Math.random() * (i + 1));
          [state.names[i], state.names[j]] = [state.names[j], state.names[i]];
        }
      }

      function clearAllNames() {
        if (!state.names.length && !state.removed.length) return;
        state.names = [];
        state.removed = [];
        state.selectedName = null;
        render();
        updateSelectedPanel('Pool cleared.', null);
      }

      function clearHistory() {
        state.picks = [];
        renderHistory();
        saveState();
      }

      function resetPicksOnly() {
        state.picks = [];
        state.selectedName = null;
        render();
        updateSelectedPanel('Pick history reset. Pool is unchanged.', null);
      }

      function pickRandomIndex() {
        if (!state.names.length) return -1;
        return Math.floor(Math.random() * state.names.length);
      }

      function spinWheel() {
        if (state.spinning) return;
        if (state.names.length < 1) {
          updateSelectedPanel('Add at least one name to spin.', null, true);
          return;
        }

        const pickedIndex = pickRandomIndex();
        if (pickedIndex < 0) return;

        state.spinning = true;
        setButtonsState();

        const segmentAngle = (Math.PI * 2) / state.names.length;
        const pointerAngle = 0; // East/right-side pointer.
        const targetCenterAngle = (pickedIndex * segmentAngle) + (segmentAngle / 2);
        const normalizedCurrent = ((state.wheelRotation % (Math.PI * 2)) + (Math.PI * 2)) % (Math.PI * 2);
        let targetRotation = pointerAngle - targetCenterAngle;
        targetRotation = ((targetRotation % (Math.PI * 2)) + (Math.PI * 2)) % (Math.PI * 2);

        const extraSpins = (Math.PI * 2) * (4 + Math.floor(Math.random() * 3));
        const deltaToTarget = targetRotation - normalizedCurrent;
        const adjustedDelta = deltaToTarget < 0 ? (deltaToTarget + Math.PI * 2) : deltaToTarget;
        const startRotation = state.wheelRotation;
        const endRotation = state.wheelRotation + extraSpins + adjustedDelta;
        const duration = 4300 + Math.floor(Math.random() * 1700);
        const startTime = performance.now();

        function easeOutQuad(t) {
          return 1 - Math.pow(1 - t, 2);
        }

        function animate(now) {
          const progress = Math.min(1, (now - startTime) / duration);
          const eased = easeOutQuad(progress);
          state.wheelRotation = startRotation + ((endRotation - startRotation) * eased);
          drawWheel();
          if (progress < 1) {
            requestAnimationFrame(animate);
            return;
          }

          const winner = state.names[pickedIndex] || null;
          state.spinning = false;

          if (winner) {
            state.selectedName = winner;
            state.picks.unshift(winner);
            state.picks = state.picks.slice(0, 30);

            if (state.autoRemove) {
              const idx = state.names.findIndex((n) => n === winner);
              if (idx !== -1) removeNameAt(idx, 'auto');
              state.selectedName = null;
              updateSelectedPanel('Picked and removed automatically.', winner);
              showWinnerModal(winner);
            } else {
              updateSelectedPanel('Picked from current pool.', winner);
              showWinnerModal(winner);
            }
          }

          render();
          saveState();
          setButtonsState();
        }

        requestAnimationFrame(animate);
      }

      function updateSelectedPanel(message, name, isWarning = false) {
        if (name) {
          els.selectedName.textContent = name;
        } else if (!state.selectedName) {
          els.selectedName.textContent = 'No pick yet';
        } else {
          els.selectedName.textContent = state.selectedName;
        }

        els.selectedMeta.textContent = message;
        els.selectedMeta.style.color = isWarning ? '#b45309' : '';
      }

      function initWinnerModal() {
        if (!els.winnerModalEl) return;
        winnerModal = els.winnerModalEl;
      }

      function showWinnerModal(name) {
        if (!name || !els.winnerModalName) return;
        els.winnerModalName.textContent = name;
        if (els.winnerNameWrap) {
          els.winnerNameWrap.classList.remove('wn-winner-burst');
          void els.winnerNameWrap.offsetWidth;
          els.winnerNameWrap.classList.add('wn-winner-burst');
        }
        if (winnerModal) {
          if (typeof winnerModal.showModal === 'function') {
            if (!winnerModal.open) winnerModal.showModal();
          } else {
            winnerModal.setAttribute('open', '');
          }
        }
        playWinnerFireworks();
      }

      function closeWinnerModal() {
        stopWinnerFireworks();
        if (!winnerModal) return;
        if (typeof winnerModal.close === 'function' && winnerModal.open) {
          winnerModal.close();
        } else {
          winnerModal.removeAttribute('open');
        }
      }

      function resizeWinnerFxCanvas() {
        if (!els.winnerFxCanvas || !winnerFxCtx) return;
        const card = els.winnerModalEl?.querySelector('.wn-winner-dialog-card');
        if (!card) return;
        const rect = card.getBoundingClientRect();
        const cssW = Math.max(1, Math.round(rect.width));
        const cssH = Math.max(1, Math.round(rect.height));
        const dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
        els.winnerFxCanvas.style.width = cssW + 'px';
        els.winnerFxCanvas.style.height = cssH + 'px';
        els.winnerFxCanvas.width = Math.round(cssW * dpr);
        els.winnerFxCanvas.height = Math.round(cssH * dpr);
        winnerFxCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
      }

      function spawnFireworkBurst(cx, cy) {
        const colors = ['#60a5fa', '#34d399', '#fbbf24', '#f472b6', '#a78bfa', '#22d3ee', '#fb7185'];
        const count = 20 + Math.floor(Math.random() * 10);
        for (let i = 0; i < count; i++) {
          const angle = (Math.PI * 2 * i / count) + (Math.random() * 0.35);
          const speed = 1.3 + Math.random() * 3.8;
          winnerFxParticles.push({
            x: cx,
            y: cy,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed - Math.random() * 1.3,
            age: 0,
            life: 24 + Math.floor(Math.random() * 20),
            size: 2 + Math.random() * 2.4,
            color: colors[Math.floor(Math.random() * colors.length)],
          });
        }
      }

      function drawWinnerFireworksFrame(now) {
        if (!winnerFxCtx || !els.winnerFxCanvas) return;
        if (!winnerModal || !winnerModal.open) {
          stopWinnerFireworks();
          return;
        }

        const w = parseFloat(els.winnerFxCanvas.style.width || '0') || 0;
        const h = parseFloat(els.winnerFxCanvas.style.height || '0') || 0;
        winnerFxCtx.clearRect(0, 0, w, h);

        if (now < winnerFxUntil && Math.random() < 0.16) {
          spawnFireworkBurst(w * (0.16 + Math.random() * 0.68), h * (0.14 + Math.random() * 0.25));
        }

        for (let i = winnerFxParticles.length - 1; i >= 0; i--) {
          const p = winnerFxParticles[i];
          p.age += 1;
          p.x += p.vx;
          p.y += p.vy;
          p.vx *= 0.992;
          p.vy += 0.036;
          const t = 1 - (p.age / p.life);
          if (t <= 0) {
            winnerFxParticles.splice(i, 1);
            continue;
          }
          winnerFxCtx.globalAlpha = t;
          winnerFxCtx.fillStyle = p.color;
          winnerFxCtx.beginPath();
          winnerFxCtx.arc(p.x, p.y, p.size * (0.65 + 0.4 * t), 0, Math.PI * 2);
          winnerFxCtx.fill();
        }
        winnerFxCtx.globalAlpha = 1;

        if (now < winnerFxUntil || winnerFxParticles.length > 0) {
          winnerFxRaf = requestAnimationFrame(drawWinnerFireworksFrame);
        } else {
          stopWinnerFireworks();
        }
      }

      function playWinnerFireworks() {
        if (!winnerFxCtx || !els.winnerFxCanvas) return;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        stopWinnerFireworks();
        resizeWinnerFxCanvas();
        const w = parseFloat(els.winnerFxCanvas.style.width || '0') || 0;
        const h = parseFloat(els.winnerFxCanvas.style.height || '0') || 0;
        winnerFxParticles = [];
        spawnFireworkBurst(w * 0.25, h * 0.23);
        spawnFireworkBurst(w * 0.75, h * 0.24);
        spawnFireworkBurst(w * 0.5, h * 0.17);
        winnerFxUntil = performance.now() + 1400;
        winnerFxRaf = requestAnimationFrame(drawWinnerFireworksFrame);
      }

      function stopWinnerFireworks() {
        if (winnerFxRaf) {
          cancelAnimationFrame(winnerFxRaf);
          winnerFxRaf = 0;
        }
        winnerFxParticles = [];
        if (winnerFxCtx && els.winnerFxCanvas) {
          const w = parseFloat(els.winnerFxCanvas.style.width || '0') || 0;
          const h = parseFloat(els.winnerFxCanvas.style.height || '0') || 0;
          winnerFxCtx.clearRect(0, 0, w, h);
        }
      }

      async function toggleWheelFullscreen() {
        const target = els.wheelBox;
        if (!target) return;
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        try {
          if (fsEl === target) {
            if (document.exitFullscreen) {
              await document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
              document.webkitExitFullscreen();
            }
          } else {
            if (target.requestFullscreen) {
              await target.requestFullscreen();
            } else if (target.webkitRequestFullscreen) {
              target.webkitRequestFullscreen();
            }
          }
        } catch (e) {
          updateSelectedPanel('Fullscreen was blocked by the browser.', null, true);
        }
      }

      function syncFullscreenButton() {
        if (!els.fullscreenBtn || !els.wheelBox) return;
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        const active = fsEl === els.wheelBox;
        els.fullscreenBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
        els.fullscreenBtn.innerHTML = active
          ? '<i class="bi bi-fullscreen-exit me-1"></i> Exit full screen'
          : '<i class="bi bi-arrows-fullscreen me-1"></i> Full screen';
      }

      function renderCounts() {
        els.poolBadge.textContent = `${state.names.length} ${state.names.length === 1 ? 'name' : 'names'}`;
        els.countAvailable.textContent = String(state.names.length);
        els.countPicked.textContent = String(state.picks.length);
        els.countRemoved.textContent = String(state.removed.length);
      }

      function renderList() {
        els.list.innerHTML = '';
        if (!state.names.length) {
          els.list.innerHTML = '<div class="wn-empty">No names in the pool yet. Add names to start.</div>';
          return;
        }

        const frag = document.createDocumentFragment();
        state.names.forEach((name, index) => {
          const row = document.createElement('div');
          row.className = 'wn-item';

          const idx = document.createElement('span');
          idx.className = 'wn-item-index';
          idx.textContent = String(index + 1);

          const label = document.createElement('div');
          label.className = 'wn-item-name';
          label.textContent = name;
          label.title = name;

          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-sm btn-outline-danger';
          btn.innerHTML = '<i class="bi bi-x-lg"></i>';
          btn.setAttribute('aria-label', `Remove ${name}`);
          btn.addEventListener('click', () => {
            removeNameAt(index, 'manual');
            render();
            saveState();
          });

          row.append(idx, label, btn);
          frag.appendChild(row);
        });
        els.list.appendChild(frag);
      }

      function renderHistory() {
        els.history.innerHTML = '';
        if (!state.picks.length) {
          els.historyEmpty.hidden = false;
          return;
        }
        els.historyEmpty.hidden = true;
        const frag = document.createDocumentFragment();
        state.picks.forEach((name, i) => {
          const li = document.createElement('li');
          li.textContent = `${name}`;
          if (i === 0) li.style.fontWeight = '700';
          frag.appendChild(li);
        });
        els.history.appendChild(frag);
      }

      function setButtonsState() {
        const hasNames = state.names.length > 0;
        const hasWinnerInPool = !!(state.selectedName && state.names.includes(state.selectedName));
        els.spinBtn.disabled = !hasNames || state.spinning;
        if (els.centerSpinBtn) els.centerSpinBtn.disabled = !hasNames || state.spinning;
        els.spinBtn.innerHTML = state.spinning
          ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Spinning...'
          : '<i class="bi bi-play-fill me-1"></i> Spin';
        els.removeWinnerBtn.disabled = state.spinning || !hasWinnerInPool;
        els.keepWinnerBtn.disabled = state.spinning || !state.selectedName;
        els.undoRemoveBtn.disabled = state.spinning || state.removed.length === 0;
        els.shuffleBtn.disabled = state.spinning || state.names.length < 2;
        els.clearBtn.disabled = state.spinning || (state.names.length === 0 && state.removed.length === 0);
        els.resetRoundBtn.disabled = state.spinning || (state.picks.length === 0 && !state.selectedName);
      }

      function drawWheel() {
        const width = els.canvas.width;
        const height = els.canvas.height;
        const centerX = width / 2;
        const centerY = height / 2;
        const radius = Math.min(width, height) / 2 - 12;

        ctx.clearRect(0, 0, width, height);
        ctx.save();
        ctx.translate(centerX, centerY);
        ctx.rotate(state.wheelRotation);

        if (!state.names.length) {
          ctx.beginPath();
          ctx.arc(0, 0, radius, 0, Math.PI * 2);
          ctx.fillStyle = '#f8fafc';
          ctx.fill();
          ctx.lineWidth = 2;
          ctx.strokeStyle = 'rgba(15,23,42,.08)';
          ctx.stroke();
          ctx.restore();

          ctx.fillStyle = '#64748b';
          ctx.font = '700 22px Inter, sans-serif';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText('Add names to start', centerX, centerY);
          return;
        }

        const sliceAngle = (Math.PI * 2) / state.names.length;
        const wheelDensityFont = Math.max(14, Math.min(22, 220 / Math.max(1, state.names.length * 0.34)));

        for (let i = 0; i < state.names.length; i++) {
          const start = i * sliceAngle;
          const end = start + sliceAngle;
          const color = COLORS[i % COLORS.length];

          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, radius, start, end);
          ctx.closePath();
          ctx.fillStyle = color;
          ctx.fill();

          ctx.lineWidth = 2;
          ctx.strokeStyle = 'rgba(255,255,255,.92)';
          ctx.stroke();

          ctx.save();
          ctx.rotate(start + sliceAngle / 2);
          ctx.textAlign = 'right';
          ctx.textBaseline = 'middle';
          ctx.fillStyle = '#ffffff';
          ctx.shadowColor = 'rgba(15,23,42,.25)';
          ctx.shadowBlur = 2;

          const rawName = state.names[i];
          const charCount = Array.from(String(rawName)).length;
          let targetFontSize;
          if (charCount <= 5) {
            targetFontSize = wheelDensityFont + 4; // bigger
          } else if (charCount <= 7) {
            targetFontSize = wheelDensityFont + 2; // a bit smaller than bigger
          } else {
            targetFontSize = wheelDensityFont - 1; // smaller for long names
          }
          targetFontSize = Math.max(12, Math.min(28, targetFontSize));
          const fitted = fitText(rawName, Math.floor(radius * 0.68), targetFontSize);
          ctx.font = `700 ${fitted.fontSize}px Inter, sans-serif`;
          const label = fitted.text;
          ctx.fillText(label, radius - 18, 0);
          ctx.restore();
        }

        ctx.beginPath();
        ctx.arc(0, 0, radius * 0.12, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = 'rgba(15,23,42,.15)';
        ctx.stroke();

        ctx.restore();
      }

      function fitText(text, maxWidth, baseFontSize) {
        let out = String(text);
        let fontSize = baseFontSize;

        ctx.save();
        while (fontSize > 10) {
          ctx.font = `700 ${fontSize}px Inter, sans-serif`;
          if (ctx.measureText(out).width <= maxWidth) break;
          fontSize -= 1;
        }

        ctx.font = `700 ${fontSize}px Inter, sans-serif`;
        while (out.length > 3 && ctx.measureText(out).width > maxWidth) {
          out = out.slice(0, -1);
        }
        if (out !== text) {
          out = out.slice(0, Math.max(1, out.length - 1)) + '…';
        }
        ctx.restore();
        return { text: out, fontSize };
      }

      function render() {
        renderCounts();
        renderList();
        renderHistory();
        drawWheel();
        setButtonsState();

        if (state.selectedName) {
          els.selectedName.textContent = state.selectedName;
        } else if (!state.names.length) {
          els.selectedName.textContent = 'No names available';
          els.selectedMeta.textContent = 'Add names to build the wheel.';
          els.selectedMeta.style.color = '';
        }

        els.dedupeToggle.checked = state.dedupe;
        els.autoRemoveToggle.checked = state.autoRemove;
      }

      function wireEvents() {
        els.addBtn.addEventListener('click', () => {
          const parsed = parseNamesFromInput(els.namesInput.value);
          const { added, skipped } = addNames(parsed);
          els.namesInput.value = '';
          render();
          saveState();

          if (!parsed.length) {
            updateSelectedPanel('No valid names found in input.', null, true);
            return;
          }

          const parts = [];
          if (added > 0) parts.push(`${added} added`);
          if (skipped > 0) parts.push(`${skipped} skipped (duplicates)`);
          updateSelectedPanel(parts.join(' · ') || 'No names added.', null, added === 0);
        });

        els.namesInput.addEventListener('keydown', (e) => {
          if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            els.addBtn.click();
          }
        });

        els.shuffleBtn.addEventListener('click', () => {
          shuffleNames();
          render();
          saveState();
          updateSelectedPanel('List shuffled. Wheel sectors updated.', null);
        });

        els.clearBtn.addEventListener('click', () => {
          if (!confirm('Clear all names and removed names?')) return;
          clearAllNames();
          saveState();
        });

        els.undoRemoveBtn.addEventListener('click', () => {
          undoLastRemoval();
          saveState();
          updateSelectedPanel('Last removed name restored.', null);
        });

        els.spinBtn.addEventListener('click', spinWheel);
        if (els.centerSpinBtn) els.centerSpinBtn.addEventListener('click', spinWheel);
        if (els.fullscreenBtn) els.fullscreenBtn.addEventListener('click', toggleWheelFullscreen);
        document.addEventListener('fullscreenchange', syncFullscreenButton);
        document.addEventListener('webkitfullscreenchange', syncFullscreenButton);
        if (els.modalCloseBtn) els.modalCloseBtn.addEventListener('click', closeWinnerModal);
        if (els.modalKeepBtn) els.modalKeepBtn.addEventListener('click', closeWinnerModal);
        if (els.modalRemoveWinnerBtn) {
          els.modalRemoveWinnerBtn.addEventListener('click', () => {
            removeSelectedWinner();
            closeWinnerModal();
            saveState();
          });
        }
        if (els.modalSpinAgainBtn) {
          els.modalSpinAgainBtn.addEventListener('click', () => {
            closeWinnerModal();
            setTimeout(spinWheel, 140);
          });
        }
        if (els.winnerModalEl && typeof els.winnerModalEl.addEventListener === 'function') {
          els.winnerModalEl.addEventListener('close', stopWinnerFireworks);
          els.winnerModalEl.addEventListener('click', (e) => {
            const rect = els.winnerModalEl.getBoundingClientRect();
            const inDialog = (
              e.clientX >= rect.left &&
              e.clientX <= rect.right &&
              e.clientY >= rect.top &&
              e.clientY <= rect.bottom
            );
            if (!inDialog) closeWinnerModal();
          });
          els.winnerModalEl.addEventListener('cancel', (e) => {
            e.preventDefault();
            closeWinnerModal();
          });
        }

        els.removeWinnerBtn.addEventListener('click', () => {
          if (!removeSelectedWinner()) {
            updateSelectedPanel('No current picked name to remove.', null, true);
            return;
          }
          saveState();
          render();
        });

        els.keepWinnerBtn.addEventListener('click', () => {
          if (!state.selectedName) return;
          updateSelectedPanel('Picked name kept in pool.', state.selectedName);
          setButtonsState();
          saveState();
        });

        els.clearHistoryBtn.addEventListener('click', clearHistory);
        els.resetRoundBtn.addEventListener('click', () => {
          resetPicksOnly();
          saveState();
        });

        els.dedupeToggle.addEventListener('change', () => {
          state.dedupe = !!els.dedupeToggle.checked;
          saveState();
        });

        els.autoRemoveToggle.addEventListener('change', () => {
          state.autoRemove = !!els.autoRemoveToggle.checked;
          saveState();
        });

        window.addEventListener('resize', drawWheel, { passive: true });
        window.addEventListener('resize', () => {
          if (winnerModal && winnerModal.open) resizeWinnerFxCanvas();
        }, { passive: true });

        document.addEventListener('keydown', (e) => {
          if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
          if (e.code === 'Space') {
            e.preventDefault();
            spinWheel();
          }
        });
      }

      loadState();
      initWinnerModal();
      wireEvents();
      syncFullscreenButton();
      render();

      if (state.selectedName) {
        updateSelectedPanel('Last picked name restored from your browser session.', state.selectedName);
      } else {
        updateSelectedPanel('Press Space or click Spin to pick a random name.', null);
      }
    })();
  </script>
<?php require_once __DIR__ . '/../teachers/footer.php'; ?>
