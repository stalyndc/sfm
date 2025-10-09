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

require_once __DIR__ . '/config.php';

// Reuse security helpers for public URL/IP validation when available.
if (!function_exists('sfm_is_public_ip') || !function_exists('sfm_url_is_public')) {
    $securityFile = __DIR__ . '/security.php';
    if (is_file($securityFile) && is_readable($securityFile)) {
        require_once $securityFile;
    }
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'SimpleFeedMaker');
}

// ---------------------------------------------------------------
// Paths / small utilities
// ---------------------------------------------------------------

/** Default tiny HTTP cache dir (kept outside the web root). */
function sfm_default_cache_dir(): string {
    $dir = defined('SFM_HTTP_CACHE_DIR')
        ? rtrim((string)SFM_HTTP_CACHE_DIR, '/\\')
        : dirname(__DIR__) . '/feeds/.httpcache';
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

/** Determine if an IP address is public (no private/reserved ranges). */
function sfm_http_ip_is_public(string $ip): bool {
    if (function_exists('sfm_is_public_ip')) {
        return sfm_is_public_ip($ip);
    }

    $ip = trim($ip);
    if ($ip === '') {
        return false;
    }

    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

/** Validate that a URL uses http(s), has no credentials, and resolves to public IPs. */
function sfm_http_url_is_allowed(string $url, ?string &$reason = null): bool {
    $reason = null;
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        $reason = 'invalid_url';
        return false;
    }

    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        $reason = 'unsupported_scheme';
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        $reason = 'disallowed_auth';
        return false;
    }

    if (function_exists('sfm_url_is_public')) {
        if (!sfm_url_is_public($url)) {
            $reason = 'private_target';
            return false;
        }
    } else {
        $host = (string)$parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) && !sfm_http_ip_is_public($host)) {
            $reason = 'private_target';
            return false;
        }
    }

    return true;
}

/** Normalize a path by removing ./ and ../ segments. */
function sfm_http_normalize_path(string $path): string {
    if ($path === '') {
        return '/';
    }

    $segments = explode('/', $path);
    $stack    = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($stack);
            continue;
        }
        $stack[] = $segment;
    }

    $normalized = '/' . implode('/', $stack);
    if ($path !== '/' && substr($path, -1) === '/' && substr($normalized, -1) !== '/') {
        $normalized .= '/';
    }
    return $normalized;
}

