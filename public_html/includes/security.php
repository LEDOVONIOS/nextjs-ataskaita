<?php
declare(strict_types=1);

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo 'Method Not Allowed';
        exit;
    }
}

function safe_int(mixed $value, int $default = 0): int
{
    if ($value === null) {
        return $default;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && $value !== '' && preg_match('/^-?\d+$/', $value)) {
        return (int)$value;
    }
    return $default;
}

function safe_string(mixed $value, string $default = ''): string
{
    if ($value === null) {
        return $default;
    }
    if (is_string($value)) {
        return $value;
    }
    return $default;
}

