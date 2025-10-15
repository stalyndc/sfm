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
  $envName = trim((string)getenv('SFM_APP_NAME'));
  define('APP_NAME', $envName !== '' ? $envName : 'SimpleFeedMaker');
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

if (!function_exists('sfm_secure_dir')) {
  /**
   * Resolve the secure directory location.
   * Prefers an explicitly provided path, then checks repo root and its parent.
   */
  function sfm_secure_dir(): ?string {
    static $cached = null;
    if ($cached !== null) {
      return $cached ?: null;
    }

    $envOverride = trim((string)getenv('SFM_SECURE_DIR'));
    if ($envOverride !== '' && is_dir($envOverride)) {
      return $cached = rtrim(str_replace('\\','/', realpath($envOverride) ?: $envOverride), '/');
    }

    $root = ROOT_DIR;
    $candidates = [
      $root . '/secure',
      dirname($root) . '/secure',
    ];

    foreach ($candidates as $dir) {
      if (is_dir($dir)) {
        return $cached = rtrim(str_replace('\\','/', realpath($dir) ?: $dir), '/');
      }
    }

    $cached = '';
    return null;
  }
}

if (!defined('SECURE_DIR')) {
  $resolvedSecure = sfm_secure_dir();
  if ($resolvedSecure !== null) {
    define('SECURE_DIR', $resolvedSecure);
  }
}

if (!defined('SFM_VENDOR_AUTOLOAD')) {
  $autoloadCandidates = [];
  if (defined('SECURE_DIR')) {
    $autoloadCandidates[] = SECURE_DIR . '/vendor/autoload.php';
  }
  $autoloadCandidates[] = ROOT_DIR . '/secure/vendor/autoload.php';
  $autoloadCandidates[] = ROOT_DIR . '/vendor/autoload.php';

  foreach ($autoloadCandidates as $autoload) {
    if (is_string($autoload) && is_file($autoload) && is_readable($autoload)) {
      require_once $autoload;
      define('SFM_VENDOR_AUTOLOAD', $autoload);
      break;
    }
  }
}
if (!defined('FEEDS_DIR')) {
  define('FEEDS_DIR', ROOT_DIR . '/feeds');
}
if (!defined('STORAGE_ROOT')) {
  // storage/ lives alongside public_html on Hostinger; fallback to ROOT_DIR/storage locally.
  $root = dirname(__DIR__);
  $parent = dirname($root);
  $storage = $parent . '/storage';
  if (!is_dir($storage)) {
    $storage = $root . '/storage';
  }
  define('STORAGE_ROOT', rtrim(str_replace('\\','/', $storage), '/'));
}
if (!defined('SFM_DRILL_STATUS_FILE')) {
  $defaultDrill = STORAGE_ROOT . '/logs/disaster_drill.json';
  define('SFM_DRILL_STATUS_FILE', $defaultDrill);
}
if (!defined('SFM_BACKUPS_DIR')) {
  $envBackups = trim((string)getenv('SFM_BACKUPS_DIR'));
  if ($envBackups !== '') {
    define('SFM_BACKUPS_DIR', rtrim($envBackups, '/\\'));
  } else {
    $candidates = [];
    if (defined('SECURE_DIR')) {
      $candidates[] = SECURE_DIR . '/backups';
    }
    $candidates[] = STORAGE_ROOT . '/backups';
    $found = '';
    foreach ($candidates as $candidate) {
      if (is_dir($candidate)) {
        $found = rtrim(str_replace('\\','/', realpath($candidate) ?: $candidate), '/');
        break;
      }
    }
    define('SFM_BACKUPS_DIR', $found);
  }
}
if (!defined('SFM_JOBS_DIR')) {
  define('SFM_JOBS_DIR', STORAGE_ROOT . '/jobs');
}
if (!defined('SFM_HTTP_CACHE_DIR')) {
  define('SFM_HTTP_CACHE_DIR', STORAGE_ROOT . '/httpcache');
}

