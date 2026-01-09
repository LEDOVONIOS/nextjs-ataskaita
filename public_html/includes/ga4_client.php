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

        // Safe-to-log identifiers (never log private_key).
        log_info('GA4 client build: credential identifiers', [
            'client_email' => (isset($creds['client_email']) && is_string($creds['client_email'])) ? trim($creds['client_email']) : null,
            'project_id' => (isset($creds['project_id']) && is_string($creds['project_id'])) ? trim($creds['project_id']) : null,
            'private_key_id' => (isset($creds['private_key_id']) && is_string($creds['private_key_id'])) ? trim($creds['private_key_id']) : null,
            'token_uri' => (isset($creds['token_uri']) && is_string($creds['token_uri'])) ? trim($creds['token_uri']) : null,
            'universe_domain' => (isset($creds['universe_domain']) && is_string($creds['universe_domain'])) ? trim($creds['universe_domain']) : null,
        ]);

        // Strict private_key integrity check (never log the key; only booleans).
        $privateKey = (isset($creds['private_key']) && is_string($creds['private_key'])) ? $creds['private_key'] : '';
        $privateKeyHasPemMarkers = ($privateKey !== '')
            && (strpos($privateKey, '-----BEGIN PRIVATE KEY-----') !== false)
            && (strpos($privateKey, '-----END PRIVATE KEY-----') !== false);
        $privateKeyHasRealNewlines = ($privateKey !== '') && (strpos($privateKey, "\n") !== false);
        $privateKeyHasEscapedNewlines = ($privateKey !== '') && (strpos($privateKey, "\\n") !== false);
        log_info('GA4 client build: private_key integrity', [
            'private_key_has_pem_markers' => $privateKeyHasPemMarkers,
            'private_key_has_real_newlines' => $privateKeyHasRealNewlines,
            'private_key_has_escaped_newlines' => $privateKeyHasEscapedNewlines,
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

        // Fail fast if private_key is clearly malformed (avoid GA4 calls).
        if (!$privateKeyHasPemMarkers) {
            $cached = ['ok' => false, 'error' => 'Service account private_key is not PEM formatted'];
            log_error('GA4 client init failed: invalid private_key format (pem_markers_missing)', [
                'private_key_has_pem_markers' => $privateKeyHasPemMarkers,
                'private_key_has_real_newlines' => $privateKeyHasRealNewlines,
                'private_key_has_escaped_newlines' => $privateKeyHasEscapedNewlines,
            ]);
            return $cached;
        }
        if (!$privateKeyHasRealNewlines && !$privateKeyHasEscapedNewlines) {
            $cached = ['ok' => false, 'error' => 'Service account private_key is not PEM formatted (missing newlines)'];
            log_error('GA4 client init failed: invalid private_key format (no_newlines)', [
                'private_key_has_pem_markers' => $privateKeyHasPemMarkers,
                'private_key_has_real_newlines' => $privateKeyHasRealNewlines,
                'private_key_has_escaped_newlines' => $privateKeyHasEscapedNewlines,
            ]);
            return $cached;
        }

        $normalizationPossible = ($privateKeyHasEscapedNewlines && !$privateKeyHasRealNewlines);
        $normalized = false;

        // OpenSSL sanity check: validate the PEM can be parsed (never log key material).
        $pkey = @openssl_pkey_get_private((string)($creds['private_key'] ?? ''));
        if ($pkey === false && $normalizationPossible && !$normalized) {
            $creds['private_key'] = str_replace("\\n", "\n", (string)$creds['private_key']);
            $normalized = true;
            $pkey = @openssl_pkey_get_private((string)($creds['private_key'] ?? ''));
        }

        $opensslParsed = ($pkey !== false);
        $opensslType = null;
        $opensslBits = null;
        if ($opensslParsed) {
            $details = @openssl_pkey_get_details($pkey);
            if (is_array($details)) {
                $opensslType = $details['type'] ?? null;
                $opensslBits = $details['bits'] ?? null;
            }
            if (function_exists('openssl_pkey_free')) {
                @openssl_pkey_free($pkey);
            }
        }
        log_info('GA4 client build: openssl private key parsed', [
            'openssl_private_key_parsed' => $opensslParsed,
            'openssl_key_type' => $opensslType,
            'openssl_key_bits' => $opensslBits,
        ]);
        if (!$opensslParsed) {
            $cached = ['ok' => false, 'error' => 'Service account private_key could not be parsed by OpenSSL'];
            log_error('GA4 client init failed: openssl_pkey_get_private failed', [
                'client_email' => (isset($creds['client_email']) && is_string($creds['client_email'])) ? trim($creds['client_email']) : null,
                'private_key_id' => (isset($creds['private_key_id']) && is_string($creds['private_key_id'])) ? trim($creds['private_key_id']) : null,
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
            $extractOauthError = static function (Throwable $t): array {
                $msg = (string)$t->getMessage();
                $payloads = [$msg];
                $prev = $t->getPrevious();
                while ($prev instanceof Throwable) {
                    $payloads[] = (string)$prev->getMessage();
                    $prev = $prev->getPrevious();
                }

                foreach ($payloads as $s) {
                    $decoded = null;
                    $tmp = json_decode($s, true);
                    if (is_array($tmp)) {
                        $decoded = $tmp;
                    } else {
                        if (preg_match('/(\{.*\})/s', $s, $m) === 1) {
                            $tmp2 = json_decode($m[1], true);
                            if (is_array($tmp2)) {
                                $decoded = $tmp2;
                            }
                        }
                    }

                    if (!is_array($decoded)) {
                        continue;
                    }

                    // OAuth token endpoint error schema:
                    // { "error": "invalid_grant", "error_description": "..." }
                    if (isset($decoded['error']) && is_string($decoded['error'])) {
                        return [
                            'oauth_error_code' => $decoded['error'],
                            'oauth_error_description' => (isset($decoded['error_description']) && is_string($decoded['error_description']))
                                ? $decoded['error_description']
                                : null,
                        ];
                    }

                    // Some clients may wrap errors.
                    if (isset($decoded['error']) && is_array($decoded['error'])) {
                        $inner = $decoded['error'];
                        $code = (isset($inner['status']) && is_string($inner['status'])) ? $inner['status'] : null;
                        $desc = (isset($inner['message']) && is_string($inner['message'])) ? $inner['message'] : null;
                        if ($code !== null || $desc !== null) {
                            return ['oauth_error_code' => $code, 'oauth_error_description' => $desc];
                        }
                    }
                }

                return ['oauth_error_code' => null, 'oauth_error_description' => null];
            };

            // One retry if the private_key looks like it has escaped newlines (\\n) instead of real newlines.
            if ($normalizationPossible && !$normalized) {
                $creds['private_key'] = str_replace("\\n", "\n", (string)$creds['private_key']);
                $normalized = true;

                $privateKey2 = (isset($creds['private_key']) && is_string($creds['private_key'])) ? $creds['private_key'] : '';
                $hasRealNewlines2 = ($privateKey2 !== '') && (strpos($privateKey2, "\n") !== false);
                if (!$hasRealNewlines2) {
                    $cached = ['ok' => false, 'error' => 'Service account private_key is not PEM formatted (newline normalization failed)'];
                    log_error('GA4 client init failed: invalid private_key format (normalization_failed)', [
                        'private_key_has_pem_markers' => $privateKeyHasPemMarkers,
                        'private_key_has_real_newlines' => $hasRealNewlines2,
                        'private_key_has_escaped_newlines' => $privateKeyHasEscapedNewlines,
                    ]);
                    return $cached;
                }

                $sa = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $creds);
                try {
                    $token = $sa->fetchAuthToken($httpHandler);
                } catch (Throwable $e2) {
                    $cached = ['ok' => false, 'error' => 'GA4 service account token preflight failed'];
                    $oauth = $extractOauthError($e2);
                    log_error('GA4 client init failed: token preflight exception', [
                        'keyPath' => $keyPath,
                        'error' => $e2->getMessage(),
                        'oauth_error_code' => $oauth['oauth_error_code'],
                        'oauth_error_description' => $oauth['oauth_error_description'],
                        'client_email' => (isset($creds['client_email']) && is_string($creds['client_email'])) ? trim($creds['client_email']) : null,
                        'private_key_id' => (isset($creds['private_key_id']) && is_string($creds['private_key_id'])) ? trim($creds['private_key_id']) : null,
                    ]);
                    return $cached;
                }
            } else {
            $cached = ['ok' => false, 'error' => 'GA4 service account token preflight failed'];
            $oauth = $extractOauthError($e);
            log_error('GA4 client init failed: token preflight exception', [
                'keyPath' => $keyPath,
                'error' => $e->getMessage(),
                'oauth_error_code' => $oauth['oauth_error_code'],
                'oauth_error_description' => $oauth['oauth_error_description'],
                'client_email' => (isset($creds['client_email']) && is_string($creds['client_email'])) ? trim($creds['client_email']) : null,
                'private_key_id' => (isset($creds['private_key_id']) && is_string($creds['private_key_id'])) ? trim($creds['private_key_id']) : null,
            ]);
            return $cached;
            }
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

