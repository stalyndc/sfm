<?php
/**
 * includes/http_guzzle.php
 *
 * Enhanced HTTP helpers for SimpleFeedMaker using Guzzle HTTP client:
 *  - sfm_http_get(): Single GET with caching using Symfony Cache
 *  - sfm_http_head(): Lightweight HEAD requests
 *  - sfm_http_multi_get(): Fetch multiple URLs concurrently (no cache)
 *  - sfm_http_post(): POST requests with proper error handling
 *
 * Dependencies:
 *  - guzzlehttp/guzzle
 *  - symfony/cache
 *  - vlucas/phpdotenv
 *
 * Return shape (consistent with original interface):
 *   [
 *     'ok'         => bool,
 *     'status'     => int,
 *     'headers'    => array<string,string>,   // lower-cased keys
 *     'body'       => string,                 // '' for HEAD
 *     'final_url'  => string,                 // after redirects
 *     'from_cache' => bool,
 *     'was_304'    => bool,
 *     'error'      => ?string,
 *   ]
 */

declare(strict_types=1);

// Load dependencies
require_once __DIR__ . '/../secure/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Initialize Guzzle client with proper configuration
 */
function sfm_create_guzzle_client(): Client
{
    $userAgent = defined('SFM_HTTP_USER_AGENT') 
        ? SFM_HTTP_USER_AGENT 
        : 'SimpleFeedMaker/1.0 (+https://simplefeedmaker.com)';
    
    $timeout = defined('SFM_HTTP_TIMEOUT') ? SFM_HTTP_TIMEOUT : 30;
    $connectTimeout = defined('SFM_HTTP_CONNECT_TIMEOUT') ? SFM_HTTP_CONNECT_TIMEOUT : 10;

    return new Client([
        'timeout' => $timeout,
        'connect_timeout' => $connectTimeout,
        'headers' => [
            'User-Agent' => $userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Encoding' => 'gzip, deflate',
        ],
        'allow_redirects' => [
            'max' => 10,
            'strict' => true,
            'referer' => true,
        ],
        'http_errors' => false, // We'll handle errors manually
    ]);
}

/**
 * Initialize cache using Symfony Cache (FilesystemAdapter for shared hosting)
 */
function sfm_create_cache(): FilesystemAdapter
{
    $cacheDir = defined('SFM_CACHE_DIR') ? SFM_CACHE_DIR : dirname(__DIR__) . '/feeds/.httpcache';
    $defaultTtl = defined('SFM_CACHE_TTL') ? SFM_CACHE_TTL : 3600;
    
    return new FilesystemAdapter(
        namespace: 'sfm_http',
        defaultLifetime: $defaultTtl,
        directory: $cacheDir
    );
}

/**
 * Check if a URL is allowed (same logic as original)
 */
function sfm_http_url_is_allowed(string $url, ?string &$reason = null): bool
{
    $reason = null;
    $parts = parse_url($url);
    
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        $reason = 'invalid_url';
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        $reason = 'unsupported_scheme';
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        $reason = 'contains_credentials';
        return false;
    }

    $host = strtolower($parts['host']);
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $reason = 'private_ip';
            return false;
        }
    }

    return true;
}

/**
 * Convert PSR-7 Response to our internal format
 */
function sfm_normalize_response(ResponseInterface $response, string $url): array
{
    $statusCode = $response->getStatusCode();
    $headers = [];
    foreach ($response->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = implode(', ', $values);
    }
    
    return [
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'status' => $statusCode,
        'headers' => $headers,
        'body' => $response->getBody()->getContents(),
        'final_url' => $url,
        'from_cache' => false,
        'was_304' => $statusCode === 304,
        'error' => null,
    ];
}

/**
 * Enhanced HTTP GET with caching using Symfony Cache
 */
