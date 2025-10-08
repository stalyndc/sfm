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
  <main class="py-4">
    <div class="container">
      <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <h1 class="h4 fw-bold mb-2">Turn any page into a feed</h1>
              <p class="muted mb-4">Paste a URL, choose RSS or JSON, and click Generate. That’s it.</p>

              <!-- One tiny form -->
              <form id="feedForm" class="vstack gap-3" novalidate>
                <?php echo csrf_input(); ?>
                <div>
                  <label for="url" class="form-label">Source URL</label>
                  <input type="url" class="form-control" id="url" name="url"
                    placeholder="https://example.com/news" required inputmode="url" autocomplete="url">
                  <div class="form-text">Blog, news listing, category page, etc.</div>
                </div>

                <div class="row g-3">
                  <div class="col-12 col-sm-6">
                    <label for="limit" class="form-label">Items (max)</label>
                    <input type="number" id="limit" name="limit" class="form-control" min="1" max="50" value="10" inputmode="numeric">
                  </div>
                  <div class="col-12 col-sm-6">
                    <label for="format" class="form-label">Format</label>
                    <select id="format" name="format" class="form-select">
                      <option value="rss" selected>RSS</option>
                      <option value="jsonfeed">JSON Feed</option>
                    </select>
                  </div>
                  <div class="col-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" id="preferNative" name="prefer_native">
                      <label class="form-check-label" for="preferNative">Prefer native feed (if available)</label>
                    </div>
                    <div class="form-text">If a site advertises an RSS or JSON feed, we will use that first and fall back to the custom parser when needed.</div>
                  </div>
                </div>

                <div class="d-grid gap-2 d-sm-flex align-items-center">
                  <button id="generateBtn" type="button" class="btn btn-primary btn-lg-sm flex-grow-1">
                    Generate feed
                  </button>
                  <button id="clearBtn" type="button" class="btn btn-outline-secondary btn-icon" aria-label="Clear form" title="Clear form">
                    <span class="icon" aria-hidden="true">&times;</span>
                    <span class="label visually-hidden visually-hidden-focusable">Clear</span>
                  </button>
                </div>

                <noscript>
                  <div class="alert alert-warning mt-2 mb-0">Enable JavaScript to generate feeds.</div>
                </noscript>
              </form>
            </div>
          </div>

          <div class="footnote text-secondary mt-3">
            We don’t need your login or API keys. Your feed URL will look like <span class="mono">/feeds/xxxx.xml</span> or <span class="mono">/feeds/xxxx.json</span>.
          </div>
        </div>

        <!-- Result -->
        <div class="col-12 col-lg-5">
          <div id="resultCard" class="card shadow-sm d-none">
            <div class="card-body">
              <h2 class="h5 fw-semibold mb-3">Feed ready</h2>

              <div id="resultBox" class="vstack gap-3">
                <!-- JS will populate -->
              </div>
            </div>
          </div>

          <div id="hintCard" class="card shadow-sm">
            <div class="card-body">
              <div class="muted">Your result will appear here after you click <strong>Generate feed</strong>.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="/assets/js/main.js" defer></script>

<?php require __DIR__ . '/includes/page_footer.php';
