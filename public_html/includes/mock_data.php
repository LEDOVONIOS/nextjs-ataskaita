<?php
declare(strict_types=1);

final class DeterministicRng
{
    private int $state;

    public function __construct(int $seed)
    {
        // Ensure non-zero state for xorshift32
        $this->state = ($seed & 0xFFFFFFFF) ?: 0xA341316C;
    }

    private function nextU32(): int
    {
        // xorshift32
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17) & 0xFFFFFFFF;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        $range = $max - $min + 1;
        $val = $this->nextU32();
        return $min + ($val % $range);
    }

    public function float(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }
        $val = $this->nextU32() / 4294967295; // 0..1
        return $min + ($max - $min) * $val;
    }

    public function pick(array $items): mixed
    {
        if (!$items) {
            return null;
        }
        $idx = $this->int(0, count($items) - 1);
        return $items[$idx];
    }
}

function mock_seed_for_period(int $projectId, int $year, int $month): int
{
    $key = $projectId . '|' . $year . '|' . $month . '|phase1';
    $crc = crc32($key);
    // crc32() can return signed int depending on PHP build; normalize to u32.
    return (int)sprintf('%u', $crc);
}

function mock_generate_report_data(int $projectId, int $year, int $month, bool $includeSales): array
{
    $seed = mock_seed_for_period($projectId, $year, $month);
    $rng = new DeterministicRng($seed);

    // Visitors overview
    $users = $rng->int(1800, 45000);
    $sessions = (int)round($users * $rng->float(1.05, 1.55));
    $newUsers = (int)round($users * $rng->float(0.35, 0.75));
    $engagementRate = $rng->float(0.35, 0.78);
    $avgEngagementTimeSec = $rng->int(28, 165);

    // Channels (deterministic weights -> integer totals)
    $channels = [
        ['name' => 'Organic Search', 'w' => $rng->float(0.22, 0.48)],
        ['name' => 'Direct', 'w' => $rng->float(0.12, 0.28)],
        ['name' => 'Paid Search', 'w' => $rng->float(0.05, 0.22)],
        ['name' => 'Referral', 'w' => $rng->float(0.04, 0.12)],
        ['name' => 'Social', 'w' => $rng->float(0.03, 0.10)],
        ['name' => 'Email', 'w' => $rng->float(0.01, 0.06)],
    ];
    $sumW = array_sum(array_map(fn($c) => $c['w'], $channels));
    $remainingUsers = $users;
    $channelRows = [];
    foreach ($channels as $i => $c) {
        $isLast = ($i === count($channels) - 1);
        $share = $c['w'] / $sumW;
        $u = $isLast ? $remainingUsers : (int)floor($users * $share);
        $remainingUsers -= $u;
        $s = (int)round($u * $rng->float(1.05, 1.6));
        $channelRows[] = [
            'channel' => $c['name'],
            'users' => $u,
            'sessions' => $s,
        ];
    }

    // Behavior + top pages
    $pagesPerSession = $rng->float(1.3, 4.6);
    $avgSessionDurationSec = $rng->int(45, 320);
    $pageTemplates = [
        '/', '/pricing', '/blog', '/blog/{slug}', '/features', '/about', '/contact', '/docs', '/case-studies', '/products/{sku}',
    ];
    $topPages = [];
    $pageCount = $rng->int(6, 10);
    for ($i = 0; $i < $pageCount; $i++) {
        $tpl = (string)$rng->pick($pageTemplates);
        $path = $tpl;
        if (str_contains($tpl, '{slug}')) {
            $path = str_replace('{slug}', 'post-' . $rng->int(1, 40), $tpl);
        }
        if (str_contains($tpl, '{sku}')) {
            $path = str_replace('{sku}', (string)$rng->int(1000, 9999), $tpl);
        }
        $views = $rng->int(200, 12000);
        $topPages[] = [
            'path' => $path,
            'views' => $views,
        ];
    }
    usort($topPages, fn($a, $b) => $b['views'] <=> $a['views']);

    // Sales (optional)
    $sales = null;
    if ($includeSales) {
        $transactions = $rng->int(12, 680);
        $aov = $rng->float(22.0, 185.0);
        $revenue = $transactions * $aov;
        $conversionRate = $sessions > 0 ? ($transactions / $sessions) : 0.0;
        $sales = [
            'transactions' => $transactions,
            'revenue' => round($revenue, 2),
            'aov' => round($aov, 2),
            'conversion_rate' => round($conversionRate, 4),
        ];
    }

    // SEO summary
    $impressions = $rng->int(12000, 950000);
    $ctr = $rng->float(0.008, 0.072);
    $clicks = (int)round($impressions * $ctr);
    $avgPosition = $rng->float(4.2, 29.5);
    $queries = [];
    $queryCount = $rng->int(6, 12);
    for ($i = 0; $i < $queryCount; $i++) {
        $queries[] = [
            'query' => 'keyword ' . $rng->int(1, 200),
            'clicks' => $rng->int(5, 1200),
            'impressions' => $rng->int(50, 45000),
        ];
    }
    usort($queries, fn($a, $b) => $b['clicks'] <=> $a['clicks']);

    return [
        'generated' => [
            'year' => $year,
            'month' => $month,
            'seed' => $seed,
        ],
        'visitors_overview' => [
            'users' => $users,
            'new_users' => $newUsers,
            'sessions' => $sessions,
            'engagement_rate' => round($engagementRate, 4),
            'avg_engagement_time_sec' => $avgEngagementTimeSec,
        ],
        'traffic_channels' => $channelRows,
        'visitor_behavior' => [
            'pages_per_session' => round($pagesPerSession, 2),
            'avg_session_duration_sec' => $avgSessionDurationSec,
            'top_pages' => $topPages,
        ],
        'sales' => $sales,
        'seo_summary' => [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => round($ctr, 4),
            'avg_position' => round($avgPosition, 2),
            'top_queries' => $queries,
        ],
    ];
}

