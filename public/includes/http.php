<?php
/**
 * includes/http.php
 *
 * Lightweight HTTP helpers for SimpleFeedMaker:
 *  - http_get(): single GET with tiny on-disk cache (ETag/Last-Modified)
 *  - http_head(): lightweight HEAD
 *  - http_multi_get(): fetch multiple URLs concurrently (no cache)
 *
 * Cache (safe on shared hosting):
 *   - Dir:   /feeds/.httpcache               (auto-created)
 *   - Files: <md5(url)>.body, <md5(url)>.meta.json
 *
 * Return shape (http_get/http_head):
 *   [
 *     'ok'         => bool,
 *     'status'     => int,
 *     'headers'    => array<string,string>,   // lower-cased keys
 *     'body'       => string,                 // '' for HEAD
 *     'final_url'  => string,                 // after redirects
 *     'from_cache' => bool,
 *     'was_304'    => bool,
 *   ]
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    define('APP_NAME', 'SimpleFeedMaker');
}

// ---------------------------------------------------------------
// Paths / small utilities
// ---------------------------------------------------------------

/** Default tiny HTTP cache dir (inside /feeds). */
function sfm_default_cache_dir(): string {
    $dir = dirname(__DIR__) . '/feeds/.httpcache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Build deterministic cache paths for a URL. */
function sfm_cache_paths(string $url, ?string $cacheDir = null): array {
    $cacheDir = $cacheDir ?: sfm_default_cache_dir();
    $key = md5($url);
    return [
        'dir'  => $cacheDir,
        'body' => $cacheDir . '/' . $key . '.body',
        'meta' => $cacheDir . '/' . $key . '.meta.json',
    ];
}

/** Normalize raw headers to an associative array (lower-cased keys). */
function sfm_parse_headers(string $rawHeaders): array {
    $lines = preg_split('/\r?\n/', $rawHeaders) ?: [];
    $out   = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'HTTP/') === 0) continue;
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $k = strtolower(trim(substr($line, 0, $pos)));
        $v = trim(substr($line, $pos + 1));
        // Combine duplicates (comma-join) — fine for our use.
        $out[$k] = isset($out[$k]) ? ($out[$k] . ', ' . $v) : $v;
    }
    return $out;
}

/** Default user-agent. */
function sfm_user_agent(): string {
    return APP_NAME . ' Bot/1.0 (+https://simplefeedmaker.com)';
}

// ---------------------------------------------------------------
// Core cURL request (GET/HEAD)
// ---------------------------------------------------------------

/**
 * Perform one request and return:
 * [ok(bool), status(int), headers_raw(string), body(string), info(array)]
 */
function sfm_request_raw(string $url, array $opts = []): array {
    $method         = strtoupper($opts['method'] ?? 'GET');  // 'GET' | 'HEAD'
    $timeout        = (int)($opts['timeout'] ?? 18);
    $connectTimeout = (int)($opts['connect_timeout'] ?? 8);
    $maxRedirs      = (int)($opts['max_redirs'] ?? 5);
    $ua             = (string)($opts['user_agent'] ?? sfm_user_agent());
    $accept         = (string)($opts['accept'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
    $acceptLang     = (string)($opts['accept_language'] ?? 'en-US,en;q=0.9');
    $extraHeaders   = (array)($opts['headers'] ?? []);

    // HTTP/2 -> 1.1 fallback, safest across hosts
    $httpVersion = defined('CURL_HTTP_VERSION_2TLS')
        ? CURL_HTTP_VERSION_2TLS
        : (defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 0);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => $maxRedirs,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER         => true,          // headers + body in one buffer
        CURLOPT_HTTP_VERSION   => $httpVersion,  // try HTTP/2, fallback to 1.1
        CURLOPT_ENCODING       => '',            // negotiate (gzip/deflate/br)
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: '           . $accept,
            'Accept-Language: '  . $acceptLang,
        ], $extraHeaders),
    ]);

    // Hard-limit supported protocols to HTTP/S to prevent SSRF to local schemes
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }

    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($err || $resp === false) {
        return [false, 0, '', '', $info];
    }

    $headerSize = (int)($info['header_size'] ?? 0);
    $headersRaw = substr($resp, 0, $headerSize);
    $body       = ($method === 'HEAD') ? '' : substr($resp, $headerSize);

    $status = (int)($info['http_code'] ?? 0);
    return [($status >= 200 && $status < 400), $status, $headersRaw, $body, $info];
}

// ---------------------------------------------------------------
// Public API
// ---------------------------------------------------------------

