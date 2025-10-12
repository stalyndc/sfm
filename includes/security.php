<?php
/**
 * includes/security.php
 *
 * Lightweight security helpers that work on shared hosting:
 * - Secure session bootstrap (SameSite/HttpOnly/Secure)
 * - CSRF token issue/verify
 * - Honeypot check (field name "website")
 * - Simple file-based rate limiting per IP + context
 *
 * Usage (JSON endpoints like generate.php):
 * -----------------------------------------
 * require_once __DIR__ . '/security.php';
 * try {
 *   secure_assert_post('generate', 2, 20); // context, min-seconds-between hits, burst window
 * } catch (Throwable $e) {
 *   // secure_assert_post() already sent a JSON error + proper HTTP status, but
 *   // we still guard here to stop execution in case code continues.
 *   exit;
 * }
 *
 * In forms (HTML):
 * ----------------
 * echo csrf_input(); // prints <input type="hidden" name="csrf_token" value="...">
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* ============================================================
 * Optional HTTP host overrides (shared with includes/http.php)
 * ============================================================ */
if (!function_exists('sfm_http_host_overrides')) {
  /**
   * Return host => [ip, ...] overrides using secure/http-overrides.php or env.
   *
   * Env format: host=ip[,ip];host2=ip (separate entries with ; or |).
   *
   * @return array<string,list<string>>
   */
  function sfm_http_host_overrides(): array {
    static $cache = null;
    if ($cache !== null) {
      return $cache;
    }

    $cache = [];

    if (defined('SECURE_DIR')) {
      $file = SECURE_DIR . '/http-overrides.php';
      if (is_file($file) && is_readable($file)) {
        $data = require $file;
        if (is_array($data)) {
          foreach ($data as $host => $ips) {
            $hostKey = strtolower(trim((string)$host));
            if ($hostKey === '') continue;
            if (!is_array($ips)) {
              $ips = preg_split('/[,\s]+/', (string)$ips, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $ips = array_values(array_filter($ips, static function ($ip): bool {
              return filter_var($ip, FILTER_VALIDATE_IP) !== false;
            }));
            if ($ips) {
              $cache[$hostKey] = $ips;
            }
          }
        }
      }
    }

    $env = getenv('SFM_HTTP_HOST_OVERRIDES');
    if (is_string($env) && trim($env) !== '') {
      foreach (preg_split('/[;|]+/', $env) ?: [] as $pair) {
        if (strpos($pair, '=') === false) continue;
        [$host, $list] = array_map('trim', explode('=', $pair, 2));
        if ($host === '' || $list === '') continue;
        $ips = preg_split('/[,\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ips = array_values(array_filter($ips, static function ($ip): bool {
          return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        }));
        if ($ips) {
          $cache[strtolower($host)] = $ips; // env overrides file entry
        }
      }
    }

    return $cache;
  }

  /**
   * Fetch override IP list for a host (lower-cased).
   *
   * @return list<string>
   */
  function sfm_http_override_ips(string $host): array {
    $host = strtolower(trim($host));
    if ($host === '') {
      return [];
    }
    $overrides = sfm_http_host_overrides();
    return $overrides[$host] ?? [];
  }
}

/* ============================================================
 * Session bootstrap (secure settings first)
 * ============================================================ */
function sec_boot_session(): void {
  static $booted = false;
  if ($booted) return;

  // Harden session cookie settings before starting session
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'domain'   => '',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  } else {
    // Fallback (older PHP): SameSite not supported; keep secure+httponly
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
  }

  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $booted = true;

  $cookieToken = isset($_COOKIE['sfm_csrf']) ? (string)$_COOKIE['sfm_csrf'] : '';

  // Ensure we have a CSRF token
  if (empty($_SESSION['csrf_token'])) {
    if ($cookieToken !== '') {
      $_SESSION['csrf_token'] = $cookieToken;
    } else {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $_SESSION['csrf_issued_at'] = time();
  }

  // Mirror token into a cookie (double-submit fallback when sessions misbehave)
  $csrfCookieOptions = [
    'expires'  => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => false, // needs to be readable by the browser for fallback
    'samesite' => 'Lax',
  ];
  $currentCookie = $cookieToken;
  $sessionToken  = (string)$_SESSION['csrf_token'];
  if ($sessionToken !== '' && (!is_string($currentCookie) || !hash_equals($sessionToken, $currentCookie))) {
    setcookie('sfm_csrf', $sessionToken, $csrfCookieOptions);
    $_COOKIE['sfm_csrf'] = $sessionToken;
  }
}

