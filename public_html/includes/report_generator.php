<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_data.php';

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

    $meta = [
        'schema_version' => 1,
        'currency' => 'EUR',
        'timezone' => 'Europe/Vilnius',
        'generated_utc' => gmdate('c'),
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

    $presets = [
        'all',
        'organic_search',
        'paid_search',
        'direct',
        'display',
        'social',
        'referral',
        'email',
        'affiliate',
    ];

    $traffic = [];
    foreach ($presets as $presetKey) {
        $traffic[$presetKey] = [
            'visits' => phase3_build_tab_visits($projectId, $year, $month, $presetKey, $thisMonth, $lastYear),
            'behavior' => phase3_build_tab_behavior($projectId, $year, $month, $presetKey, $thisMonth, $lastYear),
            'sales' => $includeSales
                ? phase3_build_tab_sales($projectId, $year, $month, $presetKey, $thisMonth, $lastYear)
                : [
                    'timeseries' => ['labels' => [], 'this' => [], 'last' => []],
                    'totals' => [],
                ],
            'goals' => phase3_build_tab_goals($projectId, $year, $month, $presetKey, $thisMonth, $lastYear),
        ];
    }

    // Phase 3 (Phase 3.1) extra payload for ONE sidebar item: "Visų tinklalapio lankytojų ataskaita" (preset "all").
    // Kept separate from the generic tabs so other sidebar items remain unchanged.
    $allVisitorsExtra = phase3_build_all_visitors_report($projectId, $year, $month, $thisMonth, $lastYear, $includeSales);
    $seoReport = phase3_build_seo_report($projectId, $year, $month, $thisMonth, $lastYear, $includeSales, $workSummary);
    $ppcReport = phase3_build_ppc_report($projectId, $year, $month, $thisMonth, $lastYear, $includeSales);

    return [
        'meta' => $meta,
        'project' => $projectInfo,
        'period' => $period,
        'sections' => [
            'traffic' => $traffic,
            'all_visitors_report' => $allVisitorsExtra,
            'seo_report' => $seoReport,
            'ppc_report' => $ppcReport,
        ],
        'notes' => [
            'work_summary' => $workSummary,
        ],
        'errors' => [],
    ];
}

