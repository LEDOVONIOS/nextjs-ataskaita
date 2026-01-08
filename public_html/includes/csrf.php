<?php
declare(strict_types=1);

const CSRF_SESSION_KEY = '_csrf_token';

function csrf_token(): string
{
    if (!isset($_SESSION[CSRF_SESSION_KEY]) || !is_string($_SESSION[CSRF_SESSION_KEY]) || $_SESSION[CSRF_SESSION_KEY] === '') {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION[CSRF_SESSION_KEY];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify_or_die(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION[CSRF_SESSION_KEY] ?? '';
    if (!is_string($sent) || !is_string($expected) || $sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
        http_response_code(400);
        echo 'Bad Request (CSRF)';
        exit;
    }
}

