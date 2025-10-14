<?php
/**
 * includes/job_refresh.php
 *
 * Shared helpers used to refresh job feeds either via cron or the admin UI.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/enrich.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/feed_validator.php';
require_once __DIR__ . '/monitor.php';

if (!function_exists('sfm_normalize_feed_encoding')) {
    /**
     * Ensure feed XML is UTF-8 encoded before libxml parsing.
     *
     * @param array<string,string> $headers
     * @return array{body:string,changed:bool,source_charset:?string}
     */
    function sfm_normalize_feed_encoding(string $body, array $headers = []): array
    {
        $result = [
            'body'           => $body,
            'changed'        => false,
            'source_charset' => null,
        ];

        $clean = $body;
        $isUtf8 = function_exists('mb_check_encoding')
            ? mb_check_encoding($clean, 'UTF-8')
            : (@preg_match('//u', $clean) === 1);

        if (!$isUtf8) {
            $charset = null;
            $headerCharset = null;

            foreach (['content-type', 'Content-Type'] as $key) {
                if (isset($headers[$key])) {
                    if (preg_match('/charset\s*=\s*"?([^";]+)"?/i', $headers[$key], $m)) {
                        $headerCharset = strtoupper(trim($m[1]));
                        break;
                    }
                }
            }

            $xmlCharset = null;
            if (preg_match('/<\?xml[^>]*encoding=["\']([^"\']+)["\']/', $clean, $m)) {
                $xmlCharset = strtoupper(trim($m[1]));
            }

            if ($xmlCharset !== null) {
                $charset = $xmlCharset;
            } elseif ($headerCharset !== null) {
                $charset = $headerCharset;
            }

            if ($charset === null && function_exists('mb_detect_encoding')) {
                $detected = @mb_detect_encoding(
                    $clean,
                    ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'WINDOWS-1252', 'CP1252', 'CP1251', 'UTF-16', 'UTF-16LE', 'UTF-16BE', 'UTF-32', 'ASCII'],
                    true
                );
                if ($detected !== false) {
                    $charset = strtoupper($detected);
                }
            }

            if ($charset === null) {
                $charset = 'WINDOWS-1252';
            }

            $charsetMap = [
                'UTF8'         => 'UTF-8',
                'UTF-8'        => 'UTF-8',
                'ISO8859-1'    => 'ISO-8859-1',
                'ISO-8859-1'   => 'ISO-8859-1',
                'ISO8859-15'   => 'ISO-8859-15',
                'ISO-8859-15'  => 'ISO-8859-15',
                'WINDOWS-1252' => 'Windows-1252',
                'CP1252'       => 'Windows-1252',
                'CP-1252'      => 'Windows-1252',
                'WINDOWS-1251' => 'Windows-1251',
                'CP1251'       => 'Windows-1251',
                'UTF-16'       => 'UTF-16',
                'UTF-16LE'     => 'UTF-16LE',
                'UTF-16BE'     => 'UTF-16BE',
                'UTF-32'       => 'UTF-32',
                'UTF-32LE'     => 'UTF-32LE',
                'UTF-32BE'     => 'UTF-32BE',
            ];
            $convertFrom = $charsetMap[$charset] ?? $charset;

            $converted = null;
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($clean, 'UTF-8', $convertFrom);
            }
            if (!is_string($converted) && function_exists('iconv')) {
                $converted = @iconv($convertFrom, 'UTF-8//IGNORE', $clean);
            }

            if (is_string($converted) && $converted !== '') {
                $clean = $converted;
                $result['changed'] = true;
                $result['source_charset'] = $charset;
            }
        }

        if (substr($clean, 0, 3) === "\xEF\xBB\xBF") {
            $clean = substr($clean, 3) ?: '';
            $result['changed'] = true;
            if ($result['source_charset'] === null) {
                $result['source_charset'] = 'UTF-8-BOM';
            }
        }

        $stillInvalid = function_exists('mb_check_encoding')
            ? !mb_check_encoding($clean, 'UTF-8')
            : (@preg_match('//u', $clean) !== 1);

        if ($stillInvalid && function_exists('iconv')) {
            /** @var string|false $fallback */
            $fallback = @iconv('UTF-8', 'UTF-8//IGNORE', $clean);
            if ($fallback !== false) {
                $clean = $fallback;
                $result['changed'] = true;
            }
        } elseif ($stillInvalid && function_exists('mb_convert_encoding')) {
            /** @var string|false $fallback */
            $fallback = @mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
            if ($fallback !== false) {
                $clean = $fallback;
                $result['changed'] = true;
            }
        }

        if ($clean !== $body) {
            $count = 0;
            $updated = preg_replace(
                '/(<\?xml\b[^>]*encoding=["\'])[^"\']+(["\'])/i',
                '$1UTF-8$2',
                $clean,
                1,
                $count
            );
            if (is_string($updated)) {
                $clean = $updated;
            }
            if ($count === 0) {
                $trimmed = ltrim($clean);
                if (str_starts_with($trimmed, '<?xml') && stripos($trimmed, 'encoding=') === false) {
                    $added = preg_replace('/(<\?xml\b[^>]*)(\?>)/i', '$1 encoding="UTF-8"$2', $clean, 1);
                    if (is_string($added)) {
                        $clean = $added;
                    }
                }
            }
        }

        $result['body'] = $clean;
        return $result;
    }
}

