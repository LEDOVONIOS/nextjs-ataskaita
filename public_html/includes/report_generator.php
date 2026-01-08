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

/**
 * Phase 3 canonical channel list (used across all report sections).
 * Keep labels stable (Phase 4 will map GA channels into these buckets).
 */
function phase3_channel_list(): array
{
    return [
        'Paid Search',
        'Direct',
        'Organic Social',
        'Google Ads Performance Max / Smart Shopping',
        'Paid Social',
        'Referral',
        'Email',
        'Display',
    ];
}

/**
 * Deterministic allocation of a total into fixed channel buckets.
 * Returns array of rows: [channel => string, this_month => int, last_year => int, change_pct => ?float]
 */
function phase3_build_channel_breakdown(
    int $projectId,
    int $year,
    int $month,
    int $thisTotal,
    int $lastYearTotal
): array {
    $channels = phase3_channel_list();

    $seedThis = mock_seed_for_period($projectId, $year, $month) ^ 0x93a6b1c3;
    $seedLast = mock_seed_for_period($projectId, $year - 1, $month) ^ 0x93a6b1c3;
    $rngThis = new DeterministicRng($seedThis);
    $rngLast = new DeterministicRng($seedLast);

    // Slightly different weights for this vs last year (but stable/deterministic).
    $weightsThis = [];
    $weightsLast = [];
    foreach ($channels as $c) {
        // Keep plausible ranges per channel bucket.
        $baseThis = match ($c) {
            'Direct' => $rngThis->float(0.12, 0.26),
            'Paid Search' => $rngThis->float(0.06, 0.20),
            'Google Ads Performance Max / Smart Shopping' => $rngThis->float(0.03, 0.15),
            'Organic Social' => $rngThis->float(0.04, 0.12),
            'Paid Social' => $rngThis->float(0.03, 0.11),
            'Referral' => $rngThis->float(0.03, 0.10),
            'Email' => $rngThis->float(0.01, 0.07),
            'Display' => $rngThis->float(0.01, 0.06),
            default => $rngThis->float(0.02, 0.10),
        };
        $baseLast = match ($c) {
            'Direct' => $rngLast->float(0.12, 0.26),
            'Paid Search' => $rngLast->float(0.06, 0.20),
            'Google Ads Performance Max / Smart Shopping' => $rngLast->float(0.03, 0.15),
            'Organic Social' => $rngLast->float(0.04, 0.12),
            'Paid Social' => $rngLast->float(0.03, 0.11),
            'Referral' => $rngLast->float(0.03, 0.10),
            'Email' => $rngLast->float(0.01, 0.07),
            'Display' => $rngLast->float(0.01, 0.06),
            default => $rngLast->float(0.02, 0.10),
        };
        $weightsThis[] = $baseThis;
        $weightsLast[] = $baseLast;
    }

    $sumThis = array_sum($weightsThis) ?: 1.0;
    $sumLast = array_sum($weightsLast) ?: 1.0;

    $remainingThis = $thisTotal;
    $remainingLast = $lastYearTotal;

    $rows = [];
    foreach ($channels as $i => $name) {
        $isLast = ($i === count($channels) - 1);
        $shareThis = $weightsThis[$i] / $sumThis;
        $shareLast = $weightsLast[$i] / $sumLast;

        $vThis = $isLast ? $remainingThis : (int)floor($thisTotal * $shareThis);
        $vLast = $isLast ? $remainingLast : (int)floor($lastYearTotal * $shareLast);
        $remainingThis -= $vThis;
        $remainingLast -= $vLast;

        $rows[] = [
            'channel' => $name,
            'this_month' => max(0, $vThis),
            'last_year' => max(0, $vLast),
            'change_pct' => pct_change((float)$vThis, (float)$vLast),
        ];
    }

    // Prepend "All visitors" aggregate row (required by Phase 3 structure).
    array_unshift($rows, [
        'channel' => 'All visitors',
        'this_month' => $thisTotal,
        'last_year' => $lastYearTotal,
        'change_pct' => pct_change((float)$thisTotal, (float)$lastYearTotal),
    ]);

    return $rows;
}

/**
 * Build a simple day-of-month line chart (this month vs last year same month).
 * Returns: ['labels'=>[...], 'this_month'=>[...], 'last_year'=>[...], 'label_this_month'=>..., 'label_last_year'=>...]
 */
