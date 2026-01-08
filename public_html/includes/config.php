<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/bootstrap_errors.php';

// =========================
// App configuration
// =========================

// IMPORTANT: Set these before uploading to production.
const APP_NAME = 'Reporting System (Phase 2)';

// If your app is in a subfolder, set e.g. '/reports'. Otherwise leave ''.
const BASE_PATH = '';

// Database configuration (MySQL)
const DB_HOST = 'localhost';
const DB_NAME = 'u683371179_atassss';
const DB_USER = 'u683371179_atassss';
const DB_PASS = '6y8NNx$A';
const DB_CHARSET = 'utf8mb4';

// Security/session
const SESSION_NAME = 'rs_phase1';

// In production, keep this false.
const APP_DEBUG = false;

// =========================
// Phase 2: GA4 Integration
// =========================
// Enable/disable GA4 calls globally. When disabled (or no key), the app uses mock analytics.
const GA4_ENABLED = false;

// Service Account JSON key path.
// - Recommended: absolute path
// - If relative: it's resolved relative to /includes
// Default: /public_html/includes/keys/service-account.json
const GOOGLE_SA_KEY_PATH = __DIR__ . '/keys/ataskaitu-sistema-6a64265403e6.json';

// Cached OAuth token file (must be writable by PHP).
const GA4_TOKEN_CACHE_FILE = __DIR__ . '/cache/ga4_token.json';

// Cache TTL (Google tokens are usually 3600s). Use slightly less.
const GA4_TOKEN_CACHE_TTL_SECONDS = 3300;

// Best-effort: ensure GA4 folders exist (shared hosting friendly).
// These folders should NOT be web-accessible; if your app is inside public_html,
// keep the provided .htaccess files in /includes/keys and /includes/cache.
@mkdir(__DIR__ . '/keys', 0755, true);
@mkdir(__DIR__ . '/cache', 0775, true);

date_default_timezone_set('UTC');

// Safety: don't expose errors/stack traces to users; use file logging instead.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

// Start session early (needed for auth, CSRF, flash).
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/security.php';
send_security_headers();