if (!function_exists('sfm_refresh_failure_threshold')) {
    function sfm_refresh_failure_threshold(): int
    {
        $env = getenv('SFM_REFRESH_ALERT_THRESHOLD');
        if ($env !== false && $env !== '' && is_numeric($env)) {
            $value = (int)$env;
        } else {
            $value = 3;
        }
        return max(1, $value);
    }
}

if (!function_exists('sfm_count_feed_items')) {
    function sfm_count_feed_items(string $body, string $format): int
    {
        $format = strtolower($format);
        if ($format === 'jsonfeed') {
            $data = json_decode($body, true);
            return is_array($data) && isset($data['items']) && is_array($data['items'])
                ? count($data['items'])
                : 0;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return 0;
        }

        if ($format === 'atom') {
            return $dom->getElementsByTagName('entry')->length;
        }

        return $dom->getElementsByTagName('item')->length;
    }
}

if (!function_exists('sfm_job_keyword_list')) {
    function sfm_job_keyword_list(array $job, string $key): array
    {
        if (!isset($job[$key])) {
            return [];
        }
        return sfm_job_normalize_keywords($job[$key]);
    }
}

if (!function_exists('sfm_job_item_text_blob')) {
    function sfm_job_item_text_blob(array $item): string
    {
        $parts = [];
        foreach (['title', 'description'] as $field) {
            if (!empty($item[$field])) {
                $parts[] = (string)$item[$field];
            }
        }
        if (!empty($item['content_html'])) {
            $parts[] = strip_tags((string)$item['content_html']);
        }
        $blob = trim(implode(' ', $parts));
        if ($blob === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($blob, 'UTF-8');
        }
        return strtolower($blob);
    }
}

if (!function_exists('sfm_job_filter_items')) {
    /**
     * @return array{0:array<int,array>,1:array{dropped:int,include_hits:int}}
     */
    function sfm_job_filter_items(array $items, array $job): array
    {
        $includes = sfm_job_keyword_list($job, 'include_keywords');
        $excludes = sfm_job_keyword_list($job, 'exclude_keywords');

        if (!$includes && !$excludes) {
            return [$items, ['dropped' => 0, 'include_hits' => 0]];
        }

        $kept = [];
        $stats = ['dropped' => 0, 'include_hits' => 0];

        foreach ($items as $item) {
            $text = sfm_job_item_text_blob($item);
            if ($text === '') {
                $text = '';
            }

            $matchedInclude = true;
            if ($includes) {
                $matchedInclude = false;
                foreach ($includes as $keyword) {
                    if ($keyword === '') {
                        continue;
                    }
                    if ($text !== '' && (function_exists('mb_strpos') ? mb_strpos($text, $keyword) !== false : strpos($text, $keyword) !== false)) {
                        $matchedInclude = true;
                        $stats['include_hits']++;
                        break;
                    }
                }
            }

            if (!$matchedInclude) {
                $stats['dropped']++;
                continue;
            }

            $matchesExclude = false;
            if ($excludes) {
                foreach ($excludes as $keyword) {
                    if ($keyword === '') {
                        continue;
                    }
                    if ($text !== '' && (function_exists('mb_strpos') ? mb_strpos($text, $keyword) !== false : strpos($text, $keyword) !== false)) {
                        $matchesExclude = true;
                        break;
                    }
                }
            }

            if ($matchesExclude) {
                $stats['dropped']++;
                continue;
            }

            $kept[] = $item;
        }

        return [$kept, $stats];
    }
}

