<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/jobs.php';

sfm_admin_boot();
sfm_admin_require_login();

$pageTitle      = 'Recent Feeds — SimpleFeedMaker Admin';
$metaRobots     = 'noindex, nofollow';
$structuredData = [];

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
<main class="py-4">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 fw-bold mb-1">Recent feeds</h1>
        <div class="text-secondary">Latest feed bundles generated across the platform.</div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/admin/">Feed jobs</a>
        <a class="btn btn-outline-secondary" href="/admin/tools.php">Selector playground</a>
        <a class="btn btn-primary" href="/admin/recent_feeds.php" aria-current="page">Recent feeds</a>
        <a class="btn btn-outline-secondary" href="/admin/?logout=1">Sign out</a>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-xl-8">
        <?= sfm_recent_feeds_card_html(null, 30, [
          'note' => 'Visible only to authenticated admins. Use refresh to pull the latest feed drops.',
          'button_label' => 'Refresh list',
          'refreshing_label' => 'Updating…',
          'empty' => 'No feeds generated yet.',
        ]); ?>
      </div>
    </div>
  </div>
</main>

<?php require __DIR__ . '/../includes/page_footer.php';
