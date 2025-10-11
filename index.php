<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

$pageTitle       = 'SimpleFeedMaker — Create RSS or JSON feeds from any URL';
$pageDescription = 'SimpleFeedMaker turns any web page into a feed. Paste a URL, choose RSS or JSON Feed, and get a clean, valid feed in seconds.';
$structuredData  = [
  [
    '@context'       => 'https://schema.org',
    '@type'          => 'WebSite',
    'url'            => 'https://simplefeedmaker.com/',
    'name'           => 'SimpleFeedMaker',
    'description'    => $pageDescription,
    'inLanguage'     => 'en',
    'publisher'      => [
      '@type' => 'Organization',
      'name'  => 'SimpleFeedMaker',
      'url'   => 'https://simplefeedmaker.com/',
    ],
  ],
  [
    '@context'          => 'https://schema.org',
    '@type'             => 'WebApplication',
    'name'              => 'SimpleFeedMaker',
    'url'               => 'https://simplefeedmaker.com/',
    'applicationCategory' => 'UtilitiesApplication',
    'operatingSystem'   => 'Any',
    'creator'           => [
      '@type' => 'Organization',
      'name'  => 'Disla.net',
      'url'   => 'https://disla.net/',
    ],
    'offers'            => [
      '@type'         => 'Offer',
      'price'         => '0',
      'priceCurrency' => 'USD',
      'availability'  => 'https://schema.org/InStock',
    ],
  ],
];

require __DIR__ . '/includes/page_head.php';
require __DIR__ . '/includes/page_header.php';
?>

  <!-- Main -->
  <main class="py-4 py-md-5 hero-section">
    <div class="container position-relative">
      <div class="row g-4 g-xl-5 align-items-stretch hero-row">
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm h-100 hero-card">
            <div class="card-body">
              <h1 class="display-6 fw-semibold mb-2">Turn any page into a feed</h1>
              <p class="muted mb-4">Paste a URL, choose RSS or JSON, and click Generate. That’s it.</p>

              <!-- One tiny form -->
              <form id="feedForm" class="vstack gap-3 gap-lg-4" novalidate>
                <?php echo csrf_input(); ?>
                <div class="floating-group">
                  <label for="url" class="form-label">Source URL</label>
                  <input type="url" class="form-control form-control-lg" id="url" name="url"
                    placeholder="https://example.com/news" required inputmode="url" autocomplete="url">
                  <small class="form-caption">Blog, news listing, category page, etc.</small>
                </div>

                <div class="row g-3">
                  <div class="col-12 col-md-5">
                    <div class="floating-group">
                      <label for="limit" class="form-label">Items (max)</label>
                      <input type="number" id="limit" name="limit" class="form-control" min="1" max="50" value="10" inputmode="numeric">
                    </div>
                  </div>
                  <div class="col-12 col-md-7">
                    <div class="floating-group">
                      <label for="format" class="form-label">Format</label>
                      <select id="format" name="format" class="form-select">
                        <option value="rss" selected>RSS</option>
                        <option value="jsonfeed">JSON Feed</option>
                      </select>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="form-check form-switch align-items-center">
                      <input class="form-check-input" type="checkbox" role="switch" id="preferNative" name="prefer_native">
                      <label class="form-check-label" for="preferNative">Prefer native feed (if available)</label>
                    </div>
                    <div class="form-text">If a site advertises an RSS or JSON feed, we will use that first and fall back to the custom parser when needed.</div>
                  </div>
                </div>

                <div class="d-grid gap-2 d-sm-flex align-items-stretch">
                  <button id="generateBtn" type="button" class="btn btn-primary btn-lg-sm flex-grow-1">
                    Generate feed
                  </button>
                  <button id="clearBtn" type="button" class="btn btn-outline-secondary btn-icon" aria-label="Clear form" title="Clear form">
                    <span class="icon" aria-hidden="true">&times;</span>
                    <span class="label d-inline d-sm-none" aria-hidden="true">Clear</span>
                  </button>
                </div>

                <noscript>
                  <div class="alert alert-warning mt-2 mb-0">Enable JavaScript to generate feeds.</div>
                </noscript>
              </form>
            </div>
          </div>

          <div class="footnote mt-4 mb-0">
            We don’t need your login or API keys. Your feed URL will look like <span class="mono">/feeds/xxxx.xml</span> or <span class="mono">/feeds/xxxx.json</span>.
          </div>
        </div>

        <!-- Result -->
        <div class="col-12 col-lg-5">
          <div id="resultCard" class="card shadow-sm h-100 d-none">
            <div class="card-body">
              <h2 class="h5 fw-semibold mb-3">Feed ready</h2>

              <div id="resultBox" class="vstack gap-3">
                <!-- JS will populate -->
              </div>
            </div>
          </div>

          <div id="hintCard" class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column justify-content-center text-center gap-3">
              <div class="placeholder-illustration mx-auto"></div>
              <div>
                <h2 class="h5 fw-semibold mb-2">No feed yet</h2>
                <p class="muted mb-0">Paste a URL and click <strong>Generate feed</strong> to see your preview here. We’ll give you a shareable link instantly.</p>
              </div>
              <ul class="list-unstyled small text-secondary mb-0">
                <li>✔&nbsp; Supports RSS and JSON Feed</li>
                <li>✔&nbsp; No login required</li>
                <li>✔&nbsp; Optional native feed detection</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="/assets/js/main.js" defer></script>

<?php require __DIR__ . '/includes/page_footer.php';
