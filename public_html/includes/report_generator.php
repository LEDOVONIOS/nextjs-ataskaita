<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_data.php';
require_once __DIR__ . '/ga4_client.php';
require_once __DIR__ . '/ga4_queries.php';
require_once __DIR__ . '/ga4_requirements.php';

function mock_visitors_overview_to_phase2(int $projectId, int $year, int $month, array $mockVisitors): array
{
    $users = (int)($mockVisitors['users'] ?? 0);
    $sessions = (int)($mockVisitors['sessions'] ?? 0);

    $start = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth = (int)date('t', strtotime($start));

    $seed = mock_seed_for_period($projectId, $year, $month) ^ 0x5a4f5a4f;
    $rng = new DeterministicRng($seed);

    $remainingUsers = $users;
    $remainingSessions = $sessions;
    $daily = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $isLast = ($d === $daysInMonth);
        if ($isLast) {
            $u = $remainingUsers;
            $s = $remainingSessions;
        } else {
            $avgU = $users / max(1, $daysInMonth);
            $avgS = $sessions / max(1, $daysInMonth);
            $u = (int)max(0, round($avgU * $rng->float(0.65, 1.35)));
            $s = (int)max(0, round($avgS * $rng->float(0.65, 1.35)));
            $u = min($u, $remainingUsers);
            $s = min($s, $remainingSessions);
            $remainingUsers -= $u;
            $remainingSessions -= $s;
        }
        $daily[] = ['date' => $date, 'users' => $u, 'sessions' => $s];
    }

    return [
        'totals' => [
            'users' => $users,
            'new_users' => (int)($mockVisitors['new_users'] ?? 0),
            'sessions' => $sessions,
            'engagement_rate' => (float)($mockVisitors['engagement_rate'] ?? 0.0),
            // Phase 1 mock had avg engagement; keep it, but also map to session duration for Phase 2 UI.
            'avg_engagement_time_sec' => (int)($mockVisitors['avg_engagement_time_sec'] ?? 0),
            'avg_session_duration_sec' => (int)($mockVisitors['avg_engagement_time_sec'] ?? 0),
            'pages_per_session' => null,
        ],
        'daily' => $daily,
    ];
}

function pct_change(?float $current, ?float $previous): ?float
{
    if ($current === null || $previous === null) {
        return null;
    }
    if ($previous == 0.0) {
        return null;
    }
    return ($current - $previous) / $previous;
}

function phase3_safe_float(mixed $v, float $fallback = 0.0): float
{
    if ($v === null || $v === '') {
        return $fallback;
    }
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    if (is_string($v) && is_numeric($v)) {
        return (float)$v;
    }
    return $fallback;
}

function phase3_safe_int(mixed $v, int $fallback = 0): int
{
    if ($v === null || $v === '') {
        return $fallback;
    }
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return (int)round($v);
    }
    if (is_string($v) && is_numeric($v)) {
        return (int)round((float)$v);
    }
    return $fallback;
}

function phase3_month_date_ranges_utc(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $days = (int)date('t', strtotime($start));
    $end = sprintf('%04d-%02d-%02d', $year, $month, $days);
    $lastStart = sprintf('%04d-%02d-01', $year - 1, $month);
    $lastDays = (int)date('t', strtotime($lastStart));
    $lastEnd = sprintf('%04d-%02d-%02d', $year - 1, $month, $lastDays);
    return [$start, $end, $lastStart, $lastEnd];
}

/**
 * Build a simple day-of-month series (counts or rates), deterministic per period + preset + tab.
 * Returns: ['labels'=>[...], 'this'=>[...], 'last'=>[...]]
 */
function phase3_build_timeseries(
    int $projectId,
    int $year,
    int $month,
    string $presetKey,
    string $tabKey,
    float $thisValue,
    float $lastValue
): array {
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $days = (int)date('t', strtotime($monthStart));

    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|' . $presetKey . '|' . $tabKey)) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p3|' . $presetKey . '|' . $tabKey)) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $labels = [];
    $a = [];
    $b = [];

    $isRate = ($thisValue >= 0.0 && $thisValue <= 1.0 && $lastValue >= 0.0 && $lastValue <= 1.0);
    $remainingThis = $thisValue;
    $remainingLast = $lastValue;

    for ($d = 1; $d <= $days; $d++) {
        $labels[] = (string)$d;
        $isLast = ($d === $days);
        $season = 0.85 + 0.3 * sin(($d / max(1, $days)) * pi() * 2);
        if ($isRate) {
            $nThis = max(0.0, min(1.0, $thisValue * $season * $rngThis->float(0.85, 1.15)));
            $nLast = max(0.0, min(1.0, $lastValue * $season * $rngLast->float(0.85, 1.15)));
        } else {
            $nThis = $isLast ? $remainingThis : max(0.0, ($thisValue / max(1, $days)) * $season * $rngThis->float(0.7, 1.3));
            $nLast = $isLast ? $remainingLast : max(0.0, ($lastValue / max(1, $days)) * $season * $rngLast->float(0.7, 1.3));
        }

        $a[] = $nThis;
        $b[] = $nLast;

        if (!$isRate) {
            $remainingThis -= $nThis;
            $remainingLast -= $nLast;
        }
    }

    return [
        'labels' => $labels,
        'this' => $a,
        'last' => $b,
    ];
}

function phase3_metric(string $label, mixed $currentMonth, mixed $last, string $format, string $unit = ''): array
{
    return [
        'label' => $label,
        'this' => $currentMonth,
        'last' => $last,
        'format' => $format, // int | pct | float1 | seconds | money
        'unit' => $unit,
    ];
}

function phase3_preset_multiplier(int $projectId, int $year, int $month, string $presetKey): float
{
    if ($presetKey === 'all') {
        return 1.0;
    }
    // Phase 3.6: alias UI traffic keys to legacy preset keys.
    $presetKey = match ($presetKey) {
        'seo' => 'organic_search',
        'ppc' => 'paid_search',
        'social_organic' => 'social',
        'social_paid' => 'social',
        default => $presetKey,
    };
    $seed = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3mul|' . $presetKey)) & 0xFFFFFFFF;
    $rng = new DeterministicRng((int)$seed);
    return match ($presetKey) {
        'organic_search' => $rng->float(0.28, 0.58),
        'paid_search' => $rng->float(0.06, 0.25),
        'direct' => $rng->float(0.10, 0.28),
        'display' => $rng->float(0.01, 0.08),
        'social' => $rng->float(0.03, 0.14),
        'referral' => $rng->float(0.03, 0.12),
        'email' => $rng->float(0.01, 0.06),
        'affiliate' => $rng->float(0.00, 0.05),
        default => $rng->float(0.05, 0.20),
    };
}

function phase3_build_tab_visits(
    int $projectId,
    int $year,
    int $month,
    string $presetKey,
    array $thisMonth,
    array $lastYear
): array {
    $mulThis = phase3_preset_multiplier($projectId, $year, $month, $presetKey);
    $mulLast = phase3_preset_multiplier($projectId, $year - 1, $month, $presetKey);

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);

    $usersThis = (int)round(phase3_safe_int($vThis['users'] ?? 0, 0) * $mulThis);
    $usersLast = (int)round(phase3_safe_int($vLast['users'] ?? 0, max(0, (int)round($usersThis / 1.12))) * $mulLast);
    $newThis = (int)round(phase3_safe_int($vThis['new_users'] ?? 0, 0) * $mulThis * 0.95);
    $newLast = (int)round(phase3_safe_int($vLast['new_users'] ?? 0, max(0, (int)round($newThis / 1.12))) * $mulLast * 0.95);
    $sessThis = (int)round(phase3_safe_int($vThis['sessions'] ?? 0, 0) * $mulThis * 1.02);
    $sessLast = (int)round(phase3_safe_int($vLast['sessions'] ?? 0, max(0, (int)round($sessThis / 1.12))) * $mulLast * 1.02);

    return [
        'timeseries' => phase3_build_timeseries($projectId, $year, $month, $presetKey, 'visits', (float)$usersThis, (float)$usersLast),
        'totals' => [
            'users' => phase3_metric('Users', $usersThis, $usersLast, 'int'),
            'new_users' => phase3_metric('New Users', $newThis, $newLast, 'int'),
            'sessions' => phase3_metric('Sessions', $sessThis, $sessLast, 'int'),
        ],
    ];
}

function phase3_build_tab_behavior(
    int $projectId,
    int $year,
    int $month,
    string $presetKey,
    array $thisMonth,
    array $lastYear
): array {
    $mulThis = phase3_preset_multiplier($projectId, $year, $month, $presetKey);
    $mulLast = phase3_preset_multiplier($projectId, $year - 1, $month, $presetKey);

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);
    $bThis = (array)($thisMonth['visitor_behavior'] ?? []);
    $bLast = (array)($lastYear['visitor_behavior'] ?? []);

    $engThis = phase3_safe_float($vThis['engagement_rate'] ?? 0.0, 0.0);
    $engLast = phase3_safe_float($vLast['engagement_rate'] ?? 0.0, max(0.0, min(1.0, $engThis - 0.04)));

    // Mild preset adjustments (kept deterministic via multipliers).
    if ($presetKey === 'organic_search') {
        $engThis = min(1.0, $engThis + 0.03);
        $engLast = min(1.0, $engLast + 0.02);
    } elseif ($presetKey === 'paid_search') {
        $engThis = max(0.0, $engThis - 0.02);
        $engLast = max(0.0, $engLast - 0.02);
    }

    $ppsThis = phase3_safe_float($bThis['pages_per_session'] ?? 0.0, 2.1) * (0.92 + 0.16 * $mulThis);
    $ppsLast = phase3_safe_float($bLast['pages_per_session'] ?? 0.0, 2.0) * (0.92 + 0.16 * $mulLast);

    $durThis = (int)round(phase3_safe_int($bThis['avg_session_duration_sec'] ?? 0, 120) * (0.90 + 0.18 * $mulThis));
    $durLast = (int)round(phase3_safe_int($bLast['avg_session_duration_sec'] ?? 0, 118) * (0.90 + 0.18 * $mulLast));

    return [
        'timeseries' => phase3_build_timeseries($projectId, $year, $month, $presetKey, 'behavior', $engThis, $engLast),
        'totals' => [
            'engagement_rate' => phase3_metric('Engagement rate', round($engThis, 4), round($engLast, 4), 'pct', '%'),
            'pages_per_session' => phase3_metric('Pages / session', round($ppsThis, 2), round($ppsLast, 2), 'float1'),
            'avg_session_duration_sec' => phase3_metric('Avg session duration', $durThis, $durLast, 'seconds', 's'),
        ],
    ];
}

function phase3_build_tab_sales(
    int $projectId,
    int $year,
    int $month,
    string $presetKey,
    array $thisMonth,
    array $lastYear
): array {
    $mulThis = phase3_preset_multiplier($projectId, $year, $month, $presetKey);
    $mulLast = phase3_preset_multiplier($projectId, $year - 1, $month, $presetKey);

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);
    $sThis = is_array($thisMonth['sales'] ?? null) ? (array)$thisMonth['sales'] : [];
    $sLast = is_array($lastYear['sales'] ?? null) ? (array)$lastYear['sales'] : [];

    $sessionsThis = (int)round(phase3_safe_int($vThis['sessions'] ?? 0, 0) * $mulThis);
    $sessionsLast = (int)round(phase3_safe_int($vLast['sessions'] ?? 0, max(0, (int)round($sessionsThis / 1.12))) * $mulLast);

    $purchThis = (int)round(phase3_safe_int($sThis['transactions'] ?? 0, 0) * $mulThis);
    $purchLast = (int)round(phase3_safe_int($sLast['transactions'] ?? 0, max(0, (int)round($purchThis / 1.10))) * $mulLast);

    $revThis = (float)phase3_safe_float($sThis['revenue'] ?? 0.0, 0.0) * $mulThis;
    $revLast = (float)phase3_safe_float($sLast['revenue'] ?? 0.0, max(0.0, $revThis / 1.10)) * $mulLast;

    $crThis = $sessionsThis > 0 ? ($purchThis / $sessionsThis) : 0.0;
    $crLast = $sessionsLast > 0 ? ($purchLast / $sessionsLast) : 0.0;

    return [
        'timeseries' => phase3_build_timeseries($projectId, $year, $month, $presetKey, 'sales', $revThis, $revLast),
        'totals' => [
            'conversion_rate' => phase3_metric('Conversion rate', round($crThis, 4), round($crLast, 4), 'pct', '%'),
            'purchases' => phase3_metric('Purchases', $purchThis, $purchLast, 'int'),
            'revenue' => phase3_metric('Revenue (EUR)', round($revThis, 2), round($revLast, 2), 'money', 'EUR'),
        ],
    ];
}

function phase3_build_tab_goals(
    int $projectId,
    int $year,
    int $month,
    string $presetKey,
    array $thisMonth,
    array $lastYear
): array {
    $mulThis = phase3_preset_multiplier($projectId, $year, $month, $presetKey);
    $mulLast = phase3_preset_multiplier($projectId, $year - 1, $month, $presetKey);

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);
    $sessionsThis = (int)round(phase3_safe_int($vThis['sessions'] ?? 0, 0) * $mulThis);
    $sessionsLast = (int)round(phase3_safe_int($vLast['sessions'] ?? 0, max(0, (int)round($sessionsThis / 1.12))) * $mulLast);

    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3goals|' . $presetKey)) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p3goals|' . $presetKey)) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $rateThis = $rngThis->float(0.008, 0.045);
    $rateLast = $rngLast->float(0.008, 0.045);
    $allGoalsThis = (int)max(0, round($sessionsThis * $rateThis));
    $allGoalsLast = (int)max(0, round($sessionsLast * $rateLast));

    // Split into 2 simple goals + keep one column as goal rate.
    $newsletterThis = (int)round($allGoalsThis * $rngThis->float(0.35, 0.60));
    $contactThis = max(0, $allGoalsThis - $newsletterThis);
    $newsletterLast = (int)round($allGoalsLast * $rngLast->float(0.35, 0.60));
    $contactLast = max(0, $allGoalsLast - $newsletterLast);

    $goalRateThis = $sessionsThis > 0 ? ($allGoalsThis / $sessionsThis) : 0.0;
    $goalRateLast = $sessionsLast > 0 ? ($allGoalsLast / $sessionsLast) : 0.0;

    return [
        'timeseries' => phase3_build_timeseries($projectId, $year, $month, $presetKey, 'goals', (float)$allGoalsThis, (float)$allGoalsLast),
        'totals' => [
            'goal_newsletter' => phase3_metric('Newsletter signups', $newsletterThis, $newsletterLast, 'int'),
            'goal_contact' => phase3_metric('Contact form submits', $contactThis, $contactLast, 'int'),
            'goal_conversion_rate' => phase3_metric('Goal conversion rate', round($goalRateThis, 4), round($goalRateLast, 4), 'pct', '%'),
        ],
    ];
}

