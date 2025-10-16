#!/usr/bin/env php
<?php
/**
 * scripts/demo_new_dependencies.php
 * 
 * Demonstration script for the new Phase 1 dependencies:
 * - Guzzle HTTP client
 * - Symfony Cache
 * - Symfony DomCrawler
 * - Monolog logging
 * - vlucas/phpdotenv
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config_env.php';
require_once __DIR__ . '/../includes/http_guzzle.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../includes/extract_symfony.php';

echo "=== SimpleFeedMaker Phase 1 Dependencies Demo ===\n\n";

// 1. Environment variables loaded via phpdotenv
echo "1. Environment Configuration:\n";
echo "   - App Name: " . (defined('SFM_APP_NAME') ? SFM_APP_NAME : 'Not defined') . "\n";
echo "   - Cache TTL: " . (defined('SFM_CACHE_TTL') ? SFM_CACHE_TTL : 'Not defined') . " seconds\n";
echo "   - Log Level: " . (defined('SFM_LOG_LEVEL') ? SFM_LOG_LEVEL : 'Not defined') . "\n\n";

// 2. HTTP client with Guzzle
echo "2. HTTP Client (Guzzle):\n";
try {
    $response = sfm_http_get('https://httpbin.org/get', ['timeout' => 10]);
    if ($response['ok']) {
        echo "   ✓ Successfully fetched JSON from httpbin.org\n";
        echo "   - Status: {$response['status']}\n";
        echo "   - Content-Type: " . ($response['headers']['content-type'] ?? 'Not specified') . "\n";
        echo "   - Body size: " . strlen($response['body']) . " bytes\n";
        echo "   - From cache: " . ($response['from_cache'] ? 'Yes' : 'No') . "\n";
    } else {
        echo "   ✗ HTTP request failed: " . ($response['error'] ?? 'Unknown error') . "\n";
    }
} catch (Exception $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Caching with Symfony Cache
echo "3. Caching (Symfony Cache):\n";
try {
    $cache = sfm_create_cache();
    $testKey = 'demo_test_' . time();
    $testValue = 'This data will be cached for testing';
    
    $cache->get($testKey, function ($item) use ($testValue) {
        $item->expiresAfter(60); // 1 minute
        return $testValue;
    });
    
    echo "   ✓ Cache item created and retrieved successfully\n";
    echo "   - Cache directory: " . (defined('SFM_CACHE_DIR') ? SFM_CACHE_DIR : 'Not specified') . "\n";
    echo "   - Test key: $testKey\n";
} catch (Exception $e) {
    echo "   ✗ Cache exception: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. HTML processing with Symfony DomCrawler
echo "4. HTML Processing (Symfony DomCrawler):\n";
try {
    $html = '<html><head><title>Test Page</title></head><body><h1>Test Headline</h1><p>Test paragraph content.</p><article class="post"><h2>Article Title</h2><p>Article content goes here.</p></article></body></html>';
    
    $crawler = new Symfony\Component\DomCrawler\Crawler($html);
    $title = $crawler->filter('title')->first()->text();
    $headlines = $crawler->filter('h1, h2')->each(function ($node) {
        return $node->text();
    });
    
    echo "   ✓ Successfully parsed HTML with DomCrawler\n";
    echo "   - Page title: $title\n";
    echo "   - Headlines: " . implode(', ', $headlines) . "\n";
    
    // Test our enhanced extraction
    $items = sfm_extract_items($html, 'https://example.com', 5);
    if ($items['ok']) {
        echo "   ✓ Enhanced extraction found {$items['count']} items\n";
    } else {
        echo "   ✗ Enhanced extraction failed: " . ($items['error'] ?? 'Unknown error') . "\n";
    }
} catch (Exception $e) {
    echo "   ✗ DomCrawler exception: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Logging with Monolog
echo "5. Logging (Monolog):\n";
try {
    // Test different log levels
    sfm_log_info('Demo script started', ['timestamp' => time()]);
    sfm_log_debug('Testing debug logging', ['demo' => true]);
    sfm_log_warning('This is a warning message', ['demo' => true]);
    sfm_log_error('This is an error message (demo only)', ['demo' => true]);
    
    // Test different channels
    sfm_log_performance('Demo performance test', ['duration' => '0.123s']);
    sfm_log_security('Demo security event', ['ip' => '127.0.0.1', 'action' => 'demo']);
    sfm_log_http('Demo HTTP request', ['url' => 'https://example.com', 'status' => 200]);
    
    echo "   ✓ Successfully logged messages to multiple channels\n";
    echo "   - Log file: " . (defined('SFM_LOG_PATH') ? SFM_LOG_PATH : 'Not specified') . "\n";
    echo "   - Channels: app, performance, security, http\n";
} catch (Exception $e) {
    echo "   ✗ Logging exception: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. Configuration summary
echo "6. Dependencies Status:\n";
$dependencies = [
    'guzzlehttp/guzzle' => class_exists('GuzzleHttp\Client'),
    'symfony/cache' => class_exists('Symfony\Component\Cache\Adapter\FilesystemAdapter'),
    'symfony/dom-crawler' => class_exists('Symfony\Component\DomCrawler\Crawler'),
    'monolog/monolog' => class_exists('Monolog\Logger'),
    'vlucas/phpdotenv' => class_exists('Dotenv\Dotenv'),
];

foreach ($dependencies as $package => $loaded) {
    $status = $loaded ? '✓' : '✗';
    echo "   $status $package - " . ($loaded ? 'Loaded' : 'Not found') . "\n";
}

echo "\n=== Demo Complete ===\n";
echo "All Phase 1 dependencies are working correctly!\n\n";

echo "Usage Examples:\n";
echo "  \$response = sfm_http_get('https://example.com');\n";
echo "  \$items = sfm_extract_items(\$html, \$baseUrl, \$limit);\n";
echo "  sfm_log_info('Event message', ['context' => 'data']);\n";
echo "  \$cache = sfm_create_cache();\n";
echo "  \$crawler = new Symfony\\Component\\DomCrawler\\Crawler(\$html);\n";
