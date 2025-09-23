<?php
// Database credentials - isolated from public access
declare(strict_types=1);

$dbHost = getenv('SFM_DB_HOST') ?: 'localhost';
$dbUser = getenv('SFM_DB_USERNAME');
$dbPass = getenv('SFM_DB_PASSWORD');
$dbName = getenv('SFM_DB_NAME');

$localFile = __DIR__ . '/db-credentials.local.php';
if ((! is_string($dbUser) || $dbUser === '' ||
    !is_string($dbPass) || $dbPass === '' ||
    !is_string($dbName) || $dbName === '') && is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $dbHost = $local['host'] ?? $dbHost;
        $dbUser = $local['u261092072_stalyn2025'] ?? $dbUser;
        $dbPass = $local['BrutoyPendej@2025yMasoMenos'] ?? $dbPass;
        $dbName = $local['u261092072_sfmnew'] ?? $dbName;
    }
}

if (!is_string($dbUser) || $dbUser === '' || !is_string($dbPass) || $dbPass === '' || !is_string($dbName) || $dbName === '') {
    throw new RuntimeException('Database credentials are not configured. Set SFM_DB_* env vars or create secure/db-credentials.local.php.');
}

define('DB_HOST', $dbHost);
define('DB_USERNAME', $dbUser);
define('DB_PASSWORD', $dbPass);
define('DB_NAME', $dbName);

// No closing PHP tag to prevent accidental output
