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
              <form
                id="feedForm"
                class="vstack gap-3 gap-lg-4"
                method="post"
                action="generate.php"
                hx-post="generate.php"
                hx-target="#resultRegion"
                hx-swap="innerHTML"
                hx-indicator="#formIndicator"
              >
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
                  <button id="generateBtn" type="submit" class="btn btn-primary btn-lg-sm flex-grow-1">
                    <span class="btn-label">Generate feed</span>
                  </button>
                  <button id="clearBtn" type="button" class="btn btn-outline-secondary btn-icon" aria-label="Clear form" title="Clear form">
                    <span class="icon" aria-hidden="true">&times;</span>
                    <span class="label d-inline d-sm-none" aria-hidden="true">Clear</span>
                  </button>
                </div>

                <div id="formIndicator" class="htmx-indicator small text-secondary align-items-center gap-2" role="status" aria-live="polite">
                  <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                  <span>Generating feed…</span>
                </div>

                <noscript>
                  <input type="hidden" name="response_mode" value="page">
                  <div class="alert alert-warning mt-2 mb-0">JavaScript is optional. Submitting will open the results page.</div>
                </noscript>
              </form>
            </div>
          </div>

        </div>

        <!-- Result -->
        <div class="col-12 col-lg-5">
          <div id="resultRegion" class="vstack gap-3" aria-live="polite">
            <div class="card shadow-sm" data-result-hint>
              <div class="card-body d-flex flex-column justify-content-center text-center gap-3">
                <div class="placeholder-illustration mx-auto" aria-hidden="true">
                  <svg class="placeholder-icon" viewBox="0 0 64 64" role="img" aria-hidden="true" focusable="false">
                    <circle cx="32" cy="32" r="30" fill="#f97316" />
                    <path d="M22 44a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm14 0h-4a14 14 0 0 0-14-14v-4A18 18 0 0 1 36 44Zm10 0h-4c0-13.807-10.193-24-24-24v-4c15.464 0 28 12.536 28 28Z" fill="#fff" />
                  </svg>
                </div>
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
            <div
              class="card shadow-sm recent-feeds-card"
              hx-get="/recent_feeds.php"
              hx-trigger="load"
              hx-swap="outerHTML"
            >
              <div class="card-body text-secondary small">Loading recent feeds…</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="footnote mt-4 mb-0 text-center">
      We don’t need your login or API keys. Your feed URL will look like <span class="mono">/feeds/xxxx.xml</span> or <span class="mono">/feeds/xxxx.json</span>.
    </div>
  </main>

  <section class="feature-belt py-5 py-lg-6">
    <div class="container">
      <div class="text-center mb-4 mb-lg-5">
        <h2 class="h4 fw-semibold text-uppercase mb-2" style="letter-spacing:.12em;">Why teams rely on SimpleFeedMaker</h2>
        <p class="muted mb-0">Lightweight automation paired with feeds that stay valid and readable.</p>
      </div>
      <div class="feature-grid">
        <article class="feature-card">
          <span class="feature-icon" aria-hidden="true">🔗</span>
          <h3 class="fw-semibold">Native feed passthrough</h3>
          <ul>
            <li>Respect a site’s official RSS/JSON feed when it’s discoverable.</li>
            <li>Fall back to the custom parser with one toggle if nothing exists.</li>
            <li>Validation guards keep every exported feed lint-free.</li>
          </ul>
        </article>
        <article class="feature-card">
          <span class="feature-icon" aria-hidden="true">🛠️</span>
          <h3 class="fw-semibold">Automation & safeguards</h3>
          <ul>
            <li>Cleanup, log sanitizer, and rate-limit scripts built for shared hosting.</li>
            <li>Daily Slack digest (private ops alert) keeps you informed without opening admin.</li>
            <li>Health endpoint + monitor cron warn before subscribers notice.</li>
          </ul>
        </article>
        <article class="feature-card">
          <span class="feature-icon" aria-hidden="true">📰</span>
          <h3 class="fw-semibold">Richer article output</h3>
          <ul>
            <li>Full-text enrichment adds hero images, summaries, and metadata.</li>
            <li>Inline HTML stays sanitized so readers like NetNewsWire show the whole story.</li>
            <li>JSON Feed, RSS, and Atom share the same polished payload.</li>
          </ul>
        </article>
      </div>
    </div>
  </section>

  <section class="py-5 py-lg-6">
    <div class="container">
      <div class="social-proof">
        <div class="text-center mb-4">
          <h2 class="h4 fw-semibold mb-2">Built for operators, trusted by readers</h2>
          <p class="muted mb-0">Whether you’re syndicating newsroom updates or curating niche news, SimpleFeedMaker keeps feeds fresh without extra dashboards.</p>
        </div>
        <div class="proof-grid">
          <div class="proof-quote">
            “Our newsletter queue stays topped up because feeds refresh every 30 minutes without fail.”
            <strong>— Indie publisher</strong>
          </div>
          <div class="proof-quote">
            “The metadata enrichment means our readers see full articles in NetNewsWire. No more partials.”
            <strong>— RSS power user</strong>
          </div>
          <div class="proof-quote">
            “Cron alerts land in my personal Slack, so I know the moment a feed slips before subscribers do.”
            <strong>— Solo operator</strong>
          </div>
        </div>
      </div>
    </div>
  </section>

  <script src="https://unpkg.com/htmx.org@1.9.12" crossorigin="anonymous" defer></script>
  <script src="/assets/js/main.js" defer></script>

<?php require __DIR__ . '/includes/page_footer.php';
