<?php
declare(strict_types=1);

// =========================
// App configuration
// =========================

// IMPORTANT: Set these before uploading to production.
const APP_NAME = 'Reporting System (Phase 1)';

// If your app is in a subfolder, set e.g. '/reports'. Otherwise leave ''.
const BASE_PATH = '';

// Database configuration (MySQL)
const DB_HOST = 'localhost';
const DB_NAME = 'reporting_system';
const DB_USER = 'db_user';
const DB_PASS = 'db_password';
const DB_CHARSET = 'utf8mb4';

// Security/session
const SESSION_NAME = 'rs_phase1';

// In production, keep this false.
const APP_DEBUG = false;

date_default_timezone_set('UTC');

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

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

