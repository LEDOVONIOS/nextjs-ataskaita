<?php
declare(strict_types=1);

/**
 * File-based error logger.
 *
 * Format per line:
 * [UTC timestamp] [LEVEL] message {json_context}
 */
function _log_line(string $level, string $message, array $context = []): void
{
    $logFile = __DIR__ . '/../storage/logs/app.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $jsonContext = '{}';
    if (!empty($context)) {
        try {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $jsonContext = $json;
            }
        } catch (Throwable $e) {
            // Ignore JSON encoding issues; fall back to empty context.
        }
    }

    $lvl = strtoupper(trim($level));
    if ($lvl === '') {
        $lvl = 'INFO';
    }

    $line = '[' . $timestamp . '] [' . $lvl . '] ' . $message . ' ' . $jsonContext . PHP_EOL;

    $ok = false;
    try {
        $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        $ok = ($written !== false);
    } catch (Throwable $e) {
        $ok = false;
    }

    if (!$ok) {
        // Fallback: use PHP's error log if file logging fails.
        error_log(rtrim($line));
    }
}

function log_info(string $message, array $context = []): void
{
    _log_line('INFO', $message, $context);
}

function log_warn(string $message, array $context = []): void
{
    _log_line('WARN', $message, $context);
}

function log_error(string $message, array $context = []): void
{
    _log_line('ERROR', $message, $context);
}

