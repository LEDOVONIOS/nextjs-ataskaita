<?php
declare(strict_types=1);

/**
 * Create a new PDO connection (no caching).
 */
function db_new(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, DB_USER, DB_PASS, $options);
}

/**
 * Close the cached PDO connection (best-effort).
 * Next call to db() will create a fresh connection.
 */
function db_close(): void
{
    // PDO closes when all references are gone; dropping the cached reference is enough.
    $GLOBALS['__APP_PDO'] = null;
}

function db(): PDO
{
    $pdo = $GLOBALS['__APP_PDO'] ?? null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = db_new();
    $GLOBALS['__APP_PDO'] = $pdo;
    return $pdo;
}