function phase3_build_line_chart(
    int $projectId,
    int $year,
    int $month,
    float $thisTotal,
    float $lastYearTotal,
    string $labelThis,
    string $labelLast
): array {
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $days = (int)date('t', strtotime($monthStart));

    $seedThis = mock_seed_for_period($projectId, $year, $month) ^ 0x1e2d3c4b;
    $seedLast = mock_seed_for_period($projectId, $year - 1, $month) ^ 0x1e2d3c4b;
    $rngThis = new DeterministicRng($seedThis);
    $rngLast = new DeterministicRng($seedLast);

    $labels = [];
    $seriesThis = [];
    $seriesLast = [];

    // If this looks like a rate (0..1), generate daily values around the mean (no "sum-to-total" constraint).
    $isRate = ($thisTotal >= 0.0 && $thisTotal <= 1.0 && $lastYearTotal >= 0.0 && $lastYearTotal <= 1.0);

    // For count-like series, distribute totals into daily values with mild seasonality.
    $remainingThis = $thisTotal;
    $remainingLast = $lastYearTotal;
    for ($d = 1; $d <= $days; $d++) {
        $labels[] = (string)$d;
        $isLast = ($d === $days);

        $season = 0.85 + 0.3 * sin(($d / max(1, $days)) * pi() * 2);
        if ($isRate) {
            $nThis = max(0.0, min(1.0, $thisTotal * $season * $rngThis->float(0.85, 1.15)));
            $nLast = max(0.0, min(1.0, $lastYearTotal * $season * $rngLast->float(0.85, 1.15)));
        } else {
            $nThis = $isLast ? $remainingThis : max(0.0, ($thisTotal / max(1, $days)) * $season * $rngThis->float(0.7, 1.3));
            $nLast = $isLast ? $remainingLast : max(0.0, ($lastYearTotal / max(1, $days)) * $season * $rngLast->float(0.7, 1.3));
        }

        // Keep integer-like series for counts, but allow decimals for rate-ish metrics.
        $seriesThis[] = $nThis;
        $seriesLast[] = $nLast;

        if (!$isRate) {
            $remainingThis -= $nThis;
            $remainingLast -= $nLast;
        }
    }

    return [
        'labels' => $labels,
        'this_month' => $seriesThis,
        'last_year' => $seriesLast,
        'label_this_month' => $labelThis,
        'label_last_year' => $labelLast,
    ];
}

