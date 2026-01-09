<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';

/**
 * Builds a single GA4 Analytics Data API client (service account).
 *
 * Credentials path resolution:
 * - Prefer environment variable GOOGLE_SA_KEY_PATH
 * - Fall back to constant GOOGLE_SA_KEY_PATH (from includes/config.php)
 */
function ga4_build_client(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        $cached = [
            'ok' => false,
            'error' => 'Missing Composer autoload at ' . $autoload,
        ];
        log_error('GA4 client init failed: missing vendor autoload', ['autoload' => $autoload]);
        return $cached;
    }
    require_once $autoload;

    $keyPath = trim((string)(getenv('GOOGLE_SA_KEY_PATH') ?: ''));
    if ($keyPath === '' && defined('GOOGLE_SA_KEY_PATH')) {
        $keyPath = trim((string)GOOGLE_SA_KEY_PATH);
    }
    if ($keyPath === '') {
        $cached = [
            'ok' => false,
            'error' => 'GOOGLE_SA_KEY_PATH is not configured',
        ];
        log_error('GA4 client init failed: missing key path');
        return $cached;
    }
    // Relative paths are resolved relative to /includes (same as existing GA4 phase-2 helpers).
    if ($keyPath !== '' && $keyPath[0] !== '/' && !preg_match('/^[A-Za-z]:\\\\/', $keyPath)) {
        $keyPath = __DIR__ . '/' . ltrim($keyPath, '/');
    }
    if (!is_file($keyPath)) {
        $cached = [
            'ok' => false,
            'error' => 'Service account key file not found',
        ];
        log_error('GA4 client init failed: key file not found', ['keyPath' => $keyPath]);
        return $cached;
    }

    try {
        $client = new \Google\Analytics\Data\V1beta\BetaAnalyticsDataClient([
            'credentials' => $keyPath,
        ]);
        $cached = ['ok' => true, 'client' => $client];
        return $cached;
    } catch (Throwable $e) {
        $cached = ['ok' => false, 'error' => $e->getMessage()];
        log_error('GA4 client init failed', ['error' => $e->getMessage()]);
        return $cached;
    }
}

