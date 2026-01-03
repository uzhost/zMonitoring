<?php
// inc/db.php — PDO connection (PHP 8.3+ / MySQL 8.0+), hardened defaults

declare(strict_types=1);

/**
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   // $pdo is available
 *
 * Recommended: define these as env vars (preferred) or edit below.
 */

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'db_name';
$DB_USER = getenv('DB_USER') ?: 'db_user';
$DB_PASS = getenv('DB_PASS') ?: 'db_password';

/**
 * If your server uses a non-standard port (you previously used 127.0.0.1:3311),
 * set DB_PORT=3311 or edit $DB_PORT above.
 */

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $DB_HOST,
    $DB_PORT,
    $DB_NAME
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // use native prepares
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'", // optional; keep consistent
];

try {
    /** @var PDO $pdo */
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // Do not leak credentials/DSN details.
    http_response_code(500);
    echo 'Database connection error.';
    exit;
}