/** Resolve a redirect Location header against the current URL. */
function sfm_http_resolve_redirect(string $currentUrl, string $location): ?string {
    $location = trim($location);
    if ($location === '') {
        return null;
    }

    $locParts  = @parse_url($location);
    $baseParts = @parse_url($currentUrl);
    if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return null;
    }

    if (is_array($locParts) && !empty($locParts['scheme'])) {
        $scheme = strtolower((string)$locParts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (empty($locParts['host'])) {
            return null;
        }

        $port     = isset($locParts['port']) ? ':' . (int)$locParts['port'] : '';
        $path     = sfm_http_normalize_path((string)($locParts['path'] ?? '/'));
        $query    = isset($locParts['query']) ? '?' . $locParts['query'] : '';
        $fragment = isset($locParts['fragment']) ? '#' . $locParts['fragment'] : '';

        return $scheme . '://' . $locParts['host'] . $port . $path . $query . $fragment;
    }

    $scheme = strtolower((string)$baseParts['scheme']);
    $host   = (string)$baseParts['host'];
    $port   = isset($baseParts['port']) ? ':' . (int)$baseParts['port'] : '';

    if (is_array($locParts) && !empty($locParts['host'])) {
        $path     = sfm_http_normalize_path((string)($locParts['path'] ?? '/'));
        $query    = isset($locParts['query']) ? '?' . $locParts['query'] : '';
        $fragment = isset($locParts['fragment']) ? '#' . $locParts['fragment'] : '';
        return $scheme . '://' . $locParts['host'] . (isset($locParts['port']) ? ':' . (int)$locParts['port'] : '') . $path . $query . $fragment;
    }

    $basePath = (string)($baseParts['path'] ?? '/');
    if ($basePath === '') {
        $basePath = '/';
    }
    $baseDir = preg_replace('~[^/]+$~', '', $basePath);
    if ($baseDir === '') {
        $baseDir = '/';
    }

    $relPath = is_array($locParts) ? (string)($locParts['path'] ?? '') : $location;
    if ($relPath === '') {
        $relPath = $basePath;
    } elseif ($relPath[0] !== '/') {
        $relPath = $baseDir . $relPath;
    }
    $path     = sfm_http_normalize_path($relPath);
    $query    = (is_array($locParts) && isset($locParts['query'])) ? '?' . $locParts['query'] : '';
    $fragment = (is_array($locParts) && isset($locParts['fragment'])) ? '#' . $locParts['fragment'] : '';

    return $scheme . '://' . $host . $port . $path . $query . $fragment;
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
    $followLocation = (bool)($opts['follow_location'] ?? false);

    // HTTP/2 -> 1.1 fallback, safest across hosts
    $httpVersion = defined('CURL_HTTP_VERSION_2TLS')
        ? CURL_HTTP_VERSION_2TLS
        : (defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 0);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $followLocation,
        CURLOPT_MAXREDIRS      => $followLocation ? $maxRedirs : 0,
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

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }

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
    $infoRaw = curl_getinfo($ch);
    curl_close($ch);

    /** @var array<string, mixed> $info */
    $info = is_array($infoRaw) ? $infoRaw : [];

    if ($err || $resp === false) {
        return [false, 0, '', '', $info];
    }

    $headerSize = isset($info['header_size']) ? (int)$info['header_size'] : 0;
    $headersRaw = substr($resp, 0, $headerSize);
    $body       = ($method === 'HEAD') ? '' : substr($resp, $headerSize);

    $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
    return [($status >= 200 && $status < 400), $status, $headersRaw, $body, $info];
}