/**
 * Phase 3.6 — Build traffic segment payload with canonical by_source contract.
 * This is MOCK-only (no GA4/GSC API calls), but enforces a stable table format.
 */
function phase36_build_traffic_segment(
    int $projectId,
    int $year,
    int $month,
    string $trafficKey,
    array $thisMonth,
    array $lastYear,
    bool $includeSales
): array {
    $sources = phase36_sources_for_traffic_key($trafficKey);

    // Reuse existing deterministic tab logic for the segment totals/timeseries baseline.
    $visitsTab = phase3_build_tab_visits($projectId, $year, $month, $trafficKey, $thisMonth, $lastYear);
    $behaviorTab = phase3_build_tab_behavior($projectId, $year, $month, $trafficKey, $thisMonth, $lastYear);
    $salesTab = $includeSales
        ? phase3_build_tab_sales($projectId, $year, $month, $trafficKey, $thisMonth, $lastYear)
        : ['timeseries' => ['labels' => [], 'this' => [], 'last' => []], 'totals' => []];

    $vTotals = is_array($visitsTab['totals'] ?? null) ? (array)$visitsTab['totals'] : [];
    $bTotals = is_array($behaviorTab['totals'] ?? null) ? (array)$behaviorTab['totals'] : [];
    $sTotals = is_array($salesTab['totals'] ?? null) ? (array)$salesTab['totals'] : [];

    $usersThis = (int)phase3_safe_int(($vTotals['users']['this'] ?? null), 0);
    $usersLast = (int)phase3_safe_int(($vTotals['users']['last'] ?? null), max(0, (int)round($usersThis / 1.12)));
    $newThis = (int)phase3_safe_int(($vTotals['new_users']['this'] ?? null), 0);
    $newLast = (int)phase3_safe_int(($vTotals['new_users']['last'] ?? null), max(0, (int)round($newThis / 1.12)));
    $sessThis = (int)phase3_safe_int(($vTotals['sessions']['this'] ?? null), 0);
    $sessLast = (int)phase3_safe_int(($vTotals['sessions']['last'] ?? null), max(0, (int)round($sessThis / 1.12)));

    $visitsSplit = phase36_build_visits_by_source(
        $projectId,
        $year,
        $month,
        'traffic|' . $trafficKey,
        $sources,
        $usersThis,
        $usersLast,
        $newThis,
        $newLast,
        $sessThis,
        $sessLast
    );
    $visitsBySource = (array)($visitsSplit['rows'] ?? []);
    $sessionsThisByKey = is_array($visitsSplit['sessions_this'] ?? null) ? (array)$visitsSplit['sessions_this'] : [];
    $sessionsLastByKey = is_array($visitsSplit['sessions_last'] ?? null) ? (array)$visitsSplit['sessions_last'] : [];

    $engThis = (float)phase3_safe_float(($bTotals['engagement_rate']['this'] ?? null), 0.0);
    $engLast = (float)phase3_safe_float(($bTotals['engagement_rate']['last'] ?? null), max(0.0, min(1.0, $engThis - 0.04)));
    $ppsThis = (float)phase3_safe_float(($bTotals['pages_per_session']['this'] ?? null), 2.2);
    $ppsLast = (float)phase3_safe_float(($bTotals['pages_per_session']['last'] ?? null), 2.1);
    $durThis = (int)phase3_safe_int(($bTotals['avg_session_duration_sec']['this'] ?? null), 135);
    $durLast = (int)phase3_safe_int(($bTotals['avg_session_duration_sec']['last'] ?? null), 130);

    $behaviorBySource = phase36_build_behavior_by_source(
        $projectId,
        $year,
        $month,
        'traffic|' . $trafficKey,
        $sources,
        $engThis,
        $engLast,
        $ppsThis,
        $ppsLast,
        $durThis,
        $durLast
    );

    $purchThis = $includeSales ? (int)phase3_safe_int(($sTotals['purchases']['this'] ?? null), 0) : 0;
    $purchLast = $includeSales ? (int)phase3_safe_int(($sTotals['purchases']['last'] ?? null), max(0, (int)round($purchThis / 1.10))) : 0;
    $revThis = $includeSales ? (float)phase3_safe_float(($sTotals['revenue']['this'] ?? null), 0.0) : 0.0;
    $revLast = $includeSales ? (float)phase3_safe_float(($sTotals['revenue']['last'] ?? null), max(0.0, $revThis / 1.10)) : 0.0;
    $crThis = ($sessThis > 0 && $includeSales) ? round($purchThis / $sessThis, 4) : ($includeSales ? 0.0 : null);
    $crLast = ($sessLast > 0 && $includeSales) ? round($purchLast / $sessLast, 4) : ($includeSales ? 0.0 : null);

    $salesBySource = phase36_build_sales_by_source(
        $projectId,
        $year,
        $month,
        'traffic|' . $trafficKey,
        $sources,
        $includeSales,
        $sessionsThisByKey,
        $sessionsLastByKey,
        $purchThis,
        $purchLast,
        $revThis,
        $revLast
    );

    $goalsTable = phase36_build_goals_table(
        $projectId,
        $year,
        $month,
        'traffic|' . $trafficKey,
        $sources,
        $sessionsThisByKey,
        $sessionsLastByKey
    );

    return [
        'sources' => $sources,
        'visits' => [
            'timeseries' => is_array($visitsTab['timeseries'] ?? null) ? (array)$visitsTab['timeseries'] : ['labels' => [], 'this' => [], 'last' => []],
            'totals' => [
                'this' => ['users' => $usersThis, 'new_users' => $newThis, 'sessions' => $sessThis],
                'last' => ['users' => $usersLast, 'new_users' => $newLast, 'sessions' => $sessLast],
            ],
            'by_source' => $visitsBySource,
        ],
        'behavior' => [
            'timeseries' => is_array($behaviorTab['timeseries'] ?? null) ? (array)$behaviorTab['timeseries'] : ['labels' => [], 'this' => [], 'last' => []],
            'totals' => [
                'this' => ['engagement_rate' => round($engThis, 4), 'pages_per_session' => round($ppsThis, 2), 'avg_session_duration_sec' => $durThis],
                'last' => ['engagement_rate' => round($engLast, 4), 'pages_per_session' => round($ppsLast, 2), 'avg_session_duration_sec' => $durLast],
            ],
            'by_source' => $behaviorBySource,
        ],
        'sales' => [
            'enabled' => $includeSales,
            'timeseries' => is_array($salesTab['timeseries'] ?? null) ? (array)$salesTab['timeseries'] : ['labels' => [], 'this' => [], 'last' => []],
            'totals' => [
                'this' => ['conversion_rate' => $crThis, 'purchases' => $includeSales ? $purchThis : null, 'revenue' => $includeSales ? round($revThis, 2) : null],
                'last' => ['conversion_rate' => $crLast, 'purchases' => $includeSales ? $purchLast : null, 'revenue' => $includeSales ? round($revLast, 2) : null],
            ],
            'by_source' => $salesBySource,
        ],
        'goals' => [
            'excluded_events' => is_array($goalsTable['excluded_events'] ?? null) ? (array)$goalsTable['excluded_events'] : ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
            'goal_names' => is_array($goalsTable['goal_names'] ?? null) ? (array)$goalsTable['goal_names'] : [],
            'totals' => is_array($goalsTable['totals'] ?? null) ? (array)$goalsTable['totals'] : ['this' => ['sessions' => $sessThis], 'last' => ['sessions' => $sessLast]],
            'by_source' => is_array($goalsTable['by_source'] ?? null) ? (array)$goalsTable['by_source'] : [],
        ],
    ];
}

