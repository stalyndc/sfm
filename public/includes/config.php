<?php
/**
 * includes/config.php
 * Central configuration for SimpleFeedMaker.
 *
 * Keep this file tiny and dependency-free so it works on shared hosting.
 * Other PHP files can `require_once __DIR__ . '/config.php';`
 */

declare(strict_types=1);

/* -----------------------------------------------------------
   App identity / environment
   ----------------------------------------------------------- */
if (!defined('APP_NAME')) {
  define('APP_NAME', 'SimpleFeedMaker');
}

/**
 * DEBUG:
 * - false in production (default)
 * - set to true temporarily while debugging (or via env SFM_DEBUG=1)
 */
if (!defined('DEBUG')) {
  $envDebug = getenv('SFM_DEBUG');
  define('DEBUG', $envDebug === '1' || $envDebug === 'true');
}

/* Show fewer errors to the browser in prod; always log server-side */
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(DEBUG ? E_ALL : (E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT));

/* Timezone (UTC keeps generated feed dates stable and predictable) */
date_default_timezone_set('UTC');

/* -----------------------------------------------------------
   Paths (relative to project root)
   ----------------------------------------------------------- */
/**
 * ROOT_DIR => absolute path to the web root (folder that contains /feeds, /assets, /includes)
 * FEEDS_DIR => where generated feeds are stored
 */
if (!defined('ROOT_DIR')) {
  define('ROOT_DIR', rtrim(str_replace('\\','/', dirname(__DIR__)), '/'));
}
if (!defined('FEEDS_DIR')) {
  define('FEEDS_DIR', ROOT_DIR . '/feeds');
}

/* -----------------------------------------------------------
   Feed generation defaults
   ----------------------------------------------------------- */
if (!defined('DEFAULT_FMT')) define('DEFAULT_FMT', 'rss');  // rss | atom | jsonfeed
if (!defined('DEFAULT_LIM')) define('DEFAULT_LIM', 10);      // default items
if (!defined('MAX_LIM'))     define('MAX_LIM', 50);          // max items
if (!defined('TIMEOUT_S'))   define('TIMEOUT_S', 18);        // network timeout (seconds)

/* -----------------------------------------------------------
   URL helpers
   ----------------------------------------------------------- */
/**
 * Resolve the public base URL of the app (scheme + host + optional subdir).
 * Works behind typical Hostinger shared hosting setups.
 */
if (!function_exists('app_url_base')) {
  function app_url_base(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // derive base path from current script location
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base   = rtrim(str_replace('\\','/', dirname($script)), '/.');
    return $scheme . '://' . $host . ($base ? $base : '');
  }
}

/**
 * Ensure /feeds exists and is writable.
 * Call before saving a feed file.
 */
if (!function_exists('ensure_feeds_dir')) {
  function ensure_feeds_dir(): void {
    if (!is_dir(FEEDS_DIR)) {
      @mkdir(FEEDS_DIR, 0775, true);
    }
    if (!is_dir(FEEDS_DIR) || !is_writable(FEEDS_DIR)) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false, 'message'=>'Server cannot write to /feeds. Check permissions.']);
      exit;
    }
  }
}

/* -----------------------------------------------------------
   Minimal origin check helper (same-origin only)
   ----------------------------------------------------------- */
if (!function_exists('reject_cross_origin_if_any')) {
  function reject_cross_origin_if_any(): void {
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
      $origin = $_SERVER['HTTP_ORIGIN'];
      $host   = app_url_base();
      if (stripos($origin, $host) !== 0) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'message'=>'Cross-origin requests are not allowed.']);
        exit;
      }
    }
  }
}

// --- SimpleFeedMaker logging (safe defaults) ---
if (!defined('SFM_LOG_ENABLED')) {
    // Flip to false to disable all logs instantly.
    define('SFM_LOG_ENABLED', true);
}

if (!defined('SFM_LOG_DIR')) {
    // Points to /secure/logs (one level up from /includes).
    define('SFM_LOG_DIR', dirname(__DIR__) . '/secure/logs');
}
