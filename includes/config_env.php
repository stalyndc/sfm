<?php
/**
 * includes/config_env.php
 * 
 * Enhanced configuration loader that integrates with .env files
 * and loads the original config.php for backward compatibility
 */

declare(strict_types=1);

// Load Composer autoloader for dotenv
if (file_exists(__DIR__ . '/../secure/vendor/autoload.php')) {
    require_once __DIR__ . '/../secure/vendor/autoload.php';
    
    // Load environment variables from .env file
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    } catch (\InvalidArgumentException $e) {
        // .env file doesn't exist, that's okay for production
    }
}

// Load original configuration
require_once __DIR__ . '/config.php';

/* -----------------------------------------------------------
   Enhanced configuration from environment variables
   ----------------------------------------------------------- */

// Cache configuration
if (!defined('SFM_CACHE_TTL')) {
    define('SFM_CACHE_TTL', (int)($_ENV['SFM_CACHE_TTL'] ?? 3600));
}

if (!defined('SFM_CACHE_DIR')) {
    define('SFM_CACHE_DIR', $_ENV['SFM_CACHE_DIR'] ?? dirname(__DIR__) . '/feeds/.httpcache');
}

// Rate limiting configuration
if (!defined('SFM_RATE_LIMIT_REQUESTS')) {
    define('SFM_RATE_LIMIT_REQUESTS', (int)($_ENV['SFM_RATE_LIMIT_REQUESTS'] ?? 20));
}

if (!defined('SFM_RATE_LIMIT_WINDOW')) {
    define('SFM_RATE_LIMIT_WINDOW', (int)($_ENV['SFM_RATE_LIMIT_WINDOW'] ?? 60));
}

// HTTP client configuration
if (!defined('SFM_HTTP_TIMEOUT')) {
    define('SFM_HTTP_TIMEOUT', (int)($_ENV['SFM_HTTP_TIMEOUT'] ?? 30));
}

if (!defined('SFM_HTTP_CONNECT_TIMEOUT')) {
    define('SFM_HTTP_CONNECT_TIMEOUT', (int)($_ENV['SFM_HTTP_CONNECT_TIMEOUT'] ?? 10));
}

if (!defined('SFM_HTTP_USER_AGENT')) {
    define('SFM_HTTP_USER_AGENT', $_ENV['SFM_HTTP_USER_AGENT'] ?? 'SimpleFeedMaker/1.0 (+https://simplefeedmaker.com)');
}

// Logging configuration
if (!defined('SFM_LOG_LEVEL')) {
    define('SFM_LOG_LEVEL', $_ENV['SFM_LOG_LEVEL'] ?? 'info');
}

if (!defined('SFM_LOG_PATH')) {
    define('SFM_LOG_PATH', $_ENV['SFM_LOG_PATH'] ?? dirname(__DIR__) . '/secure/logs/app.log');
}

// Security configuration
if (!defined('SFM_ALERT_EMAIL')) {
    define('SFM_ALERT_EMAIL', $_ENV['SFM_ALERT_EMAIL'] ?? null);
}

if (!defined('SFM_HEALTH_ALERT_EMAIL')) {
    define('SFM_HEALTH_ALERT_EMAIL', $_ENV['SFM_HEALTH_ALERT_EMAIL'] ?? null);
}

if (!defined('SFM_TRUSTED_PROXIES')) {
    define('SFM_TRUSTED_PROXIES', $_ENV['SFM_TRUSTED_PROXIES'] ?? null);
}

// Feed processing configuration
if (!defined('SFM_MAX_FEED_ITEMS')) {
    define('SFM_MAX_FEED_ITEMS', (int)($_ENV['SFM_MAX_FEED_ITEMS'] ?? 50));
}

if (!defined('SFM_MAX_CONTENT_LENGTH')) {
    define('SFM_MAX_CONTENT_LENGTH', (int)($_ENV['SFM_MAX_CONTENT_LENGTH'] ?? 100000));
}

// Application settings override
if (!empty($_ENV['SFM_APP_NAME']) && !defined('APP_NAME')) {
    define('APP_NAME', $_ENV['SFM_APP_NAME']);
}

if (!empty($_ENV['SFM_ENVIRONMENT']) && !defined('DEBUG')) {
    $env = strtolower($_ENV['SFM_ENVIRONMENT']);
    define('DEBUG', $env === 'development' || $env === 'dev' || $env === 'debug');
}

// Create cache directory if it doesn't exist
if (!is_dir(SFM_CACHE_DIR)) {
    @mkdir(SFM_CACHE_DIR, 0775, true);
}

// Create log directory if it doesn't exist
$logDir = dirname(SFM_LOG_PATH);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
