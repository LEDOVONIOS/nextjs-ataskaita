<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

require_login();
$pdo = db();
$u = current_user();
$userId = (int)$u['id'];
$role = (string)$u['role'];

// Extra assets for this page (shared-hosting friendly).
$GLOBALS['EXTRA_CSS'] = ['/assets/css/report.css'];
$GLOBALS['EXTRA_BODY_CLASS'] = 'page--report';
$GLOBALS['EXTRA_MAIN_CLASS'] = 'container--wide';

function report_month_date_ranges_utc(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $days = (int)date('t', strtotime($start));
    $end = sprintf('%04d-%02d-%02d', $year, $month, $days);
    $lastStart = sprintf('%04d-%02d-01', $year - 1, $month);
    $lastDays = (int)date('t', strtotime($lastStart));
    $lastEnd = sprintf('%04d-%02d-%02d', $year - 1, $month, $lastDays);
    return [$start, $end, $lastStart, $lastEnd];
}

function report_fetch_row_by_id(PDO $pdo, int $reportId): ?array
{
    $stmt = $pdo->prepare('
        SELECT r.id, r.project_id, r.year, r.month, r.status, r.generated_at, r.data_json,
               p.name AS project_name, p.show_sales_section, p.ga4_property_id, p.gsc_site_url
        FROM monthly_reports r
        INNER JOIN projects p ON p.id = r.project_id
        WHERE r.id = ?
        LIMIT 1
    ');
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    return $row ? (array)$row : null;
}

function report_fetch_row_by_project_period(PDO $pdo, int $projectId, int $year, int $month): ?array
{
    $stmt = $pdo->prepare('
        SELECT r.id, r.project_id, r.year, r.month, r.status, r.generated_at, r.data_json,
               p.name AS project_name, p.show_sales_section, p.ga4_property_id, p.gsc_site_url
        FROM monthly_reports r
        INNER JOIN projects p ON p.id = r.project_id
        WHERE r.project_id = ? AND r.year = ? AND r.month = ?
        LIMIT 1
    ');
    $stmt->execute([$projectId, $year, $month]);
    $row = $stmt->fetch();
    return $row ? (array)$row : null;
}

function report_fetch_latest_ready(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare('
        SELECT r.id, r.project_id, r.year, r.month, r.status, r.generated_at, r.data_json,
               p.name AS project_name, p.show_sales_section, p.ga4_property_id, p.gsc_site_url
        FROM monthly_reports r
        INNER JOIN projects p ON p.id = r.project_id
        WHERE r.project_id = ? AND r.status IN ("READY","PARTIAL")
        ORDER BY r.year DESC, r.month DESC
        LIMIT 1
    ');
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return $row ? (array)$row : null;
}

function report_contract_pct_change(?float $thisValue, ?float $last): ?float
{
    if ($thisValue === null || $last === null) {
        return null;
    }
    if ($last == 0.0) {
        return null;
    }
    return ($thisValue - $last) / $last;
}

function report_contract_make_timeseries(array $labels, array $thisArr, array $lastArr): array
{
    return [
        'labels' => array_values($labels),
        'this' => array_values($thisArr),
        'last' => array_values($lastArr),
    ];
}

function report_contract_make_metric(string $label, mixed $thisValue, mixed $last, string $format, string $unit = ''): array
{
    return [
        'label' => $label,
        'this' => $thisValue,
        'last' => $last,
        'format' => $format,
        'unit' => $unit,
    ];
}

function report_metric_value(mixed $metric, string $which): mixed
{
    if (!is_array($metric)) {
        return null;
    }
    if ($which === 'this') {
        return $metric['this'] ?? null;
    }
    if ($which === 'last') {
        return $metric['last'] ?? null;
    }
    return null;
}

/**
 * Ensure the JS UI contract keys exist even for legacy snapshots.
 * This is a wiring layer only: no layout/styling changes.
 */
function report_ensure_ui_contract(array $reportData): array
{
    $reportData['sections'] = is_array($reportData['sections'] ?? null) ? (array)$reportData['sections'] : [];

    // Ensure traffic line chart path exists (used by report_ui.js for the main visits chart).
    $traffic = is_array($reportData['sections']['traffic'] ?? null) ? (array)$reportData['sections']['traffic'] : [];
    $trafficAll = is_array($traffic['all'] ?? null) ? (array)$traffic['all'] : [];
    $trafficVisits = is_array($trafficAll['visits'] ?? null) ? (array)$trafficAll['visits'] : [];
    $trafficVisitsTs = is_array($trafficVisits['timeseries'] ?? null) ? (array)$trafficVisits['timeseries'] : null;
    $trafficVisitsTotals = is_array($trafficVisits['totals'] ?? null) ? (array)$trafficVisits['totals'] : [];

    // Ensure SEO traffic GA4 paths exist (Phase 3.2 requirement).
    $trafficSeo = is_array($traffic['seo'] ?? null) ? (array)$traffic['seo'] : [];
    $seoVisits = is_array($trafficSeo['visits'] ?? null) ? (array)$trafficSeo['visits'] : [];
    if (!isset($seoVisits['timeseries']) || !is_array($seoVisits['timeseries'])) {
        $seoVisits['timeseries'] = $trafficVisitsTs ?: ['labels' => [], 'this' => [], 'last' => []];
    }
    if (!isset($seoVisits['totals']) || !is_array($seoVisits['totals'])) {
        $seoVisits['totals'] = $trafficVisitsTotals;
    }
    $trafficSeo['visits'] = $seoVisits;
    $traffic['seo'] = $trafficSeo;
    $reportData['sections']['traffic'] = $traffic;

    // Ensure all_visitors_report exists (used by report_ui.js tables/donuts).
    if (!isset($reportData['sections']['all_visitors_report']) || !is_array($reportData['sections']['all_visitors_report'])) {
        $visitsTotals = is_array($trafficVisits['totals'] ?? null) ? (array)$trafficVisits['totals'] : [];
        $beh = is_array($trafficAll['behavior'] ?? null) ? (array)$trafficAll['behavior'] : [];
        $behTotals = is_array($beh['totals'] ?? null) ? (array)$beh['totals'] : [];
        $sales = is_array($trafficAll['sales'] ?? null) ? (array)$trafficAll['sales'] : [];
        $salesTotals = is_array($sales['totals'] ?? null) ? (array)$sales['totals'] : [];
        $goals = is_array($trafficAll['goals'] ?? null) ? (array)$trafficAll['goals'] : [];

        // Convert "metric objects" into the exact scalar totals used by JS.
        $usersThis = report_metric_value($visitsTotals['users'] ?? null, 'this');
        $usersLast = report_metric_value($visitsTotals['users'] ?? null, 'last');
        $newThis = report_metric_value($visitsTotals['new_users'] ?? null, 'this');
        $newLast = report_metric_value($visitsTotals['new_users'] ?? null, 'last');
        $sessThis = report_metric_value($visitsTotals['sessions'] ?? null, 'this');
        $sessLast = report_metric_value($visitsTotals['sessions'] ?? null, 'last');

        $engThis = report_metric_value($behTotals['engagement_rate'] ?? null, 'this');
        $engLast = report_metric_value($behTotals['engagement_rate'] ?? null, 'last');
        $ppsThis = report_metric_value($behTotals['pages_per_session'] ?? null, 'this');
        $ppsLast = report_metric_value($behTotals['pages_per_session'] ?? null, 'last');
        $durThis = report_metric_value($behTotals['avg_session_duration_sec'] ?? null, 'this');
        $durLast = report_metric_value($behTotals['avg_session_duration_sec'] ?? null, 'last');

        $crThis = report_metric_value($salesTotals['conversion_rate'] ?? null, 'this');
        $crLast = report_metric_value($salesTotals['conversion_rate'] ?? null, 'last');
        $txnThis = report_metric_value($salesTotals['purchases'] ?? null, 'this');
        $txnLast = report_metric_value($salesTotals['purchases'] ?? null, 'last');
        $revThis = report_metric_value($salesTotals['revenue'] ?? null, 'this');
        $revLast = report_metric_value($salesTotals['revenue'] ?? null, 'last');

        $includeSales = ((int)($reportData['project']['show_sales_section'] ?? 1)) === 1;

        $reportData['sections']['all_visitors_report'] = [
            'segment_key' => null,
            'sources' => [],
            'timeseries' => $trafficVisitsTs ?: ['labels' => [], 'this' => [], 'last' => []],
            'visits' => [
                'totals' => [
                    'users' => $usersThis,
                    'new_users' => $newThis,
                    'sessions' => $sessThis,
                    'last_users' => $usersLast,
                    'last_new_users' => $newLast,
                    'last_sessions' => $sessLast,
                ],
                'by_source' => [],
            ],
            'behavior' => [
                'totals' => [
                    'engagement_rate' => $engThis,
                    'pages_per_session' => $ppsThis,
                    'avg_session_duration_sec' => $durThis,
                    'last_engagement_rate' => $engLast,
                    'last_pages_per_session' => $ppsLast,
                    'last_avg_session_duration_sec' => $durLast,
                ],
                'by_source' => [],
            ],
            'sales' => [
                'enabled' => $includeSales,
                'totals' => [
                    'conversion_rate' => $crThis,
                    'transactions' => $txnThis,
                    'revenue' => $revThis,
                    'last_conversion_rate' => $crLast,
                    'last_transactions' => $txnLast,
                    'last_revenue' => $revLast,
                ],
                'by_source' => [],
            ],
            'goals' => [
                'excluded_events' => is_array($goals['excluded_events'] ?? null) ? (array)$goals['excluded_events'] : ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
                'goal_names' => [],
                'totals_this' => ['sessions' => (int)($sessThis ?? 0), 'goals' => []],
                'totals_last' => ['sessions' => (int)($sessLast ?? 0), 'goals' => []],
                'by_source' => [],
            ],
            'demographics' => [
                'gender' => [],
                'browsers' => [],
                'devices' => [],
                'age' => [],
                'cities' => [],
            ],
        ];
    }

    // If the legacy snapshot only had all_visitors_report-ish data, ensure the main chart path exists too.
    if (
        (!isset($reportData['sections']['traffic']) || !is_array($reportData['sections']['traffic']))
        || !isset($reportData['sections']['traffic']['all']['visits']['timeseries'])
    ) {
        $reportData['sections']['traffic'] = is_array($reportData['sections']['traffic'] ?? null) ? (array)$reportData['sections']['traffic'] : [];
        $reportData['sections']['traffic']['all'] = is_array($reportData['sections']['traffic']['all'] ?? null) ? (array)$reportData['sections']['traffic']['all'] : [];
        $reportData['sections']['traffic']['all']['visits'] = is_array($reportData['sections']['traffic']['all']['visits'] ?? null) ? (array)$reportData['sections']['traffic']['all']['visits'] : [];
        $ts = is_array($reportData['sections']['all_visitors_report']['timeseries'] ?? null) ? (array)$reportData['sections']['all_visitors_report']['timeseries'] : ['labels' => [], 'this' => [], 'last' => []];
        $reportData['sections']['traffic']['all']['visits']['timeseries'] = $ts;
    }

    return $reportData;
}

/**
 * Normalize any snapshot into the Phase 3 UI contract:
 *   sections.traffic[preset_key][tab_key] = { timeseries, totals }
 * This keeps old saved snapshots viewable even after we switch the UI.
 */
function normalize_report_contract(array $snapshot, array $row): array
{
    $projectId = (int)($row['project_id'] ?? 0);
    $projectName = (string)($row['project_name'] ?? '');
    $year = (int)($row['year'] ?? 0);
    $month = (int)($row['month'] ?? 0);
    $currency = 'EUR';
    $timezone = 'Europe/Vilnius';
    $generatedUtc = (string)($row['generated_at'] ?? '');

    [$thisStart, $thisEnd, $lastStart, $lastEnd] = report_month_date_ranges_utc($year, $month);

    $base = [
        'meta' => [
            'schema_version' => 1,
            'currency' => $currency,
            'timezone' => $timezone,
            'generated_utc' => $generatedUtc,
        ],
        'project' => [
            'id' => $projectId,
            'name' => $projectName,
            'show_sales_section' => ((int)($row['show_sales_section'] ?? 1)) === 1,
        ],
        'period' => [
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
        ],
        'sections' => [
            'traffic' => [],
        ],
    ];

    $enforceTrafficSegments = function (array $in): array {
        $in['sections'] = is_array($in['sections'] ?? null) ? (array)$in['sections'] : [];
        $in['sections']['traffic'] = is_array($in['sections']['traffic'] ?? null) ? (array)$in['sections']['traffic'] : [];

        $ensureTimeseries = function (mixed $ts): array {
            $arr = is_array($ts) ? (array)$ts : [];
            return [
                'labels' => is_array($arr['labels'] ?? null) ? array_values((array)$arr['labels']) : [],
                'this' => is_array($arr['this'] ?? null) ? array_values((array)$arr['this']) : [],
                'last' => is_array($arr['last'] ?? null) ? array_values((array)$arr['last']) : [],
            ];
        };

        $ensureSegment = function (mixed $seg) use ($ensureTimeseries): array {
            $s = is_array($seg) ? (array)$seg : [];
            $visits = is_array($s['visits'] ?? null) ? (array)$s['visits'] : [];
            $behavior = is_array($s['behavior'] ?? null) ? (array)$s['behavior'] : [];
            $sales = is_array($s['sales'] ?? null) ? (array)$s['sales'] : [];
            $goals = is_array($s['goals'] ?? null) ? (array)$s['goals'] : [];

            $visits['timeseries'] = $ensureTimeseries($visits['timeseries'] ?? null);
            $visits['totals'] = is_array($visits['totals'] ?? null) ? (array)$visits['totals'] : [];
            $behavior['timeseries'] = $ensureTimeseries($behavior['timeseries'] ?? null);
            $behavior['totals'] = is_array($behavior['totals'] ?? null) ? (array)$behavior['totals'] : [];
            $sales['timeseries'] = $ensureTimeseries($sales['timeseries'] ?? null);
            $sales['totals'] = is_array($sales['totals'] ?? null) ? (array)$sales['totals'] : [];
            $goals['timeseries'] = $ensureTimeseries($goals['timeseries'] ?? null);
            $goals['totals'] = is_array($goals['totals'] ?? null) ? (array)$goals['totals'] : [];

            $s['visits'] = $visits;
            $s['behavior'] = $behavior;
            $s['sales'] = $sales;
            $s['goals'] = $goals;
            return $s;
        };

        $t = (array)$in['sections']['traffic'];
        $t['all'] = $ensureSegment($t['all'] ?? null);
        $in['sections']['traffic'] = $t;

        // TEMP: SEO GA4 is a clone of "all" until filtering is implemented.
        $in['sections']['traffic']['seo'] = $in['sections']['traffic']['all'];

        // Enforce Phase 3.2 segment-ready shape.
        $pickOrAll = function (string $key) use ($in, $ensureSegment): array {
            $t2 = is_array($in['sections']['traffic'] ?? null) ? (array)$in['sections']['traffic'] : [];
            if (isset($t2[$key]) && is_array($t2[$key])) {
                return $ensureSegment($t2[$key]);
            }
            return $ensureSegment($t2['all'] ?? null);
        };

        $in['sections']['traffic']['ppc'] = $pickOrAll('paid_search');
        $in['sections']['traffic']['social_organic'] = $pickOrAll('social');
        $in['sections']['traffic']['social_paid'] = $pickOrAll('paid_social');
        $in['sections']['traffic']['referral'] = $pickOrAll('referral');
        $in['sections']['traffic']['email'] = $pickOrAll('email');

        return $in;
    };

    // If already in the contract shape, trust it (but ensure required keys exist).
    if (isset($snapshot['sections']) && is_array($snapshot['sections']) && isset($snapshot['sections']['traffic']) && is_array($snapshot['sections']['traffic'])) {
        $base['meta'] = is_array($snapshot['meta'] ?? null) ? (array)$snapshot['meta'] + $base['meta'] : $base['meta'];
        $base['period'] = is_array($snapshot['period'] ?? null) ? (array)$snapshot['period'] + $base['period'] : $base['period'];
        $base['project'] = is_array($snapshot['project'] ?? null) ? (array)$snapshot['project'] + $base['project'] : $base['project'];
        // Preserve any additional section payloads (e.g. Phase 3 "all visitors report" extras).
        $base['sections'] = (array)$snapshot['sections'] + $base['sections'];
        $base['sections']['traffic'] = (array)$snapshot['sections']['traffic'];
        return $enforceTrafficSegments($base);
    }

    // Legacy snapshot shape (pre-Phase-3.1): top-level keys like "visits", "behavior", "sales", "goals", "demographics", "gsc".
    // Map into the current UI contract expected by report_ui.js.
    if (
        !isset($snapshot['sections'])
        && (isset($snapshot['visits']) || isset($snapshot['behavior']) || isset($snapshot['sales']) || isset($snapshot['goals']) || isset($snapshot['gsc']))
    ) {
        $base['meta'] = is_array($snapshot['meta'] ?? null) ? (array)$snapshot['meta'] + $base['meta'] : $base['meta'];
        $base['period'] = is_array($snapshot['period'] ?? null) ? (array)$snapshot['period'] + $base['period'] : $base['period'];
        $base['project'] = is_array($snapshot['project'] ?? null) ? (array)$snapshot['project'] + $base['project'] : $base['project'];

        $visits = is_array($snapshot['visits'] ?? null) ? (array)$snapshot['visits'] : [];
        $behavior = is_array($snapshot['behavior'] ?? null) ? (array)$snapshot['behavior'] : [];
        $sales = is_array($snapshot['sales'] ?? null) ? (array)$snapshot['sales'] : [];
        $goals = is_array($snapshot['goals'] ?? null) ? (array)$snapshot['goals'] : [];
        $demo = is_array($snapshot['demographics'] ?? null) ? (array)$snapshot['demographics'] : [];

        $allTs = null;
        if (isset($snapshot['timeseries']) && is_array($snapshot['timeseries'])) {
            $allTs = (array)$snapshot['timeseries'];
        } elseif (isset($visits['timeseries']) && is_array($visits['timeseries'])) {
            $allTs = (array)$visits['timeseries'];
        }
        if (!$allTs) {
            $allTs = ['labels' => [], 'this' => [], 'last' => []];
        }

        // Build all_visitors_report for JS tables.
        $base['sections']['all_visitors_report'] = [
            'segment_key' => $snapshot['segment_key'] ?? null,
            'sources' => is_array($snapshot['sources'] ?? null) ? (array)$snapshot['sources'] : [],
            'timeseries' => $allTs,
            'visits' => [
                'totals' => is_array($visits['totals'] ?? null) ? (array)$visits['totals'] : [],
                'by_source' => is_array($visits['by_source'] ?? null) ? (array)$visits['by_source'] : [],
            ],
            'behavior' => [
                'totals' => is_array($behavior['totals'] ?? null) ? (array)$behavior['totals'] : [],
                'by_source' => is_array($behavior['by_source'] ?? null) ? (array)$behavior['by_source'] : [],
            ],
            'sales' => [
                'enabled' => isset($sales['enabled']) ? (bool)$sales['enabled'] : ((int)($base['project']['show_sales_section'] ?? 1) === 1),
                'totals' => is_array($sales['totals'] ?? null) ? (array)$sales['totals'] : [],
                'by_source' => is_array($sales['by_source'] ?? null) ? (array)$sales['by_source'] : [],
            ],
            'goals' => [
                'excluded_events' => is_array($goals['excluded_events'] ?? null) ? (array)$goals['excluded_events'] : ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'],
                'goal_names' => is_array($goals['goal_names'] ?? null) ? (array)$goals['goal_names'] : [],
                'totals_this' => is_array($goals['totals_this'] ?? null) ? (array)$goals['totals_this'] : ['sessions' => 0, 'goals' => []],
                'totals_last' => is_array($goals['totals_last'] ?? null) ? (array)$goals['totals_last'] : ['sessions' => 0, 'goals' => []],
                'by_source' => is_array($goals['by_source'] ?? null) ? (array)$goals['by_source'] : [],
            ],
            'demographics' => [
                'gender' => is_array($demo['gender'] ?? null) ? (array)$demo['gender'] : [],
                'browsers' => is_array($demo['browsers'] ?? null) ? (array)$demo['browsers'] : [],
                'devices' => is_array($demo['devices'] ?? null) ? (array)$demo['devices'] : [],
                'age' => is_array($demo['age'] ?? null) ? (array)$demo['age'] : [],
                'cities' => is_array($demo['cities'] ?? null) ? (array)$demo['cities'] : [],
            ],
        ];

        // Provide the main line chart path used by JS: sections.traffic.all.visits.timeseries.
        $vt = is_array($base['sections']['all_visitors_report']['visits']['totals'] ?? null) ? (array)$base['sections']['all_visitors_report']['visits']['totals'] : [];
        $bt = is_array($base['sections']['all_visitors_report']['behavior']['totals'] ?? null) ? (array)$base['sections']['all_visitors_report']['behavior']['totals'] : [];
        $st = is_array($base['sections']['all_visitors_report']['sales']['totals'] ?? null) ? (array)$base['sections']['all_visitors_report']['sales']['totals'] : [];

        $base['sections']['traffic'] = [
            'all' => [
                'visits' => [
                    'timeseries' => $allTs,
                    'totals' => [
                        'users' => report_contract_make_metric('Users', $vt['users'] ?? null, $vt['last_users'] ?? null, 'int'),
                        'new_users' => report_contract_make_metric('New Users', $vt['new_users'] ?? null, $vt['last_new_users'] ?? null, 'int'),
                        'sessions' => report_contract_make_metric('Sessions', $vt['sessions'] ?? null, $vt['last_sessions'] ?? null, 'int'),
                    ],
                ],
                'behavior' => [
                    'timeseries' => ['labels' => [], 'this' => [], 'last' => []],
                    'totals' => [
                        'engagement_rate' => report_contract_make_metric('Engagement rate', $bt['engagement_rate'] ?? null, $bt['last_engagement_rate'] ?? null, 'pct', '%'),
                        'pages_per_session' => report_contract_make_metric('Pages / session', $bt['pages_per_session'] ?? null, $bt['last_pages_per_session'] ?? null, 'float1'),
                        'avg_session_duration_sec' => report_contract_make_metric('Avg session duration', $bt['avg_session_duration_sec'] ?? null, $bt['last_avg_session_duration_sec'] ?? null, 'seconds', 's'),
                    ],
                ],
                'sales' => [
                    'timeseries' => ['labels' => [], 'this' => [], 'last' => []],
                    'totals' => [
                        // Support both old keys: transactions OR purchases.
                        'conversion_rate' => report_contract_make_metric('Conversion rate', $st['conversion_rate'] ?? null, $st['last_conversion_rate'] ?? null, 'pct', '%'),
                        'purchases' => report_contract_make_metric('Purchases', $st['transactions'] ?? ($st['purchases'] ?? null), $st['last_transactions'] ?? ($st['last_purchases'] ?? null), 'int'),
                        'revenue' => report_contract_make_metric('Revenue (EUR)', $st['revenue'] ?? null, $st['last_revenue'] ?? null, 'money', 'EUR'),
                    ],
                ],
                'goals' => [
                    'timeseries' => ['labels' => [], 'this' => [], 'last' => []],
                    'totals' => [],
                ],
            ],
        ];

        // Map SEO payload if present (for SEO tables).
        if (isset($snapshot['gsc']) && is_array($snapshot['gsc'])) {
            $base['sections']['seo_report'] = [
                'work_summary' => (string)($snapshot['work_summary'] ?? ''),
                'gsc' => (array)$snapshot['gsc'],
                'keywords' => is_array($snapshot['keywords'] ?? null) ? (array)$snapshot['keywords'] : ['items' => []],
                'behavior' => is_array($snapshot['seo_behavior'] ?? null) ? (array)$snapshot['seo_behavior'] : ['this' => [], 'last' => []],
                'sales' => is_array($snapshot['seo_sales'] ?? null) ? (array)$snapshot['seo_sales'] : ['this' => [], 'last' => []],
                'goals' => is_array($snapshot['seo_goals'] ?? null) ? (array)$snapshot['seo_goals'] : ['excluded_events' => ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'], 'items' => []],
                'charts' => is_array($snapshot['seo_charts'] ?? null) ? (array)$snapshot['seo_charts'] : [],
            ];
        }

        return $enforceTrafficSegments($base);
    }

    // Backward compatibility: map older Phase 3 snapshot keys (visitors/behavior/sales/goals) into the new contract (preset=all only).
    // Old generator used: visitors.summary, visitors.chart, behavior.summary, etc.
    $trafficAll = [];

    $mapOldSectionToTab = function (string $oldKey, string $tabKey, array $metricMap) use ($snapshot): array {
        $sec = isset($snapshot[$oldKey]) && is_array($snapshot[$oldKey]) ? (array)$snapshot[$oldKey] : [];
        $chart = isset($sec['chart']) && is_array($sec['chart']) ? (array)$sec['chart'] : [];
        $labels = (array)($chart['labels'] ?? []);
        $thisArr = (array)($chart['this_month'] ?? []);
        $lastArr = (array)($chart['last_year'] ?? []);

        $summary = isset($sec['summary']) && is_array($sec['summary']) ? (array)$sec['summary'] : [];
        $thisMain = isset($summary['this_month']) ? (float)$summary['this_month'] : null;
        $lastMain = isset($summary['last_year']) ? (float)$summary['last_year'] : null;

        $totals = [];
        foreach ($metricMap as $k => $def) {
            $totals[$k] = report_contract_make_metric($def['label'], $def['this'] ?? $thisMain, $def['last'] ?? $lastMain, $def['format'], $def['unit'] ?? '');
        }

        return [
            'timeseries' => report_contract_make_timeseries($labels, $thisArr, $lastArr),
            'totals' => $totals,
        ];
    };

    $trafficAll['visits'] = $mapOldSectionToTab('visitors', 'visits', [
        'users' => ['label' => 'Users', 'format' => 'int', 'unit' => ''],
        'new_users' => ['label' => 'New Users', 'format' => 'int', 'unit' => ''],
        'sessions' => ['label' => 'Sessions', 'format' => 'int', 'unit' => ''],
    ]);
    $trafficAll['behavior'] = $mapOldSectionToTab('behavior', 'behavior', [
        'engagement_rate' => ['label' => 'Engagement rate', 'format' => 'pct', 'unit' => '%'],
        'pages_per_session' => ['label' => 'Pages / session', 'format' => 'float1', 'unit' => ''],
        'avg_session_duration_sec' => ['label' => 'Avg session duration', 'format' => 'seconds', 'unit' => 's'],
    ]);
    $trafficAll['sales'] = $mapOldSectionToTab('sales', 'sales', [
        'conversion_rate' => ['label' => 'Conversion rate', 'format' => 'pct', 'unit' => '%'],
        'purchases' => ['label' => 'Purchases', 'format' => 'int', 'unit' => ''],
        'revenue' => ['label' => 'Revenue', 'format' => 'money', 'unit' => 'EUR'],
    ]);
    $trafficAll['goals'] = $mapOldSectionToTab('goals', 'goals', [
        'goal_1' => ['label' => 'Goal: Newsletter', 'format' => 'int', 'unit' => ''],
        'goal_2' => ['label' => 'Goal: Contact form', 'format' => 'int', 'unit' => ''],
        'goal_conversion_rate' => ['label' => 'Goal conversion rate', 'format' => 'pct', 'unit' => '%'],
    ]);

    $base['sections']['traffic'] = [
        'all' => $trafficAll,
    ];
    return $enforceTrafficSegments($base);
}

$reportId = safe_int($_GET['id'] ?? null, 0);
$projectIdParam = safe_int($_GET['project_id'] ?? null, 0);
$yearParam = safe_int($_GET['year'] ?? null, 0);
$monthParam = safe_int($_GET['month'] ?? null, 0);
$hasExplicitPeriod = array_key_exists('year', $_GET) || array_key_exists('month', $_GET);
$validPeriod = ($yearParam >= 2000 && $yearParam <= 2100 && $monthParam >= 1 && $monthParam <= 12);

$loadMode = null;
log_info('Report requested', [
    'id' => $reportId,
    'project_id' => $projectIdParam,
    'year' => $yearParam,
    'month' => $monthParam,
    'has_explicit_period' => $hasExplicitPeriod,
    'valid_period' => $validPeriod,
    'view' => (string)($_GET['view'] ?? 'all'),
]);

$row = null;
if ($reportId > 0) {
    $loadMode = 'by_id';
    $row = report_fetch_row_by_id($pdo, $reportId);
} elseif ($projectIdParam > 0 && $hasExplicitPeriod && $validPeriod) {
    $loadMode = 'by_project_period';
    $row = report_fetch_row_by_project_period($pdo, $projectIdParam, $yearParam, $monthParam);
} elseif ($projectIdParam > 0 && $hasExplicitPeriod && !$validPeriod) {
    $loadMode = 'invalid_period';
    http_response_code(200);
    render_header('Report');
    echo '<div class="card"><p>Report not available for selected month.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
} elseif ($projectIdParam > 0) {
    $loadMode = 'latest_ready_fallback';
    $row = report_fetch_latest_ready($pdo, $projectIdParam);
} else {
    $loadMode = 'missing_selector';
    http_response_code(400);
    render_header('Report');
    echo '<div class="card"><p>Missing report selector. Provide <code>id</code> or <code>project_id</code> (optional <code>year</code>, <code>month</code>).</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

if (!$row) {
    http_response_code(200);
    render_header('Report');
    echo '<div class="card"><p>Report not available for selected month.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$projectId = (int)$row['project_id'];
if (!user_can_access_project($pdo, $userId, $role, $projectId)) {
    http_response_code(403);
    render_header('Report');
    echo '<div class="card"><p>Forbidden.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$status = (string)$row['status'];
$year = (int)$row['year'];
$month = (int)$row['month'];
$projectName = (string)$row['project_name'];
$title = 'Ataskaita: ' . $projectName . ' — ' . $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);

$dataJsonLen = is_string($row['data_json'] ?? null) ? strlen((string)$row['data_json']) : 0;
log_info('Report row loaded', [
    'load_mode' => $loadMode,
    'requested' => [
        'id' => $reportId,
        'project_id' => $projectIdParam,
        'year' => $yearParam,
        'month' => $monthParam,
    ],
    'loaded' => [
        'id' => (int)($row['id'] ?? 0),
        'project_id' => (int)($row['project_id'] ?? 0),
        'year' => $year,
        'month' => $month,
        'status' => $status,
        'generated_at' => (string)($row['generated_at'] ?? ''),
        'data_json_len' => $dataJsonLen,
    ],
]);
if ($loadMode === 'latest_ready_fallback' && $hasExplicitPeriod && $validPeriod) {
    log_warn('Report fallback used instead of explicit period', [
        'requested_year' => $yearParam,
        'requested_month' => $monthParam,
        'loaded_year' => $year,
        'loaded_month' => $month,
    ]);
}

render_header($title);

if ($status !== 'READY' && $status !== 'PARTIAL') {
    echo '<div class="card"><p>Report status: <strong>' . e($status) . '</strong></p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$dataJson = $row['data_json'];
$snapshot = null;
if (is_string($dataJson) && $dataJson !== '') {
    $snapshot = json_decode($dataJson, true);
}
if (!is_array($snapshot)) {
    log_error('Report snapshot JSON decode failed', [
        'report_id' => (int)($row['id'] ?? 0),
        'project_id' => (int)($row['project_id'] ?? 0),
        'year' => (int)($row['year'] ?? 0),
        'month' => (int)($row['month'] ?? 0),
        'status' => (string)($row['status'] ?? ''),
        'data_json_len' => $dataJsonLen,
        'json_error' => json_last_error_msg(),
    ]);
    echo '<div class="card"><p>Report data is invalid/corrupted.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$reportData = normalize_report_contract($snapshot, $row);
$reportData = report_ensure_ui_contract($reportData);
$showSales = ((int)($reportData['project']['show_sales_section'] ?? 1)) === 1;

$segmentViews = [
    'organic_social' => [
        'segment_key' => 'Organic Social',
        'label' => 'Natūralaus srauto iš socialinių tinklų ataskaita',
    ],
    'referral' => [
        'segment_key' => 'Referral',
        'label' => 'Lankytojų iš kitų tinklapių ataskaita',
    ],
    'email' => [
        'segment_key' => 'Email',
        'label' => 'Lankytojų iš el. pašto ataskaita',
    ],
    'paid_social' => [
        'segment_key' => 'Paid Social',
        'label' => 'Mokamos reklamos socialinių tinklų ataskaita',
    ],
];

$view = safe_string($_GET['view'] ?? 'all', 'all');
$allowedViews = array_merge(['all', 'seo', 'ppc'], array_keys($segmentViews));
$view = in_array($view, $allowedViews, true) ? $view : 'all';

$activeSegmentKey = null;
$activeSegmentLabel = null;
if (isset($segmentViews[$view])) {
    $activeSegmentKey = (string)$segmentViews[$view]['segment_key'];
    $activeSegmentLabel = (string)$segmentViews[$view]['label'];
}

// Pass active view/segment to the UI (JS decides what data to render).
$reportData['meta'] = is_array($reportData['meta'] ?? null) ? (array)$reportData['meta'] : [];
$reportData['meta']['active_view'] = $view;
$reportData['meta']['active_segment_key'] = $activeSegmentKey;
$reportData['meta']['active_segment_label'] = $activeSegmentLabel;

// STEP 4: Temporary DEBUG block (ADMIN only).
if ($role === 'ADMIN') {
    $keys = array_keys($reportData);
    $usersSample = null;
    if (isset($reportData['sections']['all_visitors_report']['visits']['totals']['users'])) {
        $usersSample = $reportData['sections']['all_visitors_report']['visits']['totals']['users'];
    }
    $gscClicksThis = null;
    if (isset($reportData['sections']['seo_report']['gsc']['clicks']['this'])) {
        $gscClicksThis = $reportData['sections']['seo_report']['gsc']['clicks']['this'];
    }
    echo '<div class="card">';
    echo '<div class="card__title">DEBUG (admin only)</div>';
    echo '<div class="muted">jsonLen: <strong>' . e((string)$dataJsonLen) . '</strong></div>';
    echo '<div class="muted">REPORT_DATA keys: <code>' . e(implode(', ', $keys)) . '</code></div>';
    echo '<div class="muted">Sample: sections.all_visitors_report.visits.totals.users = <code>' . e(var_export($usersSample, true)) . '</code></div>';
    echo '<div class="muted">Sample: sections.seo_report.gsc.clicks.this = <code>' . e(var_export($gscClicksThis, true)) . '</code></div>';
    echo '</div>';
}

// Note saving is handled via /note.php (AJAX + CSRF), scoped per project/month.
?>

<div class="report3" id="reportApp">
  <div class="report3__top card">
    <div class="report3__topTitle">
      <?php echo e($projectName); ?>
      <span class="report3__pill"><?php echo e(sprintf('%04d-%02d', $year, $month)); ?></span>
    </div>
    <?php
      $dr = (array)($reportData['period']['date_ranges'] ?? []);
      $thisRange = (string)($dr['this_start'] ?? '') . ' – ' . (string)($dr['this_end'] ?? '');
      $lastRange = (string)($dr['last_start'] ?? '') . ' – ' . (string)($dr['last_end'] ?? '');
    ?>
    <div class="report3__topSub">
      <span class="muted">Laikotarpis:</span> <?php echo e($thisRange); ?>
      <span class="muted">· Palyginimas:</span> <?php echo e($lastRange); ?>
    </div>
  </div>

  <div class="report3__layout">
    <aside class="report3__sidebar card" aria-label="Report navigation">
      <div class="report3__sidebarTitle">Ataskaita</div>
      <nav class="report3__presetNav">
        <?php $ridForLinks = (int)($row['id'] ?? 0); ?>
        <a class="report3__presetLink <?php echo $view === 'all' ? 'is-active' : ''; ?>"
           data-report-view="all"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=all'; ?>"
           <?php echo $view === 'all' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Visų tinklalapio lankytojų ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
        <a class="report3__presetLink <?php echo $view === 'organic_social' ? 'is-active' : ''; ?>"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=organic_social'; ?>"
           <?php echo $view === 'organic_social' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Natūralaus srauto iš socialinių tinklų ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
        <a class="report3__presetLink <?php echo $view === 'referral' ? 'is-active' : ''; ?>"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=referral'; ?>"
           <?php echo $view === 'referral' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Lankytojų iš kitų tinklapių ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
        <a class="report3__presetLink <?php echo $view === 'email' ? 'is-active' : ''; ?>"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=email'; ?>"
           <?php echo $view === 'email' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Lankytojų iš el. pašto ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
        <a class="report3__presetLink <?php echo $view === 'paid_social' ? 'is-active' : ''; ?>"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=paid_social'; ?>"
           <?php echo $view === 'paid_social' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Mokamos reklamos socialinių tinklų ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
        <a class="report3__presetLink <?php echo $view === 'ppc' ? 'is-active' : ''; ?>"
           data-report-view="ppc"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=ppc'; ?>"
           <?php echo $view === 'ppc' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Mokamos reklamos paieškoje ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
        <a class="report3__presetLink <?php echo $view === 'seo' ? 'is-active' : ''; ?>"
           data-report-view="seo"
           href="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=seo'; ?>"
           <?php echo $view === 'seo' ? 'aria-current="page"' : ''; ?>>
          <span class="report3__presetLabel">Srauto iš paieškos variklių ataskaita (SEO)</span>
          <span class="report3__presetChevron">›</span>
        </a>
      </nav>
    </aside>

    <div class="report3__content">
      <div class="report3__tabs" role="navigation" aria-label="Quick scroll" id="reportQuickTabs">
        <a class="report3__tab" href="#visits" data-tab="visits">Apsilankymų duomenys</a>
        <a class="report3__tab" href="#behavior" data-tab="behavior">Lankytojų elgesys</a>
        <?php if ($showSales): ?>
          <a class="report3__tab" href="#sales" data-tab="sales">Pardavimų duomenys</a>
        <?php endif; ?>
        <a class="report3__tab" href="#goals" data-tab="goals">Įgyvendinti tikslai</a>
      </div>

      <?php if ($view === 'ppc'): ?>
        <div class="report3__view" data-view="ppc">
          <section class="report3__section report-section" id="ppc-work">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Darbų apžvalga</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="report3__tableTitle">Atlikti mokamos reklamos darbai / komentarai</div>
            <?php
              $ppcSec = is_array($reportData['sections']['ppc_report'] ?? null) ? (array)$reportData['sections']['ppc_report'] : [];
              $ppcWork = (string)($ppcSec['work_summary'] ?? '');
              $ppcWorkTrim = trim($ppcWork);
            ?>
            <?php if ($role === 'ADMIN'): ?>
              <form method="post" action="<?php echo e(url('/report.php')) . '?id=' . e((string)$ridForLinks) . '&view=ppc'; ?>">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="save_ppc_work_summary">
                <div class="form-row">
                  <textarea class="report3__textarea" id="ppc_work_summary" name="ppc_work_summary" rows="8" placeholder="Įveskite atliktus mokamos reklamos darbus / komentarus..."><?php echo e($ppcWork); ?></textarea>
                </div>
                <div class="card__actions">
                  <button class="btn btn--primary" type="submit">Išsaugoti</button>
                </div>
              </form>
            <?php else: ?>
              <div class="report3__notesRead" id="ppc_work_summary_read"><?php echo $ppcWorkTrim !== '' ? nl2br(e($ppcWorkTrim)) : '<span class="muted">—</span>'; ?></div>
            <?php endif; ?>

            <div class="report3__notice">
              Svarbu: pateikiami duomenys gali būti dalinai netikslūs dėl
              Consent Mode v2 ir ribojamo dalies vartotojų duomenų pasiekimo.
            </div>
          </div>
          </section>

        <section class="report3__section report-section" id="ppc-visits">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Google Ads pritrauktų lankytojų duomenys</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
            </div>

            <div class="report3__tableTitle">Apsilankymų duomenys</div>
            <div class="report3__chartWrap">
              <canvas id="chart-ppc-visits-line" height="160"></canvas>
            </div>

            <div class="report3__compare">
              <div class="table-wrap">
                <table class="table table--compact" id="table-ppc-visits"></table>
              </div>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="ppc-campaigns">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Mokamos reklamos kampanijos</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-ppc-campaigns"></table>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="ppc-keywords">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Raktažodžiai (Paid Search)</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-ppc-keywords"></table>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="ppc-cities">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Miestai (Paid Search)</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-ppc-cities"></table>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="ppc-behavior">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Mokamos reklamos lankytojų elgesys</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-ppc-behavior"></table>
            </div>
          </div>
        </section>

        <?php if ($showSales): ?>
        <section class="report3__section report-section" id="ppc-sales">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Pardavimai</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-ppc-sales"></table>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <section class="report3__section report-section" id="ppc-goals">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Įgyvendinti tikslai (PPC)</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-ppc-goals"></table>
            </div>
          </div>
        </section>
        </div>
      <?php elseif ($view === 'seo'): ?>
        <div class="report3__view" data-view="seo">
        <section class="report3__section report-section" id="seo-work">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Darbų apžvalga</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
            </div>
            <div class="report3__tableTitle">Atlikti SEO darbai / komentarai</div>
            <div class="form-row">
              <textarea class="report3__textarea" id="seo_work_summary" rows="8" placeholder="Įveskite atliktus SEO darbus / komentarus..."></textarea>
            </div>
            <div class="card__actions">
              <button class="btn btn--primary" type="button" id="btn-seo-work-save">Išsaugoti</button>
              <span class="muted report3__inlineNote" id="seo-work-save-status" aria-live="polite"></span>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="seo-gsc">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Google Search Console duomenys</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-seo-gsc"></table>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="seo-keywords">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Google pozicijos ir jų pokytis per laikotarpį</div>
              <div class="report3__sectionRange">Stebimi raktažodžiai</div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact table--keywords" id="table-seo-keywords"></table>
            </div>
            <div class="report3__tableTools" aria-label="Pagination (placeholder)">
              <div class="report3__pager">
                <button class="btn btn--small" type="button" disabled>‹</button>
                <span class="muted" id="seo-keywords-page">1</span>
                <button class="btn btn--small" type="button" disabled>›</button>
              </div>
              <div class="muted report3__pagerHint">Rikiavimas ir puslapiavimas bus įgyvendinti vėliau.</div>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="seo-behavior">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Organinių (SEO) lankytojų elgesys</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-seo-behavior"></table>
            </div>
          </div>
        </section>

        <?php if ($showSales): ?>
        <section class="report3__section report-section" id="seo-sales">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Pardavimai iš organinės paieškos</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-seo-sales"></table>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <section class="report3__section report-section" id="seo-goals">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Įgyvendinti tikslai (SEO)</div>
              <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-seo-goals"></table>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="seo-charts">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">SEO pjūviai</div>
              <div class="report3__sectionRange">Vieno laikotarpio pjūvis</div>
            </div>
            <div class="report3__donuts">
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Įrenginiai</div>
                <canvas id="chart-donut-seo-devices" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-seo-devices"></div>
              </div>
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Amžius</div>
                <canvas id="chart-donut-seo-age" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-seo-age"></div>
              </div>
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Lytis</div>
                <canvas id="chart-donut-seo-gender" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-seo-gender"></div>
              </div>
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Miestai</div>
                <canvas id="chart-donut-seo-cities" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-seo-cities"></div>
              </div>
            </div>
          </div>
        </section>
        </div>
      <?php else: ?>
        <div class="report3__view" data-view="all">
        <section class="report3__section report-section" id="visits">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Apsilankymų duomenys</div>
              <div class="report3__sectionRange">
                <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
              </div>
            </div>
            <div class="report3__chartWrap">
              <canvas id="chart-visits-line" height="160"></canvas>
            </div>
            <div class="report3__compare">
              <?php
                $visitsTableTitle = $view === 'all'
                  ? 'Visų lankytojų apsilankymų duomenys'
                  : 'Apsilankymų duomenys: ' . (string)($activeSegmentLabel ?? '—');
              ?>
              <div class="report3__tableTitle"><?php echo e($visitsTableTitle); ?></div>
              <div class="table-wrap">
                <table class="table table--compact" id="table-visits"></table>
              </div>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="behavior">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Lankytojų elgesys</div>
              <div class="report3__sectionRange">
                <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
              </div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-behavior"></table>
            </div>
          </div>
        </section>

        <?php if ($showSales): ?>
        <section class="report3__section report-section" id="sales">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Pardavimų duomenys</div>
              <div class="report3__sectionRange">
                <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
              </div>
            </div>
            <div class="report3__compare">
              <?php
                $salesTableTitle = $view === 'all'
                  ? 'Pardavimų duomenys visiems lankytojams'
                  : 'Pardavimų duomenys: ' . (string)($activeSegmentLabel ?? '—');
              ?>
              <div class="report3__tableTitle"><?php echo e($salesTableTitle); ?></div>
              <div class="table-wrap">
                <table class="table table--compact" id="table-sales"></table>
              </div>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <section class="report3__section report-section" id="goals">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Įgyvendinti tikslai</div>
              <div class="report3__sectionRange">
                <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
              </div>
            </div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-goals"></table>
            </div>
          </div>
        </section>

        <section class="report3__section report-section" id="bottom-charts">
          <div class="card">
            <div class="report3__sectionHead">
              <div class="report3__sectionTitle">Lankytojai</div>
              <div class="report3__sectionRange">Vieno laikotarpio pjūvis</div>
            </div>
            <div class="report3__donuts">
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Lankytojų lytis</div>
                <canvas id="chart-donut-gender" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-gender"></div>
              </div>
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle"><?php echo e($activeSegmentKey ? 'Miestai' : 'Lankytojų naudojamos naršyklės'); ?></div>
                <canvas id="chart-donut-browsers" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-browsers"></div>
              </div>
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Lankytojų įrenginiai</div>
                <canvas id="chart-donut-devices" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-devices"></div>
              </div>
              <div class="report3__donut card card--flat">
                <div class="report3__donutTitle">Lankytojų amžius</div>
                <canvas id="chart-donut-age" height="180"></canvas>
                <div class="report3__donutLegend" id="legend-donut-age"></div>
              </div>
            </div>
          </div>
        </section>
        </div>

        <div class="report3__view" data-view="seo" style="display:none">
          <section class="report3__section report-section" id="seo-work">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">Darbų apžvalga</div>
                <div class="report3__sectionRange"><?php echo e($thisRange); ?></div>
              </div>
              <div class="report3__tableTitle">Atlikti SEO darbai / komentarai</div>
              <div class="form-row">
                <textarea class="report3__textarea" id="seo_work_summary" rows="8" placeholder="Įveskite atliktus SEO darbus / komentarus..."></textarea>
              </div>
              <div class="card__actions">
                <button class="btn btn--primary" type="button" id="btn-seo-work-save">Išsaugoti</button>
                <span class="muted report3__inlineNote" id="seo-work-save-status" aria-live="polite"></span>
              </div>
            </div>
          </section>

          <section class="report3__section report-section" id="seo-gsc">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">Google Search Console duomenys</div>
                <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
              </div>
              <div class="table-wrap">
                <table class="table table--compact" id="table-seo-gsc"></table>
              </div>
            </div>
          </section>

          <section class="report3__section report-section" id="seo-keywords">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">Google pozicijos ir jų pokytis per laikotarpį</div>
                <div class="report3__sectionRange">Stebimi raktažodžiai</div>
              </div>
              <div class="table-wrap">
                <table class="table table--compact table--keywords" id="table-seo-keywords"></table>
              </div>
              <div class="report3__tableTools" aria-label="Pagination (placeholder)">
                <div class="report3__pager">
                  <button class="btn btn--small" type="button" disabled>‹</button>
                  <span class="muted" id="seo-keywords-page">1</span>
                  <button class="btn btn--small" type="button" disabled>›</button>
                </div>
                <div class="muted report3__pagerHint">Rikiavimas ir puslapiavimas bus įgyvendinti vėliau.</div>
              </div>
            </div>
          </section>

          <section class="report3__section report-section" id="seo-behavior">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">Organinių (SEO) lankytojų elgesys</div>
                <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
              </div>
              <div class="table-wrap">
                <table class="table table--compact" id="table-seo-behavior"></table>
              </div>
            </div>
          </section>

          <?php if ($showSales): ?>
          <section class="report3__section report-section" id="seo-sales">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">Pardavimai iš organinės paieškos</div>
                <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
              </div>
              <div class="table-wrap">
                <table class="table table--compact" id="table-seo-sales"></table>
              </div>
            </div>
          </section>
          <?php endif; ?>

          <section class="report3__section report-section" id="seo-goals">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">Įgyvendinti tikslai (SEO)</div>
                <div class="report3__sectionRange"><?php echo e($thisRange); ?> · <?php echo e($lastRange); ?></div>
              </div>
              <div class="table-wrap">
                <table class="table table--compact" id="table-seo-goals"></table>
              </div>
            </div>
          </section>

          <section class="report3__section report-section" id="seo-charts">
            <div class="card">
              <div class="report3__sectionHead">
                <div class="report3__sectionTitle">SEO pjūviai</div>
                <div class="report3__sectionRange">Vieno laikotarpio pjūvis</div>
              </div>
              <div class="report3__donuts">
                <div class="report3__donut card card--flat">
                  <div class="report3__donutTitle">Įrenginiai</div>
                  <canvas id="chart-donut-seo-devices" height="180"></canvas>
                  <div class="report3__donutLegend" id="legend-donut-seo-devices"></div>
                </div>
                <div class="report3__donut card card--flat">
                  <div class="report3__donutTitle">Amžius</div>
                  <canvas id="chart-donut-seo-age" height="180"></canvas>
                  <div class="report3__donutLegend" id="legend-donut-seo-age"></div>
                </div>
                <div class="report3__donut card card--flat">
                  <div class="report3__donutTitle">Lytis</div>
                  <canvas id="chart-donut-seo-gender" height="180"></canvas>
                  <div class="report3__donutLegend" id="legend-donut-seo-gender"></div>
                </div>
                <div class="report3__donut card card--flat">
                  <div class="report3__donutTitle">Miestai</div>
                  <canvas id="chart-donut-seo-cities" height="180"></canvas>
                  <div class="report3__donutLegend" id="legend-donut-seo-cities"></div>
                </div>
              </div>
            </div>
          </section>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__actions">
          <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Back to dashboard</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.REPORT_DATA = <?php echo json_encode(
    $reportData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
// Compatibility alias (case-sensitive globals):
// - Some UI builds read window.REPORT_DATA, others read window.Report_DATA.
// Ensure both point to the same object.
if (window.REPORT_DATA && !window.Report_DATA) window.Report_DATA = window.REPORT_DATA;
if (window.Report_DATA && !window.REPORT_DATA) window.REPORT_DATA = window.Report_DATA;
window.CSRF_TOKEN = <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="/assets/js/report_ui.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/report_ui.js') ?>"></script>

<?php
render_footer();