function phase3_all_visitors_sources(): array
{
    // Must match UI row order requirement.
    return [
        ['key' => 'seo', 'label' => 'SEO'],
        ['key' => 'paid_search', 'label' => 'Mokamos reklamos paieškoje'],
        ['key' => 'direct_unknown', 'label' => 'Tiesiogiai atėję / neatpažinti'],
        ['key' => 'organic_social', 'label' => 'Natūralus srautas iš socialinių tinklų'],
        ['key' => 'pmax', 'label' => 'Google Ads Performance Max / Smart Shopping'],
        ['key' => 'paid_social', 'label' => 'Mokamas socialinių tinklų srautas'],
        ['key' => 'referral', 'label' => 'Lankytojai iš kitų svetainių'],
        ['key' => 'email', 'label' => 'Lankytojai iš el. pašto'],
        ['key' => 'display', 'label' => 'Vaizdinės reklamos srautas'],
        ['key' => 'unassigned', 'label' => 'Nepriskirtas srautas'],
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

function phase3_build_all_visitors_report(
    int $projectId,
    int $year,
    int $month,
    array $thisMonth,
    array $lastYear,
    bool $includeSales
): array {
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

    $usersThisByIdx = phase3_split_total_by_weights($totalUsersThis, $weightsThis);
    $usersLastByIdx = phase3_split_total_by_weights($totalUsersLast, $weightsLast);
    $sessThisByIdx = phase3_split_total_by_weights($totalSessThis, $weightsThis);
    $sessLastByIdx = phase3_split_total_by_weights($totalSessLast, $weightsLast);
    // New users mostly follow users distribution.
    $newThisByIdx = phase3_split_total_by_weights($totalNewThis, $weightsThis);
    $newLastByIdx = phase3_split_total_by_weights($totalNewLast, $weightsLast);

    $visitsBySource = [];
    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $visitsBySource[$k] = [
            'users' => (int)($usersThisByIdx[$i] ?? 0),
            'new_users' => (int)($newThisByIdx[$i] ?? 0),
            'sessions' => (int)($sessThisByIdx[$i] ?? 0),
            'last_users' => (int)($usersLastByIdx[$i] ?? 0),
            'last_new_users' => (int)($newLastByIdx[$i] ?? 0),
            'last_sessions' => (int)($sessLastByIdx[$i] ?? 0),
        ];
    }

    // Behavior per source (rates/seconds).
    $bThis = (array)($thisMonth['visitor_behavior'] ?? []);
    $bLast = (array)($lastYear['visitor_behavior'] ?? []);
    $baseEngThis = phase3_safe_float($vThis['engagement_rate'] ?? 0.0, 0.55);
    $baseEngLast = phase3_safe_float($vLast['engagement_rate'] ?? 0.0, max(0.0, min(1.0, $baseEngThis - 0.04)));
    $basePpsThis = phase3_safe_float($bThis['pages_per_session'] ?? 0.0, 2.2);
    $basePpsLast = phase3_safe_float($bLast['pages_per_session'] ?? 0.0, 2.1);
    $baseDurThis = phase3_safe_int($bThis['avg_session_duration_sec'] ?? 0, 135);
    $baseDurLast = phase3_safe_int($bLast['avg_session_duration_sec'] ?? 0, 130);

    $behaviorBySource = [];
    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $engT = max(0.0, min(1.0, $baseEngThis + $rngThis->float(-0.08, 0.08)));
        $engL = max(0.0, min(1.0, $baseEngLast + $rngLast->float(-0.08, 0.08)));
        $ppsT = max(0.8, min(6.5, $basePpsThis + $rngThis->float(-0.7, 0.8)));
        $ppsL = max(0.8, min(6.5, $basePpsLast + $rngLast->float(-0.7, 0.8)));
        $durT = (int)max(30, min(900, (int)round($baseDurThis + $rngThis->float(-65, 95))));
        $durL = (int)max(30, min(900, (int)round($baseDurLast + $rngLast->float(-65, 95))));
        $behaviorBySource[$k] = [
            'engagement_rate' => round($engT, 4),
            'pages_per_session' => round($ppsT, 2),
            'avg_session_duration_sec' => $durT,
            'last_engagement_rate' => round($engL, 4),
            'last_pages_per_session' => round($ppsL, 2),
            'last_avg_session_duration_sec' => $durL,
        ];
    }

    // Sales per source (optional).
    $salesBySource = [];
    $salesTotals = [
        'conversion_rate' => null,
        'transactions' => null,
        'revenue' => null,
        'last_conversion_rate' => null,
        'last_transactions' => null,
        'last_revenue' => null,
    ];
    if ($includeSales) {
        $sThis = is_array($thisMonth['sales'] ?? null) ? (array)$thisMonth['sales'] : [];
        $sLast = is_array($lastYear['sales'] ?? null) ? (array)$lastYear['sales'] : [];
        $totTxnThis = phase3_safe_int($sThis['transactions'] ?? 0, 0);
        $totTxnLast = phase3_safe_int($sLast['transactions'] ?? 0, max(0, (int)round($totTxnThis / 1.10)));
        $totRevThis = (float)phase3_safe_float($sThis['revenue'] ?? 0.0, 0.0);
        $totRevLast = (float)phase3_safe_float($sLast['revenue'] ?? 0.0, max(0.0, $totRevThis / 1.10));

        $txnThisByIdx = phase3_split_total_by_weights($totTxnThis, $weightsThis);
        $txnLastByIdx = phase3_split_total_by_weights($totTxnLast, $weightsLast);
        // Revenue split tends to correlate with transactions, but not identical.
        $revThisByIdx = phase3_split_total_by_weights((int)round($totRevThis), $weightsThis);
        $revLastByIdx = phase3_split_total_by_weights((int)round($totRevLast), $weightsLast);

        $sumSessThis = max(0, $totalSessThis);
        $sumSessLast = max(0, $totalSessLast);
        $salesTotals['transactions'] = $totTxnThis;
        $salesTotals['revenue'] = round($totRevThis, 2);
        $salesTotals['conversion_rate'] = $sumSessThis > 0 ? round($totTxnThis / $sumSessThis, 4) : 0.0;
        $salesTotals['last_transactions'] = $totTxnLast;
        $salesTotals['last_revenue'] = round($totRevLast, 2);
        $salesTotals['last_conversion_rate'] = $sumSessLast > 0 ? round($totTxnLast / $sumSessLast, 4) : 0.0;

        for ($i = 0; $i < count($sources); $i++) {
            $k = (string)($sources[$i]['key'] ?? (string)$i);
            $sessT = (int)($sessThisByIdx[$i] ?? 0);
            $sessL = (int)($sessLastByIdx[$i] ?? 0);
            $txnT = (int)($txnThisByIdx[$i] ?? 0);
            $txnL = (int)($txnLastByIdx[$i] ?? 0);
            $revT = (float)($revThisByIdx[$i] ?? 0);
            $revL = (float)($revLastByIdx[$i] ?? 0);
            $salesBySource[$k] = [
                'conversion_rate' => $sessT > 0 ? round($txnT / $sessT, 4) : 0.0,
                'transactions' => $txnT,
                'revenue' => round($revT, 2),
                'last_conversion_rate' => $sessL > 0 ? round($txnL / $sessL, 4) : 0.0,
                'last_transactions' => $txnL,
                'last_revenue' => round($revL, 2),
            ];
        }
    }

    // Goals table columns: only "real" goal-like events; exclude GA/GTM boilerplate.
    $excluded = ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'];
    $goalCandidates = ['purchase', 'generate_lead', 'sign_up', 'contact_form_submit', 'begin_checkout'];
    $goalNames = [];
    foreach ($goalCandidates as $g) {
        if (!in_array($g, $excluded, true)) {
            $goalNames[] = $g;
        }
    }
    // Keep list stable but not overly wide.
    $goalNames = array_slice($goalNames, 0, 4);

    $goalsBySource = [];
    $goalsTotalsThis = ['sessions' => $totalSessThis, 'goals' => []];
    $goalsTotalsLast = ['sessions' => $totalSessLast, 'goals' => []];
    foreach ($goalNames as $gn) {
        $goalsTotalsThis['goals'][$gn] = 0;
        $goalsTotalsLast['goals'][$gn] = 0;
    }

    for ($i = 0; $i < count($sources); $i++) {
        $k = (string)($sources[$i]['key'] ?? (string)$i);
        $sessT = (int)($sessThisByIdx[$i] ?? 0);
        $sessL = (int)($sessLastByIdx[$i] ?? 0);

        $srcGoalsT = [];
        $srcGoalsL = [];
        foreach ($goalNames as $gn) {
            // Simple deterministic goal rates by goal type.
            $baseRate = match ($gn) {
                'purchase' => $rngThis->float(0.001, 0.018),
                'begin_checkout' => $rngThis->float(0.002, 0.030),
                'generate_lead' => $rngThis->float(0.003, 0.035),
                'sign_up' => $rngThis->float(0.002, 0.028),
                default => $rngThis->float(0.002, 0.020),
            };
            $baseRateL = match ($gn) {
                'purchase' => $rngLast->float(0.001, 0.018),
                'begin_checkout' => $rngLast->float(0.002, 0.030),
                'generate_lead' => $rngLast->float(0.003, 0.035),
                'sign_up' => $rngLast->float(0.002, 0.028),
                default => $rngLast->float(0.002, 0.020),
            };
            $cntT = (int)max(0, round($sessT * $baseRate));
            $cntL = (int)max(0, round($sessL * $baseRateL));
            $srcGoalsT[$gn] = $cntT;
            $srcGoalsL[$gn] = $cntL;
            $goalsTotalsThis['goals'][$gn] += $cntT;
            $goalsTotalsLast['goals'][$gn] += $cntL;
        }

        $goalsBySource[$k] = [
            'sessions' => $sessT,
            'last_sessions' => $sessL,
            'goals' => $srcGoalsT,
            'last_goals' => $srcGoalsL,
        ];
    }

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
        'sources' => $sources,
        'visits' => [
            'totals' => [
                'users' => $totalUsersThis,
                'new_users' => $totalNewThis,
                'sessions' => $totalSessThis,
                'last_users' => $totalUsersLast,
                'last_new_users' => $totalNewLast,
                'last_sessions' => $totalSessLast,
            ],
            'by_source' => $visitsBySource,
        ],
        'behavior' => [
            'totals' => [
                'engagement_rate' => round($baseEngThis, 4),
                'pages_per_session' => round($basePpsThis, 2),
                'avg_session_duration_sec' => (int)$baseDurThis,
                'last_engagement_rate' => round($baseEngLast, 4),
                'last_pages_per_session' => round($basePpsLast, 2),
                'last_avg_session_duration_sec' => (int)$baseDurLast,
            ],
            'by_source' => $behaviorBySource,
        ],
        'sales' => [
            'enabled' => $includeSales,
            'totals' => $salesTotals,
            'by_source' => $salesBySource,
        ],
        'goals' => [
            'excluded_events' => $excluded,
            'goal_names' => $goalNames,
            'totals_this' => $goalsTotalsThis,
            'totals_last' => $goalsTotalsLast,
            'by_source' => $goalsBySource,
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