if (!function_exists('sfm_custom_try_native_payload')) {
    /**
     * Attempt to treat a custom refresh response as a native feed when possible.
     */
    function sfm_custom_try_native_payload(array $page, string $sourceUrl, string $tmpPath, string $feedPath): ?array
    {
        $body = (string)($page['body'] ?? '');
        if ($body === '') {
            return null;
        }

        $headers = [];
        if (isset($page['headers']) && is_array($page['headers'])) {
            $headers = $page['headers'];
        }

        $contentType = strtolower((string)($headers['content-type'] ?? ''));
        $trimmed = ltrim($body);
        $prefix = strtolower(substr($trimmed, 0, 20));
        $looksJson = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
        $looksXml = $prefix !== '' && (
            str_starts_with($prefix, '<?xml') ||
            str_starts_with($prefix, '<rss') ||
            str_starts_with($prefix, '<rdf') ||
            str_starts_with($prefix, '<feed')
        );

        $maybeFeed = false;
        if ($contentType !== '') {
            if (str_contains($contentType, 'xml') || str_contains($contentType, 'rss') || str_contains($contentType, 'atom') || str_contains($contentType, 'json')) {
                $maybeFeed = true;
            }
        }
        if (!$maybeFeed) {
            $maybeFeed = $looksJson || $looksXml;
        }
        if (!$maybeFeed) {
            return null;
        }

        $encodingNormalization = sfm_normalize_feed_encoding($body, $headers);
        $body = $encodingNormalization['body'];

        $detected = detect_feed_format_and_ext($body, $headers, $sourceUrl);
        $format = $detected[0] ?? 'rss';
        $normalization = sfm_normalize_feed($body, $format, $sourceUrl);
        if ($normalization) {
            $body = $normalization['body'];
            $format = $normalization['format'];
        }

        $validation = sfm_validate_feed($format, $body);
        if (!$validation['ok']) {
            return null;
        }

        $itemsCount = sfm_count_feed_items($body, $format);
        if ($itemsCount <= 0) {
            return null;
        }

        if (@file_put_contents($tmpPath, $body) === false) {
            throw new RuntimeException('Unable to write temp feed file');
        }
        @chmod($tmpPath, 0664);
        if (!@rename($tmpPath, $feedPath)) {
            throw new RuntimeException('Unable to replace feed file');
        }

        $result = [
            $body,
            $itemsCount,
            $validation,
            'override' => [
                'key'    => 'auto-native-feed',
                'label'  => 'Native feed detected',
                'source' => $sourceUrl,
            ],
        ];

        return $result;
    }
}