/**
 * http_get($url, $options)
 * Options:
 *  - use_cache        (bool)  default true
 *  - cache_ttl        (int)   seconds, default 900 (15m)
 *  - cache_dir        (string) override default cache path
 *  - timeout          (int)   total timeout (s)
 *  - connect_timeout  (int)   connect timeout (s)
 *  - max_redirs       (int)
 *  - user_agent       (string)
 *  - accept           (string)
 *  - accept_language  (string)
 *  - headers          (array) extra request headers
 *
 * Returns array with: ok, status, headers, body, final_url, from_cache, was_304
 */
function http_get(string $url, array $options = []): array {
    $useCache  = $options['use_cache'] ?? true;
    $cacheTtl  = (int)($options['cache_ttl'] ?? 900);
    $cacheDir  = $options['cache_dir'] ?? null;

    $paths     = sfm_cache_paths($url, $cacheDir);
    $metaPath  = $paths['meta'];
    $bodyPath  = $paths['body'];

    $cachedMeta = null;
    $now        = time();

    // Fresh cache HIT → serve immediately
    if ($useCache && is_file($metaPath) && is_file($bodyPath)) {
        $cachedMeta = json_decode((string)@file_get_contents($metaPath), true) ?: null;
        if ($cachedMeta && isset($cachedMeta['fetched_at']) && ($now - (int)$cachedMeta['fetched_at'] <= $cacheTtl)) {
            $body    = (string)@file_get_contents($bodyPath);
            $headers = (array)($cachedMeta['headers'] ?? []);

            // log: fresh cache hit
            sfm_log_event('http', [
                'url'         => $url,
                'final_url'   => (string)($cachedMeta['final_url'] ?? $url),
                'status'      => (int)($cachedMeta['status'] ?? 200),
                'from_cache'  => true,
                'was_304'     => false,
                'bytes'       => strlen($body),
                'duration_ms' => 0,
            ]);

            return [
                'ok'         => true,
                'status'     => (int)($cachedMeta['status'] ?? 200),
                'headers'    => array_change_key_case($headers, CASE_LOWER),
                'body'       => $body,
                'final_url'  => (string)($cachedMeta['final_url'] ?? $url),
                'from_cache' => true,
                'was_304'    => false,
            ];
        }
    }

    // Revalidation headers if we have ETag/Last-Modified
    $extra = [];
    if ($cachedMeta) {
        if (!empty($cachedMeta['etag']))          $extra[] = 'If-None-Match: ' . $cachedMeta['etag'];
        if (!empty($cachedMeta['last_modified'])) $extra[] = 'If-Modified-Since: ' . $cachedMeta['last_modified'];
    }

    // Perform request (time it)
    $opts = $options;
    $opts['headers'] = array_merge($extra, (array)($options['headers'] ?? []));
    $netStart = microtime(true);
    [$ok, $status, $headersRaw, $body, $info] = sfm_request_raw($url, $opts);
    $durationMs = (int) round((microtime(true) - $netStart) * 1000);

    $headers = sfm_parse_headers($headersRaw);
    $final   = $info['url'] ?? $url;

    // 304 Not Modified → serve cached body and refresh meta timestamp
    if ($status === 304 && $cachedMeta && is_file($bodyPath)) {
        $cachedMeta['fetched_at'] = $now;
        @file_put_contents($metaPath, json_encode($cachedMeta, JSON_UNESCAPED_SLASHES));
        $cachedBody = (string)@file_get_contents($bodyPath);

        // log: 304 revalidated cache hit
        sfm_log_event('http', [
            'url'         => $url,
            'final_url'   => (string)($cachedMeta['final_url'] ?? $url),
            'status'      => (int)($cachedMeta['status'] ?? 200),
            'from_cache'  => true,
            'was_304'     => true,
            'bytes'       => strlen($cachedBody),
            'duration_ms' => 0,
        ]);

        return [
            'ok'         => true,
            'status'     => (int)($cachedMeta['status'] ?? 200),
            'headers'    => array_change_key_case($cachedMeta['headers'] ?? [], CASE_LOWER),
            'body'       => $cachedBody,
            'final_url'  => (string)($cachedMeta['final_url'] ?? $final),
            'from_cache' => true,
            'was_304'    => true,
        ];
    }

    // Save/update cache for 2xx/3xx with body
    if ($useCache && $ok && $status >= 200 && $status < 400 && $body !== '') {
        $meta = [
            'status'        => $status,
            'headers'       => $headers,
            'final_url'     => $final,
            'etag'          => $headers['etag']          ?? ($headers['weak-etag'] ?? null),
            'last_modified' => $headers['last-modified'] ?? null,
            'fetched_at'    => $now,
            'url'           => $url,
        ];
        @file_put_contents($bodyPath, $body);
        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES));
    }

    // log: network fetch outcome
    sfm_log_event('http', [
        'url'         => $url,
        'final_url'   => $final,
        'status'      => (int)$status,
        'from_cache'  => false,
        'was_304'     => false,
        'bytes'       => strlen((string)$body),
        'duration_ms' => $durationMs,
        'ok'          => (bool)$ok,
    ]);

    return [
        'ok'         => $ok,
        'status'     => $status,
        'headers'    => $headers,
        'body'       => $body,
        'final_url'  => $final,
        'from_cache' => false,
        'was_304'    => false,
    ];
}

