<?php
/**
 * includes/logger2.php
 *
 * Minimal, safe request logging with daily rotation and light redaction.
 * This file intentionally contains ONLY logging helpers to avoid
 * name collisions with http/extract code.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------- load secure config (optional) ----------
$__secureDir = sfm_secure_dir();
if ($__secureDir !== null && is_file($__secureDir . '/config.php')) {
    // May define SFM_LOG_ENABLED and SFM_LOG_DIR
    @require_once $__secureDir . '/config.php';
}

// Defaults if not provided by secure/config.php
if (!defined('SFM_LOG_ENABLED')) define('SFM_LOG_ENABLED', true);
if (!defined('SFM_LOG_DIR')) {
    $fallbackSecure = $__secureDir ?? (ROOT_DIR . '/secure');
    $defaultLogDir  = is_dir($fallbackSecure) ? ($fallbackSecure . '/logs') : (ROOT_DIR . '/logs');
    define('SFM_LOG_DIR', $defaultLogDir);
}

// ---------- tiny helpers ----------
if (!function_exists('sfm2_ensure_dir')) {
    function sfm2_ensure_dir(string $dir): bool {
        if (is_dir($dir)) return is_writable($dir);
        @mkdir($dir, 0775, true);
        return is_dir($dir) && is_writable($dir);
    }
}

if (!function_exists('sfm2_redact_ip')) {
    function sfm2_redact_ip(?string $ip): string {
        $ip = (string)$ip;
        if ($ip === '') return '';
        // IPv4: mask last octet; IPv6: keep first 4 hextets
        if (strpos($ip, ':') === false) {
            // v4
            $parts = explode('.', $ip);
            if (count($parts) === 4) $parts[3] = '0';
            return implode('.', $parts);
        } else {
            // v6
            $parts = explode(':', $ip);
            $keep  = array_slice($parts, 0, 4);
            return implode(':', $keep) . '::';
        }
    }
}

if (!function_exists('sfm2_now_iso')) {
    function sfm2_now_iso(): string {
        return date('c');
    }
}

if (!function_exists('sfm2_span_id')) {
    function sfm2_span_id(): string {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return dechex(mt_rand()) . dechex(mt_rand());
        }
    }
}

if (!function_exists('sfm2_log_write')) {
    function sfm2_log_write(array $rec): void {
        if (!SFM_LOG_ENABLED) return;
        if (!sfm2_ensure_dir(SFM_LOG_DIR)) return;

        $file = rtrim(SFM_LOG_DIR, '/\\') . '/app-' . date('Y-m-d') . '.log';

        // Add common fields
        $rec['ts'] = $rec['ts'] ?? sfm2_now_iso();
        if (!isset($rec['ip'])) {
            $rec['ip'] = sfm2_redact_ip($_SERVER['REMOTE_ADDR'] ?? '');
        }

        // Privacy: truncate very long values
        array_walk($rec, function (&$v) {
            if (is_string($v) && strlen($v) > 2000) {
                $v = substr($v, 0, 1990) . '…';
            }
        });

        @file_put_contents($file, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// ---------- public API ----------
if (!function_exists('sfm_log_begin')) {
    /**
     * Start a log span. Returns an opaque span array you pass to sfm_log_end/error.
     * $meta is free-form (small!) data like url/format/limit.
     */
    function sfm_log_begin(string $action, array $meta = []): array {
        $span = [
            'id'   => sfm2_span_id(),
            't0'   => microtime(true),
            'act'  => $action,
        ];
        sfm2_log_write([
            'ev'   => 'begin',
            'id'   => $span['id'],
            'act'  => $action,
            'meta' => $meta,
        ]);
        return $span;
    }
}

if (!function_exists('sfm_log_error')) {
    /**
     * Attach an error event to a span.
     */
    function sfm_log_error(array $span, string $stage, array $meta = []): void {
        sfm2_log_write([
            'ev'    => 'error',
            'id'    => $span['id'] ?? null,
            'act'   => $span['act'] ?? null,
            'stage' => $stage,
            'meta'  => $meta,
        ]);
    }
}

if (!function_exists('sfm_log_end')) {
    /**
     * Finish a span and record duration in ms.
     */
    function sfm_log_end(array $span, array $meta = []): void {
        $t0 = (float)($span['t0'] ?? microtime(true));
        $ms = (int)round((microtime(true) - $t0) * 1000);
        sfm2_log_write([
            'ev'   => 'end',
            'id'   => $span['id'] ?? null,
            'act'  => $span['act'] ?? null,
            'ms'   => $ms,
            'meta' => $meta,
        ]);
    }
}
