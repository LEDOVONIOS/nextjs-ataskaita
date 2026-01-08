<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_auth.php';

const GA4_SCOPE_READONLY = 'https://www.googleapis.com/auth/analytics.readonly';

function ga4_http_post_json(string $url, string $accessToken, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'Failed to encode JSON payload'];
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 25,
        ]);
        $respBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'http' => $status, 'body' => '', 'error' => $err ?: ('cURL error ' . $errno)];
        }
        return ['ok' => true, 'http' => $status, 'body' => is_string($respBody) ? $respBody : '', 'error' => ''];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);
    $respBody = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
                break;
            }
        }
    }
    return ['ok' => true, 'http' => $status, 'body' => is_string($respBody) ? $respBody : '', 'error' => ''];
}

/**
 * Raw GA4 Data API runReport wrapper.
 */
function ga4_run_report(
    string $propertyId,
    string $startDate,
    string $endDate,
    array $metrics,
    array $dimensions = [],
    ?array $dimensionFilter = null
): array {
    $propertyId = trim($propertyId);
    if ($propertyId === '' || preg_match('/^\d+$/', $propertyId) !== 1) {
        return [
            'ok' => false,
            'error' => ['message' => 'Invalid GA4 property ID (digits only).', 'details' => ['propertyId' => $propertyId]],
        ];
    }

    $tok = get_google_access_token(GA4_SCOPE_READONLY);
    if (!$tok['ok']) {
        return $tok;
    }
    $accessToken = (string)$tok['access_token'];

    $metricObjs = array_map(fn($m) => ['name' => (string)$m], $metrics);
    $dimensionObjs = array_map(fn($d) => ['name' => (string)$d], $dimensions);

    $payload = [
        'dateRanges' => [[
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]],
        'metrics' => $metricObjs,
    ];
    if ($dimensionObjs) {
        $payload['dimensions'] = $dimensionObjs;
    }
    if (is_array($dimensionFilter)) {
        $payload['dimensionFilter'] = $dimensionFilter;
    }

    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport';
    $resp = ga4_http_post_json($url, $accessToken, $payload);
    if (!$resp['ok']) {
        return [
            'ok' => false,
            'error' => [
                'message' => 'GA4 API request failed.',
                'details' => ['error' => $resp['error'] ?? 'unknown', 'http' => $resp['http'] ?? 0],
            ],
        ];
    }

    $http = (int)$resp['http'];
    $body = (string)$resp['body'];
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'error' => ['message' => 'GA4 API response was not valid JSON.', 'details' => ['http' => $http]],
        ];
    }

    if ($http < 200 || $http >= 300) {
        $msg = $json['error']['message'] ?? 'GA4 API returned an error.';
        return [
            'ok' => false,
            'error' => [
                'message' => (string)$msg,
                'details' => ['http' => $http, 'status' => $json['error']['status'] ?? null],
            ],
        ];
    }

    return ['ok' => true, 'data' => $json];
}

function ga4_format_date_yyyymmdd(string $yyyymmdd): string
{
    $s = preg_replace('/\D+/', '', $yyyymmdd);
    if (!is_string($s) || strlen($s) !== 8) {
        return $yyyymmdd;
    }
    return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
}

function ga4_parse_metric_value(?string $v): float
{
    if ($v === null) {
        return 0.0;
    }
    $v = trim($v);
    if ($v === '') {
        return 0.0;
    }
    return (float)$v;
}

/**
 * Phase 2: Visitors Overview (monthly totals + daily series).
 * Returns:
 *   ['ok'=>true, 'totals'=>[...], 'daily'=>[...]]
 */
