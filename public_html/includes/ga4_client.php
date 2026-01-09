<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/ga4_requirements.php';

/**
 * Builds a single GA4 Analytics Data API client (service account).
 *
 * Credentials path resolution:
 * - Prefer environment variable GOOGLE_SA_KEY_PATH
 * - Fall back to constant GOOGLE_SA_KEY_PATH (from includes/config.php)
 * - Finally, fall back to GOOGLE_APPLICATION_CREDENTIALS (path), if set
 */
function ga4_build_client(array $context = []): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $req = ga4_requirements_check($context + ['component' => 'ga4_client']);
    if (!($req['ok'] ?? false)) {
        $cached = [
            'ok' => false,
            'error' => 'GA4 requirements not met: ' . (string)($req['reason'] ?? 'unknown'),
        ];
        log_error('GA4 disabled', [
            'reason' => (string)($req['reason'] ?? 'unknown'),
            'autoload' => (string)($req['autoload'] ?? ''),
            'context' => $context,
        ]);
        return $cached;
    }

    $keyPath = trim((string)(getenv('GOOGLE_SA_KEY_PATH') ?: ''));
    if ($keyPath === '' && defined('GOOGLE_SA_KEY_PATH')) {
        $keyPath = trim((string)GOOGLE_SA_KEY_PATH);
    }
    if ($keyPath === '') {
        // Commonly configured on shared hosting; treat as an explicit path if present.
        $keyPath = trim((string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''));
    }
    if ($keyPath === '') {
        $cached = [
            'ok' => false,
            'error' => 'Service account key path is not configured',
        ];
        log_error('GA4 client init failed: missing key path');
        return $cached;
    }
    // Relative paths are resolved relative to /includes (same as existing GA4 phase-2 helpers).
    if ($keyPath !== '' && $keyPath[0] !== '/' && !preg_match('/^[A-Za-z]:\\\\/', $keyPath)) {
        $keyPath = __DIR__ . '/' . ltrim($keyPath, '/');
    }

    $exists = is_file($keyPath);
    $readable = $exists && is_readable($keyPath);
    log_info('GA4 client build: credentials resolved', [
        'keyPath' => $keyPath,
        'file_exists' => $exists,
        'file_readable' => $readable,
        'context' => $context,
    ]);

    if (!$exists) {
        $cached = [
            'ok' => false,
            'error' => 'Service account key file not found',
        ];
        log_error('GA4 client init failed: key file not found', ['keyPath' => $keyPath]);
        return $cached;
    }
    if (!$readable) {
        $cached = [
            'ok' => false,
            'error' => 'Service account key file is not readable',
        ];
        log_error('GA4 client init failed: key file not readable', ['keyPath' => $keyPath]);
        return $cached;
    }

    // Fallback for internal ADC lookups (only set if not already configured).
    $adcBefore = trim((string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''));
    $setAdc = false;
    if ($adcBefore === '') {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $keyPath);
        $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $keyPath;
        $_SERVER['GOOGLE_APPLICATION_CREDENTIALS'] = $keyPath;
        $setAdc = true;
    }
    log_info('GA4 auth env (GOOGLE_APPLICATION_CREDENTIALS)', [
        'before' => $adcBefore !== '' ? $adcBefore : '(empty)',
        'after' => (string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''),
        'set' => $setAdc,
    ]);

    try {
        // IMPORTANT:
        // - BetaAnalyticsDataClient must use service-account auth only.
        // - Do not use GA4_TOKEN_CACHE_FILE / google_auth.php token-cache flow here.
        $scopes = ['https://www.googleapis.com/auth/analytics.readonly'];

        $raw = @file_get_contents($keyPath);
        if (!is_string($raw) || $raw === '') {
            $cached = [
                'ok' => false,
                'error' => 'Failed to read service account key file',
            ];
            log_error('GA4 client init failed: failed to read key file', ['keyPath' => $keyPath]);
            return $cached;
        }
        $creds = json_decode($raw, true);
        if (!is_array($creds) || $creds === []) {
            $cached = [
                'ok' => false,
                'error' => 'Service account key file is not valid JSON',
            ];
            log_error('GA4 client init failed: invalid key JSON', [
                'keyPath' => $keyPath,
                'json_error' => json_last_error_msg(),
            ]);
            return $cached;
        }

        $jsonType = isset($creds['type']) && is_string($creds['type']) ? trim($creds['type']) : '';
        $hasClientEmail = isset($creds['client_email']) && is_string($creds['client_email']) && trim($creds['client_email']) !== '';
        $hasPrivateKey = isset($creds['private_key']) && is_string($creds['private_key']) && trim($creds['private_key']) !== '';

        log_info('GA4 client build: credential JSON inspected', [
            'keyPath' => $keyPath,
            'json_type' => $jsonType !== '' ? $jsonType : null,
            'has_client_email' => $hasClientEmail,
            'has_private_key' => $hasPrivateKey,
            'scopes' => $scopes,
        ]);

        $missingFields = [];
        if ($jsonType === '') {
            $missingFields[] = 'type';
        }
        if (!$hasClientEmail) {
            $missingFields[] = 'client_email';
        }
        if (!$hasPrivateKey) {
            $missingFields[] = 'private_key';
        }
        $invalidType = ($jsonType !== '' && $jsonType !== 'service_account') ? $jsonType : null;

        if ($invalidType !== null || $missingFields !== []) {
            $cached = [
                'ok' => false,
                'error' => 'Service account key JSON is not a valid service account credential',
            ];
            log_error('GA4 client init failed: invalid service account key JSON', [
                'keyPath' => $keyPath,
                'json_type' => $jsonType !== '' ? $jsonType : null,
                'invalid_type' => $invalidType,
                'missing_fields' => $missingFields,
            ]);
            return $cached;
        }

        $client = new \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient([
            // analytics-data v0.22.x: pass credentials explicitly (path or decoded JSON array).
            // Primary: decoded service-account key array.
            // Fallback: GOOGLE_APPLICATION_CREDENTIALS env var (set above, if empty before).
            'credentials' => $creds,
            'scopes' => $scopes,
        ]);
        $cached = ['ok' => true, 'client' => $client];
        return $cached;
    } catch (Throwable $e) {
        $cached = ['ok' => false, 'error' => $e->getMessage()];
        log_error('GA4 client init failed', ['error' => $e->getMessage()]);
        return $cached;
    }
}