/** HEAD helper — fast metadata without body (no cache). */
function http_head(string $url, array $options = []): array {
    $options['method'] = 'HEAD';
    [$ok, $status, $headersRaw, $_, $info] = sfm_request_raw($url, $options);
    return [
        'ok'         => $ok,
        'status'     => $status,
        'headers'    => sfm_parse_headers($headersRaw),
        'body'       => '',
        'final_url'  => $info['url'] ?? $url,
        'from_cache' => false,
        'was_304'    => false,
    ];
}

/**
 * http_multi_get(array $urls, array $baseOptions = [])
 * Minimal concurrent GETs (no cache). Returns array keyed by URL.
 */
function http_multi_get(array $urls, array $baseOptions = []): array {
    $mh   = curl_multi_init();
    $map  = []; // url => ch
    $resp = [];

    // HTTP version fallback for multi handles too
    $httpVersion = defined('CURL_HTTP_VERSION_2TLS')
        ? CURL_HTTP_VERSION_2TLS
        : (defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 0);

    foreach ($urls as $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => (int)($baseOptions['max_redirs'] ?? 5),
            CURLOPT_CONNECTTIMEOUT => (int)($baseOptions['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT        => (int)($baseOptions['timeout'] ?? 18),
            CURLOPT_USERAGENT      => (string)($baseOptions['user_agent'] ?? sfm_user_agent()),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTP_VERSION   => $httpVersion,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: '          . (string)($baseOptions['accept'] ?? '*/*'),
                'Accept-Language: ' . (string)($baseOptions['accept_language'] ?? 'en-US,en;q=0.9'),
            ], (array)($baseOptions['headers'] ?? [])),
        ]);

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_multi_add_handle($mh, $ch);
        $map[$url] = $ch;
    }

    // Drive the multi handle
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status == CURLM_OK);

    // Collect results
    foreach ($map as $url => $ch) {
        $raw  = curl_multi_getcontent($ch);
        $info = curl_getinfo($ch);
        $err  = curl_error($ch);

        if ($err || $raw === false) {
            $resp[$url] = [
                'ok'         => false,
                'status'     => 0,
                'headers'    => [],
                'body'       => '',
                'final_url'  => $url,
                'from_cache' => false,
                'was_304'    => false,
            ];
        } else {
            $headerSize = (int)($info['header_size'] ?? 0);
            $headersRaw = substr($raw, 0, $headerSize);
            $body       = substr($raw, $headerSize);
            $resp[$url] = [
                'ok'         => ($info['http_code'] ?? 0) >= 200 && ($info['http_code'] ?? 0) < 400,
                'status'     => (int)($info['http_code'] ?? 0),
                'headers'    => sfm_parse_headers($headersRaw),
                'body'       => $body,
                'final_url'  => $info['url'] ?? $url,
                'from_cache' => false,
                'was_304'    => false,
            ];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $resp;
}

// ---------------------------------------------------------------
// Minimal, safe log helper (JSON lines to /secure/logs)
// ---------------------------------------------------------------
if (!function_exists('sfm_log_event')) {
    /**
     * @param string $channel e.g. 'http' or 'parse'
     * @param array  $data    scalar values only; JSON-encoded per line
     */
    function sfm_log_event(string $channel, array $data): void
    {
        // Toggle + path come from includes/config.php
        if (!defined('SFM_LOG_ENABLED') || !SFM_LOG_ENABLED) return;

        $dir = defined('SFM_LOG_DIR') ? SFM_LOG_DIR : (dirname(__DIR__) . '/secure/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) return;

        $file = rtrim($dir, '/\\') . '/request-' . gmdate('Ymd') . '.log';

        // PII-safe envelope
        $row = [
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'channel' => $channel,
        ] + $data;

        $json = @json_encode($row, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;

        @file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
