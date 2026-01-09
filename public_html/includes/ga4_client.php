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

        // Build a credentials *object* (decoded array may be ignored by some versions).
        $sa = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $creds);

        // Auth preflight: fetch an access token once during client build.
        // If this fails, disable GA4 early to avoid repeated runReport spam.
        try {
            $httpHandler = null;
            if (class_exists(\Google\Auth\HttpHandler\HttpHandlerFactory::class)) {
                $httpHandler = \Google\Auth\HttpHandler\HttpHandlerFactory::build();
            }
            $token = $sa->fetchAuthToken($httpHandler);
        } catch (Throwable $e) {
            $cached = ['ok' => false, 'error' => 'GA4 service account token preflight failed'];
            log_error('GA4 client init failed: token preflight exception', [
                'keyPath' => $keyPath,
                'error' => $e->getMessage(),
            ]);
            return $cached;
        }

        $accessToken = (is_array($token) && isset($token['access_token']) && is_string($token['access_token']))
            ? (string)$token['access_token']
            : '';
        if ($accessToken === '') {
            $cached = ['ok' => false, 'error' => 'GA4 service account token preflight failed (token_missing)'];
            log_error('GA4 client init failed: token_missing', [
                'keyPath' => $keyPath,
            ]);
            return $cached;
        }

        log_info('GA4 client auth preflight', [
            'keyPath' => $keyPath,
            'token_ok' => true,
            'token_len' => strlen($accessToken),
            'has_expires_in' => (is_array($token) && array_key_exists('expires_in', $token)),
            'scopes' => $scopes,
        ]);

        $clientOptions = [
            // Primary: explicit credentials object.
            // Fallback: GOOGLE_APPLICATION_CREDENTIALS env var (set above, if empty before).
            'credentials' => $sa,
            'scopes' => $scopes,
        ];

        $forceRest = (defined('GA4_FORCE_REST') && (bool)GA4_FORCE_REST);
        if ($forceRest) {
            $clientOptions['transport'] = 'rest';
        }

        try {
            $client = new \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient($clientOptions);
        } catch (Throwable $e) {
            // If transport option isn't supported by this version, retry without it.
            if ($forceRest && array_key_exists('transport', $clientOptions)) {
                log_warn('GA4 client init: transport=rest unsupported; retrying without transport', [
                    'keyPath' => $keyPath,
                    'error' => $e->getMessage(),
                ]);
                unset($clientOptions['transport']);
                $client = new \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient($clientOptions);
            } else {
                throw $e;
            }
        }

        $cached = ['ok' => true, 'client' => $client];
        return $cached;
    } catch (Throwable $e) {
        $cached = ['ok' => false, 'error' => $e->getMessage()];
        log_error('GA4 client init failed', ['error' => $e->getMessage()]);
        return $cached;
    }
}

