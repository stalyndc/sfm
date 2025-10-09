<?php
/**
 * includes/rate_limit.php
 *
 * Minimal file-based sliding-window rate limiter for shared hosting.
 * - Per-IP + per-bucket counters
 * - Uses /home/<account>/secure/ratelimits if available, else falls back to /public_html/_ratelimits
 * - Atomic-ish via fopen/flock/LOCK_EX and JSON payloads
 *
 * Typical usage in an endpoint (e.g., generate.php):
 *   require_once __DIR__ . '/rate_limit.php';
 *   sfm_rate_limit_assert('generate', 30, 60); // 30 requests per 60 seconds for this IP/bucket
 *
 * If you want to return HTML instead of JSON on block:
 *   sfm_rate_limit_assert('generate', 30, 60, false);
 *
 * Functions provided:
 *   - sfm_client_ip(): string
 *   - sfm_rate_limit_dir(): string (ensures directory exists/writable)
 *   - sfm_rate_limit_check(string $bucket, int $limit, int $windowSeconds): array [ok(bool), retryAfter(int)]
 *   - sfm_rate_limit_assert(string $bucket, int $limit, int $windowSeconds, bool $asJson = true): void (exits on block)
 */

declare(strict_types=1);

/* -----------------------------------------------------------
 * Trusted proxy helpers
 * --------------------------------------------------------- */
function sfm_rate_limit_trusted_proxies(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $entries = [];

    if (defined('SFM_TRUSTED_PROXIES')) {
        $configured = SFM_TRUSTED_PROXIES;
        if (is_string($configured)) {
            $entries = preg_split('/[\s,]+/', $configured) ?: [];
        } elseif (is_array($configured)) {
            $entries = $configured;
        }
    }

    if (empty($entries)) {
        $env = getenv('SFM_TRUSTED_PROXIES');
        if (is_string($env) && $env !== '') {
            $entries = preg_split('/[\s,]+/', $env) ?: [];
        }
    }

    $entries = array_values(array_filter(array_map(function ($item) {
        $item = trim((string)$item);
        return $item === '' ? null : $item;
    }, $entries)));

    return $cached = $entries;
}

function sfm_rate_limit_ip_matches(string $ip, string $pattern): bool {
    $ip = trim($ip);
    $pattern = trim($pattern);
    if ($ip === '' || $pattern === '') {
        return false;
    }

    if (strpos($pattern, '/') === false) {
        return strcasecmp($ip, $pattern) === 0;
    }

    [$subnet, $maskBits] = explode('/', $pattern, 2);
    $maskBits = (int)$maskBits;
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) {
        return false;
    }
    if (strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    if ($maskBits < 0 || $maskBits > $maxBits) {
        return false;
    }

    $maskBytes = intdiv($maskBits, 8);
    $remainder = $maskBits % 8;
    $mask = str_repeat("\xff", $maskBytes);
    if ($remainder > 0) {
        $mask .= chr((0xFF << (8 - $remainder)) & 0xFF);
    }
    $mask = str_pad($mask, strlen($ipBin), "\0");

    return ($ipBin & $mask) === ($subnetBin & $mask);
}

function sfm_rate_limit_request_from_trusted_proxy(): bool {
    $remote = sfm_rate_limit_clean_ip($_SERVER['REMOTE_ADDR'] ?? null);
    if ($remote === null) {
        return false;
    }

    $trusted = sfm_rate_limit_trusted_proxies();
    if (empty($trusted)) {
        return false;
    }

    foreach ($trusted as $pattern) {
        if (sfm_rate_limit_ip_matches($remote, $pattern)) {
            return true;
        }
    }

    return false;
}

function sfm_rate_limit_clean_ip(?string $value): ?string {
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return $value;
    }

    return null;
}

/* -----------------------------------------------------------
 * IP helper (trust proxy headers only when request came from a trusted proxy)
 * --------------------------------------------------------- */
function sfm_client_ip(): string {
    $remote = sfm_rate_limit_clean_ip($_SERVER['REMOTE_ADDR'] ?? null) ?? '0.0.0.0';

    if (!sfm_rate_limit_request_from_trusted_proxy()) {
        return $remote;
    }

    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
    ];

    foreach ($headers as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }

        $raw = (string)$_SERVER[$header];
        if ($header === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $raw);
            foreach ($parts as $part) {
                $candidate = sfm_rate_limit_clean_ip($part);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        } else {
            $candidate = sfm_rate_limit_clean_ip($raw);
            if ($candidate !== null) {
                return $candidate;
            }
        }
    }

    return $remote;
}

/* -----------------------------------------------------------
 * Where to store counters (prefers /home/<account>/secure/ratelimits)
 * Fallback: /public_html/_ratelimits (hidden)
 * --------------------------------------------------------- */
