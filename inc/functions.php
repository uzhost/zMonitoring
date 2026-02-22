<?php
// inc/functions.php - shared utility helpers used by report pages.

declare(strict_types=1);

if (!function_exists('format_decimal_1')) {
    function format_decimal_1(mixed $n, bool $trimTrailingZero = false): string
    {
        if ($n === null || $n === '') return '—';
        $v = (float)$n;
        if ($trimTrailingZero && abs($v - round($v)) < 0.00001) {
            return (string)(int)round($v);
        }
        return number_format($v, 1, '.', '');
    }
}

if (!function_exists('format_decimal_2')) {
    function format_decimal_2(mixed $n): string
    {
        if ($n === null || $n === '') return '—';
        return number_format((float)$n, 2, '.', '');
    }
}

if (!function_exists('format_percent_1')) {
    function format_percent_1(mixed $n): string
    {
        if ($n === null || $n === '') return '—';
        return number_format((float)$n, 1, '.', '') . '%';
    }
}

if (!function_exists('stats_median')) {
    function stats_median(array $values): ?float
    {
        if (!$values) return null;
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) return (float)$values[$mid];
        return ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
    }
}

if (!function_exists('stats_stddev_sample')) {
    function stats_stddev_sample(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) return null;
        $sum = 0.0;
        foreach ($values as $v) $sum += (float)$v;
        $mean = $sum / $n;
        $acc = 0.0;
        foreach ($values as $v) {
            $d = ((float)$v - $mean);
            $acc += ($d * $d);
        }
        return sqrt($acc / ($n - 1));
    }
}

if (!function_exists('describe_skew_direction')) {
    function describe_skew_direction(?float $skew): string
    {
        if ($skew === null) return 'No spread';
        if ($skew <= -0.35) return 'Failure tail';
        if ($skew >= 0.35) return 'Top-heavy tail';
        return 'Balanced';
    }
}

if (!function_exists('badge_middle_state')) {
    function badge_middle_state(?float $lowerShare, ?float $middleShare): array
    {
        if ($lowerShare === null || $middleShare === null) {
            return ['text-bg-secondary', 'No data'];
        }
        $collapseIndex = $lowerShare - $middleShare;
        if ($collapseIndex >= 15.0) return ['text-bg-danger', 'Middle collapse'];
        if ($collapseIndex <= -10.0) return ['text-bg-success', 'Middle lift'];
        return ['text-bg-primary', 'Middle balanced'];
    }
}

if (!function_exists('badge_distribution_risk')) {
    function badge_distribution_risk(?float $weakShare, ?float $eliteShare, ?float $skew): array
    {
        if ($weakShare === null) return ['text-bg-secondary', 'No data'];
        if ($weakShare >= 35.0 || ($skew !== null && $skew <= -0.45)) return ['text-bg-danger', 'High risk'];
        if ($weakShare >= 20.0 || ($skew !== null && $skew <= -0.20)) return ['text-bg-warning text-dark', 'Watch'];
        if ($eliteShare !== null && $eliteShare >= 20.0 && $weakShare <= 12.0) return ['text-bg-success', 'Strong'];
        return ['text-bg-primary', 'Stable'];
    }
}

if (!function_exists('badge_delta')) {
    function badge_delta(?float $delta, bool $compactText = false): array
    {
        if ($delta === null) {
            $cls = 'text-bg-light text-dark';
            $ic = 'bi-dash-lg';
            $txt = '—';
        } elseif (abs($delta) < 0.0001) {
            $cls = 'text-bg-secondary';
            $ic = 'bi-dash-lg';
            $txt = $compactText ? '0' : '0.0';
        } elseif ($delta > 0) {
            $cls = 'text-bg-success';
            $ic = 'bi-arrow-up';
            $txt = '+' . format_decimal_1($delta, $compactText);
        } else {
            $cls = 'text-bg-danger';
            $ic = 'bi-arrow-down';
            $txt = format_decimal_1($delta, $compactText);
        }

        // Return both tuple and associative keys for compatibility across pages.
        return [
            0 => $cls,
            1 => $ic,
            2 => $txt,
            'cls' => $cls,
            'ic' => $ic,
            'txt' => $txt,
        ];
    }
}

if (!function_exists('badge_score_by_threshold')) {
    function badge_score_by_threshold(
        ?float $avg,
        float $pass,
        float $good,
        float $excellent,
        bool $withContrastText = false
    ): string
    {
        if ($avg === null) return 'text-bg-secondary';
        if ($avg >= $excellent) return 'text-bg-success';
        if ($avg >= $good) return $withContrastText ? 'text-bg-primary text-white' : 'text-bg-primary';
        if ($avg >= $pass) return $withContrastText ? 'text-bg-warning text-dark' : 'text-bg-warning';
        return 'text-bg-danger';
    }
}

