<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';

/**
 * Central GA4 requirements guard.
 *
 * Shared hosting note:
 * - vendor/ will be uploaded to /public_html/vendor/
 * - Resolve Composer autoload via $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php'
 *
 * Returns:
 *  - ['ok'=>true, 'autoload'=>string]
 *  - ['ok'=>false, 'reason'=>string, 'autoload'=>string]
 */
function ga4_requirements_check(array $context = []): array
{
    static $cached = null;
    static $loggedFailure = false;

    if (is_array($cached)) {
        return $cached;
    }

    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $autoloadPath = $docRoot . '/vendor/autoload.php';

    if ($docRoot === '') {
        $cached = [
            'ok' => false,
            'reason' => 'DOCUMENT_ROOT is empty; cannot resolve vendor/autoload.php',
            'autoload' => $autoloadPath,
        ];
        if (!$loggedFailure) {
            $loggedFailure = true;
            log_error('GA4 requirements failed: missing DOCUMENT_ROOT', [
                'autoload' => $autoloadPath,
                'context' => $context,
            ]);
        }
        return $cached;
    }

    if (!is_file($autoloadPath) || !is_readable($autoloadPath)) {
        $cached = [
            'ok' => false,
            'reason' => 'Missing or unreadable Composer autoload.php',
            'autoload' => $autoloadPath,
        ];
        if (!$loggedFailure) {
            $loggedFailure = true;
            log_error('GA4 requirements failed: missing vendor autoload', [
                'autoload' => $autoloadPath,
                'context' => $context,
            ]);
        }
        return $cached;
    }

    require_once $autoloadPath;

    $clientClass = \Google\Analytics\Data\V1beta\BetaAnalyticsDataClient::class;
    if (!class_exists($clientClass)) {
        $cached = [
            'ok' => false,
            'reason' => 'GA4 client class not found after autoload',
            'autoload' => $autoloadPath,
        ];
        if (!$loggedFailure) {
            $loggedFailure = true;
            log_error('GA4 requirements failed: missing GA4 client class', [
                'autoload' => $autoloadPath,
                'expected_class' => $clientClass,
                'context' => $context,
            ]);
        }
        return $cached;
    }

    $cached = [
        'ok' => true,
        'autoload' => $autoloadPath,
    ];
    return $cached;
}

function ga4_requirements_ok(array $context = []): bool
{
    $res = ga4_requirements_check($context);
    return (bool)($res['ok'] ?? false);
}