function generate_report_snapshot(PDO $pdo, array $project, int $year, int $month): array
{
    $projectId = (int)$project['id'];
    $includeSales = ((int)($project['show_sales_section'] ?? 1)) === 1;

    // Notes are part of the snapshot (so reports are immutable archives).
    $notesStmt = $pdo->prepare('SELECT work_summary FROM monthly_notes WHERE project_id = ? AND year = ? AND month = ? LIMIT 1');
    $notesStmt->execute([$projectId, $year, $month]);
    $workSummary = $notesStmt->fetchColumn();
    $workSummary = is_string($workSummary) ? $workSummary : '';

    // Phase 3 UI contract: sections.traffic[preset][tab] = { timeseries, totals }.
    // Data is MOCK (no Google APIs in Phase 3).
    $thisMonth = mock_generate_report_data($projectId, $year, $month, $includeSales);
    $lastYear = mock_generate_report_data($projectId, $year - 1, $month, $includeSales);

    [$thisStart, $thisEnd, $lastStart, $lastEnd] = phase3_month_date_ranges_utc($year, $month);

    $reportStatus = 'READY';
    $snapshotErrors = [];

    $meta = [
        'schema_version' => 1,
        'currency' => 'EUR',
        'timezone' => 'Europe/Vilnius',
        'generated_utc' => gmdate('c'),
        'reportStatus' => $reportStatus,
    ];

    $period = [
        'year' => $year,
        'month' => $month,
        'label' => sprintf('%04d-%02d', $year, $month),
        'compare_to' => [
            'year' => $year - 1,
            'month' => $month,
            'label' => sprintf('%04d-%02d', $year - 1, $month),
        ],
        'date_ranges' => [
            'this_start' => $thisStart,
            'this_end' => $thisEnd,
            'last_start' => $lastStart,
            'last_end' => $lastEnd,
        ],
    ];

    $projectInfo = [
        'id' => $projectId,
        'name' => (string)$project['name'],
        'show_sales_section' => $includeSales,
    ];

    // Phase 3.6 UI traffic keys (must match report_ui.js trafficKeyForView()).
    // Phase 4.0: fill these snapshots from GA4 (Data API), keeping the JSON contract unchanged.
    $trafficKeys = ['all', 'seo', 'ppc', 'social_organic', 'social_paid', 'referral', 'email'];
    $traffic = [];

    [$labels, $days] = (static function (int $y, int $m): array {
        $start = sprintf('%04d-%02d-01', $y, $m);
        $days = (int)date('t', strtotime($start));
        $labels = [];
        for ($d = 1; $d <= $days; $d++) {
            $labels[] = (string)$d;
        }
        return [$labels, $days];
    })($year, $month);

    $seriesByDay = static function (int $days, array $rows, string $valueKey, float $default): array {
        $out = array_fill(0, $days, null);
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $date = (string)($r['date'] ?? '');
            if (strlen($date) < 10) {
                continue;
            }
            $day = (int)substr($date, 8, 2);
            if ($day < 1 || $day > $days) {
                continue;
            }
            $v = $r[$valueKey] ?? null;
            $out[$day - 1] = is_int($v) || is_float($v) ? (float)$v : (is_numeric((string)$v) ? (float)$v : null);
        }
        for ($i = 0; $i < $days; $i++) {
            if ($out[$i] === null) {
                $out[$i] = $default;
            }
        }
        return $out;
    };

    $trafficKeyForChannel = static function (string $group): ?string {
        $g = strtolower(trim($group));
        return match ($g) {
            'organic search' => 'seo',
            'paid search' => 'ppc',
            'organic social' => 'social_organic',
            'paid social' => 'social_paid',
            'referral' => 'referral',
            'email' => 'email',
            default => null,
        };
    };

    $buildTrafficSegmentEmpty = static function (
        string $trafficKey,
        array $labels,
        bool $includeSales
    ): array {
        $sources = phase36_sources_for_traffic_key($trafficKey);
        $zeros = array_fill(0, count($labels), 0.0);

        $visitsBySource = [];
        foreach ($sources as $src) {
            $k = (string)($src['key'] ?? '');
            $label = (string)($src['label'] ?? $k);
            $visitsBySource[] = [
                'key' => $k,
                'label' => $label,
                'this' => ['users' => 0, 'new_users' => 0, 'sessions' => 0],
                'last' => ['users' => 0, 'new_users' => 0, 'sessions' => 0],
            ];
        }

        $behaviorBySource = [];
        foreach ($sources as $src) {
            $k = (string)($src['key'] ?? '');
            $label = (string)($src['label'] ?? $k);
            $behaviorBySource[] = [
                'key' => $k,
                'label' => $label,
                'this' => ['engagement_rate' => 0.0, 'pages_per_session' => 0.0, 'avg_session_duration_sec' => 0],
                'last' => ['engagement_rate' => 0.0, 'pages_per_session' => 0.0, 'avg_session_duration_sec' => 0],
            ];
        }

        $salesBySource = [];
        if ($includeSales) {
            foreach ($sources as $src) {
                $k = (string)($src['key'] ?? '');
                $label = (string)($src['label'] ?? $k);
                $salesBySource[] = [
                    'key' => $k,
                    'label' => $label,
                    'this' => ['conversion_rate' => 0.0, 'purchases' => 0, 'revenue' => 0.0],
                    'last' => ['conversion_rate' => 0.0, 'purchases' => 0, 'revenue' => 0.0],
                ];
            }
        }

        $goalNames = phase36_goal_names();
        $goalsTotalsThis = ['sessions' => 0];
        $goalsTotalsLast = ['sessions' => 0];
        foreach ($goalNames as $g) {
            $goalsTotalsThis[$g] = 0;
            $goalsTotalsLast[$g] = 0;
        }
        $goalsBySource = [];
        foreach ($sources as $src) {
            $k = (string)($src['key'] ?? '');
            $label = (string)($src['label'] ?? $k);
            $rowThis = ['sessions' => 0];
            $rowLast = ['sessions' => 0];
            foreach ($goalNames as $g) {
                $rowThis[$g] = 0;
                $rowLast[$g] = 0;
            }
            $goalsBySource[] = [
                'key' => $k,
                'label' => $label,
                'this' => $rowThis,
                'last' => $rowLast,
            ];
        }

        return [
            'sources' => $sources,
            'visits' => [
                'timeseries' => ['labels' => $labels, 'this' => $zeros, 'last' => $zeros],
                'totals' => [
                    'this' => ['users' => 0, 'new_users' => 0, 'sessions' => 0],
                    'last' => ['users' => 0, 'new_users' => 0, 'sessions' => 0],
                ],
                'by_source' => $visitsBySource,
            ],
            'behavior' => [
                'timeseries' => ['labels' => $labels, 'this' => $zeros, 'last' => $zeros],
                'totals' => [
                    'this' => ['engagement_rate' => 0.0, 'pages_per_session' => 0.0, 'avg_session_duration_sec' => 0],
                    'last' => ['engagement_rate' => 0.0, 'pages_per_session' => 0.0, 'avg_session_duration_sec' => 0],
                ],
                'by_source' => $behaviorBySource,
            ],
            'sales' => [
                'enabled' => $includeSales,
                'timeseries' => $includeSales ? ['labels' => $labels, 'this' => $zeros, 'last' => $zeros] : ['labels' => [], 'this' => [], 'last' => []],
                'totals' => [
                    'this' => ['conversion_rate' => $includeSales ? 0.0 : null, 'purchases' => $includeSales ? 0 : null, 'revenue' => $includeSales ? 0.0 : null],
                    'last' => ['conversion_rate' => $includeSales ? 0.0 : null, 'purchases' => $includeSales ? 0 : null, 'revenue' => $includeSales ? 0.0 : null],
                ],
                'by_source' => $salesBySource,
            ],
            'goals' => [
                'excluded_events' => ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
                'goal_names' => $goalNames,
                'totals' => [
                    'this' => $goalsTotalsThis,
                    'last' => $goalsTotalsLast,
                ],
                'by_source' => $goalsBySource,
            ],
        ];
    };

    $buildTrafficSegmentFromGa4 = static function (
        int $projectId,
        int $year,
        int $month,
        string $trafficKey,
        bool $includeSales,
        array $ga4TotalsThis,
        array $ga4TotalsLast,
        array $tsVisits,
        array $tsBehavior,
        array $tsSales,
        ?array $visitsBySourceOverride = null,
        ?array $sourcesOverride = null
    ): array {
        $sources = is_array($sourcesOverride) ? $sourcesOverride : phase36_sources_for_traffic_key($trafficKey);

        $usersThis = (int)ga4_parse_int($ga4TotalsThis['totalUsers'] ?? 0, 0);
        $usersLast = (int)ga4_parse_int($ga4TotalsLast['totalUsers'] ?? 0, 0);
        $newThis = (int)ga4_parse_int($ga4TotalsThis['newUsers'] ?? 0, 0);
        $newLast = (int)ga4_parse_int($ga4TotalsLast['newUsers'] ?? 0, 0);
        $sessThis = (int)ga4_parse_int($ga4TotalsThis['sessions'] ?? 0, 0);
        $sessLast = (int)ga4_parse_int($ga4TotalsLast['sessions'] ?? 0, 0);

        $visitsBySource = [];
        $sessionsThisByKey = [];
        $sessionsLastByKey = [];
        if (is_array($visitsBySourceOverride)) {
            $visitsBySource = $visitsBySourceOverride;
            foreach ($visitsBySource as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $k = (string)($r['key'] ?? '');
                if ($k === '') {
                    continue;
                }
                $sessionsThisByKey[$k] = (int)($r['this']['sessions'] ?? 0);
                $sessionsLastByKey[$k] = (int)($r['last']['sessions'] ?? 0);
            }
        } else {
            $visitsSplit = phase36_build_visits_by_source(
                $projectId,
                $year,
                $month,
                'traffic|' . $trafficKey,
                $sources,
                $usersThis,
                $usersLast,
                $newThis,
                $newLast,
                $sessThis,
                $sessLast
            );
            $visitsBySource = (array)($visitsSplit['rows'] ?? []);
            $sessionsThisByKey = is_array($visitsSplit['sessions_this'] ?? null) ? (array)$visitsSplit['sessions_this'] : [];
            $sessionsLastByKey = is_array($visitsSplit['sessions_last'] ?? null) ? (array)$visitsSplit['sessions_last'] : [];
        }

        $engThis = (float)ga4_parse_float($ga4TotalsThis['engagementRate'] ?? 0.0, 0.0);
        $engLast = (float)ga4_parse_float($ga4TotalsLast['engagementRate'] ?? 0.0, 0.0);
        $ppsThis = (float)ga4_parse_float($ga4TotalsThis['screenPageViewsPerSession'] ?? 0.0, 0.0);
        $ppsLast = (float)ga4_parse_float($ga4TotalsLast['screenPageViewsPerSession'] ?? 0.0, 0.0);
        $durThis = (int)ga4_parse_int($ga4TotalsThis['averageSessionDuration'] ?? 0, 0);
        $durLast = (int)ga4_parse_int($ga4TotalsLast['averageSessionDuration'] ?? 0, 0);

        $behaviorBySource = phase36_build_behavior_by_source(
            $projectId,
            $year,
            $month,
            'traffic|' . $trafficKey,
            $sources,
            $engThis,
            $engLast,
            $ppsThis,
            $ppsLast,
            $durThis,
            $durLast
        );

        $purchThis = $includeSales ? (int)ga4_parse_int($ga4TotalsThis['purchases'] ?? 0, 0) : 0;
        $purchLast = $includeSales ? (int)ga4_parse_int($ga4TotalsLast['purchases'] ?? 0, 0) : 0;
        $revThis = $includeSales ? (float)ga4_parse_float($ga4TotalsThis['totalRevenue'] ?? 0.0, 0.0) : 0.0;
        $revLast = $includeSales ? (float)ga4_parse_float($ga4TotalsLast['totalRevenue'] ?? 0.0, 0.0) : 0.0;
        $crThis = ($sessThis > 0 && $includeSales) ? round($purchThis / $sessThis, 4) : ($includeSales ? 0.0 : null);
        $crLast = ($sessLast > 0 && $includeSales) ? round($purchLast / $sessLast, 4) : ($includeSales ? 0.0 : null);

        $salesBySource = phase36_build_sales_by_source(
            $projectId,
            $year,
            $month,
            'traffic|' . $trafficKey,
            $sources,
            $includeSales,
            $sessionsThisByKey,
            $sessionsLastByKey,
            $purchThis,
            $purchLast,
            $revThis,
            $revLast
        );

        $goalsTable = phase36_build_goals_table(
            $projectId,
            $year,
            $month,
            'traffic|' . $trafficKey,
            $sources,
            $sessionsThisByKey,
            $sessionsLastByKey
        );

        return [
            'sources' => $sources,
            'visits' => [
                'timeseries' => $tsVisits,
                'totals' => [
                    'this' => ['users' => $usersThis, 'new_users' => $newThis, 'sessions' => $sessThis],
                    'last' => ['users' => $usersLast, 'new_users' => $newLast, 'sessions' => $sessLast],
                ],
                'by_source' => $visitsBySource,
            ],
            'behavior' => [
                'timeseries' => $tsBehavior,
                'totals' => [
                    'this' => ['engagement_rate' => round($engThis, 4), 'pages_per_session' => round($ppsThis, 2), 'avg_session_duration_sec' => $durThis],
                    'last' => ['engagement_rate' => round($engLast, 4), 'pages_per_session' => round($ppsLast, 2), 'avg_session_duration_sec' => $durLast],
                ],
                'by_source' => $behaviorBySource,
            ],
            'sales' => [
                'enabled' => $includeSales,
                'timeseries' => $includeSales ? $tsSales : ['labels' => [], 'this' => [], 'last' => []],
                'totals' => [
                    'this' => ['conversion_rate' => $crThis, 'purchases' => $includeSales ? $purchThis : null, 'revenue' => $includeSales ? round($revThis, 2) : null],
                    'last' => ['conversion_rate' => $crLast, 'purchases' => $includeSales ? $purchLast : null, 'revenue' => $includeSales ? round($revLast, 2) : null],
                ],
                'by_source' => $salesBySource,
            ],
            'goals' => [
                'excluded_events' => is_array($goalsTable['excluded_events'] ?? null) ? (array)$goalsTable['excluded_events'] : ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
                'goal_names' => is_array($goalsTable['goal_names'] ?? null) ? (array)$goalsTable['goal_names'] : [],
                'totals' => is_array($goalsTable['totals'] ?? null) ? (array)$goalsTable['totals'] : ['this' => ['sessions' => $sessThis], 'last' => ['sessions' => $sessLast]],
                'by_source' => is_array($goalsTable['by_source'] ?? null) ? (array)$goalsTable['by_source'] : [],
            ],
        ];
    };

    $ga4PropertyId = trim((string)($project['ga4_property_id'] ?? ''));
    $ga4TotalsThisByKey = [];
    $ga4TotalsLastByKey = [];
    $ga4TsRowsThisByKey = [];
    $ga4TsRowsLastByKey = [];

    $ga4ClientRes = null;
    $ga4Req = null;
    $ga4ClientForBreakdowns = null;
    if ($ga4PropertyId !== '' && ga4_is_valid_property_id($ga4PropertyId)) {
        $ga4Req = ga4_requirements_check([
            'component' => 'report_generator',
            'project_id' => $projectId,
            'ga4_property_id' => $ga4PropertyId,
        ]);
        if (($ga4Req['ok'] ?? false)) {
            $ga4ClientRes = ga4_build_client([
                'project_id' => $projectId,
                'ga4_property_id' => $ga4PropertyId,
            ]);
        } else {
            $reportStatus = 'PARTIAL';
            $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 disabled: ' . (string)($ga4Req['reason'] ?? 'requirements failed')];
            log_error('GA4 disabled: requirements failed', [
                'project_id' => $projectId,
                'ga4_property_id' => $ga4PropertyId,
                'reason' => (string)($ga4Req['reason'] ?? 'unknown'),
                'autoload' => (string)($ga4Req['autoload'] ?? ''),
            ]);
        }
    }

    if (is_array($ga4ClientRes) && !($ga4ClientRes['ok'] ?? false)) {
        $reportStatus = 'PARTIAL';
        $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 disabled: client init failed'];
        log_error('GA4 disabled: client init failed', [
            'project_id' => $projectId,
            'ga4_property_id' => $ga4PropertyId,
            'error' => (string)($ga4ClientRes['error'] ?? 'unknown'),
        ]);
    }

    if (is_array($ga4ClientRes) && ($ga4ClientRes['ok'] ?? false) && ($ga4ClientRes['client'] ?? null) instanceof \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient) {
        /** @var \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient $ga4Client */
        $ga4Client = $ga4ClientRes['client'];

        $smoke = ga4_smoke_test($ga4Client, $ga4PropertyId);
        if (!($smoke['ok'] ?? false)) {
            $reportStatus = 'PARTIAL';
            $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 smoke test failed'];
            log_error('GA4 smoke test failed (snapshot aborted)', [
                'project_id' => $projectId,
                'ga4_property_id' => $ga4PropertyId,
                'error' => (string)($smoke['error'] ?? 'unknown'),
            ]);
        } else {
            $ga4ClientForBreakdowns = $ga4Client;

            $zeroTotals = static function (bool $includeSales): array {
                $base = [
                    'totalUsers' => '0',
                    'newUsers' => '0',
                    'sessions' => '0',
                    'engagementRate' => '0',
                    'averageSessionDuration' => '0',
                    'screenPageViewsPerSession' => '0',
                ];
                if ($includeSales) {
                    $base['purchases'] = '0';
                    $base['totalRevenue'] = '0';
                }
                return $base;
            };

            // Preferred path: 1 totals query (all) + 1 totals query (by channel group) + daily series (all + by channel group).
            $allTotals = ga4_fetch_totals_all($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $lastStart, $lastEnd, $includeSales);
            $groupTotals = ga4_fetch_totals_by_channel_group($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $lastStart, $lastEnd, $includeSales);

            if (($allTotals['ok'] ?? false) && ($groupTotals['ok'] ?? false)) {
                $ga4TotalsThisByKey['all'] = (array)($allTotals['this'] ?? []);
                $ga4TotalsLastByKey['all'] = (array)($allTotals['last'] ?? []);

                foreach ((array)($groupTotals['by_group'] ?? []) as $group => $vals) {
                    $k = $trafficKeyForChannel((string)$group);
                    if ($k === null) {
                        continue;
                    }
                    $ga4TotalsThisByKey[$k] = is_array($vals['this'] ?? null) ? (array)$vals['this'] : [];
                    $ga4TotalsLastByKey[$k] = is_array($vals['last'] ?? null) ? (array)$vals['last'] : [];
                }

                $tsAllThis = ga4_fetch_timeseries_all($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $includeSales);
                $tsAllLast = ga4_fetch_timeseries_all($ga4Client, $ga4PropertyId, $lastStart, $lastEnd, $includeSales);
                if (($tsAllThis['ok'] ?? false) && ($tsAllLast['ok'] ?? false)) {
                    $ga4TsRowsThisByKey['all'] = (array)($tsAllThis['rows'] ?? []);
                    $ga4TsRowsLastByKey['all'] = (array)($tsAllLast['rows'] ?? []);
                } else {
                    $reportStatus = 'PARTIAL';
                    $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 all timeseries failed'];
                }

                $channelDim = (string)($groupTotals['dimension'] ?? '');
                $channelTsOk = false;
                if ($channelDim !== '') {
                    $tsByThis = ga4_fetch_timeseries_by_channel_group($ga4Client, $ga4PropertyId, $channelDim, $thisStart, $thisEnd, $includeSales);
                    $tsByLast = ga4_fetch_timeseries_by_channel_group($ga4Client, $ga4PropertyId, $channelDim, $lastStart, $lastEnd, $includeSales);
                    if (($tsByThis['ok'] ?? false) && ($tsByLast['ok'] ?? false)) {
                        $channelTsOk = true;
                        foreach ((array)($tsByThis['rows'] ?? []) as $r) {
                            if (!is_array($r)) {
                                continue;
                            }
                            $k = $trafficKeyForChannel((string)($r['channel_group'] ?? ''));
                            if ($k === null) {
                                continue;
                            }
                            $ga4TsRowsThisByKey[$k][] = $r;
                        }
                        foreach ((array)($tsByLast['rows'] ?? []) as $r) {
                            if (!is_array($r)) {
                                continue;
                            }
                            $k = $trafficKeyForChannel((string)($r['channel_group'] ?? ''));
                            if ($k === null) {
                                continue;
                            }
                            $ga4TsRowsLastByKey[$k][] = $r;
                        }
                    } else {
                        // We'll fall back to per-segment timeseries below.
                        log_warn('GA4 channel-group timeseries failed; falling back to per-segment timeseries', [
                            'project_id' => $projectId,
                            'ga4_property_id' => $ga4PropertyId,
                            'dimension' => $channelDim,
                        ]);
                    }
                }

                // Fallback: if we couldn't build per-channel timeseries, fetch per-segment timeseries directly.
                if (!$channelTsOk) {
                    foreach ($trafficKeys as $tk) {
                        if ($tk === 'all') {
                            continue;
                        }
                        $tsThis = ga4_fetch_timeseries_for_segment($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $includeSales, $tk);
                        $tsLast = ga4_fetch_timeseries_for_segment($ga4Client, $ga4PropertyId, $lastStart, $lastEnd, $includeSales, $tk);
                        if (($tsThis['ok'] ?? false) && ($tsLast['ok'] ?? false)) {
                            $ga4TsRowsThisByKey[$tk] = (array)($tsThis['rows'] ?? []);
                            $ga4TsRowsLastByKey[$tk] = (array)($tsLast['rows'] ?? []);
                        } else {
                            $reportStatus = 'PARTIAL';
                            $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 timeseries failed for ' . $tk];
                        }
                    }
                }

                // Ensure missing segments are represented as zeros (normal when a channel has no traffic).
                foreach ($trafficKeys as $tk) {
                    if (!isset($ga4TotalsThisByKey[$tk]) || !is_array($ga4TotalsThisByKey[$tk]) || $ga4TotalsThisByKey[$tk] === []) {
                        $ga4TotalsThisByKey[$tk] = $zeroTotals($includeSales);
                    }
                    if (!isset($ga4TotalsLastByKey[$tk]) || !is_array($ga4TotalsLastByKey[$tk]) || $ga4TotalsLastByKey[$tk] === []) {
                        $ga4TotalsLastByKey[$tk] = $zeroTotals($includeSales);
                    }
                    if (!isset($ga4TsRowsThisByKey[$tk]) || !is_array($ga4TsRowsThisByKey[$tk])) {
                        $ga4TsRowsThisByKey[$tk] = [];
                    }
                    if (!isset($ga4TsRowsLastByKey[$tk]) || !is_array($ga4TsRowsLastByKey[$tk])) {
                        $ga4TsRowsLastByKey[$tk] = [];
                    }
                }
            } else {
                // Fallback path: per-segment filter (sessionDefaultChannelGroup; then sessionSourceMedium patterns)
                foreach ($trafficKeys as $k) {
                    $segTotals = $k === 'all'
                        ? $allTotals
                        : ga4_fetch_totals_for_segment($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $lastStart, $lastEnd, $includeSales, $k);
                    if (!($segTotals['ok'] ?? false)) {
                        $reportStatus = 'PARTIAL';
                        $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 totals failed for ' . $k];
                        continue;
                    }
                    $ga4TotalsThisByKey[$k] = (array)($segTotals['this'] ?? []);
                    $ga4TotalsLastByKey[$k] = (array)($segTotals['last'] ?? []);

                    $tsThis = $k === 'all'
                        ? ga4_fetch_timeseries_all($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $includeSales)
                        : ga4_fetch_timeseries_for_segment($ga4Client, $ga4PropertyId, $thisStart, $thisEnd, $includeSales, $k);
                    $tsLast = $k === 'all'
                        ? ga4_fetch_timeseries_all($ga4Client, $ga4PropertyId, $lastStart, $lastEnd, $includeSales)
                        : ga4_fetch_timeseries_for_segment($ga4Client, $ga4PropertyId, $lastStart, $lastEnd, $includeSales, $k);
                    if (($tsThis['ok'] ?? false) && ($tsLast['ok'] ?? false)) {
                        $ga4TsRowsThisByKey[$k] = (array)($tsThis['rows'] ?? []);
                        $ga4TsRowsLastByKey[$k] = (array)($tsLast['rows'] ?? []);
                    } else {
                        $reportStatus = 'PARTIAL';
                        $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 timeseries failed for ' . $k];
                    }
                }
            }
        }
    } else {
        $reportStatus = 'PARTIAL';
        $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 client/property not available'];
    }

    $sanitizeKey = static function (string $label): string {
        $s = strtolower(trim($label));
        $s = preg_replace('/[^a-z0-9]+/i', '_', $s);
        $s = is_string($s) ? trim($s, '_') : '';
        return $s !== '' ? $s : 'other';
    };

    foreach ($trafficKeys as $k) {
        $hasTotals = isset($ga4TotalsThisByKey[$k], $ga4TotalsLastByKey[$k]) && is_array($ga4TotalsThisByKey[$k]) && $ga4TotalsThisByKey[$k] !== [];
        $hasTs = isset($ga4TsRowsThisByKey[$k], $ga4TsRowsLastByKey[$k]) && is_array($ga4TsRowsThisByKey[$k]) && is_array($ga4TsRowsLastByKey[$k]);
        if ($hasTotals && $hasTs) {
            $tThis = (array)$ga4TotalsThisByKey[$k];
            $tLast = (array)$ga4TotalsLastByKey[$k];
            $rowsThis = (array)$ga4TsRowsThisByKey[$k];
            $rowsLast = (array)$ga4TsRowsLastByKey[$k];

            $usersThisSeries = $seriesByDay($days, $rowsThis, 'users', 0.0);
            $usersLastSeries = $seriesByDay($days, $rowsLast, 'users', 0.0);

            $engDefaultThis = (float)ga4_parse_float($tThis['engagementRate'] ?? 0.0, 0.0);
            $engDefaultLast = (float)ga4_parse_float($tLast['engagementRate'] ?? 0.0, 0.0);
            $engThisSeries = $seriesByDay($days, $rowsThis, 'engagement_rate', $engDefaultThis);
            $engLastSeries = $seriesByDay($days, $rowsLast, 'engagement_rate', $engDefaultLast);

            $revThisSeries = $seriesByDay($days, $rowsThis, 'revenue', 0.0);
            $revLastSeries = $seriesByDay($days, $rowsLast, 'revenue', 0.0);

            $tsVisits = ['labels' => $labels, 'this' => $usersThisSeries, 'last' => $usersLastSeries];
            $tsBehavior = ['labels' => $labels, 'this' => $engThisSeries, 'last' => $engLastSeries];
            $tsSales = ['labels' => $labels, 'this' => $revThisSeries, 'last' => $revLastSeries];

            $visitsBySourceOverride = null;
            $sourcesOverride = null;

 if (
                ($k === 'seo' || $k === 'ppc' || $k === 'social_organic' || $k === 'social_paid' || $k === 'referral' || $k === 'email')
                && ($ga4ClientForBreakdowns instanceof \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient)
                && $ga4PropertyId !== ''
                && ga4_is_valid_property_id($ga4PropertyId)
            ) {
                // Phase 4.1: channel segments MUST NOT fall back to "all" or placeholders.
                // If GA4 returns 0 rows, by_source is an empty array (valid "no data" state).
                $filter = ga4_segment_dimension_filter($k, true, 'sessionDefaultChannelGroup');
                $metrics = ['totalUsers', 'newUsers', 'sessions'];
                $limit = 15;
               // Phase 4.1 scope: PPC/Referral by_source must use sessionSource (no sessionCampaignName).
                $dimCandidates = ['sessionSource'];


                $thisRows = null;
                $lastRows = null;
                $usedDim = null;
                $breakdownOk = false;
                $lastError = null;

                foreach ($dimCandidates as $dim) {
                    $resThis = ga4_fetch_breakdown(
                        $ga4ClientForBreakdowns,
                        $ga4PropertyId,
                        ['start_date' => $thisStart, 'end_date' => $thisEnd],
                        $filter,
                        $dim,
                        $metrics,
                        $limit
                    );
                    $resLast = ga4_fetch_breakdown(
                        $ga4ClientForBreakdowns,
                        $ga4PropertyId,
                        ['start_date' => $lastStart, 'end_date' => $lastEnd],
                        $filter,
                        $dim,
                        $metrics,
                        $limit
                    );

                    if (($resThis['ok'] ?? false) && ($resLast['ok'] ?? false)) {
                        $thisRows = (array)($resThis['rows'] ?? []);
                        $lastRows = (array)($resLast['rows'] ?? []);
                        $usedDim = $dim;
                        $breakdownOk = true;
                        break;
                    }
                    $lastError = (string)($resThis['error'] ?? $resLast['error'] ?? 'unknown');
                }

                if ($breakdownOk) {
                    $map = static function (array $rows): array {
                        $out = [];
                        foreach ($rows as $r) {
                            if (!is_array($r)) {
                                continue;
                            }
                            $label = trim((string)($r['dimension'] ?? ''));
                            if ($label === '') {
                                $label = '(not set)';
                            }
                            $m = is_array($r['metrics'] ?? null) ? (array)$r['metrics'] : [];
                            $out[$label] = [
                                'users' => (int)ga4_parse_int($m['totalUsers'] ?? 0, 0),
                                'new_users' => (int)ga4_parse_int($m['newUsers'] ?? 0, 0),
                                'sessions' => (int)ga4_parse_int($m['sessions'] ?? 0, 0),
                            ];
                        }
                        return $out;
                    };

                    $thisMap = $map(is_array($thisRows) ? $thisRows : []);
                    $lastMap = $map(is_array($lastRows) ? $lastRows : []);
                    $labelsUnion = array_values(array_unique(array_merge(array_keys($thisMap), array_keys($lastMap))));

                    $rows = [];
                    $seen = [];
                    foreach ($labelsUnion as $lbl) {
                        $lbl = (string)$lbl;
                        $thisM = $thisMap[$lbl] ?? ['users' => 0, 'new_users' => 0, 'sessions' => 0];
                        $lastM = $lastMap[$lbl] ?? ['users' => 0, 'new_users' => 0, 'sessions' => 0];
                        $keyBase = $sanitizeKey($lbl);
                        $key = $keyBase;
                        $i = 2;
                        while (isset($seen[$key])) {
                            $key = $keyBase . '_' . $i;
                            $i++;
                        }
                        $seen[$key] = true;
                        $rows[] = [
                            'key' => $key,
                            'label' => $lbl,
                            'this' => $thisM,
                            'last' => $lastM,
                        ];
                    }

                    usort($rows, static function ($a, $b) {
                        $au = (int)($a['this']['users'] ?? 0);
                        $bu = (int)($b['this']['users'] ?? 0);
                        if ($au !== $bu) {
                            return $bu <=> $au;
                        }
                        $al = (int)($a['last']['users'] ?? 0);
                        $bl = (int)($b['last']['users'] ?? 0);
                        return $bl <=> $al;
                    });

                    // IMPORTANT: if GA4 returns 0 rows, do NOT emit placeholders.
                    $visitsBySourceOverride = $rows;
                    $sourcesOverride = array_map(static fn($r) => ['key' => (string)($r['key'] ?? ''), 'label' => (string)($r['label'] ?? '')], $rows);

 $channelName = ga4_segment_channel_group_name($k);
                    log_info('GA4 segment breakdown', [
                        'key' => $k,
                        'dimension' => $usedDim,
                        'rows_this' => is_array($thisRows) ? count($thisRows) : 0,
                        'rows_last' => is_array($lastRows) ? count($lastRows) : 0,
                    ]);
                } else {
                    $reportStatus = 'PARTIAL';
                    $snapshotErrors[] = ['scope' => 'ga4', 'message' => 'GA4 breakdown failed for ' . $k];
                                        $channelName = ga4_segment_channel_group_name($k);
                    log_error('GA4 breakdown failed', [
                        'key' => $k,
                        'error' => $lastError,
                    ]);
                    // Still remove placeholders: empty list is a valid "no data" state for by_source.
                    $visitsBySourceOverride = [];
                    $sourcesOverride = [];
                }
            }

            $traffic[$k] = $buildTrafficSegmentFromGa4(
                $projectId,
                $year,
                $month,
                $k,
                $includeSales,
                $tThis,
                $tLast,
                $tsVisits,
                $tsBehavior,
                $tsSales,
                $visitsBySourceOverride,
                $sourcesOverride
            );

            $usersThisTotal = (int)ga4_parse_int($tThis['totalUsers'] ?? 0, 0);
            $usersLastTotal = (int)ga4_parse_int($tLast['totalUsers'] ?? 0, 0);
            $newThisTotal = (int)ga4_parse_int($tThis['newUsers'] ?? 0, 0);
            $newLastTotal = (int)ga4_parse_int($tLast['newUsers'] ?? 0, 0);
            $sessThisTotal = (int)ga4_parse_int($tThis['sessions'] ?? 0, 0);
            $sessLastTotal = (int)ga4_parse_int($tLast['sessions'] ?? 0, 0);
            $byCount = is_array($traffic[$k]['visits']['by_source'] ?? null) ? count((array)$traffic[$k]['visits']['by_source']) : 0;
            log_info('GA4 segment built', [
                'key' => $k,
               'filter' => (static function (string $tk): ?string {
                    $name = ga4_segment_channel_group_name($tk);
                    return $name ? ('sessionDefaultChannelGroup == "' . $name . '"') : null;
                })($k),
                'users_this' => $usersThisTotal,
                'users_last' => $usersLastTotal,
                'new_users_this' => $newThisTotal,
                'new_users_last' => $newLastTotal,
                'sessions_this' => $sessThisTotal,
                'sessions_last' => $sessLastTotal,
                'by_source_count' => $byCount,
                'timeseries_points' => count($labels),
            ]);

        } else {
            $reportStatus = 'PARTIAL';
            $traffic[$k] = $buildTrafficSegmentEmpty($k, $labels, $includeSales);
        }
    }

    $meta['reportStatus'] = $reportStatus;

    // Phase 3 (Phase 3.1) extra payload for ONE sidebar item: "Visų tinklalapio lankytojų ataskaita" (preset "all").
    // Kept separate from the generic tabs so other sidebar items remain unchanged.
    $allVisitorsExtra = phase3_build_all_visitors_report($projectId, $year, $month, $thisMonth, $lastYear, $includeSales);
    $seoReport = phase3_build_seo_report($projectId, $year, $month, $thisMonth, $lastYear, $includeSales, $workSummary);
    $ppcReport = phase3_build_ppc_report($projectId, $year, $month, $thisMonth, $lastYear, $includeSales);
    $segmentReports = [
        'Organic Social' => phase3_build_segment_report($projectId, $year, $month, 'Organic Social', $thisMonth, $lastYear, $includeSales),
        'Referral' => phase3_build_segment_report($projectId, $year, $month, 'Referral', $thisMonth, $lastYear, $includeSales),
        'Email' => phase3_build_segment_report($projectId, $year, $month, 'Email', $thisMonth, $lastYear, $includeSales),
        'Paid Social' => phase3_build_segment_report($projectId, $year, $month, 'Paid Social', $thisMonth, $lastYear, $includeSales),
    ];

    return [
        'meta' => $meta,
        'project' => $projectInfo,
        'period' => $period,
        'sections' => [
            'traffic' => $traffic,
            'all_visitors_report' => $allVisitorsExtra,
            'segment_reports' => $segmentReports,
            'seo_report' => $seoReport,
            'ppc_report' => $ppcReport,
        ],
        'notes' => [
            'work_summary' => $workSummary,
        ],
        'errors' => $snapshotErrors,
    ];
}

