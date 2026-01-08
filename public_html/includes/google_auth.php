<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Resolve absolute/relative paths for GA4 config.
 * If $path is relative, it's treated as relative to /includes.
 */
function ga4_resolve_path(string $path): string
{
    $p = trim($path);
    if ($p === '') {
        return '';
    }

    $isAbsolute = ($p[0] === '/')
        || (preg_match('/^[A-Za-z]:\\\\/', $p) === 1); // Windows drive path

    if ($isAbsolute) {
        return $p;
    }

    return __DIR__ . '/' . ltrim($p, '/');
}

function base64url_encode(string $data): string
{
    $b64 = base64_encode($data);
    if ($b64 === false) {
        return '';
    }
    return rtrim(strtr($b64, '+/', '-_'), '=');
}

/**
 * Reads and validates the Service Account JSON key.
 * Returns: ['ok'=>true, 'key'=>[...]] OR ['ok'=>false, 'error'=>['message'=>..., 'details'=>...]]
 */
function load_service_account_key(): array
{
    $path = ga4_resolve_path((string)GOOGLE_SA_KEY_PATH);
    if ($path === '' || !is_file($path)) {
        return [
            'ok' => false,
            'error' => [
                'message' => 'GA4 Service Account key file not found.',
                'details' => ['path' => $path ?: '(empty)'],
            ],
        ];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [
            'ok' => false,
            'error' => [
                'message' => 'Failed to read GA4 Service Account key file.',
                'details' => ['path' => $path],
            ],
        ];
    }

    $key = json_decode($raw, true);
    if (!is_array($key)) {
        return [
            'ok' => false,
            'error' => [
                'message' => 'GA4 Service Account key file is not valid JSON.',
                'details' => ['path' => $path],
            ],
        ];
    }

    $required = ['client_email', 'private_key', 'token_uri'];
    foreach ($required as $f) {
        if (!isset($key[$f]) || !is_string($key[$f]) || trim($key[$f]) === '') {
            return [
                'ok' => false,
                'error' => [
                    'message' => 'GA4 Service Account key is missing required fields.',
                    'details' => ['missing' => $f],
                ],
            ];
        }
    }

    return ['ok' => true, 'key' => $key];
}

function ga4_create_jwt(array $serviceAccountKey, string $scope): array
{
    if (!extension_loaded('openssl')) {
        return [
            'ok' => false,
            'error' => ['message' => 'OpenSSL extension is required for GA4 JWT signing.', 'details' => []],
        ];
    }

    $now = time();
    $tokenUri = (string)$serviceAccountKey['token_uri'];
    $clientEmail = (string)$serviceAccountKey['client_email'];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => $clientEmail,
        'scope' => $scope,
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($headerJson === false || $payloadJson === false) {
        return [
            'ok' => false,
            'error' => ['message' => 'Failed to encode GA4 JWT.', 'details' => []],
        ];
    }

    $segments = base64url_encode($headerJson) . '.' . base64url_encode($payloadJson);
    if ($segments === '.' || str_contains($segments, '..')) {
        return [
            'ok' => false,
            'error' => ['message' => 'Failed to build GA4 JWT segments.', 'details' => []],
        ];
    }

    $privateKeyPem = (string)$serviceAccountKey['private_key'];
    $signature = '';
    $ok = openssl_sign($segments, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
    if ($ok !== true) {
        return [
            'ok' => false,
            'error' => ['message' => 'Failed to sign GA4 JWT (openssl_sign).', 'details' => []],
        ];
    }

    $jwt = $segments . '.' . base64url_encode($signature);
    return ['ok' => true, 'jwt' => $jwt];
}

function ga4_http_post_form(string $url, array $fields): array
{
    $body = http_build_query($fields);
    $headers = ['Content-Type: application/x-www-form-urlencoded'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ]);
        $respBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'http' => $status, 'body' => '', 'error' => $err ?: ('cURL error ' . $errno)];
        }
        return ['ok' => true, 'http' => $status, 'body' => is_string($respBody) ? $respBody : ''];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 20,
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
    return ['ok' => true, 'http' => $status, 'body' => is_string($respBody) ? $respBody : ''];
}

/**
 * Exchanges a signed JWT for an access token and caches it.
 */
function get_google_access_token(string $scope): array
{
    $cachePath = ga4_resolve_path((string)GA4_TOKEN_CACHE_FILE);
    if ($cachePath !== '' && is_file($cachePath)) {
        $raw = @file_get_contents($cachePath);
        if (is_string($raw) && $raw !== '') {
            $cached = json_decode($raw, true);
            if (is_array($cached)) {
                $token = $cached['access_token'] ?? null;
                $expiresAt = $cached['expires_at'] ?? null;
                if (is_string($token) && $token !== '' && is_int($expiresAt) && $expiresAt > (time() + 60)) {
                    return ['ok' => true, 'access_token' => $token, 'source' => 'cache'];
                }
            }
        }
    }

    $keyRes = load_service_account_key();
    if (!$keyRes['ok']) {
        return $keyRes;
    }
    $key = (array)$keyRes['key'];

    $jwtRes = ga4_create_jwt($key, $scope);
    if (!$jwtRes['ok']) {
        return $jwtRes;
    }

    $tokenUri = (string)$key['token_uri'];
    $postRes = ga4_http_post_form($tokenUri, [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => (string)$jwtRes['jwt'],
    ]);

    if (!$postRes['ok']) {
        return [
            'ok' => false,
            'error' => [
                'message' => 'Failed to request Google OAuth token.',
                'details' => ['http' => $postRes['http'] ?? 0, 'error' => $postRes['error'] ?? 'unknown'],
            ],
        ];
    }

    $http = (int)$postRes['http'];
    $body = (string)$postRes['body'];
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'error' => [
                'message' => 'Google OAuth token response was not valid JSON.',
                'details' => ['http' => $http],
            ],
        ];
    }

    if ($http < 200 || $http >= 300) {
        return [
            'ok' => false,
            'error' => [
                'message' => 'Google OAuth token request failed.',
                'details' => [
                    'http' => $http,
                    'error' => $json['error'] ?? null,
                    'error_description' => $json['error_description'] ?? null,
                ],
            ],
        ];
    }

    $token = $json['access_token'] ?? null;
    $expiresIn = $json['expires_in'] ?? 0;
    if (!is_string($token) || $token === '') {
        return [
            'ok' => false,
            'error' => ['message' => 'Google OAuth token response missing access_token.', 'details' => ['http' => $http]],
        ];
    }

    $expiresInInt = is_numeric($expiresIn) ? (int)$expiresIn : 0;
    $ttl = (int)GA4_TOKEN_CACHE_TTL_SECONDS;
    $useTtl = $expiresInInt > 0 ? min($expiresInInt, $ttl) : $ttl;
    $expiresAt = time() + max(60, $useTtl) - 30;

    // Cache best-effort (never fail hard if caching is unavailable).
    if ($cachePath !== '') {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents(
                $cachePath,
                json_encode(['access_token' => $token, 'expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
    }

    return ['ok' => true, 'access_token' => $token, 'source' => 'live'];
}

