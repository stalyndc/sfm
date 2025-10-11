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

if (!function_exists('sfm_refresh_append_log')) {
    function sfm_refresh_append_log(array $entry): void
    {
        $path = sfm_refresh_log_path();
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
        sfm_refresh_trim_log($path, 500);
    }
}

if (!function_exists('sfm_refresh_trim_log')) {
    function sfm_refresh_trim_log(string $path, int $maxLines): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        $lineCount = count($lines);
        if ($lineCount <= $maxLines) {
            return;
        }
        $lines = array_slice($lines, -$maxLines);
        @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }
}

/**
 * CLI options:
 *   --max=<int>                 Override SFM_REFRESH_MAX_PER_RUN (0 = unlimited)
 *   --quiet                     Suppress console output
 *   --notify                    Email when failures occur
 *   --warn-only                 Only email when failure streak threshold reached
 *   --failure-threshold=<int>   Consecutive failures before alert (default 3)
 *   --no-email                  Disable email entirely
 */
$cliOptions   = cron_refresh_parse_cli_options($argv ?? []);
$quietOutput  = !empty($cliOptions['quiet']);
$notifyEmail  = array_key_exists('notify', $cliOptions);
$warnOnly     = !empty($cliOptions['warn-only']);
$maxOverride  = isset($cliOptions['max']) ? max(0, (int)$cliOptions['max']) : null;
$alertThresh  = isset($cliOptions['failure-threshold']) ? max(1, (int)$cliOptions['failure-threshold']) : 3;
$recipients   = !empty($cliOptions['no-email']) ? [] : cron_refresh_resolve_recipients();

$lock = sfm_jobs_acquire_lock();
if ($lock === false) {
    echo "Another refresh run is in progress.\n";
    exit(0);
}

$now          = time();
$maxPerRun    = $maxOverride !== null ? $maxOverride : (int)SFM_REFRESH_MAX_PER_RUN;
$refreshed    = 0;
$skipped      = 0;
$purged       = 0;
$failures     = 0;
$jobs         = sfm_job_list();

$logEnabled = function_exists('sfm_log_event');
$failedJobs = [];
$alertJobs  = [];

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
        continue;
    }

    $failures += 1;
    $updatedJob = sfm_job_load((string)$job['job_id']);
    if (!$updatedJob) {
        continue;
    }

    $failureStreak = (int)($updatedJob['failure_streak'] ?? 0);
    $lastError     = (string)($updatedJob['last_refresh_error'] ?? '');
    $info = [
        'job_id'         => (string)$updatedJob['job_id'],
        'source_url'     => (string)($updatedJob['source_url'] ?? ''),
        'feed_url'       => (string)($updatedJob['feed_url'] ?? ''),
        'failure_streak' => $failureStreak,
        'last_error'     => $lastError,
    ];
    $failedJobs[] = $info;
    if ($failureStreak >= $alertThresh) {
        $alertJobs[] = $info;
    }
}

sfm_jobs_release_lock($lock);

$summaryLine = sprintf(
    'Jobs: refreshed=%d, failures=%d, purged=%d, skipped=%d',
    $refreshed,
    $failures,
    $purged,
    $skipped
);

$logEntry = [
    'ts'        => gmdate('c'),
    'summary'   => $summaryLine,
    'refreshed' => $refreshed,
    'failures'  => $failures,
    'purged'    => $purged,
    'skipped'   => $skipped,
    'fail_jobs' => $failedJobs,
];
sfm_refresh_append_log($logEntry);

if (!$quietOutput) {
    echo $summaryLine . "\n";
    if ($failedJobs) {
        foreach ($failedJobs as $info) {
            $source = $info['source_url'] !== '' ? $info['source_url'] : '[unknown source]';
            $error  = $info['last_error'] !== '' ? $info['last_error'] : 'Unknown error';
            echo sprintf(
                " - %s (job %s) streak=%d error=%s\n",
                $source,
                $info['job_id'],
                $info['failure_streak'],
                $error
            );
        }
    }
}

$shouldEmail = $notifyEmail && $recipients;
if ($shouldEmail) {
    $pool = $warnOnly ? $alertJobs : $failedJobs;
    if ($pool) {
        $subject = '[SimpleFeedMaker] Feed refresh issues';
        $lines   = [];
        $lines[] = $summaryLine;
        $lines[] = 'Failure threshold: ' . $alertThresh;
        $lines[] = '';
        foreach ($pool as $info) {
            $lines[] = 'Job: ' . $info['job_id'];
            if ($info['source_url'] !== '') {
                $lines[] = 'Source: ' . $info['source_url'];
            }
            if ($info['feed_url'] !== '') {
                $lines[] = 'Feed: ' . $info['feed_url'];
            }
            $lines[] = 'Failure streak: ' . $info['failure_streak'];
            if ($info['last_error'] !== '') {
                $lines[] = 'Last error: ' . $info['last_error'];
            }
            $lines[] = '';
        }
        $body = implode(PHP_EOL, $lines);
        cron_refresh_send_alert($recipients, $subject, $body);
        if (!$quietOutput) {
            echo "Alert email sent to " . implode(', ', $recipients) . "\n";
        }
    } elseif (!$quietOutput && $warnOnly) {
        echo "No jobs crossed failure threshold; no email sent.\n";
    }
}

exit(0);

function cron_refresh_parse_cli_options(array $argv): array
{
    $options = [];
    foreach ($argv as $index => $arg) {
        if ($index === 0) {
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $arg, $m)) {
            $options[strtolower($m[1])] = $m[2];
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)$/i', $arg, $m)) {
            $options[strtolower($m[1])] = true;
        }
    }
    return $options;
}

function cron_refresh_resolve_recipients(): array
{
    $candidates = [];
    $primary = getenv('SFM_REFRESH_ALERT_EMAIL');
    if ($primary !== false && $primary !== '') {
        $candidates[] = $primary;
    }

    $fallback = getenv('SFM_ALERT_EMAIL');
    if ($fallback !== false && $fallback !== '') {
        $candidates[] = $fallback;
    }

    $list = getenv('SFM_ALERT_EMAILS');
    if ($list !== false && $list !== '') {
        $candidates = array_merge($candidates, preg_split('/[;,]+/', $list) ?: []);
    }

    $valid = [];
    foreach ($candidates as $candidate) {
        $email = filter_var(trim((string)$candidate), FILTER_VALIDATE_EMAIL);
        if ($email) {
            $valid[$email] = true;
        }
    }

    return array_keys($valid);
}

function cron_refresh_send_alert(array $recipients, string $subject, string $body): void
{
    if (!$recipients) {
        return;
    }
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    foreach ($recipients as $recipient) {
        @mail($recipient, $subject, $body, $headers);
    }
}