function phase3_all_visitors_sources(): array
{
    // Must match UI row order requirement.
    return [
        ['key' => 'seo', 'label' => 'SEO'],
        ['key' => 'paid_search', 'label' => 'Paid Search'],
        ['key' => 'direct_unknown', 'label' => 'Direct/Unknown'],
        ['key' => 'organic_social', 'label' => 'Organic Social'],
        ['key' => 'pmax', 'label' => 'PMax/Smart Shopping'],
        ['key' => 'paid_social', 'label' => 'Paid Social'],
        ['key' => 'referral', 'label' => 'Referral'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'display', 'label' => 'Display'],
        ['key' => 'unassigned', 'label' => 'Unassigned'],
    ];
}

function phase3_split_total_by_weights(int $total, array $weights): array
{
    $n = count($weights);
    if ($n <= 0) {
        return [];
    }
    $sum = 0.0;
    foreach ($weights as $w) {
        $sum += max(0.0, (float)$w);
    }
    if ($sum <= 0.0) {
        // Even split
        $base = (int)floor($total / $n);
        $out = array_fill(0, $n, $base);
        $rem = $total - ($base * $n);
        for ($i = 0; $i < $rem; $i++) {
            $out[$i] += 1;
        }
        return $out;
    }

    $out = [];
    $remaining = $total;
    for ($i = 0; $i < $n; $i++) {
        $isLast = ($i === $n - 1);
        if ($isLast) {
            $out[$i] = $remaining;
            break;
        }
        $share = max(0.0, (float)$weights[$i]) / $sum;
        $v = (int)floor($total * $share);
        $v = max(0, min($v, $remaining));
        $out[$i] = $v;
        $remaining -= $v;
    }
    return $out;
}

