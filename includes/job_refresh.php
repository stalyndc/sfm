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
            sfm_job_mark_failure($job, 'Missing feed filename');
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
                    sfm_job_mark_success($job, $bytes, (int)$native['status'], null, $nativeNote, $nativeValidation);
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
                sfm_job_mark_failure($job, 'Missing source URL');
                return false;
            }

            [$content, $itemsCount, $validation] = sfm_refresh_custom($job, $sourceUrl, $feedUrl, $tmpPath, $feedPath);
            $bytes = strlen($content);
            sfm_job_mark_success($job, $bytes, 200, $itemsCount, 'custom refresh', $validation);
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

        $page = http_get($sourceUrl, [
            'use_cache' => false,
            'timeout'   => TIMEOUT_S,
            'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
            throw new RuntimeException('Source fetch failed (HTTP ' . ($page['status'] ?? 0) . ')');
        }

        $items = sfm_extract_items($page['body'], $sourceUrl, $limit);
        if (empty($items)) {
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

        return [$content, count($items), $validation];
    }
}
