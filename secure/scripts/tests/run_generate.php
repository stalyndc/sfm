#!/usr/bin/env php
<?php
/**
 * run_generate.php
 *
 * Helper CLI used by smoke tests to exercise generate.php end-to-end.
 * Usage:
 *   php secure/scripts/tests/run_generate.php <url> [limit] [format]
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: {$argv[0]} <url> [limit] [format]\n");
    exit(2);
}

$url    = (string)$argv[1];
$limit  = isset($argv[2]) ? (int)$argv[2] : 5;
$format = isset($argv[3]) ? strtolower((string)$argv[3]) : 'rss';
if ($limit <= 0) $limit = 5;
if (!in_array($format, ['rss', 'atom', 'jsonfeed'], true)) {
    $format = 'rss';
}

// Ensure sessions work under CLI.
$sessionPath = sys_get_temp_dir() . '/sfm_sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0775, true);
}
session_save_path($sessionPath);
session_id('sfmtest' . bin2hex(random_bytes(4)));
$sessionId   = session_id();
$sessionName = session_name();

$csrf = bin2hex(random_bytes(16));
$sessionFile = $sessionPath . '/sess_' . $sessionId;
$sessionPayload = 'csrf_token|s:' . strlen($csrf) . ':"' . $csrf . '";';
file_put_contents($sessionFile, $sessionPayload);

$_COOKIE[$sessionName] = $sessionId;
$_COOKIE['sfm_csrf']   = $csrf;

// Populate superglobals to simulate a browser POST.
$_SERVER = array_merge($_SERVER, [
    'REQUEST_METHOD'    => 'POST',
    'REMOTE_ADDR'       => '127.0.0.1',
    'HTTP_USER_AGENT'   => 'SimpleFeedMaker Test Runner',
    'HTTPS'             => 'on',
    'HTTP_HOST'         => parse_url($url, PHP_URL_HOST) ?: 'localhost',
    'SERVER_PORT'       => '443',
]);

$_POST = [
    'csrf_token' => $csrf,
    'url'        => $url,
    'limit'      => $limit,
    'format'     => $format,
    'prefer_native' => '1',
];

// Keep generated artifacts in repo-friendly locations during tests.
putenv('SFM_APP_NAME=SimpleFeedMaker Test');
putenv('SFM_ALERT_EMAIL=');
putenv('SFM_TEST_ALLOW_LOCAL_URLS=1');

$projectRoot = dirname(__DIR__, 3);
$feedsDir    = $projectRoot . '/feeds';
if (!is_dir($feedsDir)) {
    @mkdir($feedsDir, 0775, true);
}

putenv('SFM_TEST_FIXTURE_DIR=' . $projectRoot . '/tests/fixtures');

require $projectRoot . '/generate.php';
