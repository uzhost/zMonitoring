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
function teacher_level_label(?int $level = null): string
{
    $lvl = $level ?? teacher_level();
    return match ((int)$lvl) {
        AUTH_LEVEL_SUPERADMIN => 'Super Teacher',
        AUTH_LEVEL_ADMIN      => 'Medium Teacher',
        AUTH_LEVEL_TEACHER    => 'Teacher',
        default               => 'Teacher',
    };
}

/**
 * Whether current teacher is read-only (level 3).
 */
function teacher_is_read_only(?int $level = null): bool
{
    $lvl = $level ?? teacher_level();
    return (int)$lvl === AUTH_LEVEL_TEACHER;
}

