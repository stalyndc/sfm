<?php

declare(strict_types=1);

$pageTitle       = 'SimpleFeedMaker Privacy Policy';
$pageDescription = 'Learn what SimpleFeedMaker logs, how cookies are used, and how to reach us with privacy questions.';
$canonical       = 'https://simplefeedmaker.com/privacy/';
$activeNav       = 'privacy';
$metaRobots      = 'index,follow,max-image-preview:large';

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
  <main class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-10 col-xl-8">
          <div class="card shadow-sm mb-4">
            <div class="card-body">
              <h1 class="h3 fw-bold mb-3">Privacy Policy</h1>
              <p class="text-secondary">Updated September 2025</p>
              <p>SimpleFeedMaker is intentionally lightweight—we do not run user accounts or store personal profiles. This policy explains what is collected when you use the generator and how that information is handled.</p>

              <h2 class="h5 fw-semibold mt-4">Information you provide</h2>
              <p>The only input we require is the public URL you want to convert to a feed, an optional item limit, and your format choice. We never request credentials, API keys, or payment details.</p>

              <h2 class="h5 fw-semibold mt-4">Operational logs</h2>
              <p>When you generate or refresh a feed, we record the timestamp, IP address, user agent, and target URL. These logs help us troubleshoot errors, prevent abuse, and understand basic usage patterns. Logs rotate automatically and are stored securely under restricted access.</p>

              <h2 class="h5 fw-semibold mt-4">Cookies &amp; local storage</h2>
              <p>We use a single session cookie to protect against CSRF attacks. It does not contain personal information and expires automatically. No third-party tracking pixels are embedded.</p>

              <h2 class="h5 fw-semibold mt-4">Generated feed storage</h2>
              <p>Feeds that you create live under <code>/feeds</code> on our server. Each feed job includes metadata—source URL, format, refresh cadence—so the cron worker can keep the feed up to date. Unused feeds age out and are deleted after a retention window.</p>

              <h2 class="h5 fw-semibold mt-4">Third-party services</h2>
              <p>We host SimpleFeedMaker on Hostinger and load fonts/Bootstrap from reputable CDNs. Google Analytics is disabled; we rely solely on lightweight server logs.</p>

              <h2 class="h5 fw-semibold mt-4">Questions or removal requests</h2>
              <p>If you need a feed removed or have privacy questions, contact us via <a class="link-light" href="https://disla.net/" target="_blank" rel="noopener">Disla.net</a>. We typically respond within two business days.</p>

              <p class="mb-0">By using SimpleFeedMaker you agree to this policy. We will post updates on this page whenever policies change.</p>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
              <div>
                <h2 class="h5 fw-semibold mb-1">Need a refresher on the tool?</h2>
                <p class="mb-0 text-secondary">Head back to the generator or check the <a class="link-light" href="/faq/">FAQ</a> for quick answers.</p>
              </div>
              <a class="btn btn-outline-primary" href="/">Return to generator</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
