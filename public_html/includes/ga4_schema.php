<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/google_auth.php';
require_once __DIR__ . '/ga4_requirements.php';
require_once __DIR__ . '/ga4_client.php';

/**
 * GA4 Data API schema compatibility helpers.
 *
 * Source of truth: GA4 Data API v1 API schema.
 * Metadata endpoint: properties/{propertyId}/metadata
 *
 * Responsibilities:
 * - Fetch + cache metadata (metrics/dimensions) for a property (24h)
 * - Provide mapping for deprecated/invalid fields
 * - Validate requested report fields before calling runReport
 */

const GA4_METADATA_CACHE_TTL_SECONDS = 86400; // 24h
const GA4_DATA_API_BASE_V1BETA = 'https://analyticsdata.googleapis.com/v1beta';

// Keep scope definition local to avoid coupling to other modules.
if (!defined('GA4_SCOPE_READONLY')) {
    define('GA4_SCOPE_READONLY', 'https://www.googleapis.com/auth/analytics.readonly');
}

function ga4_http_get(string $url, string $accessToken): array
{
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
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
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
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

function ga4_metadata_cache_path(string $propertyId): string
{
    $pid = preg_replace('/\D+/', '', trim($propertyId));
    if (!is_string($pid) || $pid === '') {
        return '';
    }
    return __DIR__ . '/cache/ga4_metadata_' . $pid . '.json';
}

function ga4_read_cached_metadata(string $propertyId): ?array
{
    $path = ga4_metadata_cache_path($propertyId);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }
    $fetchedAt = $json['_fetched_at'] ?? null;
    if (!is_int($fetchedAt) || $fetchedAt <= 0) {
        return null;
    }
    if ($fetchedAt < (time() - GA4_METADATA_CACHE_TTL_SECONDS)) {
        return null;
    }
    $meta = $json['metadata'] ?? null;
    return is_array($meta) ? $meta : null;
}

