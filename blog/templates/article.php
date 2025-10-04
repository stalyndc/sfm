<?php

$pageTitle       = $post['title'];
$pageDescription = $post['description'];
$canonical       = 'https://simplefeedmaker.com/blog/' . $post['slug'] . '/';
$activeNav       = 'blog';
$ogType          = 'article';
$articlePublishedTime = $post['published'];
$articleModifiedTime  = $post['updated'];
$structuredData  = [
    [
        '@context'        => 'https://schema.org',
        '@type'           => 'BlogPosting',
        'headline'        => $post['title'],
        'description'     => $post['description'],
        'datePublished'   => $post['published'],
        'dateModified'    => $post['updated'],
        'url'             => $canonical,
        'mainEntityOfPage'=> $canonical,
        'inLanguage'      => 'en',
        'author'          => [
            '@type' => 'Person',
            'name'  => $post['author'] ?? 'SimpleFeedMaker Team',
        ],
        'publisher'       => [
            '@type' => 'Organization',
            'name'  => 'SimpleFeedMaker',
            'url'   => 'https://simplefeedmaker.com/',
        ],
    ],
];

require __DIR__ . '/../../includes/page_head.php';
require __DIR__ . '/../../includes/page_header.php';
?>
  <main class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-9 col-xl-8">
          <article class="card shadow-sm mb-4">
            <div class="card-body">
              <p class="text-secondary small mb-1">Published <?= htmlspecialchars(date('F j, Y', strtotime($post['published'])), ENT_QUOTES, 'UTF-8'); ?><?= isset($post['reading_time']) ? ' · ' . htmlspecialchars($post['reading_time'], ENT_QUOTES, 'UTF-8') : ''; ?></p>
              <h1 class="h3 fw-bold mb-3"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
              <?= $post['content']; ?>
            </div>
          </article>
          <div class="text-center text-md-start">
            <a class="btn btn-outline-primary" href="/blog/">← Back to blog</a>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../../includes/page_footer.php';
