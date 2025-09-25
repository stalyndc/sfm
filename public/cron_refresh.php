<?php
/**
 * cron_refresh.php — CLI cron entrypoint to refresh generated feeds.
 *
 * Usage (Hostinger cron):
 *   /usr/bin/php /home/USER/public_html/cron_refresh.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/http.php';
require_once __DIR__ . '/includes/extract.php';
require_once __DIR__ . '/includes/jobs.php';

$lock = sfm_jobs_acquire_lock();
if ($lock === false) {
    echo "Another refresh run is in progress.\n";
    exit(0);
}

$now          = time();
$maxPerRun    = (int)SFM_REFRESH_MAX_PER_RUN;
$refreshed    = 0;
$skipped      = 0;
$purged       = 0;
$failures     = 0;
$jobs         = sfm_job_list();

$logEnabled = function_exists('sfm_log_event');

foreach ($jobs as $job) {
    if ($maxPerRun > 0 && $refreshed >= $maxPerRun) {
        $skipped += 1;
        continue;
    }

    if (sfm_job_should_purge($job, $now)) {
        $feedFile = $job['feed_filename'] ?? '';
        if ($feedFile) {
            $path = FEEDS_DIR . '/' . $feedFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        sfm_job_delete($job['job_id']);
        $purged += 1;
        if ($logEnabled) {
            sfm_log_event('refresh', [
                'phase'  => 'purge',
                'job_id' => $job['job_id'],
                'reason' => 'retention',
            ]);
        }
        continue;
    }

    if (!sfm_job_is_due($job, $now)) {
        continue;
    }

    $result = sfm_refresh_job($job, $logEnabled);
    if ($result === true) {
        $refreshed += 1;
    } else {
        $failures += 1;
    }
}

sfm_jobs_release_lock($lock);

echo sprintf(
    "Jobs: refreshed=%d, failures=%d, purged=%d, skipped=%d\n",
    $refreshed,
    $failures,
    $purged,
    $skipped
);
exit(0);

/** Refresh a single job. Returns true on success, false on failure. */
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
        if ($mode === 'native' && !empty($job['native_source'])) {
            [$body, $status] = sfm_refresh_native($job, $tmpPath, $feedPath);
            $bytes = strlen($body);
            sfm_job_mark_success($job, $bytes, $status, null, 'native refresh');
            if ($logEnabled) {
                sfm_log_event('refresh', [
                    'phase'  => 'native',
                    'job_id' => $job['job_id'],
                    'bytes'  => $bytes,
                    'source' => $job['native_source'],
                ]);
            }
            return true;
        }

        if (!$sourceUrl) {
            sfm_job_mark_failure($job, 'Missing source URL');
            return false;
        }

        [$content, $itemsCount] = sfm_refresh_custom($job, $sourceUrl, $feedUrl, $tmpPath, $feedPath);
        $bytes = strlen($content);
        sfm_job_mark_success($job, $bytes, 200, $itemsCount, 'custom refresh');
        if ($logEnabled) {
            sfm_log_event('refresh', [
                'phase'  => 'custom',
                'job_id' => $job['job_id'],
                'bytes'  => $bytes,
                'items'  => $itemsCount,
                'source' => $sourceUrl,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        sfm_job_mark_failure($job, $e->getMessage());
        if ($logEnabled) {
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

function sfm_refresh_native(array $job, string $tmpPath, string $feedPath): array
{
    $nativeUrl = $job['native_source'];
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

    if (@file_put_contents($tmpPath, $resp['body']) === false) {
        throw new RuntimeException('Unable to write temp feed file');
    }
    @chmod($tmpPath, 0664);
    if (!@rename($tmpPath, $feedPath)) {
        throw new RuntimeException('Unable to replace feed file');
    }

    return [$resp['body'], (int)($resp['status'] ?? 200)];
}

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

    if (@file_put_contents($tmpPath, $content) === false) {
        throw new RuntimeException('Unable to write temp feed file');
    }
    @chmod($tmpPath, 0664);
    if (!@rename($tmpPath, $feedPath)) {
        throw new RuntimeException('Unable to replace feed file');
    }

    return [$content, count($items)];
}