/* ============================================================
 * CSRF helpers
 * ============================================================ */
function csrf_get_token(): string {
  sec_boot_session();
  return (string)($_SESSION['csrf_token'] ?? '');
}

function csrf_input(): string {
  $t = csrf_get_token();
  return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'">';
}

function csrf_validate(?string $token): bool {
  sec_boot_session();
  $expected = (string)($_SESSION['csrf_token'] ?? '');
  if ($token === null || $token === '') return false;
  $token = (string)$token;

  if ($expected !== '' && hash_equals($expected, $token)) {
    return true;
  }

  $cookieToken = isset($_COOKIE['sfm_csrf']) ? (string)$_COOKIE['sfm_csrf'] : '';
  if ($cookieToken !== '' && hash_equals($cookieToken, $token)) {
    return true;
  }

  return false;
}

/* ============================================================
 * Client + rate limiting
 * ============================================================ */
function sfm_trusted_proxies(): array {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $env = trim((string)getenv('SFM_TRUSTED_PROXIES'));
  if ($env === '') {
    return $cached = [];
  }

  $parts = preg_split('/[,\s]+/', $env, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  return $cached = array_values(array_unique($parts));
}

function sfm_ip_in_cidr(string $ip, string $cidr): bool {
  if (strpos($cidr, '/') === false) {
    return false;
  }
  [$subnet, $maskBits] = explode('/', $cidr, 2);
  $subnetPacked = @inet_pton($subnet);
  $ipPacked     = @inet_pton($ip);
  if ($subnetPacked === false || $ipPacked === false || strlen($subnetPacked) !== strlen($ipPacked)) {
    return false;
  }
  $maskBits = (int)$maskBits;
  $maxBits  = strlen($ipPacked) * 8;
  if ($maskBits < 0 || $maskBits > $maxBits) {
    return false;
  }

  $fullBytes = intdiv($maskBits, 8);
  $remainder = $maskBits % 8;
  if ($fullBytes > 0) {
    if (strncmp($ipPacked, $subnetPacked, $fullBytes) !== 0) {
      return false;
    }
  }
  if ($remainder === 0) {
    return true;
  }
  $mask = (~0 << (8 - $remainder)) & 0xFF;
  return ((ord($ipPacked[$fullBytes]) ^ ord($subnetPacked[$fullBytes])) & $mask) === 0;
}

function sfm_ip_matches_trusted(string $ip, array $trusted): bool {
  foreach ($trusted as $entry) {
    $entry = trim($entry);
    if ($entry === '') {
      continue;
    }
    if (strpos($entry, '/') !== false) {
      if (sfm_ip_in_cidr($ip, $entry)) {
        return true;
      }
    } elseif (filter_var($entry, FILTER_VALIDATE_IP)) {
      if (strcasecmp($ip, $entry) === 0) {
        return true;
      }
    }
  }
  return false;
}

function client_ip(): string {
  $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  if ($remote !== '' && !filter_var($remote, FILTER_VALIDATE_IP)) {
    $remote = '';
  }

  $trusted = sfm_trusted_proxies();
  $canTrustForwarded = $remote !== '' && $trusted && sfm_ip_matches_trusted($remote, $trusted);

  $candidates = [];
  if ($canTrustForwarded) {
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
      $candidates[] = $cf;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $forwarded) {
        $forwarded = trim($forwarded);
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
          $candidates[] = $forwarded;
        }
      }
    }
  }

  foreach ($candidates as $candidate) {
    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
      return $candidate;
    }
  }

  if ($remote !== '') {
    return $remote;
  }

  return '0.0.0.0';
}

function rate_file_path(string $context): string {
  // STORAGE_TMP is optional in config.php; fallback to system tmp if missing.
  if (!defined('STORAGE_TMP') || !STORAGE_TMP) {
    $base = rtrim(sys_get_temp_dir(), '/');
  } else {
    $base = rtrim(STORAGE_TMP, '/');
    if (!is_dir($base)) @mkdir($base, 0775, true);
  }
  $ip = client_ip();
  return $base . '/rl_' . preg_replace('~[^a-z0-9_\-]~i', '_', $context) . '_' . md5($ip) . '.txt';
}

