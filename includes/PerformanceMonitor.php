<?php
/**
 * includes/PerformanceMonitor.php
 * 
 * Simple performance monitoring and metrics collection
 * Tracks memory usage, execution time, and other key metrics
 */

declare(strict_types=1);

class PerformanceMonitor
{
    private static array $timers = [];
    private static array $metrics = [];
    private static float $startTime = 0.0;
    
    /**
     * Start timing an operation
     */
    public static function startTimer(string $name): void
    {
        self::$timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(),
        ];
        
        if (empty(self::$startTime)) {
            self::$startTime = microtime(true);
        }
    }
    
    /**
     * End timing an operation and log performance data
     */
    public static function endTimer(string $name): array
    {
        if (!isset(self::$timers[$name])) {
            return [];
        }
        
        $timer = self::$timers[$name];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $duration = $endTime - $timer['start'];
        $memoryDiff = $endMemory - $timer['memory_start'];
        
        $performanceData = [
            'operation' => $name,
            'duration_seconds' => round($duration, 4),
            'memory_before' => $timer['memory_start'],
            'memory_after' => $endMemory,
            'memory_diff' => $memoryDiff,
            'timestamp' => $endTime,
        ];
        
        unset(self::$timers[$name]);
        self::logPerformance($performanceData);
        
        return $performanceData;
    }
    
    /**
     * Record a custom performance metric
     */
    public static function recordMetric(string $name, mixed $value, array $context = []): void
    {
        $metric = [
            'name' => $name,
            'value' => $value,
            'timestamp' => microtime(true),
            'context' => $context,
        ];
        
        self::$metrics[] = $metric;
        self::logPerformance($metric);
    }
    
    /**
     * Get all recorded metrics
     */
    public static function getMetrics(): array
    {
        return self::$metrics;
    }
    
    /**
     * Get all performance timers
     */
    public static function getTimers(): array
    {
        return self::$timers;
    }
    
    /**
     * Get performance summary for the entire request
     */
    public static function getPerformanceSummary(): array
    {
        if (self::$startTime <= 0.0) {
            self::$startTime = microtime(true);
        }

        $totalTime = microtime(true) - self::$startTime;
        
        return [
            'total_request_time' => round($totalTime, 3),
            'operation_count' => count(self::$timers),
            'metric_count' => count(self::$metrics),
            'memory_peak' => memory_get_peak_usage(true),
            'current_memory' => memory_get_usage(true),
            'timestamp' => time(),
        ];
    }
    
    /**
     * Reset all performance tracking
     */
    public static function reset(): void
    {
        self::$timers = [];
        self::$metrics = [];
        self::$startTime = microtime(true);
    }
    
    /**
     * Log performance data to the logger
     */
    private static function logPerformance(array $data): void
    {
        // Requested to use LoggerService if available
        $operation = $data['operation'] ?? 'unknown';

        if (class_exists('LoggerService')) {
            $logger = LoggerService::getLogger();
            $logger->info('Performance metric', [
                'operation' => $operation,
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'memory_before' => $data['memory_before'] ?? null,
                'memory_after' => $data['memory_after'] ?? null,
                'memory_diff' => $data['memory_diff'] ?? null,
                'timestamp' => $data['timestamp'] ?? microtime(true),
            ]);
            return;
        }

        if (!self::isDebugEnabled()) {
            return;
        }

        error_log(
            sprintf(
                'Performance metric: %s - Duration: %ss - Memory diff: %s bytes',
                $operation,
                $data['duration_seconds'] ?? 0,
                $data['memory_diff'] ?? 0
            )
        );
    }
    
    /**
     * Check if debugging is enabled
     */
    private static function isDebugEnabled(): bool
    {
        $value = getenv('SFM_DEBUG');

        if ($value === false) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
    
    /**
     * Get memory usage as percentage of memory limit
     */
    public static function getMemoryUsagePercentage(): float
    {
        $memoryLimit = self::convertToBytes(ini_get('memory_limit'));
        $currentUsage = memory_get_usage(true);

        if ($memoryLimit <= 0) {
            return 0.0;
        }

        return ($currentUsage / $memoryLimit) * 100;
    }
    
    /**
     * Get server load information
     */
    public static function getServerLoad(): array
    {
        $load = sys_getloadavg();

        return [
            'load_1m' => $load[0] ?? null,
            'load_5m' => $load[1] ?? null,
            'load_15m' => $load[2] ?? null,
            'timestamp' => time(),
        ];
    }
    
    /**
     * Get disk usage information
     */
    public static function getDiskUsage(): array
    {
        $totalSpace = @disk_total_space('/') ?: 0;
        $freeSpace = @disk_free_space('/') ?: 0;
        $usedSpace = $totalSpace - $freeSpace;
        
        return [
            'total_bytes' => $totalSpace,
            'free_bytes' => $freeSpace,
            'used_bytes' => $usedSpace,
            'usage_percentage' => $totalSpace > 0 ? ($usedSpace / $totalSpace) * 100 : 0,
            'timestamp' => time(),
        ];
    }
    
    /**
     * Get process list for system monitoring
     */
    public static function getProcessList(): array
    {
        $processes = [];

        if (function_exists('exec')) {
            @exec('ps aux', $processes);
        }

        return $processes;
    }
    
    /**
     * Check if server is under load
     */
    public static function isSystemUnderLoad(): bool
    {
        $load = self::getServerLoad();
        
        // Consider under load if 15-minute load average > 80%
        return isset($load['load_15m']) && $load['load_15m'] > 80;
    }
    
    /**
     * Health check based on performance metrics
     */
    public static function performHealthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'timestamp' => time(),
        ];
        
        // Memory check
        $memoryUsage = self::getMemoryUsagePercentage();
        if ($memoryUsage > 80) {
            $health['checks'][] = [
                'check' => 'memory',
                'status' => 'warning',
                'message' => 'High memory usage: ' . round($memoryUsage, 1) . '%',
            ];
            $health['status'] = 'warning';
        }
        
        // Load check
        if (self::isSystemUnderLoad()) {
            $health['checks'][] = [
                'check' => 'load',
                'status' => 'warning',
                'message' => 'High system load detected',
            ];
            $health['status'] = 'warning';
        }
        
        // Disk space check
        $diskInfo = self::getDiskUsage();
        if ($diskInfo['usage_percentage'] > 90) {
            $health['checks'][] = [
                'check' => 'disk',
                'status' => 'critical',
                'message' => 'High disk usage: ' . round($diskInfo['usage_percentage'], 1) . '%',
            ];
            $health['status'] = 'critical';
        }
        
        return $health;
    }
    
    /**
     * Get cron job statistics
     */
    public static function getCronStats(): array
    {
        $cronLog = __DIR__ . '/../storage/logs/cron_refresh.log';
        
        if (!file_exists($cronLog)) {
            return [
                'entries' => 0,
                'last_run' => null,
                'average_interval' => null,
                'errors' => 0,
                'success_rate' => 0,
                'timestamp' => time(),
            ];
        }
        
        $lines = file($cronLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $errorCount = 0;
        $lastRun = null;
        $runTimes = [];
        
        foreach ($lines as $line) {
            if (strpos($line, 'ERROR') !== false) {
                $errorCount++;
            }
            
            if (preg_match('/\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $timestamp = strtotime($matches[1] . ' ' . $matches[2]);
                if ($timestamp !== false) {
                    $runTimes[] = $timestamp;
                    $lastRun = $timestamp;
                }
            }
        }
        
        $totalRuns = count($runTimes);
        $averageInterval = null;

        if ($totalRuns > 1) {
            sort($runTimes);
            $intervalSum = 0;
            for ($i = 1; $i < $totalRuns; $i++) {
                $intervalSum += $runTimes[$i] - $runTimes[$i - 1];
            }

            $averageInterval = $intervalSum / ($totalRuns - 1);
        }

        $successRate = null;
        if ($totalRuns > 0) {
            $successfulRuns = max($totalRuns - $errorCount, 0);
            $successRate = ($successfulRuns / $totalRuns) * 100;
        }

        return [
            'entries' => count($lines),
            'last_run' => $lastRun,
            'average_interval' => $averageInterval,
            'errors' => $errorCount,
            'success_rate' => $successRate,
            'timestamp' => time(),
        ];
    }

    private static function convertToBytes(string|false $value): int
    {
        if ($value === false || $value === '' || $value === '-1') {
            return -1;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) substr($value, 0, -1);

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }
}
