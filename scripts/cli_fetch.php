<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/http.php';
require_once __DIR__.'/../includes/extract.php';

$url = $argv[1] ?? null;
if (!$url) {
    fwrite(STDERR, "Usage: php cli_fetch.php URL\n");
    exit(1);
}

$resp = http_get($url, ['accept' => 'text/html']);
if (!$resp['ok']) {
    fwrite(STDERR, "Fetch failed\n");
    exit(1);
}

$items = sfm_extract_items($resp['body'], $url, 10);
print_r($items[0]);