function ga4_write_cached_metadata(string $propertyId, array $metadata): void
{
    $path = ga4_metadata_cache_path($propertyId);
    if ($path === '') {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    @file_put_contents(
        $path,
        json_encode(['_fetched_at' => time(), 'metadata' => $metadata], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * Fetches GA4 Data API metadata (v1beta) and caches it.
 *
 * Returns:
 *  - ['ok'=>true, 'metadata'=>array, 'source'=>'cache'|'live']
 *  - ['ok'=>false, 'error'=>string]
 */
function ga4_get_metadata(string $propertyId): array
{
    $pid = preg_replace('/\D+/', '', trim($propertyId));
    if (!is_string($pid) || $pid === '') {
        return ['ok' => false, 'error' => 'Invalid propertyId for metadata'];
    }

    $cached = ga4_read_cached_metadata($pid);
    if (is_array($cached)) {
        return ['ok' => true, 'metadata' => $cached, 'source' => 'cache'];
    }

    // Preferred path: use the same authenticated GA4 library client as runReport (v1beta).
    try {
        if (function_exists('ga4_build_client') && ga4_requirements_ok(['component' => 'ga4_schema', 'kind' => 'metadata', 'property_id' => $pid])) {
            $clientRes = ga4_build_client(['component' => 'ga4_schema', 'kind' => 'metadata', 'property_id' => $pid]);
            if (($clientRes['ok'] ?? false) && ($clientRes['client'] ?? null) instanceof \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient) {
                /** @var \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient $client */
                $client = $clientRes['client'];
                $name = 'properties/' . $pid . '/metadata';

                $metaObj = null;
                if (class_exists(\Google\Analytics\Data\V1beta\GetMetadataRequest::class)) {
                    $metaObj = $client->getMetadata(new \Google\Analytics\Data\V1beta\GetMetadataRequest(['name' => $name]));
                } else {
                    // Some versions accept associative array requests.
                    $metaObj = $client->getMetadata(['name' => $name]);
                }

                // Normalize protobuf Metadata into the JSON-ish array shape used elsewhere.
                $dims = [];
                $mets = [];
                if (is_object($metaObj) && method_exists($metaObj, 'getDimensions')) {
                    foreach ($metaObj->getDimensions() as $d) {
                        if (!is_object($d) || !method_exists($d, 'getApiName')) {
                            continue;
                        }
                        $deprecated = [];
                        if (method_exists($d, 'getDeprecatedApiNames')) {
                            foreach ($d->getDeprecatedApiNames() as $n) {
                                if (is_string($n) && $n !== '') {
                                    $deprecated[] = $n;
                                }
                            }
                        }
                        $dims[] = [
                            'apiName' => (string)$d->getApiName(),
                            'deprecatedApiNames' => $deprecated,
                        ];
                    }
                }
                if (is_object($metaObj) && method_exists($metaObj, 'getMetrics')) {
                    foreach ($metaObj->getMetrics() as $m) {
                        if (!is_object($m) || !method_exists($m, 'getApiName')) {
                            continue;
                        }
                        $deprecated = [];
                        if (method_exists($m, 'getDeprecatedApiNames')) {
                            foreach ($m->getDeprecatedApiNames() as $n) {
                                if (is_string($n) && $n !== '') {
                                    $deprecated[] = $n;
                                }
                            }
                        }
                        $mets[] = [
                            'apiName' => (string)$m->getApiName(),
                            'deprecatedApiNames' => $deprecated,
                        ];
                    }
                }
                $meta = [
                    'name' => $name,
                    'dimensions' => $dims,
                    'metrics' => $mets,
                ];

                ga4_write_cached_metadata($pid, $meta);
                return ['ok' => true, 'metadata' => $meta, 'source' => 'live'];
            }
        }
    } catch (Throwable $e) {
        log_error('GA4 metadata fetch failed (client)', [
            'propertyId' => $pid,
            'endpoint' => 'getMetadata(properties/' . $pid . '/metadata)',
            // Best-effort: ApiException code often matches HTTP status (e.g., 404).
            'http' => (int)$e->getCode(),
            'error' => $e->getMessage(),
        ]);
        // Continue to legacy HTTP fallback below.
    }

    // Legacy fallback: direct HTTP call (still v1beta). Keep for resilience.
    $tok = get_google_access_token(GA4_SCOPE_READONLY);
    if (!($tok['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Failed to get Google access token for GA4 metadata'];
    }
    $accessToken = (string)($tok['access_token'] ?? '');
    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'Access token missing for GA4 metadata'];
    }

    $url = GA4_DATA_API_BASE_V1BETA . '/properties/' . rawurlencode($pid) . '/metadata';
    $resp = ga4_http_get($url, $accessToken);
    if (!($resp['ok'] ?? false)) {
        log_error('GA4 metadata request failed', [
            'propertyId' => $pid,
            'endpoint' => $url,
            'error' => (string)($resp['error'] ?? 'unknown'),
            'http' => (int)($resp['http'] ?? 0),
        ]);
        return ['ok' => false, 'error' => 'GA4 metadata request failed'];
    }

    $http = (int)($resp['http'] ?? 0);
    $body = (string)($resp['body'] ?? '');
    if ($http < 200 || $http >= 300) {
        log_error('GA4 metadata non-2xx', [
            'propertyId' => $pid,
            'endpoint' => $url,
            'http' => $http,
            'response_snippet' => substr($body, 0, 500),
        ]);
        return ['ok' => false, 'error' => 'GA4 metadata non-2xx'];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        log_error('GA4 metadata response invalid JSON', [
            'propertyId' => $pid,
            'endpoint' => $url,
            'http' => $http,
            'response_snippet' => substr($body, 0, 200),
        ]);
        return ['ok' => false, 'error' => 'GA4 metadata invalid JSON'];
    }

    ga4_write_cached_metadata($pid, $json);
    return ['ok' => true, 'metadata' => $json, 'source' => 'live'];
}

function ga4_metadata_field_sets(array $metadata): array
{
    $dims = [];
    $mets = [];

    foreach ((array)($metadata['dimensions'] ?? []) as $d) {
        if (!is_array($d)) {
            continue;
        }
        $apiName = $d['apiName'] ?? null;
        if (is_string($apiName) && $apiName !== '') {
            $dims[$apiName] = true;
        }
        foreach ((array)($d['deprecatedApiNames'] ?? []) as $n) {
            if (is_string($n) && $n !== '') {
                $dims[$n] = true;
            }
        }
    }

    foreach ((array)($metadata['metrics'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $apiName = $m['apiName'] ?? null;
        if (is_string($apiName) && $apiName !== '') {
            $mets[$apiName] = true;
        }
        foreach ((array)($m['deprecatedApiNames'] ?? []) as $n) {
            if (is_string($n) && $n !== '') {
                $mets[$n] = true;
            }
        }
    }

    return ['dimensions' => $dims, 'metrics' => $mets];
}

/**
 * Returns mapping/validation result for report field names.
 *
 * - Maps known invalid/deprecated names to valid names when possible.
 * - Skips unknown fields (with log context) to prevent INVALID_ARGUMENT.
 *
 * NOTE: This only validates metric/dimension names. If you rely on a specific
 * field being present for calculations, handle missing fields downstream by
 * defaulting to 0 / null.
 */
function ga4_map_and_validate_fields(string $propertyId, array $metrics, array $dimensions, array $logCtx = []): array
{
    // Explicit compatibility mappings for known INVALID_ARGUMENT cases.
    $metricMap = [
        // "purchases" is not a valid GA4 Data API metric; use transactions (ecommerce transactions).
        'purchases' => 'transactions',
    ];
    $dimensionMap = [
        // v1 (deprecated/invalid) -> v1beta:
        // defaultChannelGroup is incompatible with several metrics; prefer sessionDefaultChannelGroup.
        'defaultChannelGroup' => 'sessionDefaultChannelGroup',
    ];

    $metaRes = ga4_get_metadata($propertyId);
    if (!($metaRes['ok'] ?? false) || !is_array($metaRes['metadata'] ?? null)) {
        // If metadata cannot be fetched, do not block reporting; still apply hard-coded mappings.
        log_warn('GA4 metadata unavailable; applying only static schema mappings', [
            'propertyId' => $propertyId,
            'error' => (string)($metaRes['error'] ?? 'unknown'),
            'ctx' => $logCtx,
        ]);

        $mappedMetrics = [];
        foreach ($metrics as $m) {
            $name = (string)$m;
            $mappedMetrics[] = $metricMap[$name] ?? $name;
        }
        $mappedDims = [];
        foreach ($dimensions as $d) {
            $name = (string)$d;
            $mappedDims[] = $dimensionMap[$name] ?? $name;
        }
        return [
            'ok' => true,
            'source' => 'none',
            'metrics' => $mappedMetrics,
            'dimensions' => $mappedDims,
            'skipped_metrics' => [],
            'skipped_dimensions' => [],
        ];
    }

    $sets = ga4_metadata_field_sets((array)$metaRes['metadata']);
    $metricSet = (array)($sets['metrics'] ?? []);
    $dimensionSet = (array)($sets['dimensions'] ?? []);

    $outMetrics = [];
    $skippedMetrics = [];
    $needsPurchaseEventFilter = false;
    foreach ($metrics as $m) {
        $name = (string)$m;
        $mapped = $metricMap[$name] ?? $name;
        // Fallback: if ecommerce "transactions" metric is unavailable, approximate purchases via eventCount filtered to eventName == "purchase".
        if ($name === 'purchases' && $mapped === 'transactions' && !isset($metricSet['transactions']) && isset($metricSet['eventCount']) && isset($dimensionSet['eventName'])) {
            $mapped = 'eventCount';
            $needsPurchaseEventFilter = true;
            log_warn('GA4 purchases fallback: using eventCount filtered by eventName=purchase', [
                'propertyId' => $propertyId,
                'ctx' => $logCtx,
            ]);
        }
        if (!isset($metricSet[$mapped])) {
            $skippedMetrics[] = $name;
            continue;
        }
        if ($mapped !== $name) {
            log_info('GA4 metric mapped for schema compatibility', [
                'propertyId' => $propertyId,
                'from' => $name,
                'to' => $mapped,
                'ctx' => $logCtx,
            ]);
        }
        $outMetrics[] = $mapped;
    }

    $outDims = [];
    $skippedDims = [];
    foreach ($dimensions as $d) {
        $name = (string)$d;
        $mapped = $dimensionMap[$name] ?? $name;
        if (!isset($dimensionSet[$mapped])) {
            $skippedDims[] = $name;
            continue;
        }
        if ($mapped !== $name) {
            log_info('GA4 dimension mapped for schema compatibility', [
                'propertyId' => $propertyId,
                'from' => $name,
                'to' => $mapped,
                'ctx' => $logCtx,
            ]);
        }
        $outDims[] = $mapped;
    }

    if ($skippedMetrics !== []) {
        log_warn('GA4 invalid metrics skipped (preventing INVALID_ARGUMENT)', [
            'propertyId' => $propertyId,
            'skipped' => array_values($skippedMetrics),
            'ctx' => $logCtx,
        ]);
    }
    if ($skippedDims !== []) {
        log_warn('GA4 invalid dimensions skipped (preventing INVALID_ARGUMENT)', [
            'propertyId' => $propertyId,
            'skipped' => array_values($skippedDims),
            'ctx' => $logCtx,
        ]);
    }

    return [
        'ok' => true,
        'source' => (string)($metaRes['source'] ?? 'live'),
        'metrics' => array_values($outMetrics),
        'dimensions' => array_values($outDims),
        'skipped_metrics' => array_values($skippedMetrics),
        'skipped_dimensions' => array_values($skippedDims),
        'needs_purchase_event_filter' => $needsPurchaseEventFilter,
    ];
}