/* -----------------------------------------------------------
   Feed generation defaults
   ----------------------------------------------------------- */
if (!defined('DEFAULT_FMT')) define('DEFAULT_FMT', 'rss');  // rss | atom | jsonfeed
if (!defined('DEFAULT_LIM')) define('DEFAULT_LIM', 10);      // default items
if (!defined('MAX_LIM'))     define('MAX_LIM', 50);          // max items
if (!defined('TIMEOUT_S'))   define('TIMEOUT_S', 18);        // network timeout (seconds)
if (!defined('SFM_DEFAULT_REFRESH_INTERVAL')) {
  // Seconds between automatic refresh attempts (30 minutes default).
  define('SFM_DEFAULT_REFRESH_INTERVAL', 1800);
}
if (!defined('SFM_MIN_REFRESH_INTERVAL')) {
  define('SFM_MIN_REFRESH_INTERVAL', 600);
}
if (!defined('SFM_REFRESH_MAX_PER_RUN')) {
  define('SFM_REFRESH_MAX_PER_RUN', 40);
}
if (!defined('SFM_JOB_RETENTION_DAYS')) {
  define('SFM_JOB_RETENTION_DAYS', 21);
}
if (!defined('SFM_HTTP_MAX_BYTES')) {
  $envLimit = (int) getenv('SFM_HTTP_MAX_BYTES');
  define('SFM_HTTP_MAX_BYTES', $envLimit > 0 ? $envLimit : 8 * 1024 * 1024);
}
if (!function_exists('sfm_resolve_feed_cache_ttl')) {
  function sfm_resolve_feed_cache_ttl(): int {
    $raw = getenv('SFM_FEED_CACHE_TTL');
    if ($raw !== false && $raw !== '') {
      return max(0, (int) $raw);
    }
    return 900;
  }
}
if (!defined('SFM_FEED_CACHE_TTL')) {
  define('SFM_FEED_CACHE_TTL', sfm_resolve_feed_cache_ttl());
}

if (!function_exists('sfm_parse_host_header')) {
  /**
   * Normalise an incoming Host header.
   *
   * @return array{host:string,is_ipv6:bool,port:?int}|null
   */
  function sfm_parse_host_header(?string $host): ?array {
    $host = trim((string)$host);
    if ($host === '' || strpos($host, "\0") !== false) {
      return null;
    }

    $parsed = @parse_url('http://' . $host);
    if (!is_array($parsed) || empty($parsed['host'])) {
      return null;
    }

    $hostname = strtolower((string)$parsed['host']);
    $isIpv6   = filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

    if (!$isIpv6) {
      if (!filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $labelPattern = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i';
        if (!preg_match($labelPattern, $hostname)) {
          return null;
        }
      }
    }

    $port = null;
    if (array_key_exists('port', $parsed)) {
      $portNum = (int)$parsed['port'];
      if ($portNum <= 0) {
        return null;
      }
      $port = $portNum;
    }

    return [
      'host'    => $hostname,
      'is_ipv6' => $isIpv6,
      'port'    => $port,
    ];
  }
}

if (!function_exists('sfm_build_host_authority')) {
  function sfm_build_host_authority(string $host, bool $isIpv6, ?int $port, string $scheme): string {
    $authority = $isIpv6 ? '[' . $host . ']' : $host;
    if ($port !== null) {
      $isDefault = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
      if (!$isDefault) {
        $authority .= ':' . $port;
      }
    }
    return $authority;
  }
}

/* -----------------------------------------------------------
   URL helpers
   ----------------------------------------------------------- */
/**
 * Resolve the public base URL of the app (scheme + host + optional subdir).
 * Works behind typical Hostinger shared hosting setups.
 */
