#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);

putenv('SFM_MONITOR_DISABLED=1');
putenv('SFM_TEST_FIXTURE_DIR=' . $projectRoot . '/tests/fixtures');
putenv('SFM_TEST_ALLOW_LOCAL_URLS=1');
putenv('SFM_TEST_FIXTURE_HOSTS=www.youtube.com,youtube.com,news.google.com,www.arcamax.com,arcamax.com,www.rense.com,rense.com,www.bing.com,bing.com');
putenv('SFM_APP_NAME=SimpleFeedMaker Test');

require_once $projectRoot . '/includes/job_refresh.php';

ensure_feeds_dir();

try {
    test_youtube_normalization();
    test_google_topics_override();
    test_arcamax_override();
    test_rense_override();
    test_allow_empty_skip();
} catch (Throwable $e) {
    fwrite(STDERR, 'override_test_error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);

function cleanup_feed_file(string $filename): void
{
    $paths = [
        FEEDS_DIR . '/' . $filename,
        FEEDS_DIR . '/' . $filename . '.tmp',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function test_youtube_normalization(): void
{
    $job = [
        'job_id'        => 'youtube-fixture',
        'mode'          => 'native',
        'format'        => 'rss',
        'native_source' => 'https://www.youtube.com/feeds/videos.xml',
    ];

    $result = sfm_refresh_native($job);

    if (($result['body'] ?? '') === '') {
        throw new RuntimeException('youtube fixture returned empty body');
    }

    if (!isset($result['normalized']) || stripos((string)$result['normalized'], 'youtube normalized') === false) {
        throw new RuntimeException('youtube normalization note missing');
    }

    if ($result['format'] !== 'rss') {
        throw new RuntimeException('youtube fixture should normalize to rss');
    }

    if (strpos((string)$result['body'], '<item>') === false) {
        throw new RuntimeException('normalized youtube feed missing RSS items');
    }
}

function test_google_topics_override(): void
{
    $job = [
        'job_id'        => 'google-topics-fixture',
        'mode'          => 'custom',
        'format'        => 'rss',
        'limit'         => 5,
        'feed_filename' => 'test-google-topics.xml',
        'feed_url'      => 'https://example.test/feeds/test-google-topics.xml',
        'source_url'    => 'https://news.google.com/topics/test-topic.xml',
    ];

    $feedPath = FEEDS_DIR . '/' . $job['feed_filename'];
    $tmpPath = $feedPath . '.tmp-fixture';

    cleanup_feed_file($job['feed_filename']);

    $result = sfm_refresh_custom($job, $job['source_url'], $job['feed_url'], $tmpPath, $feedPath);

    [$content, $itemsCount, $validation] = $result;
    $overrideMeta = $result['override'] ?? null;

    if (!is_array($overrideMeta) || ($overrideMeta['key'] ?? '') !== 'google-topics') {
        throw new RuntimeException('google topics override metadata missing');
    }

    if ($itemsCount < 2) {
        throw new RuntimeException('google topics fixture expected at least two items');
    }

    if ($validation['ok'] !== true) {
        throw new RuntimeException('google topics validation failed');
    }

    if (strpos($content, '<item>') === false) {
        throw new RuntimeException('google topics feed missing RSS items');
    }

    cleanup_feed_file($job['feed_filename']);
}

function test_arcamax_override(): void
{
    $job = [
        'job_id'        => 'arcamax-fixture',
        'mode'          => 'custom',
        'format'        => 'rss',
        'limit'         => 5,
        'feed_filename' => 'test-arcamax.xml',
        'feed_url'      => 'https://example.test/feeds/test-arcamax.xml',
        'source_url'    => 'https://www.arcamax.com/thefunnies/calvinandhobbes/',
    ];

    $feedPath = FEEDS_DIR . '/' . $job['feed_filename'];
    $tmpPath = $feedPath . '.tmp-fixture';

    cleanup_feed_file($job['feed_filename']);

    $result = sfm_refresh_custom($job, $job['source_url'], $job['feed_url'], $tmpPath, $feedPath);

    [$content, $itemsCount, $validation] = $result;
    $overrideMeta = $result['override'] ?? null;

    if (!is_array($overrideMeta) || ($overrideMeta['key'] ?? '') !== 'arcamax') {
        throw new RuntimeException('arcamax override metadata missing');
    }

    if ($itemsCount < 2) {
        throw new RuntimeException('arcamax fixture expected at least two items');
    }

    if ($validation['ok'] !== true) {
        throw new RuntimeException('arcamax validation failed');
    }

    if (strpos($content, '<item>') === false) {
        throw new RuntimeException('arcamax feed missing RSS items');
    }

    cleanup_feed_file($job['feed_filename']);
}

function test_rense_override(): void
{
    $job = [
        'job_id'        => 'rense-fixture',
        'mode'          => 'custom',
        'format'        => 'rss',
        'limit'         => 5,
        'feed_filename' => 'test-rense.xml',
        'feed_url'      => 'https://example.test/feeds/test-rense.xml',
        'source_url'    => 'https://rense.com/',
    ];

    $feedPath = FEEDS_DIR . '/' . $job['feed_filename'];
    $tmpPath = $feedPath . '.tmp-fixture';

    cleanup_feed_file($job['feed_filename']);

    $result = sfm_refresh_custom($job, $job['source_url'], $job['feed_url'], $tmpPath, $feedPath);

    [$content, $itemsCount, $validation] = $result;
    $overrideMeta = $result['override'] ?? null;

    if (!is_array($overrideMeta) || ($overrideMeta['key'] ?? '') !== 'rense') {
        throw new RuntimeException('rense override metadata missing');
    }

    if ($itemsCount < 3) {
        throw new RuntimeException('rense fixture expected at least three items');
    }

    if ($validation['ok'] !== true) {
        throw new RuntimeException('rense validation failed');
    }

    if (strpos($content, '<item>') === false) {
        throw new RuntimeException('rense feed missing RSS items');
    }

    cleanup_feed_file($job['feed_filename']);
}

function test_allow_empty_skip(): void
{
    global $projectRoot;

    $job = [
        'job_id'         => 'allow-empty-fixture',
        'mode'           => 'custom',
        'format'         => 'rss',
        'limit'          => 5,
        'feed_filename'  => 'test-allow-empty.xml',
        'feed_url'       => 'https://example.test/feeds/test-allow-empty.xml',
        'source_url'     => 'https://fixtures.simplefeedmaker.test/custom-empty/',
        'allow_empty'    => true,
        'items_count'    => 3,
        'last_validation'=> [
            'warnings'   => ['previous warning'],
            'checked_at' => '2024-01-01T00:00:00Z',
        ],
    ];

    $feedPath = FEEDS_DIR . '/' . $job['feed_filename'];
    $tmpPath  = $feedPath . '.tmp-fixture';

    cleanup_feed_file($job['feed_filename']);

    $seedFeed = (string)@file_get_contents($projectRoot . '/tests/fixtures/feed.xml');
    if ($seedFeed === '') {
        throw new RuntimeException('seed feed fixture missing');
    }

    if (@file_put_contents($feedPath, $seedFeed) === false) {
        throw new RuntimeException('failed to seed existing feed file');
    }

    $result = sfm_refresh_custom($job, $job['source_url'], $job['feed_url'], $tmpPath, $feedPath);

    [$content, $itemsCount, $validation] = $result;
    $skipMeta = $result['skip'] ?? null;

    if (!is_array($skipMeta) || ($skipMeta['reason'] ?? '') !== 'no_items') {
        throw new RuntimeException('allow_empty skip metadata missing');
    }

    if (($skipMeta['auto'] ?? null) !== false) {
        throw new RuntimeException('allow_empty skip should not be marked auto');
    }

    if ($content !== $seedFeed) {
        throw new RuntimeException('allow_empty refresh should keep existing feed content');
    }

    if ($itemsCount !== $job['items_count']) {
        throw new RuntimeException('allow_empty refresh should preserve last item count');
    }

    if (!is_array($validation) || !isset($validation['warnings'])) {
        throw new RuntimeException('allow_empty refresh should return previous validation snapshot');
    }

    $stored = (string)@file_get_contents($feedPath);
    if ($stored !== $seedFeed) {
        throw new RuntimeException('allow_empty refresh should not overwrite feed file');
    }

    cleanup_feed_file($job['feed_filename']);
}

function test_auto_allow_empty_for_bing(): void
{
    global $projectRoot;

    $job = [
        'job_id'         => 'auto-allow-empty-fixture',
        'mode'           => 'custom',
        'format'         => 'rss',
        'limit'          => 30,
        'feed_filename'  => 'test-auto-allow-empty.xml',
        'feed_url'       => 'https://example.test/feeds/test-auto-allow-empty.xml',
        'source_url'     => 'https://www.bing.com/news/search?q=%222025-10-12%22+site%3Aru&qft=interval%3d%224%22&setlang=ru',
        'items_count'    => 5,
        'last_validation'=> [
            'warnings'   => ['previous warning'],
            'checked_at' => '2024-01-01T00:00:00Z',
        ],
    ];

    $feedPath = FEEDS_DIR . '/' . $job['feed_filename'];
    $tmpPath  = $feedPath . '.tmp-fixture';

    cleanup_feed_file($job['feed_filename']);

    $seedFeed = (string)@file_get_contents($projectRoot . '/tests/fixtures/feed.xml');
    if ($seedFeed === '') {
        throw new RuntimeException('seed feed fixture missing');
    }

    if (@file_put_contents($feedPath, $seedFeed) === false) {
        throw new RuntimeException('failed to seed existing feed file (auto)');
    }

    $result = sfm_refresh_custom($job, $job['source_url'], $job['feed_url'], $tmpPath, $feedPath);

    [$content, $itemsCount, $validation] = $result;
    $skipMeta = $result['skip'] ?? null;

    if (!is_array($skipMeta) || ($skipMeta['reason'] ?? '') !== 'no_items' || ($skipMeta['auto'] ?? false) !== true) {
        throw new RuntimeException('auto allow_empty skip metadata missing');
    }

    if (strpos((string)($skipMeta['note'] ?? ''), '(auto)') === false) {
        throw new RuntimeException('auto allow_empty note should mention auto');
    }

    if ($content !== $seedFeed) {
        throw new RuntimeException('auto allow_empty refresh should keep existing feed content');
    }

    if ($itemsCount !== $job['items_count']) {
        throw new RuntimeException('auto allow_empty refresh should preserve last item count');
    }

    if (!is_array($validation) || !isset($validation['warnings'])) {
        throw new RuntimeException('auto allow_empty refresh should return previous validation snapshot');
    }

    cleanup_feed_file($job['feed_filename']);
}
