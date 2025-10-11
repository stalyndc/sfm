#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);

putenv('SFM_TEST_FIXTURE_DIR=' . $projectRoot . '/tests/fixtures');
putenv('SFM_TEST_ALLOW_LOCAL_URLS=1');
putenv('SFM_APP_NAME=SimpleFeedMaker Test');

require_once $projectRoot . '/includes/job_refresh.php';

$job = [
    'job_id'         => 'test-native-encoding',
    'mode'           => 'native',
    'format'         => 'rss',
    'feed_filename'  => 'test-native-encoding.xml',
    'native_source'  => 'https://fixtures.simplefeedmaker.test/iso_feed.xml',
    'feed_url'       => 'https://example.com/feeds/test-native-encoding.xml',
];

try {
    $result = sfm_refresh_native($job);
} catch (Throwable $e) {
    fwrite(STDERR, 'refresh_error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (($result['body'] ?? '') === '') {
    fwrite(STDERR, "native refresh returned empty body\n");
    exit(1);
}

$body = (string)$result['body'];
if (!preg_match('/encoding="UTF-8"/i', substr($body, 0, 120))) {
    fwrite(STDERR, "missing UTF-8 encoding declaration\n");
    exit(1);
}

if (!str_contains($body, 'Café')) {
    fwrite(STDERR, "expected UTF-8 characters not found\n");
    exit(1);
}

$normalizedNote = (string)($result['normalized'] ?? '');
if ($normalizedNote === '' || stripos($normalizedNote, 'encoding normalized') === false) {
    fwrite(STDERR, "expected encoding normalization note\n");
    exit(1);
}

exit(0);
