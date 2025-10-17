<?php
/**
 * includes/security_headers.php
 *
 * Lightweight, shared-hosting–friendly security headers.
 * Safe defaults for:
 *  - Bootstrap CDN
 *  - Google Fonts
 *  - (Optional) Google Analytics / Tag Manager (gtag)
 *  - Inline CSS/JS in index.php (kept for “few files” goal)
 *
 * Usage (near top of every public PHP entry like index.php, generate.php):
 *   require_once __DIR__ . '/includes/security_headers.php';
 *   sfm_send_security_headers(); // or pass options (see below)
 *
 * Options:
 *   sfm_send_security_headers([
 *     'allow_analytics' => true,   // allow gtag/gtm domains
 *     'hsts'            => true,   // send HSTS if HTTPS
 *   ]);
 */

declare(strict_types=1);

if (!function_exists('sfm_send_security_headers')) {
  function sfm_send_security_headers(array $opts = []): void
  {
    static $sent = false;
    if ($sent) return;
    if (headers_sent()) return;

    $allowAnalytics = (bool)($opts['allow_analytics'] ?? true);
    $sendHsts       = (bool)($opts['hsts'] ?? true);

    // Detect HTTPS (best-effort)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    // ----- Content-Security-Policy (CSP) -----
    // Keep 'unsafe-inline' for script/style to support current inline JS/CSS.
    // If you later externalize assets, you can tighten these.
    $scriptSrc = [
      "'self'",
      "'unsafe-inline'",
      "https://cdn.jsdelivr.net",      // Bootstrap bundle
      "https://unpkg.com",            // htmx
    ];
    $connectSrc = [
      "'self'",
    ];
    $imgSrc = [
      "'self'",
      "data:",
    ];
    $styleSrc = [
      "'self'",
      "'unsafe-inline'",
      "https://fonts.googleapis.com",
      "https://cdn.jsdelivr.net",
    ];
    $fontSrc = [
      "'self'",
      "https://fonts.gstatic.com",
      "https://cdn.jsdelivr.net",      // Bootstrap Icons fonts
      "data:",
    ];

    if ($allowAnalytics) {
      $scriptSrc[]  = "https://www.googletagmanager.com";
      $scriptSrc[]  = "https://www.google-analytics.com";
      $connectSrc[] = "https://www.google-analytics.com";
      $imgSrc[]     = "https://www.google-analytics.com";
    }

    // Build CSP string
    $csp = [];
    $csp[] = "default-src 'self'";
    $csp[] = "script-src " . implode(' ', $scriptSrc);
    $csp[] = "style-src " . implode(' ', $styleSrc);
    $csp[] = "font-src " . implode(' ', $fontSrc);
    $csp[] = "img-src " . implode(' ', $imgSrc);
    $csp[] = "connect-src " . implode(' ', $connectSrc);
    $csp[] = "object-src 'none'";
    $csp[] = "base-uri 'self'";
    $csp[] = "form-action 'self'";
    $csp[] = "frame-ancestors 'none'";
    if ($isHttps) {
      $csp[] = "upgrade-insecure-requests";
    }

    header('Content-Security-Policy: ' . implode('; ', $csp));

    // ----- Other helpful headers -----
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-site');
    header('X-Frame-Options: DENY'); // legacy fallback for frame-ancestors

    // HSTS (only over HTTPS). Do NOT enable preload automatically.
    if ($sendHsts && $isHttps) {
      // 6 months; include subdomains because your app lives at apex and subdomains
      header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }

    $sent = true;
  }
}
