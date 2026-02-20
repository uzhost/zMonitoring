<?php
// teachers/logout.php - Teacher logout (CSRF protected, safe redirects)
// Drop-in fix: GET shows confirmation; POST performs logout; optional tokenized GET supported.

declare(strict_types=1);

require_once __DIR__ . '/../inc/tauth.php';

session_start_secure();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function safe_next_path(string $candidate, string $fallback = '/teachers/login.php'): string
{
    $candidate = trim($candidate);
    if ($candidate === '') return $fallback;

    $parts = parse_url($candidate);
    $path  = $parts['path']  ?? '';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

    if ($path === '' || $path[0] !== '/') return $fallback;

    // Avoid redirect loops back into logout
    if (preg_match('#^/teachers/logout\.php(?:$|[/?#])#', $path)) {
        return $fallback;
    }

    return $path . $query;
}

function method_not_allowed(array $allowed): void
{
    http_response_code(405);
    header('Allow: ' . implode(', ', $allowed));
    echo 'Method Not Allowed.';
    exit;
}

function html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Default redirect target after logout (or if already logged out)
$next = safe_next_path((string)($_GET['next'] ?? ''), '/teachers/login.php');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Preferred: POST logout with CSRF
if ($method === 'POST') {
    // Your auth.php verify_csrf('csrf') should validate and exit on failure.
    verify_csrf('csrf');

    teacher_logout_session();

    // If logout cleared session fully, ensure a new CSRF token exists for next page load.
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    header('Location: ' . $next);
    exit;
}

// GET/HEAD: optionally accept tokenized GET, otherwise show confirmation form
if ($method === 'GET' || $method === 'HEAD') {
    // Accept multiple param names to avoid brittle link mismatches
    $csrf = (string)($_GET['csrf'] ?? $_GET['token'] ?? $_GET['csrf_token'] ?? '');

    $sess = (string)($_SESSION['csrf_token'] ?? '');

    // If a valid token is present, allow one-click logout (still CSRF-protected)
    if ($csrf !== '' && $sess !== '' && hash_equals($sess, $csrf)) {
        teacher_logout_session();

        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        header('Location: ' . $next);
        exit;
    }

    // Otherwise: show a confirmation page that logs out via POST (best practice).
    // This avoids "Invalid logout request" when the token mismatches or is missing.
    $token = csrf_token(); // your helper should generate/reuse session token

    header('Content-Type: text/html; charset=utf-8');

    // Minimal, dependency-free HTML to avoid relying on teacher header/footer for logout.
    // If you prefer to use teacher/header.php, tell me and IРІР‚в„ўll align it.
    $nextHidden = html($next);
    $tokenHidden = html($token);

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Logout</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f6f7f9;margin:0;padding:24px;}
      .card{max-width:520px;margin:8vh auto;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.06);overflow:hidden;}
      .hd{padding:18px 18px 0;}
      .bd{padding:18px;}
      .ft{padding:0 18px 18px;}
      .btn{display:inline-block;border:0;border-radius:10px;padding:10px 14px;font-weight:600;cursor:pointer;}
      .btn-primary{background:#0d6efd;color:#fff;}
      .btn-secondary{background:#e9ecef;color:#111;text-decoration:none;}
      .row{display:flex;gap:10px;flex-wrap:wrap;}
      .muted{color:#6c757d;font-size:14px;margin-top:8px;}
    </style></head><body>';

    echo '<div class="card">';
    echo '<div class="hd"><h2 style="margin:0">Confirm logout</h2><div class="muted">For security, please confirm to sign out.</div></div>';
    echo '<div class="bd">';
    echo '<form method="post" action="/teachers/logout.php?next=' . rawurlencode($next) . '">';
    echo '<input type="hidden" name="csrf" value="' . $tokenHidden . '">';
    echo '<div class="row">';
    echo '<button class="btn btn-primary" type="submit">Logout</button>';
    echo '<a class="btn btn-secondary" href="' . $nextHidden . '">Cancel</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '<div class="ft"><div class="muted">If you did not intend to log out, click Cancel.</div></div>';
    echo '</div>';

    echo '</body></html>';
    exit;
}

method_not_allowed(['GET', 'HEAD', 'POST']);