/**
 * Simple IP+context rate limiter.
 * - $min_interval: minimum seconds between allowed hits
 * - $burst_window: if the file grows too many hits quickly, we still rely on interval;
 *   kept here in case we want to expand later.
 *
 * Returns true if request is allowed; false if rate-limited.
 */
function rate_limit_allow(string $context, int $min_interval = 2, int $burst_window = 20): bool {
  $file = rate_file_path($context);
  $now  = time();

  if (!file_exists($file)) {
    @file_put_contents($file, (string)$now);
    return true;
  }

  $last = (int)trim((string)@file_get_contents($file));
  if ($now - $last < $min_interval) {
    return false;
  }
  @file_put_contents($file, (string)$now);
  return true;
}

/* ============================================================
 * Remote target validation helpers
 * ============================================================ */
function sfm_is_public_ip(string $ip): bool
{
  $ip = trim($ip);
  if ($ip === '') {
    return false;
  }
  $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
  return (bool)filter_var($ip, FILTER_VALIDATE_IP, $flags);
}

function sfm_host_is_public(string $host, ?string &$reason = null): bool
{
  $reason = null;
  $host = trim($host);
  if ($host === '') {
    $reason = 'invalid_host';
    return false;
  }

  if (getenv('SFM_TEST_ALLOW_LOCAL_URLS') === '1') {
    return true;
  }

  if (filter_var($host, FILTER_VALIDATE_IP)) {
    if (!sfm_is_public_ip($host)) {
      $reason = 'private_ip';
      return false;
    }
    return true;
  }

  $overrideIps = sfm_http_override_ips($host);
  if ($overrideIps) {
    foreach ($overrideIps as $ip) {
      if (!sfm_is_public_ip($ip)) {
        $reason = 'private_override_ip';
        return false;
      }
    }
    return true;
  }

  $ips = [];
  $ipv4 = @gethostbynamel($host);
  if (is_array($ipv4)) {
    $ips = array_merge($ips, $ipv4);
  }

  if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
    $records = @dns_get_record($host, DNS_AAAA);
    if (is_array($records)) {
      foreach ($records as $rec) {
        if (!empty($rec['ipv6'])) {
          $ips[] = $rec['ipv6'];
        }
      }
    }
  }

  $ips = array_values(array_unique(array_filter($ips)));

  if (empty($ips)) {
    $reason = 'dns_failed';
    return false;
  }

  foreach ($ips as $ip) {
    if (!sfm_is_public_ip($ip)) {
      $reason = 'private_ip';
      return false;
    }
  }
  return true;
}

function sfm_url_is_public(string $url): bool
{
  if (getenv('SFM_TEST_ALLOW_LOCAL_URLS') === '1') {
    return true;
  }
  $host = parse_url($url, PHP_URL_HOST);
  if (!is_string($host) || $host === '') {
    return false;
  }
  $reason = null;
  return sfm_host_is_public($host, $reason);
}

/* ============================================================
 * JSON fail helper
 * ============================================================ */
function json_fail(string $message, int $http = 400, array $extra = []): void {
  http_response_code($http);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['ok' => false, 'message' => $message], $extra));
  exit;
}

/* ============================================================
 * Primary guard for POST JSON endpoints
 * ============================================================ */
/**
 * secure_assert_post(
 *   string $context,        // bucket for rate limit e.g. "generate"
 *   int    $min_interval,   // seconds between allowed hits per IP
 *   int    $burst_window     // reserved for future use (keep param)
 * )
 */
function secure_assert_post(string $context, int $min_interval = 2, int $burst_window = 20): void {
  // Method check
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Invalid request method.', 405);
  }

  sec_boot_session();

  // CSRF token: from POST or header
  $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!csrf_validate(is_string($token) ? $token : '')) {
    json_fail('CSRF validation failed.', 403);
  }

  // Honeypot (bots fill "website" field)
  if (!empty($_POST['website'])) {
    json_fail('Bot detected.', 400);
  }

  // Basic Origin/Referer same-site check (best-effort, tolerant)
  if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if ($host && stripos($origin, $host) === false) {
      // Allow if running from different subdomain of same apex (optional).
      // Keeping strict for now:
      json_fail('Cross-site request blocked.', 403);
    }
  }

  // Minimal User-Agent check
  if (empty($_SERVER['HTTP_USER_AGENT'])) {
    json_fail('Missing user agent.', 400);
  }

  // Rate limit
  if (!rate_limit_allow($context, $min_interval, $burst_window)) {
    json_fail('Too many requests. Please wait a moment and try again.', 429);
  }
}