function phase3_build_section(
    int $projectId,
    int $year,
    int $month,
    string $title,
    string $metricLabel,
    string $metricFormat,
    float $thisValue,
    float $lastYearValue,
    array $channels
): array {
    $labelThis = sprintf('%04d-%02d', $year, $month);
    $labelLast = sprintf('%04d-%02d', $year - 1, $month);
    return [
        'title' => $title,
        'metric' => [
            'label' => $metricLabel,
            'format' => $metricFormat, // 'int' | 'money' | 'pct' | 'seconds'
        ],
        'summary' => [
            'change_pct' => pct_change($thisValue, $lastYearValue),
            'this_month' => $thisValue,
            'last_year' => $lastYearValue,
        ],
        'chart' => phase3_build_line_chart(
            $projectId,
            $year,
            $month,
            $thisValue,
            $lastYearValue,
            $labelThis,
            $labelLast
        ),
        'channels' => $channels,
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

    // Phase 3: structure is FINAL, data can stay MOCK. No Google APIs in Phase 3.
    $errors = [];
    $reportStatus = 'READY';

    $thisMonth = mock_generate_report_data($projectId, $year, $month, $includeSales);
    $lastYear = mock_generate_report_data($projectId, $year - 1, $month, $includeSales);

    $thisVisitors = (array)($thisMonth['visitors_overview'] ?? []);
    $lastVisitors = (array)($lastYear['visitors_overview'] ?? []);
    $usersThis = phase3_safe_int($thisVisitors['users'] ?? 0, 0);
    $usersLast = phase3_safe_int($lastVisitors['users'] ?? 0, max(0, (int)round($usersThis / 1.12)));

    $sessionsThis = phase3_safe_int($thisVisitors['sessions'] ?? 0, 0);
    $sessionsLast = phase3_safe_int($lastVisitors['sessions'] ?? 0, max(0, (int)round($sessionsThis / 1.12)));

    $engRateThis = phase3_safe_float($thisVisitors['engagement_rate'] ?? 0.0, 0.0);
    $engRateLast = phase3_safe_float($lastVisitors['engagement_rate'] ?? 0.0, max(0.0, min(1.0, $engRateThis - 0.04)));

    $salesThis = is_array($thisMonth['sales'] ?? null) ? (array)$thisMonth['sales'] : null;
    $salesLast = is_array($lastYear['sales'] ?? null) ? (array)$lastYear['sales'] : null;
    $revThis = $includeSales ? phase3_safe_float($salesThis['revenue'] ?? 0.0, 0.0) : 0.0;
    $revLast = $includeSales ? phase3_safe_float($salesLast['revenue'] ?? 0.0, max(0.0, $revThis / 1.10)) : 0.0;

    $seoThis = (array)($thisMonth['seo_summary'] ?? []);
    $seoLast = (array)($lastYear['seo_summary'] ?? []);
    $clicksThis = (float)phase3_safe_int($seoThis['clicks'] ?? 0, 0);
    $clicksLast = (float)phase3_safe_int($seoLast['clicks'] ?? 0, max(0, (int)round($clicksThis / 1.15)));

    // Goals: Phase 3 is MOCK but structure is final. Tie goal completions to sessions.
    $seedGoalsThis = mock_seed_for_period($projectId, $year, $month) ^ 0x0f0e0d0c;
    $seedGoalsLast = mock_seed_for_period($projectId, $year - 1, $month) ^ 0x0f0e0d0c;
    $rngGoalsThis = new DeterministicRng($seedGoalsThis);
    $rngGoalsLast = new DeterministicRng($seedGoalsLast);
    $goalRateThis = $rngGoalsThis->float(0.008, 0.045);
    $goalRateLast = $rngGoalsLast->float(0.008, 0.045);
    $goalsThis = (float)max(0, (int)round($sessionsThis * $goalRateThis));
    $goalsLast = (float)max(0, (int)round($sessionsLast * $goalRateLast));

    $meta = [
        'generatedAt' => gmdate('c'),
        'schemaVersion' => 'phase3',
        'mode' => 'MOCK',
        'reportStatus' => $reportStatus,
        'project' => [
            'id' => $projectId,
            'name' => (string)$project['name'],
            'showSalesSection' => $includeSales,
        ],
        'period' => [
            'year' => $year,
            'month' => $month,
        ],
    ];

    $visitorsChannels = phase3_build_channel_breakdown($projectId, $year, $month, $usersThis, $usersLast);
    $behaviorChannels = phase3_build_channel_breakdown($projectId, $year, $month, $sessionsThis, $sessionsLast);
    $salesChannels = phase3_build_channel_breakdown($projectId, $year, $month, (int)round($revThis), (int)round($revLast));
    $goalsChannels = phase3_build_channel_breakdown($projectId, $year, $month, (int)round($goalsThis), (int)round($goalsLast));
    $seoChannels = phase3_build_channel_breakdown($projectId, $year, $month, (int)round($clicksThis), (int)round($clicksLast));

    $snapshot = [
        'meta' => $meta,
        'overview' => [
            'title' => 'Overview',
            'kpis' => [
                ['label' => 'Users', 'value' => $usersThis],
                ['label' => 'Sessions', 'value' => $sessionsThis],
                ['label' => 'Engagement rate', 'value' => $engRateThis],
                ['label' => 'Revenue', 'value' => $includeSales ? $revThis : null],
            ],
        ],
        'visitors' => phase3_build_section(
            $projectId,
            $year,
            $month,
            'Apsilankymų duomenys',
            'Users',
            'int',
            (float)$usersThis,
            (float)$usersLast,
            $visitorsChannels
        ),
        'behavior' => phase3_build_section(
            $projectId,
            $year,
            $month,
            'Lankytojų elgesys',
            'Engagement rate',
            'pct',
            $engRateThis,
            $engRateLast,
            $behaviorChannels
        ),
        'sales' => $includeSales
            ? phase3_build_section(
                $projectId,
                $year,
                $month,
                'Pardavimų duomenys',
                'Revenue',
                'money',
                $revThis,
                $revLast,
                $salesChannels
            )
            : [
                'title' => 'Pardavimų duomenys',
                'visible' => false,
                'metric' => ['label' => 'Revenue', 'format' => 'money'],
                'summary' => ['change_pct' => null, 'this_month' => null, 'last_year' => null],
                'chart' => ['labels' => [], 'this_month' => [], 'last_year' => [], 'label_this_month' => '', 'label_last_year' => ''],
                'channels' => [],
            ],
        'goals' => phase3_build_section(
            $projectId,
            $year,
            $month,
            'Įgyvendinti tikslai',
            'Goal completions',
            'int',
            $goalsThis,
            $goalsLast,
            $goalsChannels
        ),
        'seo' => phase3_build_section(
            $projectId,
            $year,
            $month,
            'SEO',
            'Clicks',
            'int',
            $clicksThis,
            $clicksLast,
            $seoChannels
        ),
        'notes' => [
            'work_summary' => $workSummary,
        ],
        'errors' => $errors,
    ];

    // Ensure all required top-level keys exist (stable schema contract).
    foreach (['meta', 'overview', 'visitors', 'behavior', 'sales', 'goals', 'seo', 'notes', 'errors'] as $k) {
        if (!array_key_exists($k, $snapshot)) {
            $snapshot[$k] = null;
        }
    }

    return $snapshot;
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

