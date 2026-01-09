<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/ga4_requirements.php';
require_once __DIR__ . '/ga4_schema.php';

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter\MatchType;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\Row;
use Google\Analytics\Data\V1beta\RunReportRequest;

function ga4_property_name(string $propertyId): string
{
    $id = trim($propertyId);
    if ($id === '') {
        return '';
    }
    $id = preg_replace('/\D+/', '', $id);
    return is_string($id) && $id !== '' ? ('properties/' . $id) : '';
}

function ga4_is_valid_property_id(string $propertyId): bool
{
    $id = trim($propertyId);
    return $id !== '' && preg_match('/^\d+$/', $id) === 1;
}

function ga4_parse_float(mixed $v, float $fallback = 0.0): float
{
    if ($v === null) {
        return $fallback;
    }
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    if (is_string($v)) {
        $s = trim($v);
        if ($s === '' || !is_numeric($s)) {
            return $fallback;
        }
        return (float)$s;
    }
    return $fallback;
}

function ga4_parse_int(mixed $v, int $fallback = 0): int
{
    return (int)round(ga4_parse_float($v, (float)$fallback));
}

function ga4_format_date_yyyymmdd(string $yyyymmdd): string
{
    $s = preg_replace('/\D+/', '', $yyyymmdd);
    if (!is_string($s) || strlen($s) !== 8) {
        return $yyyymmdd;
    }
    return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
}

function ga4_string_filter(string $fieldName, int $matchType, string $value, bool $caseSensitive = false): FilterExpression
{
    return new FilterExpression([
        'filter' => new Filter([
            'field_name' => $fieldName,
            'string_filter' => new StringFilter([
                'match_type' => $matchType,
                'value' => $value,
                'case_sensitive' => $caseSensitive,
            ]),
        ]),
    ]);
}

function ga4_and_filters(array $filters): ?FilterExpression
{
    $filters = array_values(array_filter($filters, fn($f) => $f instanceof FilterExpression));
    if (!$filters) {
        return null;
    }
    if (count($filters) === 1) {
        return $filters[0];
    }
    return new FilterExpression([
        'and_group' => new FilterExpressionList(['expressions' => $filters]),
    ]);
}

function ga4_run_report_request_from_array(array $request): RunReportRequest
{
    $req = new RunReportRequest();

    if (array_key_exists('property', $request)) {
        $req->setProperty((string)$request['property']);
    }

    if (isset($request['date_ranges']) && is_array($request['date_ranges'])) {
        $dateRanges = [];
        foreach ($request['date_ranges'] as $dr) {
            if ($dr instanceof DateRange) {
                $dateRanges[] = $dr;
                continue;
            }
            if (is_array($dr)) {
                $start = $dr['start_date'] ?? $dr['startDate'] ?? null;
                $end = $dr['end_date'] ?? $dr['endDate'] ?? null;
                $o = new DateRange();
                if ($start !== null) {
                    $o->setStartDate((string)$start);
                }
                if ($end !== null) {
                    $o->setEndDate((string)$end);
                }
                $dateRanges[] = $o;
            }
        }
        if ($dateRanges) {
            $req->setDateRanges($dateRanges);
        }
    }

    if (isset($request['dimensions']) && is_array($request['dimensions'])) {
        $dimensions = [];
        foreach ($request['dimensions'] as $d) {
            if ($d instanceof Dimension) {
                $dimensions[] = $d;
                continue;
            }
            if (is_string($d)) {
                $o = new Dimension();
                $o->setName($d);
                $dimensions[] = $o;
                continue;
            }
            if (is_array($d) && isset($d['name'])) {
                $o = new Dimension();
                $o->setName((string)$d['name']);
                $dimensions[] = $o;
            }
        }
        if ($dimensions) {
            $req->setDimensions($dimensions);
        }
    }

    if (isset($request['metrics']) && is_array($request['metrics'])) {
        $metrics = [];
        foreach ($request['metrics'] as $m) {
            if ($m instanceof Metric) {
                $metrics[] = $m;
                continue;
            }
            if (is_string($m)) {
                $o = new Metric();
                $o->setName($m);
                $metrics[] = $o;
                continue;
            }
            if (is_array($m) && isset($m['name'])) {
                $o = new Metric();
                $o->setName((string)$m['name']);
                $metrics[] = $o;
            }
        }
        if ($metrics) {
            $req->setMetrics($metrics);
        }
    }

    if (isset($request['dimension_filter']) && $request['dimension_filter'] instanceof FilterExpression) {
        $req->setDimensionFilter($request['dimension_filter']);
    }
    if (isset($request['limit'])) {
        $req->setLimit((int)$request['limit']);
    }

    return $req;
}

/**
 * Version-tolerant runReport helper.
 *
 * Some google/analytics-data versions require a RunReportRequest object,
 * while others accept an associative array. This helper tries both safely.
 *
 * Returns:
 *  - ['ok'=>true, 'response'=>mixed, 'request_type'=>'RunReportRequest'|'array']
 *  - ['ok'=>false, 'error'=>string]
 */
function ga4_run_report_request(BetaAnalyticsDataClient $client, array $payload, array $ctx): array
{
    try {
        // Preferred: explicit RunReportRequest when class exists.
        if (class_exists(RunReportRequest::class)) {
            try {
                $req = new RunReportRequest($payload);
                $resp = $client->runReport($req);
                return ['ok' => true, 'response' => $resp, 'request_type' => 'RunReportRequest'];
            } catch (Throwable $e1) {
                // Fallback: some versions don't accept array in constructor.
                try {
                    $req2 = ga4_run_report_request_from_array($payload);
                    $resp2 = $client->runReport($req2);
                    return ['ok' => true, 'response' => $resp2, 'request_type' => 'RunReportRequest'];
                } catch (Throwable $e2) {
                    // Final fallback: associative array request.
                    $resp3 = $client->runReport($payload);
                    return ['ok' => true, 'response' => $resp3, 'request_type' => 'array'];
                }
            }
        }

        // Fallback: associative array request.
        $resp = $client->runReport($payload);
        return ['ok' => true, 'response' => $resp, 'request_type' => 'array'];
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        $decoded = null;
        $tmp = json_decode($msg, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        } elseif (preg_match('/(\{.*\})/s', $msg, $m) === 1) {
            $tmp2 = json_decode($m[1], true);
            if (is_array($tmp2)) {
                $decoded = $tmp2;
            }
        }

        $exc = [
            'class' => get_class($e),
            'code' => (int)$e->getCode(),
            'message' => $msg,
        ];

        log_error('GA4 runReport failed', [
            'exception' => $exc,
            'exception_json' => $decoded,
            'ctx' => $ctx,
        ]);
        return ['ok' => false, 'error' => $msg, 'exception' => $exc, 'exception_json' => $decoded];
    }
}

