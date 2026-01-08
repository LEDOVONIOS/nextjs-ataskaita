<?php
declare(strict_types=1);

function url(string $path): string
{
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    if ($base === '') {
        return $path;
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return rtrim($base, '/') . $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash_set(string $key, string $message): void
{
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }
    $msg = (string)$_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

function flash_all(): array
{
    $all = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($all) ? $all : [];
}

function current_year_month(): array
{
    return [(int)date('Y'), (int)date('n')];
}

function month_name(int $month): string
{
    $names = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];
    return $names[$month] ?? 'Unknown';
}

