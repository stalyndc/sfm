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
        $details = $fn();
        return [
            'name'    => $name,
            'status'  => 'ok',
            'details' => $details,
        ];
    } catch (Throwable $e) {
        return [
            'name'    => $name,
            'status'  => 'fail',
            'error'   => $e->getMessage(),
        ];
    }
}
