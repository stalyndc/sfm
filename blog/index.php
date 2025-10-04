<?php

declare(strict_types=1);

$posts = require __DIR__ . '/posts.php';
$posts = array_values($posts);
usort($posts, function (array $a, array $b) {
    return strtotime($b['published']) <=> strtotime($a['published']);
});

$perPage = 10;
$totalPosts = count($posts);
$totalPages = max(1, (int)ceil($totalPosts / $perPage));
$currentPage = (int)($_GET['page'] ?? 1);
if ($currentPage < 1) {
    $currentPage = 1;
}
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;
$visiblePosts = array_slice($posts, $offset, $perPage);

$pageTitleBase = 'SimpleFeedMaker Blog — RSS Guides & Product Tips';
$pageDescription = 'Long-form guides on RSS, JSON Feed, and using SimpleFeedMaker to syndicate the web. Learn practical tactics for curating content your audience loves.';
$canonical = 'https://simplefeedmaker.com/blog/';
if ($currentPage > 1) {
    $pageTitle = $pageTitleBase . ' (Page ' . $currentPage . ')';
    $canonical = $canonical . '?page=' . $currentPage;
} else {
    $pageTitle = $pageTitleBase;
}
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
            <?php if (empty($visiblePosts)): ?>
              <div class="card shadow-sm">
                <div class="card-body">
                  <p class="mb-0 text-secondary">No posts yet—check back soon.</p>
                </div>
              </div>
            <?php endif; ?>
            <?php foreach ($visiblePosts as $post): ?>
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

          <nav class="mt-4" aria-label="Blog pagination">
            <ul class="pagination justify-content-center justify-content-md-start mb-0">
              <?php
              $buildPageUrl = static function (int $page) {
                  return $page === 1 ? '/blog/' : '/blog/?page=' . $page;
              };

              for ($page = 1; $page <= $totalPages; $page++) {
                  $isActive = $page === $currentPage;
                  $classes = 'page-item' . ($isActive ? ' active' : '');
                  $aria = $isActive ? ' aria-current="page"' : '';
                  echo '<li class="' . $classes . '"><a class="page-link" href="' . $buildPageUrl($page) . '"' . $aria . '>Page ' . $page . '</a></li>';
              }
              ?>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
