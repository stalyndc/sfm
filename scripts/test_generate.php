<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/http.php';
require_once __DIR__.'/../includes/extract.php';
require_once __DIR__.'/../includes/enrich.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/feed_builder.php';
require_once __DIR__.'/../includes/feed_validator.php';

$url = $argv[1] ?? null;
if (!$url) {fwrite(STDERR,"usage\n"); exit(1);} 
$limit = 5;
$format = 'rss';
$resp = http_get($url, ['accept' => 'text/html']);
$items = sfm_extract_items($resp['body'], $url, $limit);
$items = sfm_enrich_items_with_article_metadata($items, min(6, $limit));
$content = build_rss('Test', $url, 'desc', $items);
file_put_contents('/tmp/test_rss.xml', $content);
print_r(sfm_validate_feed('rss', $content));
