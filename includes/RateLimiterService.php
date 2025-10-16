<?php
/**
 * includes/RateLimiterService.php
 * 
 * Enhanced rate limiting using nikolaposa/rate-limit
 * Provides more sophisticated rate limiting with better configuration
 */

declare(strict_types=1);

use RateLimit\InMemoryRateLimiter;
use RateLimit\Rate;
use RateLimit\RedisRateLimiter;
use RateLimit\RuntimeConfigurableRateLimiter;
use RateLimit\Status;

class RateLimiterService
{
    private static ?RuntimeConfigurableRateLimiter $limiter = null;
    
    /**
     * Initialize the rate limiter
     */
    private static function getLimiter(): RuntimeConfigurableRateLimiter
    {
        if (self::$limiter === null) {
            $defaultRate = Rate::perMinute(1);

            if (self::isRedisAvailable()) {
                $limiter = new RedisRateLimiter($defaultRate, self::getRedisClient(), 'sfm:');
            } else {
                $limiter = new InMemoryRateLimiter($defaultRate);
            }

            self::$limiter = new RuntimeConfigurableRateLimiter($limiter);
        }
        
        return self::$limiter;
    }
    
    /**
     * Check if Redis is available
     */
    private static function isRedisAvailable(): bool
    {
        return class_exists('Redis') && 
               !empty(getenv('REDIS_HOST')) && 
               !empty(getenv('REDIS_PORT'));
    }
    
    /**
     * Get Redis client
     */
    private static function getRedisClient(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(
            getenv('REDIS_HOST') ?: '127.0.0.1',
            (int)(getenv('REDIS_PORT') ?: 6379)
        );
        
        if ($password = getenv('REDIS_PASSWORD')) {
            $redis->auth($password);
        }
        
        if ($database = getenv('REDIS_DATABASE')) {
            $redis->select((int)$database);
        }
        
        return $redis;
    }
    
    /**
     * Check if a request is allowed
     */
    public static function isAllowed(
        string $identifier,
        int $operations,
        int $intervalInSeconds,
        string $context = 'default'
    ): array {
        $limiter = self::getLimiter();
        $key = self::getKey($identifier, $context);
        $rate = Rate::custom($operations, $intervalInSeconds);

        try {
            $status = $limiter->limitSilently($key, $rate);
            $allowed = !$status->limitExceeded();

            if (!$allowed) {
                self::logRateLimitAttempt($identifier, $context, $operations, $intervalInSeconds);
            }

            return [
                'allowed' => $allowed,
                'remaining' => $status->getRemainingAttempts(),
                'reset_at' => $status->getResetAt()->getTimestamp(),
                'limit' => $status->getLimit(),
                'interval' => $intervalInSeconds,
            ];
        } catch (\Throwable $e) {
            self::logRateLimitError($e, $identifier, $context);

            return [
                'allowed' => true,
                'remaining' => $operations,
                'reset_at' => 0,
                'limit' => $operations,
                'interval' => $intervalInSeconds,
            ];
        }
    }
    
    /**
     * Generate cache key for rate limiting
     */
    private static function getKey(string $identifier, string $context): string
    {
        // Sanitize identifier for cache key
        $identifier = preg_replace('/[^a-zA-Z0-9._-]/', '_', $identifier);
        $context = preg_replace('/[^a-zA-Z0-9._-]/', '_', $context);
        
        return "rate_limit:{$context}:{$identifier}";
    }
    
    /**
     * Get rate limit configuration for different contexts
     */
    public static function getRateLimitConfig(string $context): array
    {
        $configs = [
            'generate' => [
                'operations' => 10,  // 10 feed generations
                'interval' => 60,   // per minute
                'block_duration' => 300, // 5 minutes
            ],
            'admin_login' => [
                'operations' => 5,
                'interval' => 300, // 5 login attempts per 5 minutes
                'block_duration' => 900, // 15 minutes
            ],
            'admin_action' => [
                'operations' => 100,
                'interval' => 60, // 100 admin actions per minute
                'block_duration' => 60,
            ],
            'api' => [
                'operations' => 60,
                'interval' => 60, // 60 API requests per minute
                'block_duration' => 180,
            ],
            'default' => [
                'operations' => 30,
                'interval' => 60, // 30 requests per minute
                'block_duration' => 120,
            ],
        ];
        
        return $configs[$context] ?? $configs['default'];
    }
    