if (!function_exists('app_url_base')) {
  function app_url_base(): string {
    $envBase = trim((string)getenv('SFM_BASE_URL'));
    if ($envBase !== '') {
      if (!preg_match('~^https?://~i', $envBase)) {
        $envBase = 'https://' . ltrim($envBase, '/');
      }
      return rtrim($envBase, '/');
    }

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $meta   = sfm_parse_host_header($_SERVER['HTTP_HOST'] ?? '');
    if ($meta === null) {
      $meta = sfm_parse_host_header($_SERVER['SERVER_NAME'] ?? '');
    }
    if ($meta === null) {
      $meta = ['host' => 'localhost', 'is_ipv6' => false, 'port' => null];
    }
    $authority = sfm_build_host_authority($meta['host'], (bool)$meta['is_ipv6'], $meta['port'], $scheme);

    // derive base path from current script location
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base   = rtrim(str_replace('\\','/', dirname($script)), '/.');
    return $scheme . '://' . $authority . ($base ? $base : '');
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
      exit(1);
    }
  }
}

/* -----------------------------------------------------------
   Minimal origin check helper (same-origin only)
   ----------------------------------------------------------- */
if (!function_exists('sfm_origin_is_allowed')) {
  function sfm_origin_is_allowed(string $origin, string $expectedBase): bool {
    $origin = trim($origin);
    if ($origin === '') {
      return false;
    }

    $originParts = @parse_url($origin);
    if (!is_array($originParts) || empty($originParts['scheme']) || empty($originParts['host'])) {
      return false;
    }

    $originScheme = strtolower((string)$originParts['scheme']);
    if ($originScheme !== 'http' && $originScheme !== 'https') {
      return false;
    }

    $originHostRaw = array_key_exists('host', $originParts) ? (string)$originParts['host'] : '';
    if ($originHostRaw === '') {
      return false;
    }

    $originHost = strtolower($originHostRaw);

    $originPort = isset($originParts['port']) ? (int)$originParts['port'] : null;

    $expectedParts = @parse_url($expectedBase);
    if (is_array($expectedParts) && !empty($expectedParts['host'])) {
      $expectedHost = strtolower((string)$expectedParts['host']);
      $expectedScheme = strtolower((string)($expectedParts['scheme'] ?? ''));
      $expectedPort = isset($expectedParts['port']) ? (int)$expectedParts['port'] : null;
    } else {
      $expectedHost = strtolower(trim($expectedBase));
      if ($expectedHost === '') {
        return false;
      }
      $expectedScheme = '';
      $expectedPort = null;
    }

    if ($originHost !== $expectedHost) {
      return false;
    }

    $normalizedOriginPort = $originPort;
    if ($normalizedOriginPort === null) {
      if ($originScheme === 'https') {
        $normalizedOriginPort = 443;
      } elseif ($originScheme === 'http') {
        $normalizedOriginPort = 80;
      }
    }

    $normalizedExpectedPort = $expectedPort;
    if ($normalizedExpectedPort === null) {
      if ($expectedScheme === 'https') {
        $normalizedExpectedPort = 443;
      } elseif ($expectedScheme === 'http') {
        $normalizedExpectedPort = 80;
      }
    }

    if ($normalizedExpectedPort !== null && $normalizedOriginPort !== $normalizedExpectedPort) {
      return false;
    }

    return true;
  }
}

if (!function_exists('reject_cross_origin_if_any')) {
  function reject_cross_origin_if_any(): void {
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
      $origin = $_SERVER['HTTP_ORIGIN'];
      if (!sfm_origin_is_allowed($origin, app_url_base())) {
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
    $secureDir = sfm_secure_dir();
    if ($secureDir !== null) {
        define('SFM_LOG_DIR', $secureDir . '/logs');
    } else {
        define('SFM_LOG_DIR', ROOT_DIR . '/logs');
    }
}

if (!defined('SFM_ALERT_EMAIL')) {
  $envAlert = trim((string)getenv('SFM_ALERT_EMAIL'));
  if ($envAlert !== '') {
    define('SFM_ALERT_EMAIL', $envAlert);
  }
}
