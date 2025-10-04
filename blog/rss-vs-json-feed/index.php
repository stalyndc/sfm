<?php

declare(strict_types=1);

$posts = require __DIR__ . '/../posts.php';
$post = $posts['rss-vs-json-feed'] ?? null;
if ($post === null) {
    http_response_code(404);
    exit('Post not found');
}

require __DIR__ . '/../templates/article.php';