/** Execute an HTTP request with manual redirect handling and SSRF guards. */
function sfm_http_execute(string $url, array $options, string $method): array {
    $blockReason   = null;
    $maxRedirects  = (int)($options['max_redirs'] ?? 5);
    $currentUrl    = $url;
    $redirectCount = 0;
    $visited       = [];

    $lastOk         = false;
    $lastStatus     = 0;
    $lastHeadersRaw = '';
    $lastBody       = '';
    $lastInfo       = ['url' => $currentUrl];
    $lastFinalUrl   = $currentUrl;

    while (true) {
        $visited[strtolower($currentUrl)] = true;

        $reason = null;
        if (!sfm_http_url_is_allowed($currentUrl, $reason)) {
            $blockReason = $reason ?? 'invalid_url';
            return [false, 0, '', '', ['url' => $currentUrl], $currentUrl, $blockReason];
        }

        $opts = $options;
        $opts['method']           = $method;
        $opts['follow_location']  = false;
        $opts['max_redirs']       = 0;

        [$ok, $status, $headersRaw, $body, $info] = sfm_request_raw($currentUrl, $opts);
        if (!isset($info['url']) || $info['url'] === '') {
            $info['url'] = $currentUrl;
        }

        $primaryIp = isset($info['primary_ip']) ? (string)$info['primary_ip'] : '';
        if ($primaryIp !== '' && !sfm_http_ip_is_public($primaryIp)) {
            $blockReason = 'blocked_private_ip';
            return [false, 0, '', '', $info, $currentUrl, $blockReason];
        }

        $lastOk         = $ok;
        $lastStatus     = $status;
        $lastHeadersRaw = $headersRaw;
        $lastBody       = $body;
        $lastInfo       = $info;
        $lastFinalUrl   = (string)$info['url'];

        if ($status >= 300 && $status < 400) {
            $headers  = sfm_parse_headers($headersRaw);
            $location = $headers['location'] ?? '';
            if ($location === '') {
                break;
            }

            $nextUrl = sfm_http_resolve_redirect($currentUrl, $location);
            if ($nextUrl === null) {
                $blockReason = 'invalid_redirect_target';
                return [false, 0, '', '', $info, $currentUrl, $blockReason];
            }

            $nextReason = null;
            if (!sfm_http_url_is_allowed($nextUrl, $nextReason)) {
                $blockReason = $nextReason ?? 'private_target';
                return [false, 0, '', '', $info, $currentUrl, $blockReason];
            }

            $key = strtolower($nextUrl);
            if (isset($visited[$key])) {
                $blockReason = 'redirect_loop';
                return [false, 0, '', '', $info, $currentUrl, $blockReason];
            }

            $redirectCount++;
            if ($redirectCount > $maxRedirects) {
                $blockReason = 'too_many_redirects';
                return [false, 0, '', '', $info, $currentUrl, $blockReason];
            }

            $currentUrl = $nextUrl;
            continue;
        }

        break;
    }

    return [$lastOk, $lastStatus, $lastHeadersRaw, $lastBody, $lastInfo, $lastFinalUrl, null];
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
 *
 * @return array{
 *   ok: bool,
 *   status: int,
 *   headers: array<string,string>,
 *   body: string,
 *   final_url: string,
 *   from_cache: bool,
 *   was_304: bool
 * }
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
    [$ok, $status, $headersRaw, $body, $info, $finalUrl, $blockReason] = sfm_http_execute($url, $opts, 'GET');
    $durationMs = (int) round((microtime(true) - $netStart) * 1000);

    if ($blockReason !== null) {
        sfm_log_event('http', [
            'url'         => $url,
            'final_url'   => $finalUrl,
            'status'      => (int)$status,
            'from_cache'  => false,
            'was_304'     => false,
            'bytes'       => 0,
            'duration_ms' => $durationMs,
            'ok'          => false,
            'error'       => $blockReason,
        ]);

        return [
            'ok'         => false,
            'status'     => 0,
            'headers'    => [],
            'body'       => '',
            'final_url'  => $finalUrl,
            'from_cache' => false,
            'was_304'    => false,
        ];
    }

    $headers = sfm_parse_headers($headersRaw);
    $final   = $finalUrl;

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
    [$ok, $status, $headersRaw, $_, $info, $finalUrl, $blockReason] = sfm_http_execute($url, $options, 'HEAD');
    if ($blockReason !== null) {
        sfm_log_event('http', [
            'url'         => $url,
            'final_url'   => $finalUrl,
            'status'      => (int)$status,
            'from_cache'  => false,
            'was_304'     => false,
            'bytes'       => 0,
            'duration_ms' => 0,
            'ok'          => false,
            'error'       => $blockReason,
        ]);

        return [
            'ok'         => false,
            'status'     => 0,
            'headers'    => [],
            'body'       => '',
            'final_url'  => $finalUrl,
            'from_cache' => false,
            'was_304'    => false,
        ];
    }

    return [
        'ok'         => $ok,
        'status'     => $status,
        'headers'    => sfm_parse_headers($headersRaw),
        'body'       => '',
        'final_url'  => $finalUrl,
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
        if (!sfm_http_url_is_allowed($url)) {
            $resp[$url] = [
                'ok'         => false,
                'status'     => 0,
                'headers'    => [],
                'body'       => '',
                'final_url'  => $url,
                'from_cache' => false,
                'was_304'    => false,
            ];
            continue;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
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
        $infoRaw = curl_getinfo($ch);
        $err  = curl_error($ch);

        /** @var array<string, mixed> $info */
        $info = is_array($infoRaw) ? $infoRaw : [];

        $primaryIp = isset($info['primary_ip']) ? (string)$info['primary_ip'] : '';
        if ($err || $raw === false || ($primaryIp !== '' && !sfm_http_ip_is_public($primaryIp))) {
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
            $headerSize = isset($info['header_size']) ? (int)$info['header_size'] : 0;
            $headersRaw = substr($raw, 0, $headerSize);
            $body       = substr($raw, $headerSize);
            $status     = isset($info['http_code']) ? (int)$info['http_code'] : 0;
            $resp[$url] = [
                'ok'         => $status >= 200 && $status < 400,
                'status'     => $status,
                'headers'    => sfm_parse_headers($headersRaw),
                'body'       => $body,
                'final_url'  => isset($info['url']) ? (string)$info['url'] : $url,
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