function ga4_get_visitors_overview(string $propertyId, string $startDate, string $endDate): array
{
    $totalsMetrics = [
        'totalUsers',
        'newUsers',
        'sessions',
        'engagementRate',
        'averageSessionDuration',
        'screenPageViewsPerSession',
    ];

    $totalsRes = ga4_run_report($propertyId, $startDate, $endDate, $totalsMetrics, []);
    $usedPageViewsFallback = false;
    if (!$totalsRes['ok']) {
        $msg = (string)($totalsRes['error']['message'] ?? '');
        if (str_contains($msg, 'screenPageViewsPerSession')) {
            // Retry with screenPageViews and compute pages/session.
            $usedPageViewsFallback = true;
            $totalsMetrics = [
                'totalUsers',
                'newUsers',
                'sessions',
                'engagementRate',
                'averageSessionDuration',
                'screenPageViews',
            ];
            $totalsRes = ga4_run_report($propertyId, $startDate, $endDate, $totalsMetrics, []);
        }
    }
    if (!$totalsRes['ok']) {
        return $totalsRes;
    }

    $totalsData = (array)$totalsRes['data'];
    $row = (array)($totalsData['rows'][0]['metricValues'] ?? []);
    $values = array_map(fn($mv) => (string)($mv['value'] ?? ''), $row);

    // Map values based on totalsMetrics ordering.
    $map = [];
    foreach ($totalsMetrics as $i => $name) {
        $map[$name] = $values[$i] ?? '0';
    }

    $users = (int)round(ga4_parse_metric_value($map['totalUsers'] ?? '0'));
    $newUsers = (int)round(ga4_parse_metric_value($map['newUsers'] ?? '0'));
    $sessions = (int)round(ga4_parse_metric_value($map['sessions'] ?? '0'));
    $engagementRate = ga4_parse_metric_value($map['engagementRate'] ?? '0');
    $avgSessionDurationSec = (int)round(ga4_parse_metric_value($map['averageSessionDuration'] ?? '0'));

    $pagesPerSession = 0.0;
    if (!$usedPageViewsFallback) {
        $pagesPerSession = ga4_parse_metric_value($map['screenPageViewsPerSession'] ?? '0');
    } else {
        $pageViews = ga4_parse_metric_value($map['screenPageViews'] ?? '0');
        $pagesPerSession = $sessions > 0 ? ($pageViews / $sessions) : 0.0;
    }

    // Daily series (users + sessions)
    $dailyRes = ga4_run_report($propertyId, $startDate, $endDate, ['totalUsers', 'sessions'], ['date']);
    if (!$dailyRes['ok']) {
        return $dailyRes;
    }
    $dailyData = (array)$dailyRes['data'];
    $rows = (array)($dailyData['rows'] ?? []);
    $daily = [];
    foreach ($rows as $r) {
        $dimVals = (array)($r['dimensionValues'] ?? []);
        $metVals = (array)($r['metricValues'] ?? []);
        $dateRaw = (string)($dimVals[0]['value'] ?? '');
        $usersV = (string)($metVals[0]['value'] ?? '0');
        $sessionsV = (string)($metVals[1]['value'] ?? '0');
        $daily[] = [
            'date' => ga4_format_date_yyyymmdd($dateRaw),
            'users' => (int)round(ga4_parse_metric_value($usersV)),
            'sessions' => (int)round(ga4_parse_metric_value($sessionsV)),
        ];
    }

    return [
        'ok' => true,
        'totals' => [
            'users' => $users,
            'new_users' => $newUsers,
            'sessions' => $sessions,
            'engagement_rate' => $engagementRate,
            'avg_session_duration_sec' => $avgSessionDurationSec,
            'pages_per_session' => round($pagesPerSession, 4),
        ],
        'daily' => $daily,
    ];
}

function ga4_dimension_filter_exact(string $fieldName, string $value): array
{
    return [
        'filter' => [
            'fieldName' => $fieldName,
            'stringFilter' => [
                'matchType' => 'EXACT',
                'value' => $value,
                'caseSensitive' => false,
            ],
        ],
    ];
}

/**
 * Channel-group summary table for a month.
 * Returns: ['ok'=>true, 'rows'=>[ ['channel'=>'Organic Search', 'totals'=>[...] ], ... ] ]
 */