function phase36_goal_names(): array
{
    // Stable goals list for Phase 3 (no API calls). Must exclude GA/GTM boilerplate.
    return ['purchase', 'generate_lead', 'sign_up', 'contact_form_submit'];
}

function phase36_sources_for_traffic_key(string $trafficKey): array
{
    return match ($trafficKey) {
        'all' => phase3_all_visitors_sources(),
        'seo' => [
            ['key' => 'google', 'label' => 'Google'],
            ['key' => 'bing', 'label' => 'Bing'],
            ['key' => 'duckduckgo', 'label' => 'DuckDuckGo'],
            ['key' => 'yahoo', 'label' => 'Yahoo'],
            ['key' => 'other_search', 'label' => 'Other search'],
        ],
        'ppc' => [
            ['key' => 'brand_search', 'label' => 'Brand search'],
            ['key' => 'nonbrand_search', 'label' => 'Non-brand search'],
            ['key' => 'competitor_search', 'label' => 'Competitor search'],
            ['key' => 'performance_max', 'label' => 'Performance Max'],
            ['key' => 'other', 'label' => 'Other'],
        ],
        'social_organic' => [
            ['key' => 'facebook', 'label' => 'Facebook'],
            ['key' => 'instagram', 'label' => 'Instagram'],
            ['key' => 'tiktok', 'label' => 'TikTok'],
            ['key' => 'linkedin', 'label' => 'LinkedIn'],
            ['key' => 'pinterest', 'label' => 'Pinterest'],
            ['key' => 'youtube', 'label' => 'YouTube'],
            ['key' => 'other_social', 'label' => 'Other social'],
        ],
        'social_paid' => [
            ['key' => 'facebook_ads', 'label' => 'Facebook Ads'],
            ['key' => 'instagram_ads', 'label' => 'Instagram Ads'],
            ['key' => 'tiktok_ads', 'label' => 'TikTok Ads'],
            ['key' => 'linkedin_ads', 'label' => 'LinkedIn Ads'],
            ['key' => 'other_paid_social', 'label' => 'Other paid social'],
        ],
        'referral' => [
            ['key' => 'google.com_ref', 'label' => 'google.com (ref)'],
            ['key' => 'partner1.lt', 'label' => 'partner1.lt'],
            ['key' => 'partner2.com', 'label' => 'partner2.com'],
            ['key' => 'directory.lt', 'label' => 'directory.lt'],
            ['key' => 'other_referrals', 'label' => 'other referrals'],
        ],
        'email' => [
            ['key' => 'newsletter', 'label' => 'Newsletter'],
            ['key' => 'promo_campaign', 'label' => 'Promo campaign'],
            ['key' => 'automated_flows', 'label' => 'Automated flows'],
            ['key' => 'other_email', 'label' => 'Other email'],
        ],
        default => [
            ['key' => 'other', 'label' => 'Other'],
        ],
    };
}

/**
 * Canonical by_source rows for Visits table (users/new_users/sessions).
 * Returns: ['rows'=>[...], 'sessions_this'=>[key=>int], 'sessions_last'=>[key=>int]]
 */
function phase36_build_visits_by_source(
    int $projectId,
    int $year,
    int $month,
    string $contextKey,
    array $sources,
    int $usersThis,
    int $usersLast,
    int $newThis,
    int $newLast,
    int $sessThis,
    int $sessLast
): array {
    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p36|' . $contextKey . '|visits')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p36|' . $contextKey . '|visits')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $weightsThis = [];
    $weightsLast = [];
    foreach ($sources as $src) {
        $k = (string)($src['key'] ?? '');
        $isOther = (strpos($k, 'other') !== false) || (strpos($k, 'unassigned') !== false) || (strpos($k, 'unknown') !== false);
        $weightsThis[] = $isOther ? $rngThis->float(0.10, 0.40) : $rngThis->float(0.35, 1.20);
        $weightsLast[] = $isOther ? $rngLast->float(0.10, 0.40) : $rngLast->float(0.35, 1.20);
    }

    $usersThisByIdx = phase3_split_total_by_weights($usersThis, $weightsThis);
    $usersLastByIdx = phase3_split_total_by_weights($usersLast, $weightsLast);
    $sessThisByIdx = phase3_split_total_by_weights($sessThis, $weightsThis);
    $sessLastByIdx = phase3_split_total_by_weights($sessLast, $weightsLast);
    $newThisByIdx = phase3_split_total_by_weights($newThis, $weightsThis);
    $newLastByIdx = phase3_split_total_by_weights($newLast, $weightsLast);

    $rows = [];
    $sessionsThisByKey = [];
    $sessionsLastByKey = [];
    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $label = (string)($sources[$i]['label'] ?? $k);
        $uT = (int)($usersThisByIdx[$i] ?? 0);
        $uL = (int)($usersLastByIdx[$i] ?? 0);
        $sT = (int)($sessThisByIdx[$i] ?? 0);
        $sL = (int)($sessLastByIdx[$i] ?? 0);
        $nT = (int)($newThisByIdx[$i] ?? 0);
        $nL = (int)($newLastByIdx[$i] ?? 0);
        $rows[] = [
            'key' => $k,
            'label' => $label,
            'this' => [
                'users' => $uT,
                'new_users' => $nT,
                'sessions' => $sT,
            ],
            'last' => [
                'users' => $uL,
                'new_users' => $nL,
                'sessions' => $sL,
            ],
        ];
        $sessionsThisByKey[$k] = $sT;
        $sessionsLastByKey[$k] = $sL;
    }

    return [
        'rows' => $rows,
        'sessions_this' => $sessionsThisByKey,
        'sessions_last' => $sessionsLastByKey,
    ];
}

function phase36_build_behavior_by_source(
    int $projectId,
    int $year,
    int $month,
    string $contextKey,
    array $sources,
    float $engThis,
    float $engLast,
    float $ppsThis,
    float $ppsLast,
    int $durThis,
    int $durLast
): array {
    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p36|' . $contextKey . '|behavior')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p36|' . $contextKey . '|behavior')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $rows = [];
    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $label = (string)($sources[$i]['label'] ?? $k);
        $eT = max(0.0, min(1.0, $engThis + $rngThis->float(-0.08, 0.08)));
        $eL = max(0.0, min(1.0, $engLast + $rngLast->float(-0.08, 0.08)));
        $pT = max(0.8, min(7.0, $ppsThis + $rngThis->float(-0.7, 0.9)));
        $pL = max(0.8, min(7.0, $ppsLast + $rngLast->float(-0.7, 0.9)));
        $dT = (int)max(20, min(1800, (int)round($durThis + $rngThis->float(-65, 95))));
        $dL = (int)max(20, min(1800, (int)round($durLast + $rngLast->float(-65, 95))));
        $rows[] = [
            'key' => $k,
            'label' => $label,
            'this' => [
                'engagement_rate' => round($eT, 4),
                'pages_per_session' => round($pT, 2),
                'avg_session_duration_sec' => $dT,
            ],
            'last' => [
                'engagement_rate' => round($eL, 4),
                'pages_per_session' => round($pL, 2),
                'avg_session_duration_sec' => $dL,
            ],
        ];
    }
    return $rows;
}

function phase36_build_sales_by_source(
    int $projectId,
    int $year,
    int $month,
    string $contextKey,
    array $sources,
    bool $includeSales,
    array $sessionsThisByKey,
    array $sessionsLastByKey,
    int $purchThis,
    int $purchLast,
    float $revThis,
    float $revLast
): array {
    if (!$includeSales) {
        return [];
    }
    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p36|' . $contextKey . '|sales')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p36|' . $contextKey . '|sales')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $weightsThis = [];
    $weightsLast = [];
    foreach ($sources as $src) {
        $k = (string)($src['key'] ?? '');
        $weightsThis[] = max(0.01, (float)($sessionsThisByKey[$k] ?? 0) + $rngThis->float(0.2, 6.0));
        $weightsLast[] = max(0.01, (float)($sessionsLastByKey[$k] ?? 0) + $rngLast->float(0.2, 6.0));
    }
    $purchThisByIdx = phase3_split_total_by_weights($purchThis, $weightsThis);
    $purchLastByIdx = phase3_split_total_by_weights($purchLast, $weightsLast);
    $revThisByIdx = phase3_split_total_by_weights((int)round($revThis), $weightsThis);
    $revLastByIdx = phase3_split_total_by_weights((int)round($revLast), $weightsLast);

    $rows = [];
    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $label = (string)($sources[$i]['label'] ?? $k);
        $sessT = (int)($sessionsThisByKey[$k] ?? 0);
        $sessL = (int)($sessionsLastByKey[$k] ?? 0);
        $pT = (int)($purchThisByIdx[$i] ?? 0);
        $pL = (int)($purchLastByIdx[$i] ?? 0);
        $rT = (float)($revThisByIdx[$i] ?? 0);
        $rL = (float)($revLastByIdx[$i] ?? 0);
        $rows[] = [
            'key' => $k,
            'label' => $label,
            'this' => [
                'conversion_rate' => $sessT > 0 ? round($pT / $sessT, 4) : 0.0,
                'purchases' => $pT,
                'revenue' => round($rT, 2),
            ],
            'last' => [
                'conversion_rate' => $sessL > 0 ? round($pL / $sessL, 4) : 0.0,
                'purchases' => $pL,
                'revenue' => round($rL, 2),
            ],
        ];
    }
    return $rows;
}

/**
 * Canonical goals contract:
 * - goal_names: stable list
 * - totals.this / totals.last: { sessions:int, <goal>:int, ... }
 * - by_source rows: this/last contain sessions and goal counts by name
 */
function phase36_build_goals_table(
    int $projectId,
    int $year,
    int $month,
    string $contextKey,
    array $sources,
    array $sessionsThisByKey,
    array $sessionsLastByKey
): array {
    $goalNames = phase36_goal_names();
    $excluded = ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'];

    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p36|' . $contextKey . '|goals')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p36|' . $contextKey . '|goals')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $totalsThis = ['sessions' => 0];
    $totalsLast = ['sessions' => 0];
    foreach ($goalNames as $g) {
        $totalsThis[$g] = 0;
        $totalsLast[$g] = 0;
    }

    $rows = [];
    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $label = (string)($sources[$i]['label'] ?? $k);
        $sessT = (int)($sessionsThisByKey[$k] ?? 0);
        $sessL = (int)($sessionsLastByKey[$k] ?? 0);

        $rowThis = ['sessions' => $sessT];
        $rowLast = ['sessions' => $sessL];
        foreach ($goalNames as $g) {
            $rateT = match ($g) {
                'purchase' => $rngThis->float(0.0010, 0.0120),
                'begin_checkout' => $rngThis->float(0.0015, 0.0200),
                'generate_lead' => $rngThis->float(0.0020, 0.0280),
                'sign_up' => $rngThis->float(0.0015, 0.0220),
                default => $rngThis->float(0.0010, 0.0180),
            };
            $rateL = match ($g) {
                'purchase' => $rngLast->float(0.0010, 0.0120),
                'begin_checkout' => $rngLast->float(0.0015, 0.0200),
                'generate_lead' => $rngLast->float(0.0020, 0.0280),
                'sign_up' => $rngLast->float(0.0015, 0.0220),
                default => $rngLast->float(0.0010, 0.0180),
            };
            $cntT = (int)max(0, round($sessT * $rateT));
            $cntL = (int)max(0, round($sessL * $rateL));
            $rowThis[$g] = $cntT;
            $rowLast[$g] = $cntL;
            $totalsThis[$g] += $cntT;
            $totalsLast[$g] += $cntL;
        }

        $totalsThis['sessions'] += $sessT;
        $totalsLast['sessions'] += $sessL;

        $rows[] = [
            'key' => $k,
            'label' => $label,
            'this' => $rowThis,
            'last' => $rowLast,
        ];
    }

    return [
        'excluded_events' => $excluded,
        'goal_names' => $goalNames,
        'totals' => [
            'this' => $totalsThis,
            'last' => $totalsLast,
        ],
        'by_source' => $rows,
    ];
}