function ga4_run_report_safe(BetaAnalyticsDataClient $client, array $request, array $logCtx): array
{
    try {
        // Centralized GA4 schema validation/mapping (property metadata cached 24h).
        $property = isset($request['property']) ? (string)$request['property'] : '';
        $propertyId = '';
        if ($property !== '' && preg_match('~properties/(\d+)~', $property, $m) === 1) {
            $propertyId = (string)$m[1];
        }

        if ($propertyId !== '') {
            $metricNames = [];
            foreach ((array)($request['metrics'] ?? []) as $mx) {
                if ($mx instanceof Metric && method_exists($mx, 'getName')) {
                    $metricNames[] = (string)$mx->getName();
                } elseif (is_string($mx)) {
                    $metricNames[] = $mx;
                } elseif (is_array($mx) && isset($mx['name'])) {
                    $metricNames[] = (string)$mx['name'];
                }
            }

            $dimensionNames = [];
            foreach ((array)($request['dimensions'] ?? []) as $dx) {
                if ($dx instanceof Dimension && method_exists($dx, 'getName')) {
                    $dimensionNames[] = (string)$dx->getName();
                } elseif (is_string($dx)) {
                    $dimensionNames[] = $dx;
                } elseif (is_array($dx) && isset($dx['name'])) {
                    $dimensionNames[] = (string)$dx['name'];
                }
            }

            $mapped = ga4_map_and_validate_fields($propertyId, $metricNames, $dimensionNames, $logCtx);
            if (is_array($mapped['metrics'] ?? null)) {
                $request['metrics'] = (array)$mapped['metrics'];
            }
            if (is_array($mapped['dimensions'] ?? null)) {
                $request['dimensions'] = (array)$mapped['dimensions'];
            }

            if (empty($request['metrics'])) {
                log_error('GA4 report blocked: no valid metrics after validation', [
                    'propertyId' => $propertyId,
                    'ctx' => $logCtx,
                ]);
                return ['ok' => false, 'error' => 'GA4 report blocked: no valid metrics after validation'];
            }

            // If we had to fall back to eventCount for purchases, apply eventName == "purchase" filter.
            if (($mapped['needs_purchase_event_filter'] ?? false) === true) {
                $purchaseFilter = ga4_string_filter('eventName', MatchType::EXACT, 'purchase', false);
                $existing = (isset($request['dimension_filter']) && $request['dimension_filter'] instanceof FilterExpression)
                    ? $request['dimension_filter']
                    : null;
                $request['dimension_filter'] = ga4_and_filters([$existing, $purchaseFilter]);
            }
        }

        $call = ga4_run_report_request($client, $request, $logCtx);
        if (!($call['ok'] ?? false)) {
            return $call;
        }
        return ['ok' => true, 'response' => $call['response']];
    } catch (Throwable $e) {
        log_error('GA4 runReport failed (safe wrapper)', [
            'error' => $e->getMessage(),
            'ctx' => $logCtx,
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Minimal smoke test to verify the GA4 Data API client can run a query.
 *
 * Runs: last 7 days, metrics: sessions, no dimensions.
 */
function ga4_smoke_test(BetaAnalyticsDataClient $client, string $propertyId): array
{
    $pid = trim($propertyId);
    if ($pid === '' || !ga4_is_valid_property_id($pid)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }

    $property = ga4_property_name($pid);
    $payload = [
        'property' => $property,
        'date_ranges' => [new DateRange(['start_date' => '7daysAgo', 'end_date' => 'today'])],
        'metrics' => [new Metric(['name' => 'sessions'])],
    ];

    $res = ga4_run_report_request($client, $payload, ['kind' => 'smoke_test', 'property_id' => $pid]);
    if (!($res['ok'] ?? false)) {
        log_error('GA4 smoke test failed', [
            'property_id' => $pid,
            'error' => (string)($res['error'] ?? 'unknown'),
            'exception' => (is_array($res['exception'] ?? null) ? (array)$res['exception'] : null),
            'exception_json' => (is_array($res['exception_json'] ?? null) ? (array)$res['exception_json'] : null),
            'ctx' => ['kind' => 'smoke_test'],
        ]);
        return ['ok' => false, 'error' => (string)($res['error'] ?? 'GA4 smoke test failed')];
    }

    log_info('GA4 smoke test ok', [
        'property_id' => $pid,
        'request_type' => (string)($res['request_type'] ?? ''),
    ]);
    return ['ok' => true];
}

/**
 * For multiple dateRanges, GA4 returns metric values ordered by:
 * metric[0]/range[0], metric[0]/range[1], ..., metric[1]/range[0], metric[1]/range[1], ...
 */
function ga4_row_metric_value(Row $row, int $metricIndex, int $rangeIndex, int $rangesCount): string
{
    $idx = ($metricIndex * $rangesCount) + $rangeIndex;
    $mvs = $row->getMetricValues();
    if ($idx < 0 || $idx >= count($mvs)) {
        return '0';
    }
    return (string)$mvs[$idx]->getValue();
}

function ga4_try_channel_group_dimension(
    BetaAnalyticsDataClient $client,
    string $property,
    array $dateRanges,
    array $metrics
): array {
    // Do not use defaultChannelGroup (incompatible with several metric combos). Prefer sessionDefaultChannelGroup.
    $dimCandidates = ['sessionDefaultChannelGroup', 'sessionChannelGroup'];
    foreach ($dimCandidates as $dim) {
        $req = [
            'property' => $property,
            'date_ranges' => $dateRanges,
            'dimensions' => [new Dimension(['name' => $dim])],
            'metrics' => $metrics,
            'limit' => 250,
        ];
        $res = ga4_run_report_safe($client, $req, ['kind' => 'totals_by_channel_group', 'dimension_candidate' => $dim]);
        if ($res['ok']) {
            $resp = $res['response'];
            $usedDim = $dim;
            $dhs = $resp->getDimensionHeaders();
            if (count($dhs) > 0) {
                $usedDim = (string)$dhs[0]->getName();
            }
            log_info('GA4 channel group dimension selected', [
                'candidate' => $dim,
                'used' => $usedDim,
                'kind' => 'totals_by_channel_group',
            ]);
            return ['ok' => true, 'dimension' => $usedDim, 'response' => $resp];
        }
    }
    return ['ok' => false, 'error' => 'No channel group dimension worked'];
}

function ga4_fetch_totals_all(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    string $thisStart,
    string $thisEnd,
    string $lastStart,
    string $lastEnd,
    bool $includeSales
): array {
    if (!ga4_requirements_ok(['component' => 'ga4_queries', 'kind' => 'totals_all', 'property_id' => $propertyId])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $property = ga4_property_name($propertyId);

    $dateRanges = [
        new DateRange(['start_date' => $thisStart, 'end_date' => $thisEnd]),
        new DateRange(['start_date' => $lastStart, 'end_date' => $lastEnd]),
    ];

    $baseMetrics = [
        new Metric(['name' => 'totalUsers']),
        new Metric(['name' => 'newUsers']),
        new Metric(['name' => 'sessions']),
        new Metric(['name' => 'engagementRate']),
        new Metric(['name' => 'averageSessionDuration']),
        new Metric(['name' => 'screenPageViewsPerSession']),
    ];
    if ($includeSales) {
        $baseMetrics[] = new Metric(['name' => 'transactions']);
        $baseMetrics[] = new Metric(['name' => 'totalRevenue']);
    }

    $req = [
        'property' => $property,
        'date_ranges' => $dateRanges,
        'metrics' => $baseMetrics,
    ];
    $res = ga4_run_report_safe($client, $req, ['kind' => 'totals_all']);

    $usedPageViewsFallback = false;
    if (!$res['ok'] && str_contains((string)$res['error'], 'screenPageViewsPerSession')) {
        $usedPageViewsFallback = true;
        $metrics = [
            new Metric(['name' => 'totalUsers']),
            new Metric(['name' => 'newUsers']),
            new Metric(['name' => 'sessions']),
            new Metric(['name' => 'engagementRate']),
            new Metric(['name' => 'averageSessionDuration']),
            new Metric(['name' => 'screenPageViews']),
        ];
        if ($includeSales) {
            $metrics[] = new Metric(['name' => 'transactions']);
            $metrics[] = new Metric(['name' => 'totalRevenue']);
        }
        $req['metrics'] = $metrics;
        $res = ga4_run_report_safe($client, $req, ['kind' => 'totals_all', 'fallback' => 'screenPageViews']);
    }
    if (!$res['ok']) {
        return $res;
    }

    $resp = $res['response'];
    $rows = $resp->getRows();
    if (count($rows) < 1) {
        return ['ok' => true, 'used_pageviews_fallback' => $usedPageViewsFallback, 'this' => [], 'last' => []];
    }
    $row = $rows[0];
    $rangesCount = 2;
    $names = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $names[] = (string)$mh->getName();
    }

    $outThis = [];
    $outLast = [];
    foreach ($names as $i => $metricName) {
        $outThis[$metricName] = ga4_row_metric_value($row, $i, 0, $rangesCount);
        $outLast[$metricName] = ga4_row_metric_value($row, $i, 1, $rangesCount);
    }
    // Back-compat contract: keep "purchases" key expected by report generator/UI.
    if ($includeSales && array_key_exists('transactions', $outThis)) {
        $outThis['purchases'] = $outThis['transactions'];
        $outLast['purchases'] = $outLast['transactions'] ?? '0';
    }

    // Compute pages/session if we used screenPageViews fallback, or if GA4 omitted screenPageViewsPerSession.
    if ($usedPageViewsFallback || !array_key_exists('screenPageViewsPerSession', $outThis)) {
        $sessThis = max(0.0, ga4_parse_float($outThis['sessions'] ?? '0', 0.0));
        $sessLast = max(0.0, ga4_parse_float($outLast['sessions'] ?? '0', 0.0));
        $pvThis = max(0.0, ga4_parse_float($outThis['screenPageViews'] ?? '0', 0.0));
        $pvLast = max(0.0, ga4_parse_float($outLast['screenPageViews'] ?? '0', 0.0));
        if ($pvThis > 0 || $pvLast > 0) {
            $outThis['screenPageViewsPerSession'] = (string)($sessThis > 0 ? ($pvThis / $sessThis) : 0.0);
            $outLast['screenPageViewsPerSession'] = (string)($sessLast > 0 ? ($pvLast / $sessLast) : 0.0);
        }
    }

    return [
        'ok' => true,
        'used_pageviews_fallback' => $usedPageViewsFallback,
        'this' => $outThis,
        'last' => $outLast,
    ];
}

function ga4_fetch_totals_by_channel_group(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    string $thisStart,
    string $thisEnd,
    string $lastStart,
    string $lastEnd,
    bool $includeSales
): array {
    if (!ga4_requirements_ok(['component' => 'ga4_queries', 'kind' => 'totals_by_channel_group', 'property_id' => $propertyId])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $property = ga4_property_name($propertyId);

    $dateRanges = [
        new DateRange(['start_date' => $thisStart, 'end_date' => $thisEnd]),
        new DateRange(['start_date' => $lastStart, 'end_date' => $lastEnd]),
    ];

    $metrics = [
        new Metric(['name' => 'totalUsers']),
        new Metric(['name' => 'newUsers']),
        new Metric(['name' => 'sessions']),
        new Metric(['name' => 'engagementRate']),
        new Metric(['name' => 'averageSessionDuration']),
        new Metric(['name' => 'screenPageViewsPerSession']),
    ];
    if ($includeSales) {
        $metrics[] = new Metric(['name' => 'transactions']);
        $metrics[] = new Metric(['name' => 'totalRevenue']);
    }

    $usedPageViewsFallback = false;
    $groupRes = ga4_try_channel_group_dimension($client, $property, $dateRanges, $metrics);
    if (!$groupRes['ok']) {
        return $groupRes;
    }
    $dim = (string)$groupRes['dimension'];
    $resp = $groupRes['response'];

    // If pages/session metric is not available, retry once with screenPageViews and compute.
    $rows = $resp->getRows();
    if (count($rows) === 0) {
        // Might still be valid, but empty.
        return [
            'ok' => true,
            'dimension' => $dim,
            'used_pageviews_fallback' => $usedPageViewsFallback,
            'by_group' => [],
        ];
    }

    // Heuristic: if request succeeded, keep it. If it failed, we'd have no response.
    // But sometimes a metric-specific error can still bubble up; handle via a second attempt.
    // We detect "screenPageViewsPerSession" issues by doing a lightweight retry if needed:
    // (Only when previous attempt failed; here it didn't.)

    $rangesCount = 2;
    $names = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $names[] = (string)$mh->getName();
    }

    $by = [];
    foreach ($rows as $r) {
        $dimVals = $r->getDimensionValues();
        $group = count($dimVals) > 0 ? (string)$dimVals[0]->getValue() : '';
        $group = trim($group);
        if ($group === '') {
            $group = '(not set)';
        }
        $thisVals = [];
        $lastVals = [];
        foreach ($names as $i => $metricName) {
            $thisVals[$metricName] = ga4_row_metric_value($r, $i, 0, $rangesCount);
            $lastVals[$metricName] = ga4_row_metric_value($r, $i, 1, $rangesCount);
        }
        if (!array_key_exists('screenPageViewsPerSession', $thisVals) && array_key_exists('screenPageViews', $thisVals)) {
            $sessThis = max(0.0, ga4_parse_float($thisVals['sessions'] ?? '0', 0.0));
            $sessLast = max(0.0, ga4_parse_float($lastVals['sessions'] ?? '0', 0.0));
            $pvThis = max(0.0, ga4_parse_float($thisVals['screenPageViews'] ?? '0', 0.0));
            $pvLast = max(0.0, ga4_parse_float($lastVals['screenPageViews'] ?? '0', 0.0));
            $thisVals['screenPageViewsPerSession'] = (string)($sessThis > 0 ? ($pvThis / $sessThis) : 0.0);
            $lastVals['screenPageViewsPerSession'] = (string)($sessLast > 0 ? ($pvLast / $sessLast) : 0.0);
        }
        if ($includeSales && array_key_exists('transactions', $thisVals)) {
            $thisVals['purchases'] = $thisVals['transactions'];
            $lastVals['purchases'] = $lastVals['transactions'] ?? '0';
        }
        $by[$group] = ['this' => $thisVals, 'last' => $lastVals];
    }

    return [
        'ok' => true,
        'dimension' => $dim,
        'used_pageviews_fallback' => $usedPageViewsFallback,
        'by_group' => $by,
    ];
}

function ga4_fetch_timeseries_all(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    string $startDate,
    string $endDate,
    bool $includeSales
): array {
    if (!ga4_requirements_ok(['component' => 'ga4_queries', 'kind' => 'timeseries_all', 'property_id' => $propertyId])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $property = ga4_property_name($propertyId);

    $metrics = [
        new Metric(['name' => 'totalUsers']),
        new Metric(['name' => 'engagementRate']),
    ];
    if ($includeSales) {
        $metrics[] = new Metric(['name' => 'totalRevenue']);
    }

    $req = [
        'property' => $property,
        'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
        'dimensions' => [new Dimension(['name' => 'date'])],
        'metrics' => $metrics,
        'limit' => 10000,
    ];
    $res = ga4_run_report_safe($client, $req, ['kind' => 'timeseries_all', 'start' => $startDate, 'end' => $endDate]);
    if (!$res['ok']) {
        return $res;
    }

    $out = [];
    $resp = $res['response'];
    $dimNames = [];
    foreach ($resp->getDimensionHeaders() as $dh) {
        $dimNames[] = (string)$dh->getName();
    }
    $metricNames = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $metricNames[] = (string)$mh->getName();
    }
    foreach ($resp->getRows() as $r) {
        $dims = [];
        $dvs = $r->getDimensionValues();
        foreach ($dimNames as $i => $n) {
            $dims[$n] = ($i >= 0 && $i < count($dvs)) ? (string)$dvs[$i]->getValue() : '';
        }
        $mets = [];
        foreach ($metricNames as $i => $n) {
            $mets[$n] = ga4_row_metric_value($r, $i, 0, 1);
        }

        $dateRaw = (string)($dims['date'] ?? '');
        $users = (string)($mets['totalUsers'] ?? '0');
        $eng = (string)($mets['engagementRate'] ?? '0');
        $rev = (string)($mets['totalRevenue'] ?? '0');
        $out[] = [
            'date' => ga4_format_date_yyyymmdd($dateRaw),
            'users' => ga4_parse_int($users, 0),
            'engagement_rate' => ga4_parse_float($eng, 0.0),
            'revenue' => $includeSales ? ga4_parse_float($rev, 0.0) : null,
        ];
    }
    return ['ok' => true, 'rows' => $out];
}

function ga4_fetch_timeseries_by_channel_group(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    string $channelGroupDimension,
    string $startDate,
    string $endDate,
    bool $includeSales
): array {
    if (!ga4_requirements_ok([
        'component' => 'ga4_queries',
        'kind' => 'timeseries_by_channel_group',
        'dimension' => $channelGroupDimension,
        'property_id' => $propertyId,
    ])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $property = ga4_property_name($propertyId);

    $metrics = [
        new Metric(['name' => 'totalUsers']),
        new Metric(['name' => 'engagementRate']),
    ];
    if ($includeSales) {
        $metrics[] = new Metric(['name' => 'totalRevenue']);
    }

    $req = [
        'property' => $property,
        'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
        'dimensions' => [
            new Dimension(['name' => 'date']),
            new Dimension(['name' => $channelGroupDimension]),
        ],
        'metrics' => $metrics,
        'limit' => 100000,
    ];
    $res = ga4_run_report_safe($client, $req, [
        'kind' => 'timeseries_by_channel_group',
        'dimension' => $channelGroupDimension,
        'start' => $startDate,
        'end' => $endDate,
    ]);
    if (!$res['ok']) {
        return $res;
    }

    $out = [];
    $resp = $res['response'];
    $dimNames = [];
    foreach ($resp->getDimensionHeaders() as $dh) {
        $dimNames[] = (string)$dh->getName();
    }
    $metricNames = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $metricNames[] = (string)$mh->getName();
    }
    foreach ($resp->getRows() as $r) {
        $dims = [];
        $dvs = $r->getDimensionValues();
        foreach ($dimNames as $i => $n) {
            $dims[$n] = ($i >= 0 && $i < count($dvs)) ? (string)$dvs[$i]->getValue() : '';
        }
        $mets = [];
        foreach ($metricNames as $i => $n) {
            $mets[$n] = ga4_row_metric_value($r, $i, 0, 1);
        }

        $dateRaw = (string)($dims['date'] ?? '');
        $groupRaw = '';
        foreach ($dims as $k => $v) {
            if ($k === 'date') {
                continue;
            }
            $groupRaw = (string)$v;
            break;
        }
        $group = trim($groupRaw);
        if ($group === '') {
            $group = '(not set)';
        }

        $users = (string)($mets['totalUsers'] ?? '0');
        $eng = (string)($mets['engagementRate'] ?? '0');
        $rev = (string)($mets['totalRevenue'] ?? '0');

        $out[] = [
            'date' => ga4_format_date_yyyymmdd($dateRaw),
            'channel_group' => $group,
            'users' => ga4_parse_int($users, 0),
            'engagement_rate' => ga4_parse_float($eng, 0.0),
            'revenue' => $includeSales ? ga4_parse_float($rev, 0.0) : null,
        ];
    }

    return ['ok' => true, 'rows' => $out];
}

function ga4_segment_channel_group_name(string $trafficKey): ?string
{
    return match ($trafficKey) {
        'seo' => 'Organic Search',
        'ppc' => 'Paid Search',
        'social_organic' => 'Organic Social',
        'social_paid' => 'Paid Social',
        'referral' => 'Referral',
        'email' => 'Email',
        default => null,
    };
}

function ga4_segment_fallback_regex(string $trafficKey): ?string
{
    // Fallback patterns for sessionSourceMedium when channel-group filtering is unavailable.
    // Keep them conservative to avoid cross-channel leakage.
    return match ($trafficKey) {
        'seo' => ' / organic',
        'ppc' => '(cpc|ppc|paidsearch|sem| / cpc| / ppc)',
        'social_organic' => ' / social',
        'social_paid' => '(facebook|instagram|tiktok|linkedin|pinterest|youtube|twitter|t\\.co|x\\.com).*(cpc|paid|paid-social|paid social)',
        'referral' => ' / referral',
        'email' => ' / e-?mail',
        default => null,
    };
}

function ga4_segment_dimension_filter(
    string $trafficKey,
    bool $preferChannelGroup = true,
    string $channelGroupField = 'sessionDefaultChannelGroup'
): ?FilterExpression
{
    if ($trafficKey === 'all') {
        return null;
    }

    if ($preferChannelGroup) {
        $channelName = ga4_segment_channel_group_name($trafficKey);
        if ($channelName !== null) {
            return ga4_string_filter($channelGroupField, MatchType::EXACT, $channelName, false);
        }
    }

    $rx = ga4_segment_fallback_regex($trafficKey);
    if ($rx === null || trim($rx) === '') {
        return null;
    }
    return ga4_string_filter('sessionSourceMedium', MatchType::PARTIAL_REGEXP, $rx, false);
}

function ga4_fetch_totals_for_segment(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    string $thisStart,
    string $thisEnd,
    string $lastStart,
    string $lastEnd,
    bool $includeSales,
    string $trafficKey
): array {
    if (!ga4_requirements_ok([
        'component' => 'ga4_queries',
        'kind' => 'totals_segment',
        'traffic_key' => $trafficKey,
        'property_id' => $propertyId,
    ])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $property = ga4_property_name($propertyId);

    $dateRanges = [
        new DateRange(['start_date' => $thisStart, 'end_date' => $thisEnd]),
        new DateRange(['start_date' => $lastStart, 'end_date' => $lastEnd]),
    ];

    $dimFilter = ga4_segment_dimension_filter($trafficKey, true, 'sessionDefaultChannelGroup');
    $metrics = [
        new Metric(['name' => 'totalUsers']),
        new Metric(['name' => 'newUsers']),
        new Metric(['name' => 'sessions']),
        new Metric(['name' => 'engagementRate']),
        new Metric(['name' => 'averageSessionDuration']),
        new Metric(['name' => 'screenPageViewsPerSession']),
    ];
    if ($includeSales) {
        $metrics[] = new Metric(['name' => 'transactions']);
        $metrics[] = new Metric(['name' => 'totalRevenue']);
    }

    $req = [
        'property' => $property,
        'date_ranges' => $dateRanges,
        'metrics' => $metrics,
        'dimension_filter' => $dimFilter,
    ];
    $res = ga4_run_report_safe($client, $req, [
        'kind' => 'totals_segment',
        'trafficKey' => $trafficKey,
        'filter' => 'channel_group',
        'filter_field' => 'sessionDefaultChannelGroup',
    ]);

    $usedPageViewsFallback = false;
    $usedFilterFallback = false;

    // Retry #1: pages/session fallback
    if (!$res['ok'] && str_contains((string)$res['error'], 'screenPageViewsPerSession')) {
        $usedPageViewsFallback = true;
        $metrics = [
            new Metric(['name' => 'totalUsers']),
            new Metric(['name' => 'newUsers']),
            new Metric(['name' => 'sessions']),
            new Metric(['name' => 'engagementRate']),
            new Metric(['name' => 'averageSessionDuration']),
            new Metric(['name' => 'screenPageViews']),
        ];
        if ($includeSales) {
            $metrics[] = new Metric(['name' => 'transactions']);
            $metrics[] = new Metric(['name' => 'totalRevenue']);
        }
        $req['metrics'] = $metrics;
        $res = ga4_run_report_safe($client, $req, [
            'kind' => 'totals_segment',
            'trafficKey' => $trafficKey,
            'filter' => 'channel_group',
            'filter_field' => 'sessionDefaultChannelGroup',
            'fallback' => 'screenPageViews',
        ]);
    }

    // Retry #1b: alternate channel group field (still no defaultChannelGroup).
    if (!$res['ok']) {
        $alt = ga4_segment_dimension_filter($trafficKey, true, 'sessionChannelGroup');
        if ($alt instanceof FilterExpression) {
            $req['dimension_filter'] = $alt;

            // Reset metrics for a clean retry (then re-apply pages/session fallback if needed).
            $metrics = [
                new Metric(['name' => 'totalUsers']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'engagementRate']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'screenPageViewsPerSession']),
            ];
            if ($includeSales) {
                $metrics[] = new Metric(['name' => 'transactions']);
                $metrics[] = new Metric(['name' => 'totalRevenue']);
            }
            $req['metrics'] = $metrics;

            $res = ga4_run_report_safe($client, $req, [
                'kind' => 'totals_segment',
                'trafficKey' => $trafficKey,
                'filter' => 'channel_group',
                'filter_field' => 'sessionChannelGroup',
            ]);

            if (!$res['ok'] && str_contains((string)$res['error'], 'screenPageViewsPerSession')) {
                $usedPageViewsFallback = true;
                $metrics2 = [
                    new Metric(['name' => 'totalUsers']),
                    new Metric(['name' => 'newUsers']),
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'engagementRate']),
                    new Metric(['name' => 'averageSessionDuration']),
                    new Metric(['name' => 'screenPageViews']),
                ];
                if ($includeSales) {
                    $metrics2[] = new Metric(['name' => 'transactions']);
                    $metrics2[] = new Metric(['name' => 'totalRevenue']);
                }
                $req['metrics'] = $metrics2;
                $res = ga4_run_report_safe($client, $req, [
                    'kind' => 'totals_segment',
                    'trafficKey' => $trafficKey,
                    'filter' => 'channel_group',
                    'filter_field' => 'sessionChannelGroup',
                    'fallback' => 'screenPageViews',
                ]);
            }
        }
    }

    // Retry #2: channel group filter fallback to sessionSourceMedium regex
    if (!$res['ok']) {
        $dimFilter = ga4_segment_dimension_filter($trafficKey, false);
        $req['dimension_filter'] = $dimFilter;
        $usedFilterFallback = true;
        $res = ga4_run_report_safe($client, $req, ['kind' => 'totals_segment', 'trafficKey' => $trafficKey, 'filter' => 'source_medium']);
    }

    if (!$res['ok']) {
        return $res;
    }

    $resp = $res['response'];
    $rows = $resp->getRows();
    if (count($rows) < 1) {
        return [
            'ok' => true,
            'used_pageviews_fallback' => $usedPageViewsFallback,
            'used_filter_fallback' => $usedFilterFallback,
            'this' => [],
            'last' => [],
        ];
    }

    $row = $rows[0];
    $rangesCount = 2;
    $names = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $names[] = (string)$mh->getName();
    }

    $outThis = [];
    $outLast = [];
    foreach ($names as $i => $metricName) {
        $outThis[$metricName] = ga4_row_metric_value($row, $i, 0, $rangesCount);
        $outLast[$metricName] = ga4_row_metric_value($row, $i, 1, $rangesCount);
    }
    if ($includeSales && array_key_exists('transactions', $outThis)) {
        $outThis['purchases'] = $outThis['transactions'];
        $outLast['purchases'] = $outLast['transactions'] ?? '0';
    }
    if ($usedPageViewsFallback || !array_key_exists('screenPageViewsPerSession', $outThis)) {
        $sessThis = max(0.0, ga4_parse_float($outThis['sessions'] ?? '0', 0.0));
        $sessLast = max(0.0, ga4_parse_float($outLast['sessions'] ?? '0', 0.0));
        $pvThis = max(0.0, ga4_parse_float($outThis['screenPageViews'] ?? '0', 0.0));
        $pvLast = max(0.0, ga4_parse_float($outLast['screenPageViews'] ?? '0', 0.0));
        if ($pvThis > 0 || $pvLast > 0) {
            $outThis['screenPageViewsPerSession'] = (string)($sessThis > 0 ? ($pvThis / $sessThis) : 0.0);
            $outLast['screenPageViewsPerSession'] = (string)($sessLast > 0 ? ($pvLast / $sessLast) : 0.0);
        }
    }

    return [
        'ok' => true,
        'used_pageviews_fallback' => $usedPageViewsFallback,
        'used_filter_fallback' => $usedFilterFallback,
        'this' => $outThis,
        'last' => $outLast,
    ];
}

