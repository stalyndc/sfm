<?php
/**
 * includes/feed_cache.php
 *
 * Disk-backed cache for generated feed responses plus health metadata.
 * Stores JSON blobs under storage/cache/feeds and exposes helpers to
 * read, write, and update entries keyed by request parameters.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!defined('SFM_FEED_CACHE_TTL')) {
    define('SFM_FEED_CACHE_TTL', sfm_resolve_feed_cache_ttl());
}

/**
 * Resolve the cache directory and ensure it exists.
 */
function sfm_feed_cache_dir(): string
{
    $root = defined('STORAGE_ROOT') ? STORAGE_ROOT : (dirname(__DIR__) . '/storage');
    $dir  = rtrim($root, '/') . '/cache/feeds';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Build a deterministic cache key for a feed request.
 *
 * @param array<string,string> $options Additional request options (selectors, etc.)
 */
function sfm_feed_cache_key(string $url, string $format, int $limit, bool $preferNative, array $options = []): string
{
    $parts = [
        strtolower(trim($url)),
        strtolower($format),
        (string) $limit,
        $preferNative ? '1' : '0',
        strtolower(trim($options['item_selector'] ?? '')),
        strtolower(trim($options['title_selector'] ?? '')),
        strtolower(trim($options['summary_selector'] ?? '')),
    ];

    return hash('sha256', implode('|', $parts));
}

/**
 * Return the JSON file path for a cache key.
 */
function sfm_feed_cache_path(string $key): string
{
    return sfm_feed_cache_dir() . '/' . $key . '.json';
}

/**
 * Read a cache entry from disk.
 *
 * @return array|null
 */
function sfm_feed_cache_read(string $key): ?array
{
    $path = sfm_feed_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $json = @file_get_contents($path);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Determine whether a cache entry is still fresh.
 */
function sfm_feed_cache_is_fresh(array $entry): bool
{
    $expires = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
    if ($expires <= 0) {
        return false;
    }
    return $expires >= time();
}

/**
 * Persist a cache entry to disk.
 *
 * @param array $payload Successful response payload (ok => true, etc.)
 * @param array $health  Health metadata describing the feed.
 */
function sfm_feed_cache_store(string $key, array $payload, array $health, int $ttl): void
{
    $now     = time();
    $data = [
        'version'    => 1,
        'stored_at'  => $now,
        'expires_at' => $ttl > 0 ? ($now + $ttl) : $now,
        'payload'    => $payload,
        'health'     => $health,
    ];

    $path = sfm_feed_cache_path($key);
    $tmp  = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return;
    }
    @rename($tmp, $path);
}

/**
 * Update only the health block for an existing entry.
 *
 * @param array $entry Existing cache entry as returned by sfm_feed_cache_read.
 */
function sfm_feed_cache_update_health(string $key, array $entry, array $health): void
{
    $payload = isset($entry['payload']) && is_array($entry['payload']) ? $entry['payload'] : [];
    $ttl     = isset($entry['expires_at']) ? ((int) $entry['expires_at'] - time()) : 0;
    if ($ttl < 0) {
        $ttl = 60;
    }
    sfm_feed_cache_store($key, $payload, $health, $ttl);
}

/**
 * Remove a cache entry entirely.
 */
function sfm_feed_cache_delete(string $key): void
{
    $path = sfm_feed_cache_path($key);
    if (is_file($path)) {
        @unlink($path);
    }
}
