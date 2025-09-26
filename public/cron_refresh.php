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
require_once __DIR__ . '/includes/job_refresh.php';

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