function sfm_http_get(string $url, ?array $options = null): array
{
    if (!sfm_http_url_is_allowed($url, $reason)) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => "URL not allowed: {$reason}",
        ];
    }

    $options = $options ?? [];
    $cache = sfm_create_cache();
    $client = sfm_create_guzzle_client();
    $cacheKey = 'http_get_' . md5($url . serialize($options));

    try {
        // Try to get from cache first
        $cachedResponse = $cache->get($cacheKey, function (ItemInterface $item) use ($client, $url, $options) {
            try {
                $response = $client->get($url, $options);
                $result = sfm_normalize_response($response, $url);
                $result['from_cache'] = false;
                
                // Cache only successful responses
                if ($result['ok']) {
                    $item->expiresAfter(intval($options['cache_ttl'] ?? 3600));
                    
                    // Return the result for caching
                    return json_encode($result);
                }
                
                throw new \Exception('Response not cacheable (status: ' . $result['status'] . ')');
            } catch (GuzzleException $e) {
                throw new \Exception('HTTP request failed: ' . $e->getMessage());
            }
        });

        // If we got cached data, decode it
        if (is_string($cachedResponse)) {
            $result = json_decode($cachedResponse, true);
            $result['from_cache'] = true;
            return $result;
        }

        // Shouldn't happen, but fallback
        return [
            'ok' => false,
            'status' => 500,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => 'Cache retrieval failed',
        ];

    } catch (Exception $e) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => 'Request failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * HTTP HEAD request
 */
function sfm_http_head(string $url, ?array $options = null): array
{
    if (!sfm_http_url_is_allowed($url, $reason)) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => "URL not allowed: {$reason}",
        ];
    }

    $options = $options ?? [];
    $client = sfm_create_guzzle_client();

    try {
        $response = $client->head($url, $options);
        $result = sfm_normalize_response($response, $url);
        // HEAD responses should have empty body
        $result['body'] = '';
        return $result;
    } catch (GuzzleException $e) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => 'HEAD request failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Execute multiple HTTP GET requests concurrently
 */
function sfm_http_multi_get(array $urls, ?array $options = null): array
{
    $client = sfm_create_guzzle_client();
    $options = $options ?? [];
    $promises = [];
    $results = [];

    foreach ($urls as $index => $url) {
        if (!sfm_http_url_is_allowed($url, $reason)) {
            $results[$index] = [
                'ok' => false,
                'status' => 0,
                'headers' => [],
                'body' => '',
                'final_url' => $url,
                'from_cache' => false,
                'was_304' => false,
                'error' => "URL not allowed: {$reason}",
            ];
            continue;
        }

        $promises[$index] = $client->getAsync($url, $options);
    }

    // Wait for all requests to complete
    try {
        $responses = Utils::settle($promises)->wait();

        foreach ($responses as $index => $settlement) {
            if ($settlement['state'] === 'fulfilled') {
                $response = $settlement['value'];
                $results[$index] = sfm_normalize_response($response, $urls[$index] ?? 'unknown');
            } else {
                $results[$index] = [
                    'ok' => false,
                    'status' => 0,
                    'headers' => [],
                    'body' => '',
                    'final_url' => $urls[$index] ?? 'unknown',
                    'from_cache' => false,
                    'was_304' => false,
                    'error' => 'Request failed: ' . $settlement['reason']->getMessage(),
                ];
            }
        }
    } catch (Exception $e) {
        // Handle any unexpected errors
        foreach ($urls as $index => $url) {
            if (!isset($results[$index])) {
                $results[$index] = [
                    'ok' => false,
                    'status' => 0,
                    'headers' => [],
                    'body' => '',
                    'final_url' => $url,
                    'from_cache' => false,
                    'was_304' => false,
                    'error' => 'Concurrent request error: ' . $e->getMessage(),
                ];
            }
        }
    }

    return $results;
}

/**
 * HTTP POST request (for APIs, form submissions, etc.)
 */
function sfm_http_post(string $url, ?array $postData = null, ?array $options = null): array
{
    if (!sfm_http_url_is_allowed($url, $reason)) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => "URL not allowed: {$reason}",
        ];
    }

    $options = $options ?? [];
    if ($postData !== null) {
        $options['json'] = $postData; // Send as JSON by default
    }
    
    $client = sfm_create_guzzle_client();

    try {
        $response = $client->post($url, $options);
        return sfm_normalize_response($response, $url);
    } catch (GuzzleException $e) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'from_cache' => false,
            'was_304' => false,
            'error' => 'POST request failed: ' . $e->getMessage(),
        ];
    }
}

// Backward compatibility functions that map to the new implementation
if (!function_exists('http_get')) {
    function http_get(string $url, ?array $options = null): array {
        return sfm_http_get($url, $options);
    }
}

if (!function_exists('http_head')) {
    function http_head(string $url, ?array $options = null): array {
        return sfm_http_head($url, $options);
    }
}

if (!function_exists('http_multi_get')) {
    function http_multi_get(array $urls, ?array $options = null): array {
        return sfm_http_multi_get($urls, $options);
    }
}