function ga4_fetch_timeseries_for_segment(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    string $startDate,
    string $endDate,
    bool $includeSales,
    string $trafficKey
): array {
    if (!ga4_requirements_ok([
        'component' => 'ga4_queries',
        'kind' => 'timeseries_segment',
        'traffic_key' => $trafficKey,
        'property_id' => $propertyId,
    ])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $property = ga4_property_name($propertyId);

    $metrics = [
        new Metric(['name' => 'totalUsers']),
        new Metric(['name' => 'engagementRate']),
    ];
    if ($includeSales) {
        $metrics[] = new Metric(['name' => 'totalRevenue']);
    }

    $dimFilter = ga4_segment_dimension_filter($trafficKey, true, 'sessionDefaultChannelGroup');
    $req = [
        'property' => $property,
        'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
        'dimensions' => [new Dimension(['name' => 'date'])],
        'metrics' => $metrics,
        'dimension_filter' => $dimFilter,
        'limit' => 10000,
    ];
    $res = ga4_run_report_safe($client, $req, [
        'kind' => 'timeseries_segment',
        'trafficKey' => $trafficKey,
        'filter' => 'channel_group',
        'filter_field' => 'sessionDefaultChannelGroup',
    ]);

    $usedFilterFallback = false;
    if (!$res['ok']) {
        // Retry with alternate channel group field (still no defaultChannelGroup).
        $alt = ga4_segment_dimension_filter($trafficKey, true, 'sessionChannelGroup');
        if ($alt instanceof FilterExpression) {
            $req['dimension_filter'] = $alt;
            $res = ga4_run_report_safe($client, $req, [
                'kind' => 'timeseries_segment',
                'trafficKey' => $trafficKey,
                'filter' => 'channel_group',
                'filter_field' => 'sessionChannelGroup',
            ]);
        }
    }
    if (!$res['ok']) {
        $dimFilter = ga4_segment_dimension_filter($trafficKey, false);
        $req['dimension_filter'] = $dimFilter;
        $usedFilterFallback = true;
        $res = ga4_run_report_safe($client, $req, ['kind' => 'timeseries_segment', 'trafficKey' => $trafficKey, 'filter' => 'source_medium']);
    }
    if (!$res['ok']) {
        return $res;
    }

    $out = [];
    $resp = $res['response'];
    $dimNames = [];
    foreach ($resp->getDimensionHeaders() as $dh) {
        $dimNames[] = (string)$dh->getName();
    }
    $metricNames = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $metricNames[] = (string)$mh->getName();
    }
    foreach ($resp->getRows() as $r) {
        $dims = [];
        $dvs = $r->getDimensionValues();
        foreach ($dimNames as $i => $n) {
            $dims[$n] = ($i >= 0 && $i < count($dvs)) ? (string)$dvs[$i]->getValue() : '';
        }
        $mets = [];
        foreach ($metricNames as $i => $n) {
            $mets[$n] = ga4_row_metric_value($r, $i, 0, 1);
        }

        $dateRaw = (string)($dims['date'] ?? '');
        $users = (string)($mets['totalUsers'] ?? '0');
        $eng = (string)($mets['engagementRate'] ?? '0');
        $rev = (string)($mets['totalRevenue'] ?? '0');
        $out[] = [
            'date' => ga4_format_date_yyyymmdd($dateRaw),
            'users' => ga4_parse_int($users, 0),
            'engagement_rate' => ga4_parse_float($eng, 0.0),
            'revenue' => $includeSales ? ga4_parse_float($rev, 0.0) : null,
        ];
    }

    return ['ok' => true, 'used_filter_fallback' => $usedFilterFallback, 'rows' => $out];
}