function ga4_get_channel_groups_summary(string $propertyId, string $startDate, string $endDate): array
{
    $metrics = [
        'totalUsers',
        'newUsers',
        'sessions',
        'engagementRate',
        'averageSessionDuration',
        'screenPageViewsPerSession',
    ];

    $res = ga4_run_report($propertyId, $startDate, $endDate, $metrics, ['sessionDefaultChannelGroup']);
    $usedPageViewsFallback = false;
    if (!$res['ok']) {
        $msg = (string)($res['error']['message'] ?? '');
        if (str_contains($msg, 'screenPageViewsPerSession')) {
            $usedPageViewsFallback = true;
            $metrics = [
                'totalUsers',
                'newUsers',
                'sessions',
                'engagementRate',
                'averageSessionDuration',
                'screenPageViews',
            ];
            $res = ga4_run_report($propertyId, $startDate, $endDate, $metrics, ['sessionDefaultChannelGroup']);
        }
    }
    if (!$res['ok']) {
        return $res;
    }

    $data = (array)$res['data'];
    $rows = (array)($data['rows'] ?? []);
    $out = [];
    foreach ($rows as $r) {
        $dimVals = (array)($r['dimensionValues'] ?? []);
        $metVals = (array)($r['metricValues'] ?? []);
        $channel = (string)($dimVals[0]['value'] ?? '');
        if ($channel === '') {
            continue;
        }
        $vals = array_map(fn($mv) => (string)($mv['value'] ?? ''), $metVals);
        $map = [];
        foreach ($metrics as $i => $name) {
            $map[$name] = $vals[$i] ?? '0';
        }

        $users = (int)round(ga4_parse_metric_value($map['totalUsers'] ?? '0'));
        $newUsers = (int)round(ga4_parse_metric_value($map['newUsers'] ?? '0'));
        $sessions = (int)round(ga4_parse_metric_value($map['sessions'] ?? '0'));
        $engagementRate = ga4_parse_metric_value($map['engagementRate'] ?? '0');
        $avgSessionDurationSec = (int)round(ga4_parse_metric_value($map['averageSessionDuration'] ?? '0'));

        $pagesPerSession = 0.0;
        if (!$usedPageViewsFallback) {
            $pagesPerSession = ga4_parse_metric_value($map['screenPageViewsPerSession'] ?? '0');
        } else {
            $pageViews = ga4_parse_metric_value($map['screenPageViews'] ?? '0');
            $pagesPerSession = $sessions > 0 ? ($pageViews / $sessions) : 0.0;
        }

        $out[] = [
            'channel' => $channel,
            'totals' => [
                'users' => $users,
                'new_users' => $newUsers,
                'sessions' => $sessions,
                'engagement_rate' => $engagementRate,
                'avg_session_duration_sec' => $avgSessionDurationSec,
                'pages_per_session' => round($pagesPerSession, 4),
            ],
        ];
    }

    return ['ok' => true, 'rows' => $out];
}

/**
 * Daily series (users + sessions), optionally filtered by a channel group.
 * Returns: ['ok'=>true, 'daily'=>[ ['date'=>'YYYY-MM-DD', 'users'=>..., 'sessions'=>...], ... ] ]
 */
function ga4_get_daily_users_sessions(string $propertyId, string $startDate, string $endDate, ?string $channelGroup = null): array
{
    $filter = null;
    if (is_string($channelGroup) && trim($channelGroup) !== '') {
        $filter = ga4_dimension_filter_exact('sessionDefaultChannelGroup', trim($channelGroup));
    }
    $dailyRes = ga4_run_report($propertyId, $startDate, $endDate, ['totalUsers', 'sessions'], ['date'], $filter);
    if (!$dailyRes['ok']) {
        return $dailyRes;
    }
    $dailyData = (array)$dailyRes['data'];
    $rows = (array)($dailyData['rows'] ?? []);
    $daily = [];
    foreach ($rows as $r) {
        $dimVals = (array)($r['dimensionValues'] ?? []);
        $metVals = (array)($r['metricValues'] ?? []);
        $dateRaw = (string)($dimVals[0]['value'] ?? '');
        $usersV = (string)($metVals[0]['value'] ?? '0');
        $sessionsV = (string)($metVals[1]['value'] ?? '0');
        $daily[] = [
            'date' => ga4_format_date_yyyymmdd($dateRaw),
            'users' => (int)round(ga4_parse_metric_value($usersV)),
            'sessions' => (int)round(ga4_parse_metric_value($sessionsV)),
        ];
    }
    return ['ok' => true, 'daily' => $daily];
}

