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
 * IP helper (trust common proxy headers if present)
 * --------------------------------------------------------- */
function sfm_client_ip(): string {
    // Prefer Cloudflare/Proxy headers if present
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR', // may contain a list
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($candidates as $h) {
        if (!empty($_SERVER[$h])) {
            $val = $_SERVER[$h];
            if ($h === 'HTTP_X_FORWARDED_FOR' && strpos($val, ',') !== false) {
                $parts = explode(',', $val);
                $val = trim($parts[0]);
            }
            // Basic sanitization
            $val = preg_replace('/[^0-9a-fA-F:.\-]/', '', $val);
            return $val ?: '0.0.0.0';
        }
    }
    return '0.0.0.0';
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

    // Preferred secure path outside web root
    $preferred  = $homeDir . '/secure/ratelimits';
    $fallback   = $publicHtml . '/_ratelimits';

    // Try preferred
    if (!is_dir($preferred)) {
        @mkdir($preferred, 0775, true);
    }
    if (is_dir($preferred) && is_writable($preferred)) {
        $dir = $preferred;
        return $dir;
    }

    // Fallback under web root (hidden folder)
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0775, true);
    }
    if (is_dir($fallback) && is_writable($fallback)) {
        $dir = $fallback;
        return $dir;
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
