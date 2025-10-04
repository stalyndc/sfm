<?php

declare(strict_types=1);

$posts = require __DIR__ . '/posts.php';
$posts = array_values($posts);
usort($posts, function (array $a, array $b) {
    return strtotime($b['published']) <=> strtotime($a['published']);
});

$pageTitle       = 'SimpleFeedMaker Blog — RSS Guides & Product Tips';
$pageDescription = 'Long-form guides on RSS, JSON Feed, and using SimpleFeedMaker to syndicate the web. Learn practical tactics for curating content your audience loves.';
$canonical       = 'https://simplefeedmaker.com/blog/';
$activeNav       = 'blog';
$structuredData  = [
    [
        '@context'    => 'https://schema.org',
        '@type'       => 'Blog',
        'name'        => 'SimpleFeedMaker Blog',
        'description' => $pageDescription,
        'url'         => $canonical,
        'inLanguage'  => 'en',
        'publisher'   => [
            '@type' => 'Organization',
            'name'  => 'SimpleFeedMaker',
            'url'   => 'https://simplefeedmaker.com/',
        ],
    ],
];

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
  <main class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-10 col-xl-9">
          <header class="mb-4 text-center text-md-start">
            <p class="text-uppercase small text-secondary mb-1">SimpleFeedMaker Blog</p>
            <h1 class="h3 fw-bold mb-2">Guides to syndicating the web</h1>
            <p class="text-secondary mb-0">We publish actionable articles about RSS, JSON Feed, and audience growth so you can deliver fresh content everywhere your readers hang out.</p>
          </header>

          <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-2 mb-4">
            <a class="btn btn-outline-primary btn-sm" href="/blog/feed.xml">Subscribe via RSS</a>
            <a class="btn btn-outline-secondary btn-sm" href="/blog/feed.xml?format=json">JSON Feed</a>
          </div>

          <div class="vstack gap-4">
            <?php foreach ($posts as $post): ?>
              <article class="card shadow-sm">
                <div class="card-body">
                  <p class="text-secondary small mb-1">
                    Published <?= htmlspecialchars(date('F j, Y', strtotime($post['published'])), ENT_QUOTES, 'UTF-8'); ?>
                    <?= isset($post['reading_time']) ? ' · ' . htmlspecialchars($post['reading_time'], ENT_QUOTES, 'UTF-8') : ''; ?>
                  </p>
                  <h2 class="h4 fw-semibold mb-2">
                    <a class="text-decoration-none" href="/blog/<?= htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?>/">
                      <?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  </h2>
                  <p class="text-secondary mb-3"><?= htmlspecialchars($post['excerpt'], ENT_QUOTES, 'UTF-8'); ?></p>
                  <a class="btn btn-outline-primary btn-sm" href="/blog/<?= htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?>/">Read article</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
