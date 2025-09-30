<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/jobs.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json; charset=utf-8');

$now = time();
$checks = [];
$ok = true;

$checks[] = run_check('feeds_dir_writable', function () {
    ensure_feeds_dir();
    return ['path' => FEEDS_DIR];
});

$checks[] = run_check('storage_root_writable', function () {
    if (!defined('STORAGE_ROOT')) {
        throw new RuntimeException('STORAGE_ROOT is not defined');
    }
    $dir = STORAGE_ROOT;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        throw new RuntimeException('storage directory missing: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('storage directory not writable: ' . $dir);
    }
    return ['path' => $dir];
});

$checks[] = run_check('jobs_dir_access', function () {
    $dir = sfm_jobs_dir();
    $files = glob($dir . '/*.json') ?: [];
    return [
        'path'  => $dir,
        'count' => count($files),
    ];
});

$checks[] = run_check('cleanup_log_recent', function () use ($now) {
    $logDir = STORAGE_ROOT . '/logs';
    $logPath = $logDir . '/cleanup.log';
    if (!is_file($logPath)) {
        throw new RuntimeException('cleanup.log not found at ' . $logPath);
    }
    $age = $now - (int)filemtime($logPath);
    $maxAge = 36 * 3600; // warn if older than 36 hours
    if ($age > $maxAge) {
        throw new RuntimeException('cleanup.log older than 36h (age seconds: ' . $age . ')');
    }
    return [
        'path' => $logPath,
        'age_seconds' => $age,
    ];
});

$checks[] = run_check('php_error_log', function () {
    $logFile = ini_get('error_log');
    if (!$logFile) {
        return ['configured' => false];
    }
    return [
        'configured' => true,
        'path' => $logFile,
        'exists' => is_file($logFile),
    ];
});

$checks[] = run_check('backups_status', function () use ($now) {
    $dir = resolve_backups_dir();
    if ($dir === null) {
        return warn_result('Backup directory not configured (set SFM_BACKUPS_DIR).', ['configured' => false]);
    }

    $items = glob($dir . '/*');
    $files = [];
    if ($items) {
        foreach ($items as $item) {
            if (is_file($item)) {
                $files[] = $item;
            }
        }
    }

    if (!$files) {
        return warn_result('No backup files found in ' . $dir, ['path' => $dir, 'files' => 0]);
    }

    $latestFile = null;
    $latestTime = 0;
    $totalBytes = 0;
    foreach ($files as $file) {
        $size = filesize($file) ?: 0;
        $mtime = filemtime($file) ?: 0;
        $totalBytes += $size;
        if ($mtime > $latestTime) {
            $latestTime = $mtime;
            $latestFile = $file;
        }
    }

    $ageSeconds = $now - $latestTime;
    $details = [
        'path'           => $dir,
        'files'          => count($files),
        'latest_file'    => $latestFile ? basename($latestFile) : null,
        'latest_mtime'   => $latestTime,
        'latest_age_sec' => $ageSeconds,
        'total_bytes'    => $totalBytes,
        'total_human'    => human_bytes($totalBytes),
    ];

    $maxAge = 7 * 86400;
    if ($ageSeconds > $maxAge) {
        return warn_result('Latest backup older than 7 days.', $details);
    }

    return ['status' => 'ok', 'details' => $details];
});

$checks[] = run_check('disaster_drill_recent', function () use ($now) {
    $statusFile = defined('SFM_DRILL_STATUS_FILE') ? SFM_DRILL_STATUS_FILE : (defined('STORAGE_ROOT') ? STORAGE_ROOT . '/logs/disaster_drill.json' : null);
    if ($statusFile === null) {
        return warn_result('Drill status file path not defined; set SFM_DRILL_STATUS_FILE.', []);
    }
    if (!is_file($statusFile)) {
        return warn_result('Drill status file missing: ' . $statusFile, ['path' => $statusFile]);
    }

    $raw = file_get_contents($statusFile);
    if ($raw === false || $raw === '') {
        return warn_result('Drill status file empty: ' . $statusFile, ['path' => $statusFile]);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['status' => 'fail', 'message' => 'Drill status file invalid JSON.', 'details' => ['path' => $statusFile]];
    }

    $fileMtime = filemtime($statusFile) ?: $now;
    $ageSeconds = $now - $fileMtime;
    $details = [
        'path'         => $statusFile,
        'status'       => $data['status'] ?? 'unknown',
        'generated_at' => $data['generated_at'] ?? null,
        'file_age_sec' => $ageSeconds,
        'failures'     => $data['failures'] ?? null,
        'warnings'     => $data['warnings'] ?? null,
    ];

    if (($data['status'] ?? 'unknown') === 'fail') {
        return ['status' => 'fail', 'message' => 'Last disaster drill recorded failures.', 'details' => $details];
    }

    $maxAge = 120 * 86400;
    if ($ageSeconds > $maxAge) {
        return warn_result('Last disaster drill older than 120 days.', $details);
    }

    return ['status' => 'ok', 'details' => $details];
});

foreach ($checks as $check) {
    if ($check['status'] !== 'ok') {
        $ok = false;
    }
}

$response = [
    'ok'      => $ok,
    'time'    => gmdate('c', $now),
    'version' => [
        'php' => PHP_VERSION,
    ],
    'checks'  => $checks,
];

$status = $ok ? 200 : 503;
http_response_code($status);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

function run_check(string $name, callable $fn): array
{
    try {
        $result = $fn();
        if (is_array($result) && isset($result['status'])) {
            $result['name'] = $name;
            return $result;
        }
        return [
            'name'    => $name,
            'status'  => 'ok',
            'details' => $result,
        ];
    } catch (Throwable $e) {
        return [
            'name'    => $name,
            'status'  => 'fail',
            'error'   => $e->getMessage(),
        ];
    }
}

function warn_result(string $message, array $details = []): array
{
    $result = ['status' => 'warn', 'message' => $message];
    if ($details) {
        $result['details'] = $details;
    }
    return $result;
}

function resolve_backups_dir(): ?string
{
    $candidates = [];
    $env = getenv('SFM_BACKUPS_DIR');
    if ($env !== false && $env !== '') {
        $candidates[] = $env;
    }
    if (defined('SFM_BACKUPS_DIR') && SFM_BACKUPS_DIR !== '') {
        $candidates[] = SFM_BACKUPS_DIR;
    }
    if (defined('SECURE_DIR')) {
        $candidates[] = SECURE_DIR . '/backups';
    }
    if (defined('STORAGE_ROOT')) {
        $candidates[] = STORAGE_ROOT . '/backups';
    }

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }
        $real = realpath($candidate);
        if ($real !== false && is_dir($real)) {
            return rtrim($real, '/\\');
        }
    }

    return null;
}

function human_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return sprintf('%.2f %s', $value, $units[$i]);
}
