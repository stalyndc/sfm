<?php
/**
 * includes/logging.php
 *
 * Logging configuration using Monolog for SimpleFeedMaker
 * - Structured logging with context
 * - Multiple log channels (app, security, performance)
 * - Log rotation and archival
 * - Integration with existing log directory structure
 *
 * Dependencies:
 *  - monolog/monolog
 *  - vlucas/phpdotenv
 */

declare(strict_types=1);

require_once __DIR__ . '/../secure/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;

/**
 * Global logger instances */
if (!array_key_exists('sfm_loggers', $GLOBALS)) {
    $GLOBALS['sfm_loggers'] = [];
}

/**
 * Get or create a logger instance for a specific channel
 */
function sfm_get_logger(string $channel = 'app'): Logger
{
    if (isset($GLOBALS['sfm_loggers'][$channel])) {
        return $GLOBALS['sfm_loggers'][$channel];
    }

    $logger = new Logger($channel);
    
    // Determine log level from environment
    $logLevel = defined('SFM_LOG_LEVEL') ? strtoupper(SFM_LOG_LEVEL) : 'INFO';
    $level = match($logLevel) {
        'DEBUG' => Level::Debug,
        'INFO' => Level::Info,
        'WARNING' => Level::Warning,
        'ERROR' => Level::Error,
        'CRITICAL' => Level::Critical,
        default => Level::Info,
    };

    // Determine log path
    $defaultLogDir = dirname(__DIR__) . '/secure/logs';
    $logDir = defined('SFM_LOG_PATH') ? dirname(SFM_LOG_PATH) : $defaultLogDir;
    $logFile = defined('SFM_LOG_PATH') ? SFM_LOG_PATH : $defaultLogDir . '/app.log';

    // Ensure log directory exists
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    // Create rotating file handler with 30 days retention
    $rotatingHandler = new RotatingFileHandler(
        filename: $logFile,
        maxFiles: 30,
        level: $level
    );

    // Custom formatter for better readability
    $formatter = new LineFormatter(
        format: "[%datetime%] [%channel%.%level_name%] %message% %context% %extra%\n",
        dateFormat: "Y-m-d H:i:s",
        allowInlineLineBreaks: true,
        ignoreEmptyContextAndExtra: true
    );
    
    $rotatingHandler->setFormatter($formatter);
    $logger->pushHandler($rotatingHandler);

    // Add error_log handler for critical errors
    $errorLogHandler = new StreamHandler('php://stderr', Level::Error);
    $errorLogHandler->setFormatter($formatter);
    $logger->pushHandler($errorLogHandler);

    $GLOBALS['sfm_loggers'][$channel] = $logger;
    return $logger;
}

/**
 * Convenience function for logging within the app channel
 */
function sfm_log_debug(string $message, array $context = []): void
{
    sfm_get_logger('app')->debug($message, $context);
}

function sfm_log_info(string $message, array $context = []): void
{
    sfm_get_logger('app')->info($message, $context);
}

function sfm_log_warning(string $message, array $context = []): void
{
    sfm_get_logger('app')->warning($message, $context);
}

function sfm_log_error(string $message, array $context = []): void
{
    sfm_get_logger('app')->error($message, $context);
}

function sfm_log_critical(string $message, array $context = []): void
{
    sfm_get_logger('app')->critical($message, $context);
}

/**
 * Security-specific logging
 */
function sfm_log_security(string $message, array $context = []): void
{
    sfm_get_logger('security')->warning($message, $context);
}

/**
 * Performance logging
 */
function sfm_log_performance(string $message, array $context = []): void
{
    sfm_get_logger('performance')->info($message, $context);
}

/**
 * Feed processing logging
 */
function sfm_log_feed(string $message, array $context = []): void
{
    sfm_get_logger('feed')->info($message, $context);
}

/**
 * HTTP request logging
 */
function sfm_log_http(string $message, array $context = []): void
{
    sfm_get_logger('http')->info($message, $context);
}

/**
 * Error handler integration
 */
function sfm_log_exception(\Throwable $exception, array $context = []): void
{
    sfm_get_logger('app')->error($exception->getMessage(), array_merge($context, [
        'exception_type' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]));
}

/**
 * Backward compatibility with existing logging functions
 */
if (!function_exists('sfm_log')) {
    function sfm_log(string $level, string $message, array $context = []): void
    {
        $logger = sfm_get_logger('app');
        switch (strtoupper($level)) {
            case 'DEBUG':
                $logger->debug($message, $context);
                break;
            case 'INFO':
                $logger->info($message, $context);
                break;
            case 'WARNING':
            case 'WARN':
                $logger->warning($message, $context);
                break;
            case 'ERROR':
                $logger->error($message, $context);
                break;
            case 'CRITICAL':
            case 'CRIT':
                $logger->critical($message, $context);
                break;
            default:
                $logger->info($message, $context);
        }
    }
}
