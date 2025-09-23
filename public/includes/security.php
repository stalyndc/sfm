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

  // Ensure we have a CSRF token
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_issued_at'] = time();
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
  // timing-safe
  return hash_equals($expected, (string)$token);
}

/* ============================================================
 * Client + rate limiting
 * ============================================================ */
function client_ip(): string {
  // Best-effort; on shared hosting we often only have REMOTE_ADDR.
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    // First item is the original client
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($parts[0]);
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
