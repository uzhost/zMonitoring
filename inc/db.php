<?php
// inc/db.php — PDO connection (PHP 8.3+ / MySQL 8.0+), hardened defaults + optional TLS + safe helpers

declare(strict_types=1);

/**
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   // $pdo is available
 *
 * Environment variables supported:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   DB_CHARSET (default utf8mb4)
 *   DB_TZ (default +00:00)
 *   DB_SQL_MODE (optional override)
 *   DB_SSL_CA (path), DB_SSL_VERIFY (0/1), DB_SSL_CIPHER (optional)
 *   APP_ENV (production|staging|development)
 *
 * Notes:
 * - You previously used 127.0.0.1:3311. Set DB_PORT=3311 (or edit below).
 * - This file intentionally does NOT echo sensitive error details.
 */

if (!class_exists('PDO')) {
    http_response_code(500);
    echo 'Server misconfiguration.';
    exit;
}

// ---- Env / defaults ---------------------------------------------------------

$APP_ENV   = getenv('APP_ENV') ?: 'production';
$IS_DEV    = in_array(strtolower($APP_ENV), ['dev', 'development', 'local'], true);

$DB_HOST   = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT   = getenv('DB_PORT') ?: '3306';
$DB_NAME   = getenv('DB_NAME') ?: 'db_name';
$DB_USER   = getenv('DB_USER') ?: 'db_user';
$DB_PASS   = getenv('DB_PASS') ?: 'db_password';

$DB_CHARSET = getenv('DB_CHARSET') ?: 'utf8mb4';
$DB_TZ      = getenv('DB_TZ') ?: '+00:00';

// Basic validation to avoid odd DSN injection/misconfig
if (!preg_match('/^\d{2,5}$/', (string)$DB_PORT)) {
    http_response_code(500);
    echo 'Server misconfiguration.';
    exit;
}
if ($DB_NAME === '' || preg_match('/[^\w$]/', $DB_NAME)) {
    // allow a conservative charset for dbname (letters/digits/_/$)
    http_response_code(500);
    echo 'Server misconfiguration.';
    exit;
}

// Build DSN safely (do not include user/pass)
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $DB_HOST,
    $DB_PORT,
    $DB_NAME,
    $DB_CHARSET
);

// ---- PDO options ------------------------------------------------------------

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // native prepares
    PDO::ATTR_STRINGIFY_FETCHES  => false, // keep ints as ints where possible
];

// Optional: enforce TLS if you provide DB_SSL_CA
// Set DB_SSL_VERIFY=1 to verify server cert when available.
$sslCa = getenv('DB_SSL_CA') ?: '';
if ($sslCa !== '') {
    // These constants exist for mysqlnd; if not, PDO will ignore unknown options.
    if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }
    $sslVerify = getenv('DB_SSL_VERIFY');
    if ($sslVerify !== false && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = ((string)$sslVerify === '1');
    }
    $sslCipher = getenv('DB_SSL_CIPHER') ?: '';
    if ($sslCipher !== '' && defined('PDO::MYSQL_ATTR_SSL_CIPHER')) {
        $options[PDO::MYSQL_ATTR_SSL_CIPHER] = $sslCipher;
    }
}

// ---- Connect ----------------------------------------------------------------

try {
    /** @var PDO $pdo */
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

    // Session-level settings (stable analytics + consistent results)
    // 1) time zone
    $pdo->exec("SET time_zone = " . $pdo->quote($DB_TZ));

    // 2) SQL mode: strict by default (recommended for data integrity)
    // You can override by setting DB_SQL_MODE explicitly.
    $sqlMode = getenv('DB_SQL_MODE');
    if ($sqlMode === false || $sqlMode === '') {
        // Strict, predictable; avoid silent truncation.
        // NOTE: MySQL 8 defaults may already include some of these; setting is harmless.
        $sqlMode = "STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";
    }
    $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($sqlMode));

    // 3) Ensure InnoDB durability expectations (optional; safe to omit if managed)
    // $pdo->exec("SET SESSION innodb_strict_mode = 1");

} catch (PDOException $e) {
    // Do not leak credentials/DSN details.
    // In dev, you might want logging; keep output generic regardless.
    http_response_code(500);

    // Minimal error logging (safe): message only, no DSN/credentials.
    // Ensure your PHP error log is not public.
    error_log('[DB] Connection failed: ' . $e->getMessage());

    echo 'Database connection error.';
    exit;
}

// ---- Optional tiny helpers (safe, no side effects) --------------------------
// These are optional; existing code using only $pdo will continue working.

if (!function_exists('db_begin')) {
    function db_begin(PDO $pdo): void { $pdo->beginTransaction(); }
}
if (!function_exists('db_commit')) {
    function db_commit(PDO $pdo): void { $pdo->commit(); }
}
if (!function_exists('db_rollback')) {
    function db_rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/**
 * Run a callback inside a transaction.
 * Rolls back on any Throwable; rethrows.
 */
if (!function_exists('db_tx')) {
    function db_tx(PDO $pdo, callable $fn)
    {
        $pdo->beginTransaction();
        try {
            $res = $fn($pdo);
            $pdo->commit();
            return $res;
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }
}