if (!function_exists('sfm_known_feed_override')) {
    function sfm_known_feed_override(array $job, string $feedUrl, string $tmpPath, string $feedPath): ?array
    {
        $sourceUrl = (string)($job['source_url'] ?? '');
        if ($sourceUrl === '') {
            return null;
        }

        $buildNativeOverride = static function (array $job, string $overrideUrl, string $key, string $label) use ($tmpPath, $feedPath): ?array {
            $overrideJob = $job;
            $overrideJob['native_source'] = $overrideUrl;

            try {
                $native = sfm_refresh_native($overrideJob);
            } catch (Throwable $e) {
                return null;
            }

            if (@file_put_contents($tmpPath, $native['body']) === false) {
                throw new RuntimeException('Unable to write temp feed file');
            }
            @chmod($tmpPath, 0664);
            if (!@rename($tmpPath, $feedPath)) {
                throw new RuntimeException('Unable to replace feed file');
            }

            $itemsCount = sfm_count_feed_items($native['body'], $native['format']);

            return [
                $native['body'],
                $itemsCount,
                $native['validation'],
                'override' => [
                    'key'    => $key,
                    'label'  => $label,
                    'source' => $overrideUrl,
                ],
            ];
        };

        if (preg_match('#^https?://(?:www\.)?arcamax\.com/thefunnies/([a-z0-9_-]+)/?$#i', $sourceUrl, $m)) {
            $slug = $m[1];
            $rssUrl = 'https://www.arcamax.com/rss/thefunnies/' . $slug . '/';
            return $buildNativeOverride($job, $rssUrl, 'arcamax', 'ArcaMax native RSS fallback');
        }

        if (stripos($sourceUrl, 'news.google.com/topics/') !== false) {
            $rssUrl = preg_replace('/\/topics\//i', '/rss/topics/', $sourceUrl, 1, $replaced);
            if ($replaced > 0 && is_string($rssUrl)) {
                return $buildNativeOverride($job, $rssUrl, 'google-topics', 'Google Topics RSS fallback');
            }
        }

        if (preg_match('#^https?://(?:www\.)?ninefornews\.nl/?$#i', $sourceUrl)) {
            $rssUrl = rtrim($sourceUrl, '/') . '/feed/';
            return $buildNativeOverride($job, $rssUrl, 'ninefornews', 'NineForNews native feed override');
        }

        if (preg_match('#^https?://(?:www\.)?rense\.com/?$#i', $sourceUrl)) {
            /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $page */
            $page = http_get($sourceUrl, [
                'use_cache' => false,
                'timeout'   => TIMEOUT_S,
                'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ]);
            $pageBody = $page['body'];
            if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $pageBody === '') {
                return null;
            }

            $items = [];
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $converted = $pageBody;
            if (function_exists('mb_convert_encoding')) {
                $tmp = @mb_convert_encoding($pageBody, 'HTML-ENTITIES', 'UTF-8');
                if (strlen((string)$tmp) > 0) {
                    $converted = (string)$tmp;
                }
            }
            $htmlLoaded = @$dom->loadHTML($converted);
            if (!$htmlLoaded && $converted !== $pageBody) {
                $htmlLoaded = @$dom->loadHTML($pageBody);
            }
            if ($htmlLoaded) {
                $links = $dom->getElementsByTagName('a');
                $seen = [];
                foreach ($links as $a) {
                    /** @var DOMElement $a */
                    $href = trim($a->getAttribute('href'));
                    if ($href === '') {
                        continue;
                    }
                    $abs = sfm_abs_url($href, $sourceUrl);
                    if ($abs === '') {
                        continue;
                    }
                    if (stripos($abs, '.htm') === false && stripos($abs, '.html') === false) {
                        continue;
                    }
                    if (stripos($abs, '://rense.com') === false) {
                        continue;
                    }
                    $text = trim($a->textContent ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $key = strtolower($abs);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $items[] = [
                        'title'       => $text,
                        'link'        => $abs,
                        'description' => $text,
                        'date'        => '',
                    ];
                    if (count($items) >= max(10, (int)($job['limit'] ?? DEFAULT_LIM))) {
                        break;
                    }
                }
            }
            libxml_clear_errors();

            if (!$items) {
                return null;
            }

            $title = 'Rense.com Highlights';
            $desc = 'Top links captured from Rense.com homepage.';
            $rss = build_rss($title, $sourceUrl, $desc, $items);
            $validation = sfm_validate_feed('rss', $rss);
            if (!$validation['ok']) {
                return null;
            }

            if (@file_put_contents($tmpPath, $rss) === false) {
                throw new RuntimeException('Unable to write temp feed file');
            }
            @chmod($tmpPath, 0664);
            if (!@rename($tmpPath, $feedPath)) {
                throw new RuntimeException('Unable to replace feed file');
            }

            return [
                $rss,
                count($items),
                $validation,
                'override' => [
                    'key'    => 'rense',
                    'label'  => 'Rense.com homepage parser',
                    'source' => $sourceUrl,
                ],
            ];
        }

        return null;
    }
}

if (!function_exists('sfm_refresh_job')) {
    /**
     * Refresh a single job (native or custom).
     * Returns true on success, false on failure.
     */
    function sfm_refresh_job(array $job, bool $logEnabled)
    {
        $mode        = $job['mode'] ?? 'custom';
        $feedFile    = $job['feed_filename'] ?? '';
        $sourceUrl   = $job['source_url'] ?? '';
        $feedPath    = $feedFile ? FEEDS_DIR . '/' . $feedFile : '';
        $tmpPath     = $feedPath ? ($feedPath . '.tmp' . bin2hex(random_bytes(4))) : '';
        $feedUrl     = $job['feed_url'] ?? (rtrim(app_url_base(), '/') . '/feeds/' . $feedFile);

        if ($feedFile === '' || !$feedPath) {
            $updatedJob = sfm_job_mark_failure($job, 'Missing feed filename');
            if (is_array($updatedJob)) {
                sfm_monitor_on_failure($updatedJob);
            } else {
                sfm_monitor_on_failure($job);
            }
            return false;
        }

        ensure_feeds_dir();

        try {
            $native = null;
            if ($mode === 'native' && !empty($job['native_source'])) {
                try {
                    $native = sfm_refresh_native($job);
                } catch (Throwable $nativeError) {
                    if ($logEnabled && function_exists('sfm_log_event')) {
                        sfm_log_event('refresh', [
                            'phase'  => 'native-error',
                            'job_id' => $job['job_id'],
                            'error'  => $nativeError->getMessage(),
                        ]);
                    }
                    $jobUpdate = sfm_job_update($job['job_id'], [
                        'mode'              => 'custom',
                        'native_source'     => null,
                        'last_refresh_note' => 'native failed, switched to custom',
                    ]);
                    if (is_array($jobUpdate)) {
                        $job = $jobUpdate;
                    } else {
                        $job['mode'] = 'custom';
                        $job['native_source'] = null;
                        $job['last_refresh_note'] = 'native failed, switched to custom';
                    }
                    $mode = 'custom';
                    $sourceUrl = $job['source_url'] ?? $sourceUrl;
                }
            }

            if ($native !== null) {

                $jobFormat    = strtolower((string)($job['format'] ?? DEFAULT_FMT));
                $nativeFormat = strtolower((string)($native['format'] ?? $jobFormat));
                $currentExt   = strtolower((string)pathinfo($feedFile, PATHINFO_EXTENSION));
                $nativeExt    = strtolower((string)($native['ext'] ?? ($nativeFormat === 'jsonfeed' ? 'json' : 'xml')));
                if ($nativeExt === '') {
                    $nativeExt = ($nativeFormat === 'jsonfeed') ? 'json' : 'xml';
                }

                $formatMatches    = ($nativeFormat === $jobFormat);
                $extensionMatches = ($currentExt === '' || $nativeExt === $currentExt);

                if ($formatMatches && $extensionMatches) {
                    if (@file_put_contents($tmpPath, $native['body']) === false) {
                        throw new RuntimeException('Unable to write temp feed file');
                    }
                    @chmod($tmpPath, 0664);
                    if (!@rename($tmpPath, $feedPath)) {
                        throw new RuntimeException('Unable to replace feed file');
                    }

                    $bytes = strlen($native['body']);
                    $nativeValidation = isset($native['validation']) && is_array($native['validation']) ? $native['validation'] : null;
                    $nativeNote = 'native refresh';
                    if (!empty($native['normalized'])) {
                        $nativeNote .= ' (' . $native['normalized'] . ')';
                    }
                    $updatedJob = sfm_job_mark_success($job, $bytes, (int)$native['status'], null, $nativeNote, $nativeValidation);
                    if (is_array($updatedJob)) {
                        $job = $updatedJob;
                    }
                    sfm_monitor_on_success($job, [
                        'mode'        => 'native',
                        'bytes'       => $bytes,
                        'status'      => (int)($native['status'] ?? 200),
                        'validation'  => $nativeValidation,
                        'normalized'  => $native['normalized'] ?? null,
                        'source'      => (string)($job['native_source'] ?? ''),
                    ]);
                    if ($logEnabled && function_exists('sfm_log_event')) {
                        $validationNote = null;
                        if ($nativeValidation && !empty($nativeValidation['warnings'])) {
                            $validationNote = $nativeValidation['warnings'][0] ?? null;
                        }
                        sfm_log_event('refresh', [
                            'phase'  => 'native',
                            'job_id' => $job['job_id'],
                            'bytes'  => $bytes,
                            'source' => $job['native_source'],
                            'validation' => $validationNote,
                            'normalized' => $native['normalized'] ?? null,
                        ]);
                    }
                    return true;
                }

                // Native format no longer matches what we serve — switch job to custom mode.
                $jobUpdate = sfm_job_update($job['job_id'], [
                    'mode'              => 'custom',
                    'native_source'     => null,
                    'last_refresh_note' => 'native format changed',
                ]);
                if (is_array($jobUpdate)) {
                    $job       = $jobUpdate;
                    $mode      = 'custom';
                    $sourceUrl = $job['source_url'] ?? $sourceUrl;
                } else {
                    $mode = 'custom';
                }
                if ($logEnabled && function_exists('sfm_log_event')) {
                    sfm_log_event('refresh', [
                        'phase'  => 'native-switch',
                        'job_id' => $job['job_id'],
                        'reason' => 'format mismatch',
                    ]);
                }
            }

            if (!$sourceUrl) {
                $updatedJob = sfm_job_mark_failure($job, 'Missing source URL');
                if (is_array($updatedJob)) {
                    sfm_monitor_on_failure($updatedJob);
                } else {
                    sfm_monitor_on_failure($job);
                }
                return false;
            }

            $customResult = sfm_refresh_custom($job, $sourceUrl, $feedUrl, $tmpPath, $feedPath);
            [$content, $itemsCount, $validation] = $customResult;
            $skipMeta = is_array($customResult['skip'] ?? null) ? $customResult['skip'] : null;
            $overrideMeta = $customResult['override'] ?? null;
            $filterMeta = is_array($customResult['filters'] ?? null) ? $customResult['filters'] : ['dropped' => 0];
            $note = 'custom refresh';
            if (!empty($filterMeta['dropped'])) {
                $note .= ' (filtered ' . (int)$filterMeta['dropped'] . ')';
            }
            if ($skipMeta) {
                $skipNote = trim((string)($skipMeta['note'] ?? ''));
                $note = $skipNote !== '' ? $skipNote : 'custom refresh (no items)';
            }
            if (is_array($overrideMeta)) {
                $label = trim((string)($overrideMeta['label'] ?? ''));
                if ($label === '') {
                    $label = (string)($overrideMeta['key'] ?? 'override');
                }
                $note = 'override: ' . $label;
            }
            $bytes = strlen($content);
            $updatedJob = sfm_job_mark_success($job, $bytes, 200, $itemsCount, $note, $validation);
            if (is_array($updatedJob)) {
                $job = $updatedJob;
            }
            if (is_array($overrideMeta)) {
                sfm_monitor_on_override($job, $overrideMeta);
            }
            sfm_monitor_on_success($job, [
                'mode'     => 'custom',
                'bytes'    => $bytes,
                'items'    => $itemsCount,
                'override' => $overrideMeta,
                'source'   => $sourceUrl,
                'filters'  => $filterMeta,
                'skip'     => $skipMeta,
            ]);
            if ($logEnabled && function_exists('sfm_log_event')) {
                $validationNote = null;
                if (!empty($validation['warnings'])) {
                    $validationNote = $validation['warnings'][0] ?? null;
                }
                sfm_log_event('refresh', [
                    'phase'  => 'custom',
                    'job_id' => $job['job_id'],
                    'bytes'  => $bytes,
                    'items'  => $itemsCount,
                    'source' => $sourceUrl,
                    'override' => is_array($overrideMeta) ? ($overrideMeta['key'] ?? null) : null,
                    'validation' => $validationNote,
                ]);
            }
            return true;
        } catch (Throwable $e) {
            $updatedJob = sfm_job_mark_failure($job, $e->getMessage());
            if (is_array($updatedJob)) {
                $job = $updatedJob;
            }

            $streak = (int)($job['failure_streak'] ?? 0);
            $threshold = sfm_refresh_failure_threshold();
            if ($streak >= $threshold) {
                sfm_job_attach_diagnostics($job['job_id'], [
                    'captured_at'     => sfm_job_now_iso(),
                    'failure_streak'  => $streak,
                    'error'           => $e->getMessage(),
                    'source_url'      => (string)($job['source_url'] ?? ''),
                    'mode'            => (string)($job['mode'] ?? ''),
                    'http_status'     => $job['last_refresh_code'] ?? null,
                    'note'            => $job['last_refresh_note'] ?? null,
                ]);
            } else {
                sfm_job_clear_diagnostics($job['job_id']);
            }
            if ($logEnabled && function_exists('sfm_log_event')) {
                sfm_log_event('refresh', [
                    'phase'  => 'error',
                    'job_id' => $job['job_id'],
                    'error'  => $e->getMessage(),
                ]);
            }
            sfm_monitor_on_failure($job);
            return false;
        } finally {
            if ($tmpPath && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}

if (!function_exists('sfm_refresh_native')) {
    function sfm_refresh_native(array $job): array
    {
        $nativeUrl = $job['native_source'];
        if (!sfm_url_is_public($nativeUrl)) {
            throw new RuntimeException('Native feed host is not public');
        }
        $resp = http_get($nativeUrl, [
            'use_cache' => false,
            'timeout'   => TIMEOUT_S,
            'accept'    => 'application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml;q=0.9, */*;q=0.8',
        ]);

        if (!$resp['ok'] || $resp['status'] < 200 || $resp['status'] >= 400) {
            throw new RuntimeException('Native refresh failed (HTTP ' . ($resp['status'] ?? 0) . ')');
        }
        if ($resp['body'] === '') {
            throw new RuntimeException('Native refresh returned empty body');
        }

        $notes = [];

        $encodingNormalization = sfm_normalize_feed_encoding($resp['body'], $resp['headers'] ?? []);
        $resp['body'] = $encodingNormalization['body'];
        if (!empty($encodingNormalization['changed'])) {
            $sourceCharset = $encodingNormalization['source_charset'] ? strtolower($encodingNormalization['source_charset']) : null;
            $notes[] = $sourceCharset ? ('encoding normalized from ' . $sourceCharset) : 'encoding normalized';
        }

        [$detectedFormat, $ext] = detect_feed_format_and_ext($resp['body'], $resp['headers'] ?? [], $nativeUrl);
        $normalization = sfm_normalize_feed($resp['body'], $detectedFormat, $nativeUrl);
        if ($normalization) {
            $resp['body'] = $normalization['body'];
            $detectedFormat = $normalization['format'];
            $ext = $normalization['ext'];
            if (!empty($normalization['note'])) {
                $notes[] = $normalization['note'];
            }
        }

        $validation = sfm_validate_feed($detectedFormat ?: 'rss', $resp['body']);
        if (!$validation['ok']) {
            $primary = $validation['errors'][0] ?? 'Native feed failed validation.';
            throw new RuntimeException($primary);
        }

        return [
            'body'       => $resp['body'],
            'status'     => (int)($resp['status'] ?? 200),
            'format'     => $detectedFormat ?: 'rss',
            'ext'        => $ext ?: 'xml',
            'validation' => $validation,
            'normalized' => $notes ? implode('; ', $notes) : null,
        ];
    }
}

if (!function_exists('sfm_refresh_custom')) {
    function sfm_refresh_custom(array $job, string $sourceUrl, string $feedUrl, string $tmpPath, string $feedPath): array
    {
        $limit  = max(1, min(MAX_LIM, (int)($job['limit'] ?? DEFAULT_LIM)));
        $format = strtolower((string)($job['format'] ?? DEFAULT_FMT));
        if (!in_array($format, ['rss', 'atom', 'jsonfeed'], true)) {
            $format = DEFAULT_FMT;
        }

        $allowEmpty = !empty($job['allow_empty']);

        $override = sfm_known_feed_override($job, $feedUrl, $tmpPath, $feedPath);
        if ($override !== null) {
            return $override;
        }

        /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $page */
        $page = http_get($sourceUrl, [
            'use_cache' => false,
            'timeout'   => TIMEOUT_S,
            'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        if ($page['error'] === 'body_too_large') {
            $limitBytes = defined('SFM_HTTP_MAX_BYTES') ? (int) SFM_HTTP_MAX_BYTES : 0;
            $limitMb = $limitBytes > 0 ? round($limitBytes / 1048576, 1) : null;
            $message = 'Source fetch aborted: page exceeded size limit';
            if ($limitMb !== null) {
                $message .= ' (' . $limitMb . ' MB)';
            }
            throw new RuntimeException($message . '.');
        }

        if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
            if ($page['error'] === 'curl_error') {
                throw new RuntimeException('Source fetch failed before receiving a response.');
            }
            throw new RuntimeException('Source fetch failed (HTTP ' . $page['status'] . ')');
        }

        $native = sfm_custom_try_native_payload($page, $sourceUrl, $tmpPath, $feedPath);
        if (is_array($native)) {
            return $native;
        }

        $items = sfm_extract_items($page['body'], $sourceUrl, $limit);
        [$items, $filterStats] = sfm_job_filter_items($items, $job);
        if (empty($items)) {
            if ($allowEmpty) {
                $previousContent = '';
                if (is_file($feedPath)) {
                    $previous = @file_get_contents($feedPath);
                    if (is_string($previous)) {
                        $previousContent = $previous;
                    }
                }

                if ($previousContent !== '') {
                    return [
                        $previousContent,
                        isset($job['items_count']) ? (int)$job['items_count'] : 0,
                        is_array($job['last_validation'] ?? null) ? $job['last_validation'] : null,
                        'filters' => $filterStats,
                        'skip'    => [
                            'reason' => 'no_items',
                            'note'   => 'No items detected; kept previous feed content.',
                        ],
                    ];
                }

                $title = APP_NAME . ' Feed';
                $desc  = 'Custom feed generated by ' . APP_NAME;

                switch ($format) {
                    case 'jsonfeed':
                        $content = build_jsonfeed($title, $sourceUrl, $desc, [], $feedUrl);
                        break;
                    case 'atom':
                        $content = build_atom($title, $sourceUrl, $desc, []);
                        break;
                    default:
                        $content = build_rss($title, $sourceUrl, $desc, []);
                        break;
                }

                $validation = sfm_validate_feed($format, $content);
                if (!$validation['ok']) {
                    throw new RuntimeException('Generated feed failed validation.');
                }

                if (@file_put_contents($tmpPath, $content) === false) {
                    throw new RuntimeException('Unable to write temp feed file');
                }
                @chmod($tmpPath, 0664);
                if (!@rename($tmpPath, $feedPath)) {
                    throw new RuntimeException('Unable to replace feed file');
                }

                return [
                    $content,
                    0,
                    $validation,
                    'filters' => $filterStats,
                    'skip'    => [
                        'reason' => 'no_items',
                        'note'   => 'No items detected; generated empty feed.',
                    ],
                ];
            }

            throw new RuntimeException('No items found during refresh');
        }

        if (function_exists('sfm_enrich_items_with_article_metadata')) {
            $items = sfm_enrich_items_with_article_metadata($items, min(6, $limit));
        }

        $title = APP_NAME . ' Feed';
        $desc  = 'Custom feed generated by ' . APP_NAME;

        switch ($format) {
            case 'jsonfeed':
                $content = build_jsonfeed($title, $sourceUrl, $desc, $items, $feedUrl);
                break;
            case 'atom':
                $content = build_atom($title, $sourceUrl, $desc, $items);
                break;
            default:
                $content = build_rss($title, $sourceUrl, $desc, $items);
                break;
        }

        $validation = sfm_validate_feed($format, $content);
        if (!$validation['ok']) {
            $primary = $validation['errors'][0] ?? 'Generated feed failed validation.';
            throw new RuntimeException($primary);
        }

        if (@file_put_contents($tmpPath, $content) === false) {
            throw new RuntimeException('Unable to write temp feed file');
        }
        @chmod($tmpPath, 0664);
        if (!@rename($tmpPath, $feedPath)) {
            throw new RuntimeException('Unable to replace feed file');
        }

        return [$content, count($items), $validation, 'filters' => $filterStats];
    }
}