function phase3_build_all_visitors_report(
    int $projectId,
    int $year,
    int $month,
    array $thisMonth,
    array $lastYear,
    bool $includeSales
): array {
    [$thisStart, $thisEnd, $lastStart, $lastEnd] = phase3_month_date_ranges_utc($year, $month);
    $period = [
        'this_start' => $thisStart,
        'this_end' => $thisEnd,
        'last_start' => $lastStart,
        'last_end' => $lastEnd,
    ];
    $sources = phase3_all_visitors_sources();

    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|all_visitors_extra')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p3|all_visitors_extra')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);

    $totalUsersThis = phase3_safe_int($vThis['users'] ?? 0, 0);
    $totalUsersLast = phase3_safe_int($vLast['users'] ?? 0, max(0, (int)round($totalUsersThis / 1.12)));
    $totalNewThis = phase3_safe_int($vThis['new_users'] ?? 0, 0);
    $totalNewLast = phase3_safe_int($vLast['new_users'] ?? 0, max(0, (int)round($totalNewThis / 1.12)));
    $totalSessThis = phase3_safe_int($vThis['sessions'] ?? 0, 0);
    $totalSessLast = phase3_safe_int($vLast['sessions'] ?? 0, max(0, (int)round($totalSessThis / 1.12)));

    $weightsThis = [];
    $weightsLast = [];
    foreach ($sources as $src) {
        $k = (string)($src['key'] ?? '');
        // deterministic-ish: each source has its own expected range
        $weightsThis[] = match ($k) {
            'seo' => $rngThis->float(0.22, 0.48),
            'paid_search' => $rngThis->float(0.05, 0.20),
            'direct_unknown' => $rngThis->float(0.10, 0.25),
            'organic_social' => $rngThis->float(0.03, 0.10),
            'pmax' => $rngThis->float(0.02, 0.14),
            'paid_social' => $rngThis->float(0.02, 0.10),
            'referral' => $rngThis->float(0.03, 0.12),
            'email' => $rngThis->float(0.01, 0.06),
            'display' => $rngThis->float(0.01, 0.08),
            'unassigned' => $rngThis->float(0.01, 0.07),
            default => $rngThis->float(0.03, 0.10),
        };
        $weightsLast[] = match ($k) {
            'seo' => $rngLast->float(0.22, 0.48),
            'paid_search' => $rngLast->float(0.05, 0.20),
            'direct_unknown' => $rngLast->float(0.10, 0.25),
            'organic_social' => $rngLast->float(0.03, 0.10),
            'pmax' => $rngLast->float(0.02, 0.14),
            'paid_social' => $rngLast->float(0.02, 0.10),
            'referral' => $rngLast->float(0.03, 0.12),
            'email' => $rngLast->float(0.01, 0.06),
            'display' => $rngLast->float(0.01, 0.08),
            'unassigned' => $rngLast->float(0.01, 0.07),
            default => $rngLast->float(0.03, 0.10),
        };
    }

    $visitsSplit = phase36_build_visits_by_source(
        $projectId,
        $year,
        $month,
        'all',
        $sources,
        $totalUsersThis,
        $totalUsersLast,
        $totalNewThis,
        $totalNewLast,
        $totalSessThis,
        $totalSessLast
    );
    $visitsBySourceRows = (array)($visitsSplit['rows'] ?? []);
    $sessionsThisByKey = is_array($visitsSplit['sessions_this'] ?? null) ? (array)$visitsSplit['sessions_this'] : [];
    $sessionsLastByKey = is_array($visitsSplit['sessions_last'] ?? null) ? (array)$visitsSplit['sessions_last'] : [];

    // Behavior per source (rates/seconds).
    $bThis = (array)($thisMonth['visitor_behavior'] ?? []);
    $bLast = (array)($lastYear['visitor_behavior'] ?? []);
    $baseEngThis = phase3_safe_float($vThis['engagement_rate'] ?? 0.0, 0.55);
    $baseEngLast = phase3_safe_float($vLast['engagement_rate'] ?? 0.0, max(0.0, min(1.0, $baseEngThis - 0.04)));
    $basePpsThis = phase3_safe_float($bThis['pages_per_session'] ?? 0.0, 2.2);
    $basePpsLast = phase3_safe_float($bLast['pages_per_session'] ?? 0.0, 2.1);
    $baseDurThis = phase3_safe_int($bThis['avg_session_duration_sec'] ?? 0, 135);
    $baseDurLast = phase3_safe_int($bLast['avg_session_duration_sec'] ?? 0, 130);

    $behaviorBySourceRows = phase36_build_behavior_by_source(
        $projectId,
        $year,
        $month,
        'all',
        $sources,
        $baseEngThis,
        $baseEngLast,
        $basePpsThis,
        $basePpsLast,
        (int)$baseDurThis,
        (int)$baseDurLast
    );

    // Sales per source (optional).
    $salesBySourceRows = [];
    $salesTotals = [
        'this' => [
            'conversion_rate' => null,
            'purchases' => null,
            'revenue' => null,
        ],
        'last' => [
            'conversion_rate' => null,
            'purchases' => null,
            'revenue' => null,
        ],
    ];
    if ($includeSales) {
        $sThis = is_array($thisMonth['sales'] ?? null) ? (array)$thisMonth['sales'] : [];
        $sLast = is_array($lastYear['sales'] ?? null) ? (array)$lastYear['sales'] : [];
        $totTxnThis = phase3_safe_int($sThis['transactions'] ?? 0, 0);
        $totTxnLast = phase3_safe_int($sLast['transactions'] ?? 0, max(0, (int)round($totTxnThis / 1.10)));
        $totRevThis = (float)phase3_safe_float($sThis['revenue'] ?? 0.0, 0.0);
        $totRevLast = (float)phase3_safe_float($sLast['revenue'] ?? 0.0, max(0.0, $totRevThis / 1.10));

        $salesTotals['this']['purchases'] = $totTxnThis;
        $salesTotals['this']['revenue'] = round($totRevThis, 2);
        $salesTotals['this']['conversion_rate'] = $totalSessThis > 0 ? round($totTxnThis / max(1, $totalSessThis), 4) : 0.0;
        $salesTotals['last']['purchases'] = $totTxnLast;
        $salesTotals['last']['revenue'] = round($totRevLast, 2);
        $salesTotals['last']['conversion_rate'] = $totalSessLast > 0 ? round($totTxnLast / max(1, $totalSessLast), 4) : 0.0;

        $salesBySourceRows = phase36_build_sales_by_source(
            $projectId,
            $year,
            $month,
            'all',
            $sources,
            $includeSales,
            $sessionsThisByKey,
            $sessionsLastByKey,
            $totTxnThis,
            $totTxnLast,
            $totRevThis,
            $totRevLast
        );
    }
    $goalsTable = phase36_build_goals_table(
        $projectId,
        $year,
        $month,
        'all',
        $sources,
        $sessionsThisByKey,
        $sessionsLastByKey
    );

    // Bottom charts (single-period snapshot; no YoY compare).
    $distSeed = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|demographics')) & 0xFFFFFFFF;
    $distRng = new DeterministicRng((int)$distSeed);
    $totalDemo = max(1, (int)round($totalUsersThis * 0.65));

    $mkDist = function (array $labels) use ($distRng, $totalDemo): array {
        $weights = [];
        foreach ($labels as $_) {
            $weights[] = $distRng->float(0.2, 1.2);
        }
        $vals = phase3_split_total_by_weights($totalDemo, $weights);
        $items = [];
        for ($i = 0; $i < count($labels); $i++) {
            $items[] = ['label' => (string)$labels[$i], 'value' => (int)($vals[$i] ?? 0)];
        }
        return $items;
    };

    $demographics = [
        'gender' => $mkDist(['Vyrai', 'Moterys', 'Nežinoma']),
        'browsers' => $mkDist(['Chrome', 'Safari', 'Firefox', 'Edge', 'Kita']),
        'devices' => $mkDist(['Mobilus', 'Stalinis', 'Planšetė']),
        'age' => $mkDist(['18–24', '25–34', '35–44', '45–54', '55–64', '65+', 'Nežinoma']),
    ];

    return [
        'period' => $period,
        'sources' => $sources,
        // Optional for UI: YoY timeseries for "visitors" line chart (users).
        // All-visitors view currently reads timeseries from sections.traffic.all.visits,
        // but segment-based views can use report-local timeseries.
        'timeseries' => phase3_build_timeseries($projectId, $year, $month, 'all_visitors', 'visits', (float)$totalUsersThis, (float)$totalUsersLast),
        'visits' => [
            'totals' => [
                'this' => [
                    'users' => $totalUsersThis,
                    'new_users' => $totalNewThis,
                    'sessions' => $totalSessThis,
                ],
                'last' => [
                    'users' => $totalUsersLast,
                    'new_users' => $totalNewLast,
                    'sessions' => $totalSessLast,
                ],
            ],
            'by_source' => $visitsBySourceRows,
        ],
        'behavior' => [
            'totals' => [
                'this' => [
                    'engagement_rate' => round($baseEngThis, 4),
                    'pages_per_session' => round($basePpsThis, 2),
                    'avg_session_duration_sec' => (int)$baseDurThis,
                ],
                'last' => [
                    'engagement_rate' => round($baseEngLast, 4),
                    'pages_per_session' => round($basePpsLast, 2),
                    'avg_session_duration_sec' => (int)$baseDurLast,
                ],
            ],
            'by_source' => $behaviorBySourceRows,
        ],
        'sales' => [
            'enabled' => $includeSales,
            'totals' => $salesTotals,
            'by_source' => $salesBySourceRows,
        ],
        'goals' => [
            'excluded_events' => is_array($goalsTable['excluded_events'] ?? null) ? (array)$goalsTable['excluded_events'] : ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
            'goal_names' => is_array($goalsTable['goal_names'] ?? null) ? (array)$goalsTable['goal_names'] : [],
            'totals' => is_array($goalsTable['totals'] ?? null) ? (array)$goalsTable['totals'] : ['this' => ['sessions' => $totalSessThis], 'last' => ['sessions' => $totalSessLast]],
            'by_source' => is_array($goalsTable['by_source'] ?? null) ? (array)$goalsTable['by_source'] : [],
        ],
        'demographics' => $demographics,
    ];
}

/**
 * Segment reports reuse the All Visitors UI contract, but with a different "segment_key"
 * and segment-specific "sources" list.
 *
 * Segment keys (required by prompt):
 * - Organic Social
 * - Referral
 * - Email
 * - Paid Social
 */
function phase3_segment_sources(string $segmentKey): array
{
    return match ($segmentKey) {
        'Organic Social' => [
            ['key' => 'facebook', 'label' => 'Facebook'],
            ['key' => 'instagram', 'label' => 'Instagram'],
            ['key' => 'tiktok', 'label' => 'TikTok'],
            ['key' => 'linkedin', 'label' => 'LinkedIn'],
            ['key' => 'pinterest', 'label' => 'Pinterest'],
            ['key' => 'youtube', 'label' => 'YouTube'],
            ['key' => 'other_social', 'label' => 'Other social'],
        ],
        'Referral' => [
            ['key' => 'google.com_ref', 'label' => 'google.com (ref)'],
            ['key' => 'partner1.lt', 'label' => 'partner1.lt'],
            ['key' => 'partner2.com', 'label' => 'partner2.com'],
            ['key' => 'directory.lt', 'label' => 'directory.lt'],
            ['key' => 'other_referrals', 'label' => 'other referrals'],
        ],
        'Email' => [
            ['key' => 'newsletter', 'label' => 'Newsletter'],
            ['key' => 'promo_campaign', 'label' => 'Promo campaign'],
            ['key' => 'automated_flows', 'label' => 'Automated flows'],
            ['key' => 'other_email', 'label' => 'Other email'],
        ],
        'Paid Social' => [
            ['key' => 'facebook_ads', 'label' => 'Facebook Ads'],
            ['key' => 'instagram_ads', 'label' => 'Instagram Ads'],
            ['key' => 'tiktok_ads', 'label' => 'TikTok Ads'],
            ['key' => 'linkedin_ads', 'label' => 'LinkedIn Ads'],
            ['key' => 'other_paid_social', 'label' => 'Other paid social'],
        ],
        default => [
            ['key' => 'other', 'label' => 'Kita'],
        ],
    };
}

function phase3_segment_multiplier(int $projectId, int $year, int $month, string $segmentKey): float
{
    $seed = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3segmul|' . $segmentKey)) & 0xFFFFFFFF;
    $rng = new DeterministicRng((int)$seed);
    return match ($segmentKey) {
        'Organic Social' => $rng->float(0.03, 0.12),
        'Referral' => $rng->float(0.03, 0.14),
        'Email' => $rng->float(0.01, 0.07),
        'Paid Social' => $rng->float(0.02, 0.12),
        default => $rng->float(0.02, 0.12),
    };
}