if (!function_exists('resolve_class_grade')) {
    function resolve_class_grade(PDO $pdo, string $classCode): ?string
    {
        $s = trim($classCode);
        if ($s === '') return null;

        static $stByCode = null;
        if (!$stByCode instanceof PDOStatement) {
            $stByCode = $pdo->prepare(
                "SELECT grade
                 FROM classes
                 WHERE grade IS NOT NULL
                   AND (
                        class_code = ?
                        OR TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(class_code, '–', '-'), '—', '-'), ' - ', '-'), ' -', '-'), '- ', '-'), '  ', ' ')) = ?
                   )
                 LIMIT 1"
            );
        }

        $normalized = trim(str_replace(['–', '—'], '-', $s));
        $normalized = preg_replace('/\s*-\s*/u', '-', $normalized) ?? $normalized;

        $stByCode->execute([$s, $normalized]);
        $dbGrade = $stByCode->fetchColumn();
        if ($dbGrade !== false && $dbGrade !== null && $dbGrade !== '') {
            return (string)(int)$dbGrade;
        }

        // Fallback for legacy/missing classes rows.
        if (preg_match('/^\s*(\d{1,2})\s*(?:[-–—]\s*.*)?$/u', $s, $m)) {
            return $m[1];
        }
        return null;
    }
}

if (!function_exists('clamp_int')) {
    function clamp_int(?string $v, int $min = 0, int $max = 999): int
    {
        $n = (int)($v ?? '');
        if ($n < $min) return $min;
        if ($n > $max) return $max;
        return $n;
    }
}

if (!function_exists('build_sort_link_html')) {
    function build_sort_link_html(string $label, string $key, string $currentSort, string $currentDir, array $qBase): string
    {
        $dir = 'asc';
        if ($currentSort === $key && $currentDir === 'asc') {
            $dir = 'desc';
        }
        $q = $qBase + ['sort' => $key, 'dir' => $dir];
        $href = '?' . http_build_query($q);
        $ic = '';
        if ($currentSort === $key) {
            $ic = $currentDir === 'asc'
                ? ' <i class="bi bi-sort-up"></i>'
                : ' <i class="bi bi-sort-down"></i>';
        }
        return '<a class="link-dark text-decoration-none" href="' . h($href) . '">' . h($label) . $ic . '</a>';
    }
}

// Backward-compatible aliases (thin wrappers) so legacy pages keep working during migration.
if (!function_exists('fmt1')) {
    function fmt1(mixed $n, bool $trimTrailingZero = false): string { return format_decimal_1($n, $trimTrailingZero); }
}
if (!function_exists('fmt2')) {
    function fmt2(mixed $n): string { return format_decimal_2($n); }
}
if (!function_exists('fmtPct')) {
    function fmtPct(mixed $n): string { return format_percent_1($n); }
}
if (!function_exists('median')) {
    function median(array $values): ?float { return stats_median($values); }
}
if (!function_exists('stddev_samp')) {
    function stddev_samp(array $values): ?float { return stats_stddev_sample($values); }
}
if (!function_exists('skew_direction')) {
    function skew_direction(?float $skew): string { return describe_skew_direction($skew); }
}
if (!function_exists('middle_state')) {
    function middle_state(?float $lowerShare, ?float $middleShare): array { return badge_middle_state($lowerShare, $middleShare); }
}
if (!function_exists('distribution_risk_badge')) {
    function distribution_risk_badge(?float $weakShare, ?float $eliteShare, ?float $skew): array { return badge_distribution_risk($weakShare, $eliteShare, $skew); }
}
if (!function_exists('delta_badge')) {
    function delta_badge(?float $delta, bool $compactText = false): array { return badge_delta($delta, $compactText); }
}
if (!function_exists('score_badge_class')) {
    function score_badge_class(?float $avg, float $pass, float $good, float $excellent, bool $withContrastText = false): string
    {
        return badge_score_by_threshold($avg, $pass, $good, $excellent, $withContrastText);
    }
}
if (!function_exists('extract_grade')) {
    function extract_grade(PDO $pdo, string $classCode): ?string { return resolve_class_grade($pdo, $classCode); }
}
if (!function_exists('safe_int')) {
    function safe_int(?string $v, int $min = 0, int $max = 999): int { return clamp_int($v, $min, $max); }
}
if (!function_exists('sort_link_html')) {
    function sort_link_html(string $label, string $key, string $currentSort, string $currentDir, array $qBase): string
    {
        return build_sort_link_html($label, $key, $currentSort, $currentDir, $qBase);
    }
}
