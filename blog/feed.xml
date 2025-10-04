<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/feed_builder.php';

$posts = require __DIR__ . '/posts.php';
$posts = array_values($posts);

$format = strtolower($_GET['format'] ?? 'rss');
if (!in_array($format, ['rss', 'json'], true)) {
    $format = 'rss';
}

$baseUrl = 'https://simplefeedmaker.com';
$blogBase = $baseUrl . '/blog/';
$feedUrl = $blogBase . 'feed.xml' . ($format === 'json' ? '?format=json' : '');

usort($posts, function (array $a, array $b) {
    return strtotime($b['published']) <=> strtotime($a['published']);
});

$items = [];
foreach ($posts as $post) {
    $items[] = [
        'title'        => $post['title'],
        'link'         => $blogBase . $post['slug'] . '/',
        'description'  => $post['excerpt'],
        'content_html' => $post['content'],
        'date'         => $post['published'],
    ];
}

$title = 'SimpleFeedMaker Blog';
$desc  = 'Guides to RSS, JSON Feed, syndication, and product updates from the SimpleFeedMaker team.';

if ($format === 'json') {
    header('Content-Type: application/feed+json; charset=utf-8');
    echo build_jsonfeed($title, $blogBase, $desc, $items, $feedUrl);
    exit;
}

header('Content-Type: application/rss+xml; charset=utf-8');
echo build_rss($title, $blogBase, $desc, $items);
