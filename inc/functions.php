<?php
// inc/functions.php - shared utility helpers used by report pages.

declare(strict_types=1);

if (!function_exists('fmt1')) {
    function fmt1(mixed $n): string
    {
        if ($n === null || $n === '') return '—';
        return number_format((float)$n, 1, '.', '');
    }
}

if (!function_exists('fmt2')) {
    function fmt2(mixed $n): string
    {
        if ($n === null || $n === '') return '—';
        return number_format((float)$n, 2, '.', '');
    }
}

if (!function_exists('fmtPct')) {
    function fmtPct(mixed $n): string
    {
        if ($n === null || $n === '') return '—';
        return number_format((float)$n, 1, '.', '') . '%';
    }
}

if (!function_exists('median')) {
    function median(array $values): ?float
    {
        if (!$values) return null;
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) return (float)$values[$mid];
        return ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
    }
}

if (!function_exists('stddev_samp')) {
    function stddev_samp(array $values): ?float
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

if (!function_exists('skew_direction')) {
    function skew_direction(?float $skew): string
    {
        if ($skew === null) return 'No spread';
        if ($skew <= -0.35) return 'Failure tail';
        if ($skew >= 0.35) return 'Top-heavy tail';
        return 'Balanced';
    }
}

if (!function_exists('middle_state')) {
    function middle_state(?float $lowerShare, ?float $middleShare): array
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

if (!function_exists('distribution_risk_badge')) {
    function distribution_risk_badge(?float $weakShare, ?float $eliteShare, ?float $skew): array
    {
        if ($weakShare === null) return ['text-bg-secondary', 'No data'];
        if ($weakShare >= 35.0 || ($skew !== null && $skew <= -0.45)) return ['text-bg-danger', 'High risk'];
        if ($weakShare >= 20.0 || ($skew !== null && $skew <= -0.20)) return ['text-bg-warning text-dark', 'Watch'];
        if ($eliteShare !== null && $eliteShare >= 20.0 && $weakShare <= 12.0) return ['text-bg-success', 'Strong'];
        return ['text-bg-primary', 'Stable'];
    }
}

if (!function_exists('delta_badge')) {
    function delta_badge(?float $delta): array
    {
        if ($delta === null) return ['text-bg-light text-dark', 'bi-dash-lg', '—'];
        if (abs($delta) < 0.0001) return ['text-bg-secondary', 'bi-dash-lg', '0.0'];
        if ($delta > 0) return ['text-bg-success', 'bi-arrow-up', '+' . fmt1($delta)];
        return ['text-bg-danger', 'bi-arrow-down', fmt1($delta)];
    }
}

if (!function_exists('score_badge_class')) {
    function score_badge_class(?float $avg, float $pass, float $good, float $excellent): string
    {
        if ($avg === null) return 'text-bg-secondary';
        if ($avg >= $excellent) return 'text-bg-success';
        if ($avg >= $good) return 'text-bg-primary';
        if ($avg >= $pass) return 'text-bg-warning';
        return 'text-bg-danger';
    }
}

if (!function_exists('extract_grade')) {
    function extract_grade(PDO $pdo, string $classCode): ?string
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
