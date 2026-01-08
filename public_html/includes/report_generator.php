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

    return [
        'meta' => $meta,
        'project' => $projectInfo,
        'period' => $period,
        'sections' => [
            'traffic' => $traffic,
        ],
        'notes' => [
            'work_summary' => $workSummary,
        ],
        'errors' => [],
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

