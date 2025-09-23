<?php
/**
 * Admin credentials for SimpleFeedMaker
 * KEEP THIS FILE SECURE AND OUTSIDE WEB ROOT
 */

declare(strict_types=1);

$adminUsername = getenv('SFM_ADMIN_USERNAME');
$adminPassword = getenv('SFM_ADMIN_PASSWORD');

$localFile = __DIR__ . '/admin-credentials.local.php';
if ((!is_string($adminUsername) || $adminUsername === '' || !is_string($adminPassword) || $adminPassword === '') && is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $adminUsername = $local['username'] ?? $adminUsername;
        $adminPassword = $local['password'] ?? $adminPassword;
    }
}

if (!is_string($adminUsername) || $adminUsername === '' || !is_string($adminPassword) || $adminPassword === '') {
    throw new RuntimeException('Admin credentials are not configured. Set SFM_ADMIN_USERNAME/SFM_ADMIN_PASSWORD or create secure/admin-credentials.local.php.');
}

define('ADMIN_USERNAME', $adminUsername);
define('ADMIN_PASSWORD', $adminPassword);
