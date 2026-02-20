<?php
// inc/tauth.php - Teacher auth facade.
// Use this in /teachers/* pages to keep teacher auth entry-points separate from admin pages.

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Teacher level labels:
 * 1 => Super Teacher
 * 2 => Medium Teacher
 * 3 => Teacher
 */
if (!defined('TAUTH_LEVEL_SUPER_TEACHER')) {
    define('TAUTH_LEVEL_SUPER_TEACHER', 1);
}
if (!defined('TAUTH_LEVEL_MEDIUM_TEACHER')) {
    define('TAUTH_LEVEL_MEDIUM_TEACHER', 2);
}
if (!defined('TAUTH_LEVEL_TEACHER')) {
    define('TAUTH_LEVEL_TEACHER', 3);
}

function teacher_level_normalize(?int $level = null): int
{
    $lvl = (int)($level ?? teacher_level());
    return in_array($lvl, [TAUTH_LEVEL_SUPER_TEACHER, TAUTH_LEVEL_MEDIUM_TEACHER, TAUTH_LEVEL_TEACHER], true)
        ? $lvl
        : TAUTH_LEVEL_TEACHER;
}

function teacher_level_label(?int $level = null): string
{
    $lvl = teacher_level_normalize($level);
    return match ((int)$lvl) {
        TAUTH_LEVEL_SUPER_TEACHER => 'Super Teacher',
        TAUTH_LEVEL_MEDIUM_TEACHER => 'Medium Teacher',
        TAUTH_LEVEL_TEACHER => 'Teacher',
        default               => 'Teacher',
    };
}

/**
 * Whether current teacher is read-only (level 3).
 */
function teacher_is_read_only(?int $level = null): bool
{
    return teacher_level_normalize($level) === TAUTH_LEVEL_TEACHER;
}
