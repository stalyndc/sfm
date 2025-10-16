<?php
/**
 * includes/LoggerService.php
 * 
 * Enhanced logging service using Monolog
 * Centralized logging with multiple handlers and structured logging
 */

declare(strict_types=1);

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;

class LoggerService
{
    private static ?Logger $logger = null;
    
    /**
     * Get the logger instance (singleton)
     */
    public static function getLogger(): Logger
    {
        if (self::$logger === null) {
            self::$logger = self::createLogger();
        }
        
        return self::$logger;
    }
    
    /**
     * Create and configure the logger
     */
    private static function createLogger(): Logger
    {
        $logger = new Logger('simplefeedmaker');
        
        // Add handlers based on environment
        $logger->pushHandler(self::createStreamHandler());
        $logger->pushHandler(self::createRotatingFileHandler());
        
        // Add processors for better context
        $logger->pushProcessor(new WebProcessor());
        $logger->pushProcessor(new MemoryUsageProcessor());
        $logger->pushProcessor(new ProcessIdProcessor());
        $logger->pushProcessor(new UidProcessor());
        $logger->pushProcessor(new RequestIdProcessor());
        
        return $logger;
    }
    
    /**
     * Create stream handler (outputs to php://stderr in CLI)
     */
    private static function createStreamHandler(): StreamHandler
    {
        // Only add stream handler in CLI environment
        if (php_sapi_name() === 'cli') {
            return new StreamHandler('php://stderr', Level::Warning);
        }
        
        // In web environment, only log errors to stream
        return new StreamHandler('php://stderr', Level::Error);
    }
    
    /**
     * Create rotating file handler for persistent logging
     */
    private static function createRotatingFileHandler(): RotatingFileHandler
    {
        $logFile = self::getLogFilePath();
        $logLevel = self::getLogLevel();
        
        return new RotatingFileHandler(
            $logFile,
            10, // Keep 10 files
            $logLevel
        );
    }
    
    /**
     * Get log file path
     */
    private static function getLogFilePath(): string
    {
        // Try secure/logs first, fallback to storage/logs
        $logDirs = [
            __DIR__ . '/../secure/logs/app.log',
            __DIR__ . '/../storage/logs/app.log',
        ];
        
        foreach ($logDirs as $logFile) {
            $logDir = dirname($logFile);
            if (!is_dir($logDir) && is_writable(dirname($logDir))) {
                mkdir($logDir, 0755, true);
            }
            
            if (is_writable($logDir) || file_exists($logFile)) {
                return $logFile;
            }
        }
        
        // Fallback to system temp
        return sys_get_temp_dir() . '/sfm-app.log';
    }
    
    /**
     * Get log level from environment
     */
    private static function getLogLevel(): Level
    {
        $envLevel = strtoupper((string) (getenv('SFM_LOG_LEVEL') ?: 'INFO'));

        try {
            return Level::fromName($envLevel);
        } catch (\UnhandledMatchError) {
            return Level::Info;
        }
    }
    
    /**
     * Log generation request
     */
    public static function logGenerationRequest(string $url, string $format, int $limit, array $context = []): void
    {
        self::getLogger()->info('Feed generation request', [
            'url' => $url,
            'format' => $format,
            'limit' => $limit,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'context' => $context,
        ]);
    }
    
    /**
     * Log successful generation
     */
    public static function logGenerationSuccess(string $feedUrl, int $itemsCount, float $generationTime, array $context = []): void
    {
        self::getLogger()->info('Feed generation successful', [
            'feed_url' => $feedUrl,
            'items_count' => $itemsCount,
            'generation_time_seconds' => round($generationTime, 3),
            'context' => $context,
        ]);
    }
    
    /**
     * Log generation error
     */
    public static function logGenerationError(string $error, \Throwable $exception = null, array $context = []): void
    {
        self::getLogger()->error('Feed generation failed', [
            'error' => $error,
            'exception' => $exception ? [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ] : null,
            'context' => $context,
        ]);
    }
    
    /**
     * Log invalid selector
     */
    public static function logInvalidSelector(string $selector, string $type, array $context = []): void
    {
        self::getLogger()->warning('Invalid CSS selector', [
            'selector' => $selector,
            'type' => $type,
            'context' => $context,
        ]);
    }
    
    /**
     * Log rate limit breach
     */
    public static function logRateLimitBreach(string $identifier, string $reason, array $limitConfig, array $context = []): void
    {
        self::getLogger()->warning('Rate limit exceeded', [
            'identifier' => $identifier,
            'reason' => $reason,
            'limit' => $limitConfig,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'context' => $context,
        ]);
    }
    
    /**
     * Log admin action
     */
    public static function logAdminAction(string $action, string $user = null, array $context = []): void
    {
        self::getLogger()->info('Admin action', [
            'action' => $action,
            'user' => $user,
            'context' => $context,
        ]);
    }
    
    /**
     * Log performance metrics
     */
    public static function logPerformance(string $operation, float $duration, array $metrics = []): void
    {
        self::getLogger()->info('Performance metric', [
            'operation' => $operation,
            'duration_seconds' => round($duration, 3),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'metrics' => $metrics,
        ]);
    }
    
    /**
     * Log security event
     */
    public static function logSecurityEvent(string $event, string $severity = 'info', array $context = []): void
    {
        $level = match ($severity) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            default => Level::Info,
        };
        
        self::getLogger()->log($level, "Security event: {$event}", [
            'event' => $event,
            'severity' => $severity,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'context' => $context,
        ]);
    }
    
    /**
     * Log cleanup activity
     */
    public static function logCleanup(string $type, int $itemsProcessed, int $itemsRemoved, float $duration): void
    {
        self::getLogger()->info('Cleanup completed', [
            'type' => $type,
            'items_processed' => $itemsProcessed,
            'items_removed' => $itemsRemoved,
            'duration_seconds' => round($duration, 3),
        ]);
    }
    
    /**
     * Log system health check
     */
    public static function logHealthCheck(array $healthStatus): void
    {
        $status = $healthStatus['healthy'] ? 'healthy' : 'unhealthy';
        self::getLogger()->info("Health check: {$status}", $healthStatus);
    }
    
    /**
     * Log with custom context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $logLevel = match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
        
        self::getLogger()->log($logLevel, $message, $context);
    }
    
    /**
     * Get logger stats
     */
    public static function getStats(): array
    {
        $handler = self::getLogger()->getHandlers();
        $fileHandler = null;
        
        foreach ($handler as $h) {
            if ($h instanceof RotatingFileHandler) {
                $fileHandler = $h;
                break;
            }
        }
        
        $stats = [
            'handlers' => [],
            'log_file' => null,
            'log_directory' => null,
        ];
        
        foreach ($handler as $h) {
            $stats['handlers'][] = get_class($h);
        }
        
        if ($fileHandler) {
            $logFile = self::reflectionGetProperty($fileHandler, 'url');
            $stats['log_file'] = $logFile;
            $stats['log_directory'] = dirname($logFile);
        }
        
        return $stats;
    }
    
    /**
     * Get private property using reflection
     */
    private static function reflectionGetProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }
}

/**
 * Custom processor to add request ID
 */
class RequestIdProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $extra['request_id'] = $_SERVER['HTTP_X_REQUEST_ID'];
        } elseif (!array_key_exists('request_id', $extra) && function_exists('random_bytes')) {
            $extra['request_id'] = bin2hex(random_bytes(8));
        }

        return $record->with(extra: $extra);
    }
}