    /**
     * Check rate limit with automatic configuration
     */
    public static function checkRateLimit(string $identifier, string $context = 'default'): array
    {
        $config = self::getRateLimitConfig($context);
        
        // Check for existing blocks first
        if (self::isBlocked($identifier, $context)) {
            $timeRemaining = self::getBlockTimeRemaining($identifier, $context);
            return [
                'allowed' => false,
                'blocked' => true,
                'remaining' => 0,
                'reset_at' => $timeRemaining,
                'limit' => $config['operations'],
                'interval' => $config['interval'],
                'message' => "Rate limit exceeded. Please try again in {$timeRemaining} seconds."
            ];
        }
        
        $result = self::isAllowed($identifier, $config['operations'], $config['interval'], $context);
        
        // If not allowed, apply block
        if (!$result['allowed']) {
            self::applyBlock($identifier, $context, $config['block_duration']);
            $result['blocked'] = true;
            $result['reset_at'] = $config['block_duration'];
            $result['message'] = "Rate limit exceeded. Please try again in {$config['block_duration']} seconds.";
        } else {
            $result['blocked'] = false;
        }
        
        return $result;
    }
    
    /**
     * Get client identifier (IP address, user ID, etc.)
     */
    public static function getIdentifier(): string
    {
        // Priorities: user ID > session ID > IP address
        if (isset($_SESSION['user_id'])) {
            return 'user_' . (string)$_SESSION['user_id'];
        }
        
        $sessionId = session_id();
        if (is_string($sessionId) && $sessionId !== '') {
            return 'session_' . $sessionId;
        }
        
        // Get real IP address accounting for proxies
        $ip = self::getRealIpAddress();
        return 'ip_' . $ip;
    }
    
    /**
     * Get real IP address (accounts for trusted proxies)
     */
    private static function getRealIpAddress(): string
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_CLIENT_IP',
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Check if identifier is blocked
     */
    private static function isBlocked(string $identifier, string $context): bool
    {
        $blockFile = self::getBlockFile($identifier, $context);
        
        if (!file_exists($blockFile)) {
            return false;
        }
        
        $data = json_decode(file_get_contents($blockFile), true);
        return $data && isset($data['expires']) && $data['expires'] > time();
    }
    
    /**
     * Apply block to identifier
     */
    private static function applyBlock(string $identifier, string $context, int $duration): void
    {
        $blockFile = self::getBlockFile($identifier, $context);
        $blockDir = dirname($blockFile);
        
        if (!is_dir($blockDir)) {
            mkdir($blockDir, 0755, true);
        }
        
        $blockData = [
            'identifier' => $identifier,
            'context' => $context,
            'blocked_at' => time(),
            'expires' => time() + $duration,
            'duration' => $duration,
        ];
        
        file_put_contents($blockFile, json_encode($blockData));
    }
    
    /**
     * Get remaining block time
     */
    private static function getBlockTimeRemaining(string $identifier, string $context): int
    {
        $blockFile = self::getBlockFile($identifier, $context);
        
        if (!file_exists($blockFile)) {
            return 0;
        }
        
        $data = json_decode(file_get_contents($blockFile), true);
        if (!$data || !isset($data['expires'])) {
            return 0;
        }
        
        $remaining = $data['expires'] - time();
        return max(0, $remaining);
    }
    
    /**
     * Get block file path
     */
    private static function getBlockFile(string $identifier, string $context): string
    {
        $hash = md5($identifier . $context);
        // Use the same directory structure as other security files
        return __DIR__ . '/../secure/rate_blocks/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }
    
    /**
     * Clean up expired blocks
     */
    public static function cleanupExpiredBlocks(): int
    {
        $blockDir = __DIR__ . '/../secure/rate_blocks';
        $cleaned = 0;
        
        if (!is_dir($blockDir)) {
            return $cleaned;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($blockDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $data = json_decode(file_get_contents($file->getPathname()), true);
                if ($data && isset($data['expires']) && $data['expires'] <= time()) {
                    unlink($file->getPathname());
                    $cleaned++;
                }
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Log rate limit attempts
     */
    private static function logRateLimitAttempt(string $identifier, string $context, int $operations, int $interval): void
    {
        $logMessage = sprintf(
            '[RateLimit] Blocked: %s | Context: %s | Limit: %d/%ds | Time: %s',
            $identifier,
            $context,
            $operations,
            $interval,
            date('Y-m-d H:i:s')
        );
        
        error_log($logMessage);
    }
    
    /**
     * Log rate limiting errors
     */
    private static function logRateLimitError(\Throwable $e, string $identifier, string $context): void
    {
        $logMessage = sprintf(
            '[RateLimit] Error: %s | Identifier: %s | Context: %s | Error: %s',
            $e->getMessage(),
            $identifier,
            $context,
            $e->getTraceAsString()
        );
        
        error_log($logMessage);
    }
    
    /**
     * Get rate limit status for logging
     */
    public static function getRateLimitStatus(string $identifier, string $context): array
    {
        $config = self::getRateLimitConfig($context);
        $result = self::checkRateLimit($identifier, $context);
        
        return [
            'identifier' => $identifier,
            'context' => $context,
            'config' => $config,
            'status' => $result,
            'timestamp' => time(),
        ];
    }
}
