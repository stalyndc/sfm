<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const SFM_MONITOR_STATE_VERSION = 1;

function sfm_monitor_enabled(): bool
{
    $disabled = getenv('SFM_MONITOR_DISABLED');
    if ($disabled !== false) {
        $disabled = strtolower(trim($disabled));
        if (in_array($disabled, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }
    }

    return true;
}

function sfm_monitor_dir(): string
{
    $base = defined('STORAGE_ROOT') ? STORAGE_ROOT : (dirname(__DIR__) . '/storage');
    return rtrim($base, "\\/") . '/monitoring';
}

function sfm_monitor_state_path(): string
{
    return sfm_monitor_dir() . '/refresh_monitor.json';
}

/**
 * @return array{version:int,jobs:array<string,array<string,mixed>>}
 */
function sfm_monitor_load_state(): array
{
    $path = sfm_monitor_state_path();
    if (!is_file($path)) {
        return ['version' => SFM_MONITOR_STATE_VERSION, 'jobs' => []];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['version' => SFM_MONITOR_STATE_VERSION, 'jobs' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['version' => SFM_MONITOR_STATE_VERSION, 'jobs' => []];
    }

    $jobs = [];
    if (isset($decoded['jobs']) && is_array($decoded['jobs'])) {
        foreach ($decoded['jobs'] as $jobId => $info) {
            if (!is_string($jobId)) {
                continue;
            }
            if (!is_array($info)) {
                continue;
            }
            $jobs[$jobId] = $info;
        }
    }

    return [
        'version' => SFM_MONITOR_STATE_VERSION,
        'jobs'    => $jobs,
    ];
}

/**
 * @param array{version:int,jobs:array<string,array<string,mixed>>} $state
 */
function sfm_monitor_save_state(array $state): void
{
    $dir = sfm_monitor_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $state['version'] = SFM_MONITOR_STATE_VERSION;
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    $path = sfm_monitor_state_path();
    $tmp  = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return;
    }
    @chmod($tmp, 0664);
    @rename($tmp, $path);
}

/**
 * @return array{version:int,jobs:array<string,array<string,mixed>>}
 */
function &sfm_monitor_state_ref(): array
{
    static $state = null;
    if ($state === null) {
        $state = sfm_monitor_load_state();
    }
    return $state;
}

function sfm_monitor_dirty_flag(?bool $set = null): bool
{
    static $flag = false;
    if ($set !== null) {
        $flag = $set;
    }
    return $flag;
}

function sfm_monitor_mark_dirty(bool $dirty = true): void
{
    if ($dirty) {
        sfm_monitor_dirty_flag(true);
    } else {
        sfm_monitor_dirty_flag(false);
    }
}

function sfm_monitor_is_dirty(): bool
{
    return sfm_monitor_dirty_flag();
}

/**
 * @return array<int,array<string,mixed>>
 */
function &sfm_monitor_alerts_ref(): array
{
    static $alerts = [];
    return $alerts;
}

function sfm_monitor_now_iso(): string
{
    return gmdate('c');
}

function sfm_monitor_failure_min_streak(): int
{
    $env = getenv('SFM_MONITOR_FAILURE_MIN_STREAK');
    if ($env !== false && $env !== '' && is_numeric($env)) {
        $value = (int)$env;
    } else {
        $value = 1;
    }
    return max(1, $value);
}

function sfm_monitor_override_threshold(): int
{
    $env = getenv('SFM_MONITOR_OVERRIDE_THRESHOLD');
    if ($env !== false && $env !== '' && is_numeric($env)) {
        $value = (int)$env;
    } else {
        $value = 3;
    }
    return max(1, $value);
}

function sfm_monitor_override_window_seconds(): int
{
    $env = getenv('SFM_MONITOR_OVERRIDE_WINDOW');
    if ($env !== false && $env !== '' && is_numeric($env)) {
        $value = (int)$env;
    } else {
        $value = 86400;
    }
    return max(300, $value);
}

function sfm_monitor_on_success(array $job, array $context = []): void
{
    if (!sfm_monitor_enabled()) {
        return;
    }

    $jobId = (string)($job['job_id'] ?? '');
    if ($jobId === '') {
        return;
    }

    $state =& sfm_monitor_state_ref();
    $jobs  =& $state['jobs'];
    $current = $jobs[$jobId] ?? [];

    if (($current['failure_streak'] ?? 0) !== 0) {
        $current['failure_streak'] = 0;
        $current['last_failure_at'] = null;
        $current['last_alerted_streak'] = 0;
    }

    $current['last_success_at'] = sfm_monitor_now_iso();

    $skipContext = $context['skip'] ?? null;
    if (is_array($skipContext) && !empty($skipContext['auto'])) {
        $current['auto_skip_streak'] = (int)($current['auto_skip_streak'] ?? 0) + 1;
        $current['last_auto_skip_at'] = sfm_monitor_now_iso();
    } else {
        $current['auto_skip_streak'] = 0;
    }

    $jobs[$jobId] = $current;
    sfm_monitor_mark_dirty();
}

function sfm_monitor_on_failure(array $job): void
{
    if (!sfm_monitor_enabled()) {
        return;
    }

    $jobId = (string)($job['job_id'] ?? '');
    if ($jobId === '') {
        return;
    }

    $streak = (int)($job['failure_streak'] ?? 0);

    $state =& sfm_monitor_state_ref();
    $jobs  =& $state['jobs'];
    $current = $jobs[$jobId] ?? [];
    $previousStreak = (int)($current['failure_streak'] ?? 0);
    $lastAlerted = (int)($current['last_alerted_streak'] ?? 0);

    $current['failure_streak'] = $streak;
    $current['last_failure_at'] = sfm_monitor_now_iso();

    $alertMin = sfm_monitor_failure_min_streak();
    $shouldAlert = false;
    if ($streak > 0 && $streak > $lastAlerted && $streak >= $alertMin) {
        $shouldAlert = true;
        $current['last_alerted_streak'] = $streak;
    }

    $jobs[$jobId] = $current;
    sfm_monitor_mark_dirty();

    if ($shouldAlert) {
        $alerts =& sfm_monitor_alerts_ref();
        $threshold = function_exists('sfm_refresh_failure_threshold')
            ? sfm_refresh_failure_threshold()
            : 3;
        $alerts[] = [
            'type'        => 'failure_streak',
            'job_id'      => $jobId,
            'streak'      => $streak,
            'threshold'   => $threshold,
            'source_url'  => (string)($job['source_url'] ?? ''),
            'feed_url'    => (string)($job['feed_url'] ?? ''),
            'error'       => (string)($job['last_refresh_error'] ?? ''),
            'mode'        => (string)($job['mode'] ?? ''),
        ];
    }
}

function sfm_monitor_on_override(array $job, array $override): void
{
    if (!sfm_monitor_enabled()) {
        return;
    }

    $jobId = (string)($job['job_id'] ?? '');
    $key   = (string)($override['key'] ?? '');
    if ($jobId === '' || $key === '') {
        return;
    }

    $state =& sfm_monitor_state_ref();
    $jobs  =& $state['jobs'];
    $current = $jobs[$jobId] ?? [];
    $overrides = isset($current['overrides']) && is_array($current['overrides']) ? $current['overrides'] : [];

    $entry = $overrides[$key] ?? [
        'timestamps'        => [],
        'last_alert_count'  => 0,
        'last_alert_at'     => null,
    ];

    $now      = time();
    $window   = sfm_monitor_override_window_seconds();
    $cutoff   = $now - $window;
    $threshold = sfm_monitor_override_threshold();

    $timestamps = array_values(array_filter($entry['timestamps'] ?? [], static function ($ts) use ($cutoff) {
        return (int)$ts >= $cutoff;
    }));
    $timestamps[] = $now;
    $entry['timestamps'] = $timestamps;

    $count = count($timestamps);
    $shouldAlert = false;
    if ($count >= $threshold) {
        $lastAlertCount = (int)($entry['last_alert_count'] ?? 0);
        $lastAlertAt = isset($entry['last_alert_at']) ? strtotime((string)$entry['last_alert_at']) : 0;
        if ($count !== $lastAlertCount || $lastAlertAt < $cutoff) {
            $shouldAlert = true;
            $entry['last_alert_count'] = $count;
            $entry['last_alert_at'] = sfm_monitor_now_iso();
        }
    }

    $overrides[$key] = $entry;
    $current['overrides'] = $overrides;
    $jobs[$jobId] = $current;
    sfm_monitor_mark_dirty();

    if ($shouldAlert) {
        $alerts =& sfm_monitor_alerts_ref();
        $alerts[] = [
            'type'           => 'override_usage',
            'job_id'         => $jobId,
            'override_key'   => $key,
            'override_label' => (string)($override['label'] ?? $key),
            'count'          => $count,
            'window_seconds' => $window,
            'source_url'     => (string)($job['source_url'] ?? ''),
            'feed_url'       => (string)($job['feed_url'] ?? ''),
            'source'         => (string)($override['source'] ?? ''),
        ];
    }
}

/**
 * @param array<int,string> $activeJobIds
 * @return array<int,array<string,mixed>>
 */
function sfm_monitor_finalize(array $activeJobIds): array
{
    if (!sfm_monitor_enabled()) {
        return [];
    }

    $activeMap = [];
    foreach ($activeJobIds as $id) {
        $id = (string)$id;
        if ($id !== '') {
            $activeMap[$id] = true;
        }
    }

    $state =& sfm_monitor_state_ref();
    $jobs  =& $state['jobs'];
    $changed = false;
    foreach ($jobs as $jobId => $_info) {
        if ($activeMap && !isset($activeMap[$jobId])) {
            unset($jobs[$jobId]);
            $changed = true;
        }
    }
    if ($changed) {
        $state['jobs'] = $jobs;
        sfm_monitor_mark_dirty();
    }

    if (sfm_monitor_is_dirty()) {
        sfm_monitor_save_state($state);
        sfm_monitor_mark_dirty(false);
    }

    $alerts =& sfm_monitor_alerts_ref();
    $out = $alerts;
    $alerts = [];
    return $out;
}
