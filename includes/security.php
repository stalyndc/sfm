<?php
/**
 * includes/security.php
 *
 * Lightweight security helpers that work on shared hosting:
 * - Secure session bootstrap (SameSite/HttpOnly/Secure)
 * - CSRF token issue/verify
 * - Honeypot check (field name "website")
 * - Enhanced rate limiting using RateLimiterService (Redis/memory-backed)
 *
 * Usage (JSON endpoints like generate.php):
 * -----------------------------------------
 * require_once __DIR__ . '/security.php';
 * try {
 *   secure_assert_post('generate'); // context for rate limiting
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
require_once __DIR__ . '/RateLimiterService.php';

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

    $mergeOverrides = static function ($path) use (&$cache): void {
      if (!is_file($path) || !is_readable($path)) {
        return;
      }
      $data = require $path;
      if (!is_array($data)) {
        return;
      }
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
    };

    if (defined('SECURE_DIR')) {
      $mergeOverrides(SECURE_DIR . '/http-overrides.default.php');
      $mergeOverrides(SECURE_DIR . '/http-overrides.php');
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

  if ($sessionToken !== '' && !headers_sent()) {
    header('X-CSRF-Token: ' . $sessionToken);
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

  // Additional validation: ensure token is reasonable length and format
  if (strlen($token) < 32 || strlen($token) > 128 || !ctype_xdigit($token)) {
    return false;
  }

  // Check against session token first (primary)
  if ($expected !== '' && hash_equals($expected, $token)) {
    // Regenerate token after successful validation to prevent replay attacks
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
  }

  // Fallback to double-submit cookie token
  $cookieToken = isset($_COOKIE['sfm_csrf']) ? (string)$_COOKIE['sfm_csrf'] : '';
  if ($cookieToken !== '' && strlen($cookieToken) >= 32 && strlen($cookieToken) <= 128 && ctype_xdigit($cookieToken) && hash_equals($cookieToken, $token)) {
    // Regenerate token after successful validation
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
  }

  // Log failed CSRF attempts for security monitoring
  error_log('CSRF validation failed: ' . json_encode([
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'timestamp' => time()
  ]));

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
 * )
 */
function secure_assert_post(string $context): void {
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
    $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
    $hostMeta = sfm_parse_host_header($hostHeader);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($hostMeta !== null) {
      $expected = $scheme . '://' . sfm_build_host_authority($hostMeta['host'], (bool)$hostMeta['is_ipv6'], $hostMeta['port'], $scheme);
    } else {
      $expected = app_url_base();
    }
    if (!sfm_origin_is_allowed($origin, $expected)) {
      json_fail('Cross-site request blocked.', 403);
    }
  }

  // Minimal User-Agent check
  if (empty($_SERVER['HTTP_USER_AGENT'])) {
    json_fail('Missing user agent.', 400);
  }

  // Rate limit using RateLimiterService
  $config = RateLimiterService::getRateLimitConfig($context);
  $result = RateLimiterService::isAllowed(client_ip(), $config['operations'], $config['interval'], $context);
  
  if (!$result['allowed']) {
    json_fail('Too many requests. Please wait a moment and try again.', 429);
  }
}
