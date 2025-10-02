<?php

declare(strict_types=1);

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

$posts = [
  [
    'slug'        => 'why-rss-still-matters',
    'title'       => 'Why RSS Still Matters in 2025',
    'description' => 'RSS hasn\'t gone away—it powers newsletters, curated digests, personal dashboards, and knowledge workflows. Here\'s why the format is still essential for audiences and publishers alike.',
    'date'        => 'October 1, 2025',
    'readingTime' => '8 minute read',
  ],
  [
    'slug'        => 'turn-any-website-into-a-feed',
    'title'       => 'How to Turn Any Website into a Feed with SimpleFeedMaker',
    'description' => 'A step-by-step walkthrough showing how to generate a reliable feed from any public page, tune the output, and put the feed to work in your favorite reader.',
    'date'        => 'October 1, 2025',
    'readingTime' => '7 minute read',
  ],
  [
    'slug'        => 'rss-vs-json-feed',
    'title'       => 'RSS vs. JSON Feed: Which Format Should You Use?',
    'description' => 'Understand the differences between RSS 2.0 and JSON Feed, when it makes sense to deliver both, and how SimpleFeedMaker keeps your subscribers happy in every reader.',
    'date'        => 'October 1, 2025',
    'readingTime' => '6 minute read',
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

          <div class="vstack gap-4">
            <?php foreach ($posts as $post) : ?>
              <article class="card shadow-sm">
                <div class="card-body">
                  <p class="text-secondary small mb-1">Published <?= htmlspecialchars($post['date']); ?> · <?= htmlspecialchars($post['readingTime']); ?></p>
                  <h2 class="h4 fw-semibold mb-2">
                    <a class="text-decoration-none" href="/blog/<?= htmlspecialchars($post['slug']); ?>/">
                      <?= htmlspecialchars($post['title']); ?>
                    </a>
                  </h2>
                  <p class="text-secondary mb-3"><?= htmlspecialchars($post['description']); ?></p>
                  <a class="btn btn-outline-primary btn-sm" href="/blog/<?= htmlspecialchars($post['slug']); ?>/">Read article</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
