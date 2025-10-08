<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/http.php';
require_once __DIR__.'/../includes/extract.php';
require_once __DIR__.'/../includes/enrich.php';
require_once __DIR__.'/../includes/feed_builder.php';

$url = 'https://www.cnet.com/tech/computing/laptops/';
$resp = http_get($url, ['accept' => 'text/html'] );
$items = sfm_extract_items($resp['body'], $url, 10);
$items = sfm_enrich_items_with_article_metadata($items, 1);
var_dump($items[0]);