function sfm_rate_limit_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;

    // /public_html/includes -> /public_html
    $publicHtml = dirname(__DIR__);
    // /public_html -> /home/<account>
    $homeDir    = dirname($publicHtml);

    $secureDir = function_exists('sfm_secure_dir') ? sfm_secure_dir() : null;
    $candidates = [];
    if ($secureDir !== null) {
        $candidates[] = $secureDir . '/ratelimits';
    }
    $candidates[] = $homeDir . '/secure/ratelimits';     // legacy outside-webroot placement
    $candidates[] = $publicHtml . '/secure/ratelimits';   // local repo fallback
    $candidates[] = $publicHtml . '/_ratelimits';         // hidden in-webroot fallback

    foreach ($candidates as $candidate) {
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }
        if (is_dir($candidate) && is_writable($candidate)) {
            $dir = $candidate;
            return $dir;
        }
    }

    // Last resort: throw (caller can catch, but usually fatal)
    throw new RuntimeException('Rate-limit storage is not writable.');
}

/* -----------------------------------------------------------
 * Small helper: does client seem to accept JSON responses?
 * --------------------------------------------------------- */
function sfm_accepts_json(): bool {
    $hdr = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return (strpos($hdr, 'application/json') !== false) || (strpos($hdr, '*/*') !== false);
}

/* -----------------------------------------------------------
 * Core check: sliding window using per-IP files
 * Returns: [ok(bool), retryAfter(int seconds)]
 * --------------------------------------------------------- */
function sfm_rate_limit_check(string $bucket, int $limit, int $windowSeconds): array {
    $bucket = preg_replace('/[^a-z0-9_\-]/i', '', $bucket);
    if ($bucket === '') $bucket = 'default';

    $ip     = sfm_client_ip();
    $ipSafe = preg_replace('/[^0-9a-fA-F:.\-]/', '', $ip);
    if ($ipSafe === '') $ipSafe = 'unknown';

    $dir  = sfm_rate_limit_dir();
    $file = $dir . '/' . $bucket . '__' . $ipSafe . '.json';

    $now   = time();
    $since = $now - $windowSeconds;
    $hits  = [];

    // Read old hits
    $fh = @fopen($file, 'c+'); // create if not exists
    if ($fh === false) {
        // If we cannot open, be safe and allow once
        return [true, 0];
    }

    // Exclusive lock while we read/update/write
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return [true, 0]; // allow if lock failed (avoid hard block)
    }

    // Read existing content
    $raw = stream_get_contents($fh);
    if (is_string($raw) && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            foreach ($data as $ts) {
                if (is_int($ts) && $ts >= $since) {
                    $hits[] = $ts;
                }
            }
        }
    }

    // If already at (or above) limit, compute retry
    if (count($hits) >= $limit) {
        sort($hits);
        $oldest     = $hits[0] ?? $now;
        $retryAfter = max(1, $windowSeconds - ($now - $oldest));

        // Keep lock until after we set headers (no write needed)
        flock($fh, LOCK_UN);
        fclose($fh);

        return [false, $retryAfter];
    }

    // Record current hit and persist
    $hits[] = $now;

    // Truncate and rewind before writing
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    fflush($fh);
    // Relax permissions for shared hosting (group writable)
    @chmod($file, 0664);

    flock($fh, LOCK_UN);
    fclose($fh);

    // Opportunistic GC: 1% chance to delete very old files to keep dir tidy
    if (mt_rand(1, 100) === 1) {
        sfm_rate_limit_gc(dirname($file), $since - (7 * 86400)); // older than window+7d
    }

    return [true, 0];
}

/* -----------------------------------------------------------
 * Assert helper: emits 429 + Retry-After and exits when blocked
 * --------------------------------------------------------- */
function sfm_rate_limit_assert(string $bucket, int $limit, int $windowSeconds, bool $asJson = true): void {
    [$ok, $retry] = sfm_rate_limit_check($bucket, $limit, $windowSeconds);
    if ($ok) return;

    http_response_code(429);
    header('Retry-After: ' . (int)$retry);

    if ($asJson || sfm_accepts_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => false,
            'message' => "Rate limit exceeded. Try again in ~{$retry}s.",
        ], JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Rate limit exceeded. Try again in ~{$retry}s.";
    }
    exit;
}

/* -----------------------------------------------------------
 * Tiny GC: remove RL files not touched since $olderThanTs
 * Safe best-effort; ignores errors.
 * --------------------------------------------------------- */
function sfm_rate_limit_gc(string $dir, int $olderThanTs): void {
    if (!is_dir($dir)) return;
    $dh = @opendir($dir);
    if (!$dh) return;
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        // Only our files: "<bucket>__<ip>.json"
        if (!preg_match('/^[A-Za-z0-9_\-]+__[\w\.\:\-]+\.json$/', $f)) continue;
        $path = $dir . '/' . $f;
        $mt   = @filemtime($path);
        if ($mt !== false && $mt < $olderThanTs) {
            @unlink($path);
        }
    }
    closedir($dh);
}
