<?php

declare(strict_types=1);

$pageTitle       = 'About SimpleFeedMaker';
$pageDescription = 'SimpleFeedMaker is a lightweight feed generator built by Disla.net to turn any URL into a fast, dependable RSS or JSON feed.';
$canonical       = 'https://simplefeedmaker.com/about/';
$activeNav       = 'about';

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
  <main class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-10 col-xl-8">
          <div class="card shadow-sm mb-4">
            <div class="card-body">
              <h1 class="h3 fw-bold mb-3">About SimpleFeedMaker</h1>
              <p class="lead text-secondary mb-3">SimpleFeedMaker exists for one reason: converting any public web page into a clean RSS or JSON feed without hoops, tokens, or code.</p>
              <p class="mb-3">The project is designed and built by <a class="link-light" href="https://disla.net/" target="_blank" rel="noopener">Disla.net</a>, a small studio obsessed with creating dependable tools for publishers and curators. We keep the interface focused, the output valid, and the infrastructure simple enough to run on shared hosting without breaking a sweat.</p>
              <p class="mb-0">Every decision—from dark mode by default to one-click copy buttons—is about saving you time so you can focus on curating the right sources.</p>
            </div>
          </div>

          <div class="card shadow-sm mb-4">
            <div class="card-body">
              <h2 class="h5 fw-semibold mb-3">What guides the product</h2>
              <ul class="mb-0 ps-3 page-list">
                <li><strong>Speed:</strong> A fresh feed in seconds, even on mobile data.</li>
                <li><strong>Clarity:</strong> No logins or clutter—just the fields you actually need.</li>
                <li><strong>Reliability:</strong> Generated feeds refresh automatically in the background so subscribers always get the latest items.</li>
                <li><strong>Respect:</strong> Minimal data collection, transparent privacy, and feeds you control.</li>
              </ul>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
              <div>
                <h2 class="h5 fw-semibold mb-1">Need help or have an idea?</h2>
                <p class="mb-0 text-secondary">Reach out through the <a class="link-light" href="https://disla.net/" target="_blank" rel="noopener">Disla.net</a> site—feedback keeps SimpleFeedMaker moving forward.</p>
              </div>
              <a class="btn btn-outline-primary" href="/">Back to generator</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