function phase3_build_segment_report(
    int $projectId,
    int $year,
    int $month,
    string $segmentKey,
    array $thisMonth,
    array $lastYear,
    bool $includeSales
): array {
    [$thisStart, $thisEnd, $lastStart, $lastEnd] = phase3_month_date_ranges_utc($year, $month);
    $period = [
        'this_start' => $thisStart,
        'this_end' => $thisEnd,
        'last_start' => $lastStart,
        'last_end' => $lastEnd,
    ];
    $sources = phase3_segment_sources($segmentKey);

    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|segment|' . $segmentKey)) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p3|segment|' . $segmentKey)) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);

    $mulThis = phase3_segment_multiplier($projectId, $year, $month, $segmentKey);
    $mulLast = phase3_segment_multiplier($projectId, $year - 1, $month, $segmentKey);

    $allUsersThis = phase3_safe_int($vThis['users'] ?? 0, 0);
    $allUsersLast = phase3_safe_int($vLast['users'] ?? 0, max(0, (int)round($allUsersThis / 1.12)));
    $allNewThis = phase3_safe_int($vThis['new_users'] ?? 0, 0);
    $allNewLast = phase3_safe_int($vLast['new_users'] ?? 0, max(0, (int)round($allNewThis / 1.12)));
    $allSessThis = phase3_safe_int($vThis['sessions'] ?? 0, 0);
    $allSessLast = phase3_safe_int($vLast['sessions'] ?? 0, max(0, (int)round($allSessThis / 1.12)));

    $totalUsersThis = (int)max(0, round($allUsersThis * $mulThis));
    $totalUsersLast = (int)max(0, round($allUsersLast * $mulLast));
    $totalNewThis = (int)max(0, round($allNewThis * $mulThis * 0.95));
    $totalNewLast = (int)max(0, round($allNewLast * $mulLast * 0.95));
    $totalSessThis = (int)max(0, round($allSessThis * $mulThis * 1.02));
    $totalSessLast = (int)max(0, round($allSessLast * $mulLast * 1.02));

    $ctx = 'segment|' . $segmentKey;
    $visitsSplit = phase36_build_visits_by_source(
        $projectId,
        $year,
        $month,
        $ctx,
        $sources,
        $totalUsersThis,
        $totalUsersLast,
        $totalNewThis,
        $totalNewLast,
        $totalSessThis,
        $totalSessLast
    );
    $visitsBySourceRows = (array)($visitsSplit['rows'] ?? []);
    $sessionsThisByKey = is_array($visitsSplit['sessions_this'] ?? null) ? (array)$visitsSplit['sessions_this'] : [];
    $sessionsLastByKey = is_array($visitsSplit['sessions_last'] ?? null) ? (array)$visitsSplit['sessions_last'] : [];

    // Behavior per source (rates/seconds).
    $bThis = (array)($thisMonth['visitor_behavior'] ?? []);
    $bLast = (array)($lastYear['visitor_behavior'] ?? []);
    $baseEngThis = phase3_safe_float($vThis['engagement_rate'] ?? 0.0, 0.55);
    $baseEngLast = phase3_safe_float($vLast['engagement_rate'] ?? 0.0, max(0.0, min(1.0, $baseEngThis - 0.04)));
    $basePpsThis = phase3_safe_float($bThis['pages_per_session'] ?? 0.0, 2.2);
    $basePpsLast = phase3_safe_float($bLast['pages_per_session'] ?? 0.0, 2.1);
    $baseDurThis = phase3_safe_int($bThis['avg_session_duration_sec'] ?? 0, 135);
    $baseDurLast = phase3_safe_int($bLast['avg_session_duration_sec'] ?? 0, 130);

    // Segment-specific engagement nudges.
    if ($segmentKey === 'Email') {
        $baseEngThis = min(1.0, $baseEngThis + 0.04);
        $baseEngLast = min(1.0, $baseEngLast + 0.03);
    } elseif ($segmentKey === 'Paid Social') {
        $baseEngThis = max(0.0, $baseEngThis - 0.02);
        $baseEngLast = max(0.0, $baseEngLast - 0.02);
    }

    $behaviorBySourceRows = phase36_build_behavior_by_source(
        $projectId,
        $year,
        $month,
        $ctx,
        $sources,
        $baseEngThis,
        $baseEngLast,
        $basePpsThis,
        $basePpsLast,
        (int)$baseDurThis,
        (int)$baseDurLast
    );

    // Sales per source (optional).
    $salesBySourceRows = [];
    $salesTotals = [
        'this' => [
            'conversion_rate' => null,
            'purchases' => null,
            'revenue' => null,
        ],
        'last' => [
            'conversion_rate' => null,
            'purchases' => null,
            'revenue' => null,
        ],
    ];
    if ($includeSales) {
        $sThis = is_array($thisMonth['sales'] ?? null) ? (array)$thisMonth['sales'] : [];
        $sLast = is_array($lastYear['sales'] ?? null) ? (array)$lastYear['sales'] : [];
        $allTxnThis = phase3_safe_int($sThis['transactions'] ?? 0, 0);
        $allTxnLast = phase3_safe_int($sLast['transactions'] ?? 0, max(0, (int)round($allTxnThis / 1.10)));
        $allRevThis = (float)phase3_safe_float($sThis['revenue'] ?? 0.0, 0.0);
        $allRevLast = (float)phase3_safe_float($sLast['revenue'] ?? 0.0, max(0.0, $allRevThis / 1.10));

        $totTxnThis = (int)max(0, round($allTxnThis * $mulThis));
        $totTxnLast = (int)max(0, round($allTxnLast * $mulLast));
        $totRevThis = (float)max(0.0, $allRevThis * $mulThis);
        $totRevLast = (float)max(0.0, $allRevLast * $mulLast);

        $salesTotals['this']['purchases'] = $totTxnThis;
        $salesTotals['this']['revenue'] = round($totRevThis, 2);
        $salesTotals['this']['conversion_rate'] = $totalSessThis > 0 ? round($totTxnThis / max(1, $totalSessThis), 4) : 0.0;
        $salesTotals['last']['purchases'] = $totTxnLast;
        $salesTotals['last']['revenue'] = round($totRevLast, 2);
        $salesTotals['last']['conversion_rate'] = $totalSessLast > 0 ? round($totTxnLast / max(1, $totalSessLast), 4) : 0.0;

        $salesBySourceRows = phase36_build_sales_by_source(
            $projectId,
            $year,
            $month,
            $ctx,
            $sources,
            $includeSales,
            $sessionsThisByKey,
            $sessionsLastByKey,
            $totTxnThis,
            $totTxnLast,
            $totRevThis,
            $totRevLast
        );
    }
    $goalsTable = phase36_build_goals_table(
        $projectId,
        $year,
        $month,
        $ctx,
        $sources,
        $sessionsThisByKey,
        $sessionsLastByKey
    );

    // Bottom charts: devices/age/gender + optional cities.
    $distSeed = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|segment_demo|' . $segmentKey)) & 0xFFFFFFFF;
    $distRng = new DeterministicRng((int)$distSeed);
    $totalDemo = max(1, (int)round($totalUsersThis * 0.65));
    $mkDist = function (array $labels) use ($distRng, $totalDemo): array {
        $weights = [];
        foreach ($labels as $_) {
            $weights[] = $distRng->float(0.2, 1.2);
        }
        $vals = phase3_split_total_by_weights($totalDemo, $weights);
        $items = [];
        for ($i = 0; $i < count($labels); $i++) {
            $items[] = ['label' => (string)$labels[$i], 'value' => (int)($vals[$i] ?? 0)];
        }
        return $items;
    };

    $cities = [];
    if ($segmentKey !== 'Email') {
        $cities = $mkDist(['Vilnius', 'Kaunas', 'Klaipėda', 'Šiauliai', 'Panevėžys', 'Kita']);
    }

    $demographics = [
        'devices' => $mkDist(['Mobilus', 'Stalinis', 'Planšetė']),
        'age' => $mkDist(['18–24', '25–34', '35–44', '45–54', '55–64', '65+', 'Nežinoma']),
        'gender' => $mkDist(['Vyrai', 'Moterys', 'Nežinoma']),
        'cities' => $cities,
    ];

    return [
        'period' => $period,
        'segment_key' => $segmentKey,
        'sources' => $sources,
        'timeseries' => phase3_build_timeseries(
            $projectId,
            $year,
            $month,
            'segment_' . strtolower(str_replace(' ', '_', $segmentKey)),
            'visits',
            (float)$totalUsersThis,
            (float)$totalUsersLast
        ),
        'visits' => [
            'totals' => [
                'this' => [
                    'users' => $totalUsersThis,
                    'new_users' => $totalNewThis,
                    'sessions' => $totalSessThis,
                ],
                'last' => [
                    'users' => $totalUsersLast,
                    'new_users' => $totalNewLast,
                    'sessions' => $totalSessLast,
                ],
            ],
            'by_source' => $visitsBySourceRows,
        ],
        'behavior' => [
            'totals' => [
                'this' => [
                    'engagement_rate' => round($baseEngThis, 4),
                    'pages_per_session' => round($basePpsThis, 2),
                    'avg_session_duration_sec' => (int)$baseDurThis,
                ],
                'last' => [
                    'engagement_rate' => round($baseEngLast, 4),
                    'pages_per_session' => round($basePpsLast, 2),
                    'avg_session_duration_sec' => (int)$baseDurLast,
                ],
            ],
            'by_source' => $behaviorBySourceRows,
        ],
        'sales' => [
            'enabled' => $includeSales,
            'totals' => $salesTotals,
            'by_source' => $salesBySourceRows,
        ],
        'goals' => [
            'excluded_events' => is_array($goalsTable['excluded_events'] ?? null) ? (array)$goalsTable['excluded_events'] : ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
            'goal_names' => is_array($goalsTable['goal_names'] ?? null) ? (array)$goalsTable['goal_names'] : [],
            'totals' => is_array($goalsTable['totals'] ?? null) ? (array)$goalsTable['totals'] : ['this' => ['sessions' => $totalSessThis], 'last' => ['sessions' => $totalSessLast]],
            'by_source' => is_array($goalsTable['by_source'] ?? null) ? (array)$goalsTable['by_source'] : [],
        ],
        'demographics' => $demographics,
    ];
}

function phase3_build_seo_report(
    int $projectId,
    int $year,
    int $month,
    array $thisMonth,
    array $lastYear,
    bool $includeSales,
    string $workSummary
): array {
    [$thisStart, $thisEnd, $lastStart, $lastEnd] = phase3_month_date_ranges_utc($year, $month);
    $period = [
        'this_start' => $thisStart,
        'this_end' => $thisEnd,
        'last_start' => $lastStart,
        'last_end' => $lastEnd,
    ];
    // Deterministic per period, SEO-only snapshot (no traffic sources).
    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|seo_report')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p3|seo_report')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $mulThis = phase3_preset_multiplier($projectId, $year, $month, 'organic_search');
    $mulLast = phase3_preset_multiplier($projectId, $year - 1, $month, 'organic_search');

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);
    $bThis = (array)($thisMonth['visitor_behavior'] ?? []);
    $bLast = (array)($lastYear['visitor_behavior'] ?? []);

    $usersThis = (int)round(phase3_safe_int($vThis['users'] ?? 0, 0) * $mulThis);
    $usersLast = (int)round(phase3_safe_int($vLast['users'] ?? 0, max(0, (int)round($usersThis / 1.12))) * $mulLast);
    $sessThis = (int)round(phase3_safe_int($vThis['sessions'] ?? 0, 0) * $mulThis * 1.02);
    $sessLast = (int)round(phase3_safe_int($vLast['sessions'] ?? 0, max(0, (int)round($sessThis / 1.12))) * $mulLast * 1.02);

    // GSC metrics (mock)
    $impThis = (int)max(0, round($sessThis * $rngThis->float(12.0, 34.0)));
    $impLast = (int)max(0, round($sessLast * $rngLast->float(12.0, 34.0)));
    $ctrThis = $rngThis->float(0.028, 0.085);
    $ctrLast = $rngLast->float(0.028, 0.085);
    $clickThis = (int)max(0, round($impThis * $ctrThis));
    $clickLast = (int)max(0, round($impLast * $ctrLast));

    $top5This = (int)max(0, round($rngThis->float(6, 45) * (0.85 + 0.5 * $mulThis)));
    $top5Last = (int)max(0, round($rngLast->float(6, 45) * (0.85 + 0.5 * $mulLast)));
    $top10This = $top5This + (int)max(0, round($rngThis->float(5, 40)));
    $top10Last = $top5Last + (int)max(0, round($rngLast->float(5, 40)));
    $top30This = $top10This + (int)max(0, round($rngThis->float(20, 160)));
    $top30Last = $top10Last + (int)max(0, round($rngLast->float(20, 160)));

    $idxThis = (int)max(0, round($rngThis->float(80, 1200) * (0.65 + 0.7 * $mulThis)));
    $idxLast = (int)max(0, round($rngLast->float(80, 1200) * (0.65 + 0.7 * $mulLast)));

    // SEO behavior (mock)
    $engThis = phase3_safe_float($vThis['engagement_rate'] ?? 0.0, 0.58);
    $engLast = phase3_safe_float($vLast['engagement_rate'] ?? 0.0, max(0.0, min(1.0, $engThis - 0.04)));
    // Slight "SEO" uplift in engagement.
    $engThis = max(0.0, min(1.0, $engThis + 0.03));
    $engLast = max(0.0, min(1.0, $engLast + 0.02));
    $ppsThis = phase3_safe_float($bThis['pages_per_session'] ?? 0.0, 2.3) * (0.95 + 0.18 * $mulThis);
    $ppsLast = phase3_safe_float($bLast['pages_per_session'] ?? 0.0, 2.2) * (0.95 + 0.18 * $mulLast);
    $durThis = (int)round(phase3_safe_int($bThis['avg_session_duration_sec'] ?? 0, 150) * (0.94 + 0.22 * $mulThis));
    $durLast = (int)round(phase3_safe_int($bLast['avg_session_duration_sec'] ?? 0, 145) * (0.94 + 0.22 * $mulLast));

    // SEO sales (mock). If sales are disabled for the project, keep null placeholders.
    $salesThis = ['conversion_rate' => null, 'transactions' => null, 'revenue' => null];
    $salesLast = ['conversion_rate' => null, 'transactions' => null, 'revenue' => null];
    if ($includeSales) {
        $sThis = is_array($thisMonth['sales'] ?? null) ? (array)$thisMonth['sales'] : [];
        $sLast = is_array($lastYear['sales'] ?? null) ? (array)$lastYear['sales'] : [];
        $txnThis = (int)round(phase3_safe_int($sThis['transactions'] ?? 0, 0) * $mulThis);
        $txnLast = (int)round(phase3_safe_int($sLast['transactions'] ?? 0, max(0, (int)round($txnThis / 1.10))) * $mulLast);
        $revThis = (float)phase3_safe_float($sThis['revenue'] ?? 0.0, 0.0) * $mulThis;
        $revLast = (float)phase3_safe_float($sLast['revenue'] ?? 0.0, max(0.0, $revThis / 1.10)) * $mulLast;
        $salesThis = [
            'conversion_rate' => $sessThis > 0 ? round($txnThis / $sessThis, 4) : 0.0,
            'transactions' => $txnThis,
            'revenue' => round($revThis, 2),
        ];
        $salesLast = [
            'conversion_rate' => $sessLast > 0 ? round($txnLast / $sessLast, 4) : 0.0,
            'transactions' => $txnLast,
            'revenue' => round($revLast, 2),
        ];
    }

    // SEO goals (mock conversions), exclude GA/GTM boilerplate.
    $excluded = ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'];
    $goalCandidates = ['purchase', 'generate_lead', 'sign_up', 'contact_form_submit', 'begin_checkout'];
    $goalItems = [];
    foreach ($goalCandidates as $name) {
        if (in_array($name, $excluded, true)) {
            continue;
        }
        $rateThis = match ($name) {
            'purchase' => $rngThis->float(0.001, 0.020),
            'begin_checkout' => $rngThis->float(0.003, 0.040),
            'generate_lead' => $rngThis->float(0.004, 0.050),
            'contact_form_submit' => $rngThis->float(0.002, 0.030),
            'sign_up' => $rngThis->float(0.002, 0.035),
            default => $rngThis->float(0.002, 0.020),
        };
        $rateLast = match ($name) {
            'purchase' => $rngLast->float(0.001, 0.020),
            'begin_checkout' => $rngLast->float(0.003, 0.040),
            'generate_lead' => $rngLast->float(0.004, 0.050),
            'contact_form_submit' => $rngLast->float(0.002, 0.030),
            'sign_up' => $rngLast->float(0.002, 0.035),
            default => $rngLast->float(0.002, 0.020),
        };
        $goalItems[] = [
            'name' => $name,
            'this' => (int)max(0, round($sessThis * $rateThis)),
            'last' => (int)max(0, round($sessLast * $rateLast)),
        ];
    }

    // Tracked keywords (mock; sorting/pagination are UI-only in this phase).
    $domain = 'projektas-' . $projectId . '.lt';
    $kwCount = (int)$rngThis->float(8, 18);
    $kwItems = [];
    for ($i = 0; $i < $kwCount; $i++) {
        $pos = (int)max(1, round($rngThis->float(1, 55)));
        $delta = (int)round($rngThis->float(-12, 12));
        $kwItems[] = [
            'keyword' => 'Raktažodis ' . ($i + 1),
            'position' => $pos,
            'delta' => $delta,
            'domain' => $domain,
        ];
    }

    // Bottom charts (single-period snapshot; no YoY).
    $distSeed = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|seo_charts')) & 0xFFFFFFFF;
    $distRng = new DeterministicRng((int)$distSeed);
    $totalDemo = max(1, (int)round($usersThis * 0.55));
    $mkDist = function (array $labels) use ($distRng, $totalDemo): array {
        $weights = [];
        foreach ($labels as $_) {
            $weights[] = $distRng->float(0.2, 1.2);
        }
        $vals = phase3_split_total_by_weights($totalDemo, $weights);
        $items = [];
        for ($i = 0; $i < count($labels); $i++) {
            $items[] = ['label' => (string)$labels[$i], 'value' => (int)($vals[$i] ?? 0)];
        }
        return $items;
    };

    return [
        'period' => $period,
        'work_summary' => $workSummary,
        'gsc' => [
            'clicks' => ['this' => $clickThis, 'last' => $clickLast],
            'impressions' => ['this' => $impThis, 'last' => $impLast],
            'top5_keywords' => ['this' => $top5This, 'last' => $top5Last],
            'top10_keywords' => ['this' => $top10This, 'last' => $top10Last],
            'top30_keywords' => ['this' => $top30This, 'last' => $top30Last],
            'indexed_pages' => ['this' => $idxThis, 'last' => $idxLast],
        ],
        'keywords' => [
            'items' => $kwItems,
        ],
        'behavior' => [
            'this' => [
                'engagement_rate' => round($engThis, 4),
                'pages_per_session' => round($ppsThis, 2),
                'avg_session_duration_sec' => (int)$durThis,
            ],
            'last' => [
                'engagement_rate' => round($engLast, 4),
                'pages_per_session' => round($ppsLast, 2),
                'avg_session_duration_sec' => (int)$durLast,
            ],
        ],
        'sales' => [
            'this' => $salesThis,
            'last' => $salesLast,
        ],
        'goals' => [
            'excluded_events' => $excluded,
            'seo_sessions_this' => $sessThis,
            'seo_sessions_last' => $sessLast,
            'items' => $goalItems,
        ],
        'charts' => [
            'devices' => $mkDist(['Mobilus', 'Stalinis', 'Planšetė']),
            'age' => $mkDist(['18–24', '25–34', '35–44', '45–54', '55–64', '65+', 'Nežinoma']),
            'gender' => $mkDist(['Vyrai', 'Moterys', 'Nežinoma']),
            'cities' => $mkDist(['Vilnius', 'Kaunas', 'Klaipėda', 'Šiauliai', 'Panevėžys', 'Kita']),
        ],
    ];
}

