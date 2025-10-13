#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/jobs.php';

$webhook = getenv('SFM_SLACK_WEBHOOK');
if ($webhook === false || trim($webhook) === '') {
    fwrite(STDOUT, "[daily-digest] SFM_SLACK_WEBHOOK not configured, skipping.\n");
    exit(0);
}
$webhook = trim($webhook);

$now = time();
$jobs = sfm_job_list();
$totalJobs = count($jobs);

$failing = [];
$streaks = [];
$recentFailures = [];
$threshold = 3;
foreach ($jobs as $job) {
    $status = strtolower((string)($job['last_refresh_status'] ?? ''));
    if ($status !== 'ok') {
        $failing[] = $job;
        $recentFailures[] = $job;
    }

    $streak = (int)($job['failure_streak'] ?? 0);
    if ($streak > 0) {
        $streaks[] = [
            'job_id' => (string)($job['job_id'] ?? ''),
            'streak' => $streak,
            'source' => (string)($job['source_url'] ?? ''),
            'error'  => (string)($job['last_refresh_error'] ?? ''),
        ];
    }
}

usort($streaks, static function ($a, $b): int {
    return $b['streak'] <=> $a['streak'];
});

$topStreaks = array_slice($streaks, 0, 5);
$failingCount = count($failing);

$purgedCount = digest_count_recent_purges($now);
$skippedCount = digest_sum_recent_metric($now, 'skipped');

$lines = [];
$lines[] = '*SimpleFeedMaker Daily Digest*';
$lines[] = '`' . gmdate('Y-m-d H:i:s', $now) . ' UTC`';
$lines[] = sprintf('Jobs: %d total · %d failing · %d purged · %d skipped (last 24h)', $totalJobs, $failingCount, $purgedCount, $skippedCount);

if ($topStreaks) {
    $lines[] = '';
    $lines[] = '*Top failure streaks*';
    foreach ($topStreaks as $entry) {
        $label = $entry['job_id'] !== '' ? $entry['job_id'] : '(unknown)';
        $lines[] = sprintf('• Job %s → streak %d%s',
            $label,
            $entry['streak'],
            $entry['error'] !== '' ? ' — ' . digest_trim($entry['error']) : ''
        );
    }
}

$recentFailures = array_slice($recentFailures, 0, 5);
if ($recentFailures) {
    $lines[] = '';
    $lines[] = '*Recent failures*';
    foreach ($recentFailures as $job) {
        $label = (string)($job['job_id'] ?? '(unknown)');
        $source = (string)($job['source_url'] ?? '');
        $error  = (string)($job['last_refresh_error'] ?? '');
        $lines[] = sprintf('• Job %s%s', $label, $source !== '' ? ' — ' . digest_trim($source, 90) : '');
        if ($error !== '') {
            $lines[] = '   Error: ' . digest_trim($error);
        }
    }
}

if (empty($topStreaks) && empty($recentFailures)) {
    $lines[] = '';
    $lines[] = 'All jobs healthy ✅';
}

$lines[] = '';
$lines[] = 'Admin: https://simplefeedmaker.com/admin/';

$payload = json_encode(['text' => implode("\n", $lines)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($payload === false) {
    fwrite(STDERR, "[daily-digest] Failed to encode payload.\n");
    exit(1);
}

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 5,
    ],
]);

$result = @file_get_contents($webhook, false, $context);
if ($result === false) {
    fwrite(STDERR, "[daily-digest] Slack webhook request failed.\n");
    exit(1);
}

fwrite(STDOUT, "[daily-digest] Slack notification sent.\n");
exit(0);

function digest_count_recent_purges(int $now): int
{
    return digest_sum_recent_metric($now, 'purged');
}

function digest_sum_recent_metric(int $now, string $metric): int
{
    $logPath = storage_root_path() . '/logs/cron_refresh.log';
    if (!is_file($logPath) || !is_readable($logPath)) {
        return 0;
    }

    $cut = $now - 86400;
    $fh = @fopen($logPath, 'r');
    if (!$fh) {
        return 0;
    }

    $total = 0;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            continue;
        }
        $ts = isset($data['ts']) ? strtotime((string)$data['ts']) : false;
        if ($ts === false || $ts < $cut) {
            continue;
        }
        $value = isset($data[$metric]) ? (int)$data[$metric] : null;
        if ($value !== null) {
            $total += $value;
        }
    }
    fclose($fh);

    return $total;
}

function digest_trim(string $value, int $limit = 120): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value) > $limit) {
            return mb_substr($value, 0, $limit - 1) . '…';
        }
        return $value;
    }
    if (strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }
    return $value;
}

function storage_root_path(): string
{
    return defined('STORAGE_ROOT') ? STORAGE_ROOT : dirname(__DIR__, 2) . '/storage';
}