function ga4_date_range_from_mixed(mixed $dateRange): ?DateRange
{
    if ($dateRange instanceof DateRange) {
        return $dateRange;
    }
    if (is_array($dateRange)) {
        $start = $dateRange['start_date'] ?? $dateRange['startDate'] ?? $dateRange['start'] ?? null;
        $end = $dateRange['end_date'] ?? $dateRange['endDate'] ?? $dateRange['end'] ?? null;
        if ($start === null || $end === null) {
            return null;
        }
        return new DateRange(['start_date' => (string)$start, 'end_date' => (string)$end]);
    }
    return null;
}

/**
 * Generic totals fetch for a single dateRange.
 *
 * Returns:
 *  - ['ok'=>true, 'totals'=>[metricName=>string]]
 *  - ['ok'=>false, 'error'=>string]
 */
function ga4_fetch_totals(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    mixed $dateRange,
    ?FilterExpression $filterExpr,
    array $metrics
): array {
    if (!ga4_requirements_ok(['component' => 'ga4_queries', 'kind' => 'fetch_totals', 'property_id' => $propertyId])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $dr = ga4_date_range_from_mixed($dateRange);
    if (!$dr instanceof DateRange) {
        return ['ok' => false, 'error' => 'Invalid dateRange'];
    }

    $property = ga4_property_name($propertyId);
    $mx = [];
    foreach ($metrics as $m) {
        if ($m instanceof Metric) {
            $mx[] = $m;
        } elseif (is_string($m) && trim($m) !== '') {
            $mx[] = new Metric(['name' => trim($m)]);
        } elseif (is_array($m) && isset($m['name']) && is_string($m['name']) && trim($m['name']) !== '') {
            $mx[] = new Metric(['name' => trim((string)$m['name'])]);
        }
    }
    if ($mx === []) {
        return ['ok' => false, 'error' => 'No metrics provided'];
    }

    $req = [
        'property' => $property,
        'date_ranges' => [$dr],
        'metrics' => $mx,
    ];
    if ($filterExpr instanceof FilterExpression) {
        $req['dimension_filter'] = $filterExpr;
    }

    $res = ga4_run_report_safe($client, $req, [
        'kind' => 'fetch_totals',
        'property_id' => $propertyId,
        'has_filter' => $filterExpr instanceof FilterExpression,
    ]);
    if (!($res['ok'] ?? false)) {
        return $res;
    }

    $resp = $res['response'];
    $rows = $resp->getRows();
    if (count($rows) < 1) {
        return ['ok' => true, 'totals' => []];
    }
    $row = $rows[0];
    $names = [];
    foreach ($resp->getMetricHeaders() as $mh) {
        $names[] = (string)$mh->getName();
    }
    $out = [];
    foreach ($names as $i => $name) {
        $out[$name] = ga4_row_metric_value($row, $i, 0, 1);
    }
    return ['ok' => true, 'totals' => $out];
}

/**
 * Timeseries (by date) for a single metric.
 *
 * Returns rows: [{date:'YYYY-MM-DD', value: float|int}]
 */
function ga4_fetch_timeseries_by_date(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    mixed $dateRange,
    ?FilterExpression $filterExpr,
    string $metric = 'totalUsers'
): array {
    if (!ga4_requirements_ok(['component' => 'ga4_queries', 'kind' => 'fetch_timeseries_by_date', 'property_id' => $propertyId])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $dr = ga4_date_range_from_mixed($dateRange);
    if (!$dr instanceof DateRange) {
        return ['ok' => false, 'error' => 'Invalid dateRange'];
    }
    $metric = trim($metric);
    if ($metric === '') {
        $metric = 'totalUsers';
    }

    $property = ga4_property_name($propertyId);
    $req = [
        'property' => $property,
        'date_ranges' => [$dr],
        'dimensions' => [new Dimension(['name' => 'date'])],
        'metrics' => [new Metric(['name' => $metric])],
        'limit' => 10000,
    ];
    if ($filterExpr instanceof FilterExpression) {
        $req['dimension_filter'] = $filterExpr;
    }

    $res = ga4_run_report_safe($client, $req, [
        'kind' => 'fetch_timeseries_by_date',
        'property_id' => $propertyId,
        'metric' => $metric,
        'has_filter' => $filterExpr instanceof FilterExpression,
    ]);
    if (!($res['ok'] ?? false)) {
        return $res;
    }

    $resp = $res['response'];
    $rows = [];
    foreach ($resp->getRows() as $r) {
        $dvs = $r->getDimensionValues();
        $mvs = $r->getMetricValues();
        $dateRaw = (count($dvs) > 0) ? (string)$dvs[0]->getValue() : '';
        $valRaw = (count($mvs) > 0) ? (string)$mvs[0]->getValue() : '0';
        $rows[] = [
            'date' => ga4_format_date_yyyymmdd($dateRaw),
            'value' => is_numeric($valRaw) ? (float)$valRaw : 0.0,
        ];
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Generic breakdown for a single dimension + metrics.
 *
 * Returns:
 *  - ['ok'=>true, 'rows'=>[['dimension'=>string, 'metrics'=>[metricName=>string]], ...]]
 */
function ga4_fetch_breakdown(
    BetaAnalyticsDataClient $client,
    string $propertyId,
    mixed $dateRange,
    ?FilterExpression $filterExpr,
    string $dimension,
    array $metrics,
    int $limit
): array {
    if (!ga4_requirements_ok(['component' => 'ga4_queries', 'kind' => 'fetch_breakdown', 'property_id' => $propertyId])) {
        return ['ok' => false, 'error' => 'GA4 disabled: requirements not met'];
    }
    if (!ga4_is_valid_property_id($propertyId)) {
        return ['ok' => false, 'error' => 'Invalid GA4 property ID'];
    }
    $dr = ga4_date_range_from_mixed($dateRange);
    if (!$dr instanceof DateRange) {
        return ['ok' => false, 'error' => 'Invalid dateRange'];
    }
    $dimension = trim($dimension);
    if ($dimension === '') {
        return ['ok' => false, 'error' => 'Invalid dimension'];
    }

    $mx = [];
    foreach ($metrics as $m) {
        if ($m instanceof Metric) {
            $mx[] = $m;
        } elseif (is_string($m) && trim($m) !== '') {
            $mx[] = new Metric(['name' => trim($m)]);
        } elseif (is_array($m) && isset($m['name']) && is_string($m['name']) && trim($m['name']) !== '') {
            $mx[] = new Metric(['name' => trim((string)$m['name'])]);
        }
    }
    if ($mx === []) {
        return ['ok' => false, 'error' => 'No metrics provided'];
    }

    $property = ga4_property_name($propertyId);
    $req = [
        'property' => $property,
        'date_ranges' => [$dr],
        'dimensions' => [new Dimension(['name' => $dimension])],
        'metrics' => $mx,
        'limit' => max(1, min(100, $limit)),
    ];
    if ($filterExpr instanceof FilterExpression) {
        $req['dimension_filter'] = $filterExpr;
    }

    // Try to order by totalUsers (desc) if supported.
    $metricNames = [];
    foreach ($mx as $m) {
        if (method_exists($m, 'getName')) {
            $metricNames[] = (string)$m->getName();
        }
    }
    if (in_array('totalUsers', $metricNames, true) && class_exists(OrderBy::class) && class_exists(MetricOrderBy::class)) {
        try {
            $req['order_bys'] = [new OrderBy([
                'metric' => new MetricOrderBy(['metric_name' => 'totalUsers']),
                'desc' => true,
            ])];
        } catch (Throwable $e) {
            // Ignore if this client version doesn't support order_bys.
        }
    }

    $res = ga4_run_report_safe($client, $req, [
        'kind' => 'fetch_breakdown',
        'property_id' => $propertyId,
        'dimension' => $dimension,
        'metrics' => $metricNames,
        'limit' => $limit,
        'has_filter' => $filterExpr instanceof FilterExpression,
    ]);
    if (!($res['ok'] ?? false)) {
        return $res;
    }

    $resp = $res['response'];
    $mh = [];
    foreach ($resp->getMetricHeaders() as $h) {
        $mh[] = (string)$h->getName();
    }
    $out = [];
    foreach ($resp->getRows() as $r) {
        $dvs = $r->getDimensionValues();
        $dimVal = (count($dvs) > 0) ? trim((string)$dvs[0]->getValue()) : '';
        if ($dimVal === '') {
            $dimVal = '(not set)';
        }
        $mets = [];
        foreach ($mh as $i => $name) {
            $mets[$name] = ga4_row_metric_value($r, $i, 0, 1);
        }
        $out[] = ['dimension' => $dimVal, 'metrics' => $mets];
    }
    return ['ok' => true, 'rows' => $out];
}