/**
 * Conversions by event name.
 * Returns: ['ok'=>true, 'events'=>[ ['eventName'=>..., 'conversions'=>...], ... ] ]
 */
function ga4_get_conversions_by_event(string $propertyId, string $startDate, string $endDate, ?string $channelGroup = null): array
{
    $filter = null;
    if (is_string($channelGroup) && trim($channelGroup) !== '') {
        $filter = ga4_dimension_filter_exact('sessionDefaultChannelGroup', trim($channelGroup));
    }
    $res = ga4_run_report($propertyId, $startDate, $endDate, ['conversions'], ['eventName'], $filter);
    if (!$res['ok']) {
        return $res;
    }
    $data = (array)$res['data'];
    $rows = (array)($data['rows'] ?? []);
    $events = [];
    foreach ($rows as $r) {
        $dimVals = (array)($r['dimensionValues'] ?? []);
        $metVals = (array)($r['metricValues'] ?? []);
        $name = (string)($dimVals[0]['value'] ?? '');
        if ($name === '') {
            continue;
        }
        $cnt = (int)round(ga4_parse_metric_value((string)($metVals[0]['value'] ?? '0')));
        if ($cnt <= 0) {
            continue;
        }
        $events[] = ['eventName' => $name, 'conversions' => $cnt];
    }
    usort($events, fn($a, $b) => ((int)($b['conversions'] ?? 0)) <=> ((int)($a['conversions'] ?? 0)));
    return ['ok' => true, 'events' => $events];
}

/**
 * Ecommerce totals (best-effort; returns ok=false if metrics unavailable).
 * Returns: ['ok'=>true, 'totals'=>[...]]
 */
function ga4_get_ecommerce_totals(string $propertyId, string $startDate, string $endDate, ?string $channelGroup = null): array
{
    $filter = null;
    if (is_string($channelGroup) && trim($channelGroup) !== '') {
        $filter = ga4_dimension_filter_exact('sessionDefaultChannelGroup', trim($channelGroup));
    }

    $metrics = ['purchases', 'purchaseRevenue', 'ecommercePurchaseConversionRate', 'transactions'];
    $res = ga4_run_report($propertyId, $startDate, $endDate, $metrics, [], $filter);
    if (!$res['ok']) {
        // Retry with a minimal safe set.
        $metrics = ['purchases', 'purchaseRevenue'];
        $res = ga4_run_report($propertyId, $startDate, $endDate, $metrics, [], $filter);
        if (!$res['ok']) {
            return $res;
        }
    }

    $data = (array)$res['data'];
    $row = (array)($data['rows'][0]['metricValues'] ?? []);
    $values = array_map(fn($mv) => (string)($mv['value'] ?? ''), $row);
    $map = [];
    foreach ($metrics as $i => $name) {
        $map[$name] = $values[$i] ?? '0';
    }

    $purchases = (int)round(ga4_parse_metric_value($map['purchases'] ?? '0'));
    $revenue = ga4_parse_metric_value($map['purchaseRevenue'] ?? '0');
    $conversionRate = isset($map['ecommercePurchaseConversionRate']) ? ga4_parse_metric_value($map['ecommercePurchaseConversionRate']) : null;
    $transactions = isset($map['transactions']) ? (int)round(ga4_parse_metric_value($map['transactions'])) : null;
    if ($transactions === null) {
        $transactions = $purchases;
    }

    return [
        'ok' => true,
        'totals' => [
            'purchases' => $purchases,
            'transactions' => $transactions,
            'revenue' => round($revenue, 2),
            'conversion_rate' => $conversionRate,
        ],
    ];
}

