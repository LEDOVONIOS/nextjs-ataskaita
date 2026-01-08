<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';

// Never expose stack traces/errors to end users.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');

/**
 * Best-effort: emit a generic 500 response without leaking details.
 */
function _app_emit_generic_500(): void
{
    if (PHP_SAPI === 'cli') {
        // Keep CLI output minimal and non-sensitive.
        fwrite(STDERR, "An unexpected error occurred.\n");
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "An unexpected error occurred.";
}

set_exception_handler(static function (Throwable $e): void {
    log_error('Uncaught exception', [
        'exception_class' => get_class($e),
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    _app_emit_generic_500();
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Respect @-suppressed errors and current error_reporting().
    if (!(error_reporting() & $severity)) {
        return false;
    }

    log_error('PHP error', [
        'severity' => $severity,
        'error' => $message,
        'file' => $file,
        'line' => $line,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);

    // Prevent PHP from outputting the default error handler (which can leak details).
    return true;
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    log_error('Fatal error', [
        'severity' => $err['type'] ?? null,
        'error' => $err['message'] ?? null,
        'file' => $err['file'] ?? null,
        'line' => $err['line'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);

    _app_emit_generic_500();
});