function phase3_build_ppc_report(
    int $projectId,
    int $year,
    int $month,
    array $thisMonth,
    array $lastYear,
    bool $includeSales
): array {
    [$thisStart, $thisEnd, $lastStart, $lastEnd] = phase3_month_date_ranges_utc($year, $month);
    $period = [
        'this_start' => $thisStart,
        'this_end' => $thisEnd,
        'last_start' => $lastStart,
        'last_end' => $lastEnd,
    ];
    $seedThis = (mock_seed_for_period($projectId, $year, $month) ^ crc32('p3|ppc_report')) & 0xFFFFFFFF;
    $seedLast = (mock_seed_for_period($projectId, $year - 1, $month) ^ crc32('p3|ppc_report')) & 0xFFFFFFFF;
    $rngThis = new DeterministicRng((int)$seedThis);
    $rngLast = new DeterministicRng((int)$seedLast);

    $mulThis = phase3_preset_multiplier($projectId, $year, $month, 'paid_search');
    $mulLast = phase3_preset_multiplier($projectId, $year - 1, $month, 'paid_search');

    $vThis = (array)($thisMonth['visitors_overview'] ?? []);
    $vLast = (array)($lastYear['visitors_overview'] ?? []);

    $usersThis = (int)max(0, round(phase3_safe_int($vThis['users'] ?? 0, 0) * $mulThis));
    $usersLast = (int)max(0, round(phase3_safe_int($vLast['users'] ?? 0, max(0, (int)round($usersThis / 1.12))) * $mulLast));

    // Campaigns (Google Ads / Paid Search mock).
    $campaignCount = (int)max(2, round($rngThis->float(4, 9)));
    $campaigns = [];
    $sumUsersThis = 0;
    $sumUsersLast = 0;
    for ($i = 0; $i < $campaignCount; $i++) {
        $name = 'Kampanija ' . ($i + 1);

        $impT = (int)max(0, round($rngThis->float(2500, 42000) * (0.75 + 0.9 * $mulThis)));
        $ctrT = $rngThis->float(0.015, 0.085);
        $clkT = (int)max(0, min($impT, round($impT * $ctrT)));
        $cpcT = $rngThis->float(0.18, 1.65);
        $costT = round($clkT * $cpcT, 2);
        $sessT = (int)max(0, round($clkT * $rngThis->float(0.68, 0.98)));
        $usrT = (int)max(0, min($sessT, round($sessT * $rngThis->float(0.72, 0.96))));
        $convT = (int)max(0, min($clkT, round($sessT * $rngThis->float(0.006, 0.055))));
        $engT = max(0.0, min(1.0, $rngThis->float(0.22, 0.74)));

        $impL = (int)max(0, round($rngLast->float(2500, 42000) * (0.75 + 0.9 * $mulLast)));
        $ctrL = $rngLast->float(0.015, 0.085);
        $clkL = (int)max(0, min($impL, round($impL * $ctrL)));
        $cpcL = $rngLast->float(0.18, 1.65);
        $costL = round($clkL * $cpcL, 2);
        $sessL = (int)max(0, round($clkL * $rngLast->float(0.68, 0.98)));
        $usrL = (int)max(0, min($sessL, round($sessL * $rngLast->float(0.72, 0.96))));
        $convL = (int)max(0, min($clkL, round($sessL * $rngLast->float(0.006, 0.055))));
        $engL = max(0.0, min(1.0, $rngLast->float(0.22, 0.74)));

        $sumUsersThis += $usrT;
        $sumUsersLast += $usrL;

        $campaigns[] = [
            'campaign' => $name,
            'impressions' => $impT,
            'clicks' => $clkT,
            'cost_eur' => $costT,
            'conversions' => $convT,
            'ctr_rate' => ($impT > 0) ? round($clkT / $impT, 6) : 0.0,
            'avg_cpc_eur' => ($clkT > 0) ? round($costT / $clkT, 4) : null,
            'engagement_rate' => round($engT, 6),
            'users' => $usrT,
            'sessions' => $sessT,
            // last-year fields (used in optional comparisons or future UI).
            'last_impressions' => $impL,
            'last_clicks' => $clkL,
            'last_cost_eur' => $costL,
            'last_conversions' => $convL,
            'last_ctr_rate' => ($impL > 0) ? round($clkL / $impL, 6) : 0.0,
            'last_avg_cpc_eur' => ($clkL > 0) ? round($costL / $clkL, 4) : null,
            'last_engagement_rate' => round($engL, 6),
            'last_users' => $usrL,
            'last_sessions' => $sessL,
        ];
    }

    // Timeseries for PPC visits chart (users). If we have campaign user totals, prefer them; otherwise fall back to paid_search preset.
    $baseThisUsers = $sumUsersThis > 0 ? (float)$sumUsersThis : (float)$usersThis;
    $baseLastUsers = $sumUsersLast > 0 ? (float)$sumUsersLast : (float)$usersLast;
    $visitsTimeseries = phase3_build_timeseries($projectId, $year, $month, 'paid_search', 'ppc_visits', $baseThisUsers, $baseLastUsers);

    // Keywords (Paid Search).
    $kwCount = (int)max(6, round($rngThis->float(10, 22)));
    $keywords = [];
    for ($i = 0; $i < $kwCount; $i++) {
        $kw = 'Raktažodis ' . ($i + 1);
        $impT = (int)max(0, round($rngThis->float(450, 12500) * (0.75 + 0.9 * $mulThis)));
        $ctrT = $rngThis->float(0.012, 0.11);
        $clkT = (int)max(0, min($impT, round($impT * $ctrT)));
        $cpcT = $rngThis->float(0.12, 2.20);
        $costT = round($clkT * $cpcT, 2);
        $sessT = (int)max(0, round($clkT * $rngThis->float(0.70, 0.99)));
        $usrT = (int)max(0, min($sessT, round($sessT * $rngThis->float(0.70, 0.96))));
        $convT = (int)max(0, min($clkT, round($sessT * $rngThis->float(0.004, 0.060))));
        $engT = max(0.0, min(1.0, $rngThis->float(0.20, 0.78)));
        $ppsT = round(max(0.8, min(7.0, $rngThis->float(1.2, 4.8))), 2);
        $durT = (int)max(20, min(1200, (int)round($rngThis->float(55, 360))));

        $purchaseT = $includeSales ? (int)max(0, min($convT, round($sessT * $rngThis->float(0.000, 0.020)))) : null;
        $revT = ($includeSales && $purchaseT !== null)
            ? round($purchaseT * $rngThis->float(18, 160), 2)
            : null;

        $keywords[] = [
            'keyword' => $kw,
            'impressions' => $impT,
            'clicks' => $clkT,
            'cost_eur' => $costT,
            'conversions' => $convT,
            'ctr_rate' => ($impT > 0) ? round($clkT / $impT, 6) : 0.0,
            'engagement_rate' => round($engT, 6),
            'users' => $usrT,
            'sessions' => $sessT,
            'pages_per_session' => $ppsT,
            'avg_session_duration_sec' => $durT,
            'purchases' => $purchaseT,
            'revenue_eur' => $revT,
        ];
    }

    // Cities (Paid Search).
    $cityLabels = ['Vilnius', 'Kaunas', 'Klaipėda', 'Šiauliai', 'Panevėžys', 'Alytus', 'Marijampolė', 'Kita'];
    $cityCount = (int)max(4, min(count($cityLabels), round($rngThis->float(6, 9))));
    $cities = [];
    for ($i = 0; $i < $cityCount; $i++) {
        $city = $cityLabels[$i];
        $impT = (int)max(0, round($rngThis->float(900, 22000) * (0.75 + 0.9 * $mulThis)));
        $ctrT = $rngThis->float(0.010, 0.095);
        $clkT = (int)max(0, min($impT, round($impT * $ctrT)));
        $costT = round($clkT * $rngThis->float(0.14, 1.85), 2);
        $convT = (int)max(0, min($clkT, round($clkT * $rngThis->float(0.01, 0.10))));
        $engT = max(0.0, min(1.0, $rngThis->float(0.18, 0.76)));
        $cities[] = [
            'city' => $city,
            'impressions' => $impT,
            'clicks' => $clkT,
            'cost_eur' => $costT,
            'conversions' => $convT,
            'ctr_rate' => ($impT > 0) ? round($clkT / $impT, 6) : 0.0,
            'engagement_rate' => round($engT, 6),
        ];
    }

    // Goals (PPC): keyword + goal rows, exclude GA boilerplate events.
    $excluded = ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'];
    $goalCandidates = ['purchase', 'generate_lead', 'sign_up', 'contact_form_submit', 'begin_checkout'];
    $goalNames = [];
    foreach ($goalCandidates as $g) {
        if (!in_array($g, $excluded, true)) {
            $goalNames[] = $g;
        }
    }
    $goalNames = array_slice($goalNames, 0, 5);

    $goalRows = [];
    foreach ($keywords as $kwRow) {
        $kw = (string)($kwRow['keyword'] ?? '—');
        $sess = (int)($kwRow['sessions'] ?? 0);
        foreach ($goalNames as $g) {
            // Deterministic-ish per keyword + goal using base rng + crc.
            $seed = ($seedThis ^ crc32('ppc_goal|' . $kw . '|' . $g)) & 0xFFFFFFFF;
            $r = new DeterministicRng((int)$seed);
            $rate = match ($g) {
                'purchase' => $r->float(0.000, 0.020),
                'begin_checkout' => $r->float(0.001, 0.035),
                'generate_lead' => $r->float(0.002, 0.045),
                'contact_form_submit' => $r->float(0.001, 0.030),
                'sign_up' => $r->float(0.001, 0.028),
                default => $r->float(0.001, 0.020),
            };
            $cnt = (int)max(0, round($sess * $rate));
            $goalRows[] = [
                'keyword' => $kw,
                'goal' => $g,
                'count' => $cnt,
                'conversion_rate' => ($sess > 0) ? round($cnt / $sess, 6) : 0.0,
            ];
        }
    }

    return [
        'period' => $period,
        'work_summary' => '',
        'visits' => [
            'timeseries' => $visitsTimeseries,
        ],
        'campaigns' => [
            'items' => $campaigns,
        ],
        'keywords' => [
            'items' => $keywords,
        ],
        'cities' => [
            'items' => $cities,
        ],
        'goals' => [
            'excluded_events' => $excluded,
            'items' => $goalRows,
        ],
    ];
}

function upsert_monthly_report(PDO $pdo, int $projectId, int $year, int $month, string $status, array $snapshot): void
{
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON snapshot');
    }

    upsert_monthly_report_json($pdo, $projectId, $year, $month, $status, $json);
}

function upsert_monthly_report_error(PDO $pdo, int $projectId, int $year, int $month): void
{
    upsert_monthly_report_json($pdo, $projectId, $year, $month, 'ERROR', null);
}

function upsert_monthly_report_json(PDO $pdo, int $projectId, int $year, int $month, string $status, ?string $json): void
{
    $allowed = ['READY', 'PARTIAL', 'GENERATING', 'ERROR'];
    if (!in_array($status, $allowed, true)) {
        $status = 'READY';
    }

    // Insert-or-update by unique key (project_id, year, month).
    $sql = "
        INSERT INTO monthly_reports (project_id, year, month, status, generated_at, data_json)
        VALUES (:pid, :y, :m, :status, UTC_TIMESTAMP(), :json)
        ON DUPLICATE KEY UPDATE
          status = :status2,
          generated_at = UTC_TIMESTAMP(),
          data_json = :json2
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':pid' => $projectId,
        ':y' => $year,
        ':m' => $month,
        ':status' => $status,
        ':json' => $json,
        ':status2' => $status,
        ':json2' => $json,
    ]);
}
