<?php

declare(strict_types=1);

$pageTitle       = 'SimpleFeedMaker FAQ';
$pageDescription = 'Answers to the most common questions about generating and refreshing feeds with SimpleFeedMaker.';
$canonical       = 'https://simplefeedmaker.com/faq/';
$activeNav       = 'faq';
$ogType          = 'article';
$articleModifiedTime  = gmdate('c', filemtime(__FILE__));
$articlePublishedTime = '2025-09-01T00:00:00Z';
$structuredData  = [
  [
    '@context'      => 'https://schema.org',
    '@type'         => 'FAQPage',
    'url'           => $canonical,
    'mainEntity'    => [
      [
        '@type' => 'Question',
        'name'  => 'How often are generated feeds refreshed?',
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text'  => 'Every feed you generate is saved as a job. The cron worker checks jobs roughly every 30 minutes (host limits permitting) and regenerates the feed in the background. If a source page changes dramatically or goes offline, the job retries on the next run and logs the error for review.',
        ],
      ],
      [
        '@type' => 'Question',
        'name'  => 'Do I need an account or API key to use SimpleFeedMaker?',
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text'  => 'No account required. Paste the public URL you want to monitor, choose the format (RSS or JSON Feed), and click Generate feed. We never ask for API keys or passwords.',
        ],
      ],
      [
        '@type' => 'Question',
        'name'  => 'What happens if the page already has a native feed?',
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text'  => 'Flip the “Prefer native feed” switch before generating. When enabled, SimpleFeedMaker autodiscovers the site\'s advertised feed and serves that directly. If discovery fails or the native feed is unreachable, the app falls back to the custom parser.',
        ],
      ],
      [
        '@type' => 'Question',
        'name'  => 'Can I delete a feed or stop auto-refreshing it?',
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text'  => 'Yes. Remove the generated file from the feeds directory or contact us via Disla.net and we will retire the job. Old feeds that have not been accessed for a while are cleaned up automatically.',
        ],
      ],
      [
        '@type' => 'Question',
        'name'  => 'What data do you log when I generate a feed?',
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text'  => 'We keep lightweight request logs—timestamp, IP, target URL, and status—to debug issues and prevent abuse. No personal accounts, browsing history, or analytics profiles are stored.',
        ],
      ],
    ],
    'inLanguage'    => 'en',
    'datePublished' => $articlePublishedTime,
    'dateModified'  => $articleModifiedTime,
  ],
];

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
  <main class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-10 col-xl-8">
          <div class="card shadow-sm mb-4">
            <div class="card-body">
              <h1 class="h3 fw-bold mb-3">Frequently Asked Questions</h1>
              <p class="text-secondary mb-0">If you are just getting started, these answers cover how feeds are generated, updated, and how we treat your data.</p>
            </div>
          </div>

          <div class="accordion" id="faqAccordion">
            <div class="accordion-item">
              <h2 class="accordion-header" id="q1">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1" aria-expanded="true" aria-controls="a1">
                  How often are generated feeds refreshed?
                </button>
              </h2>
              <div id="a1" class="accordion-collapse collapse show" aria-labelledby="q1" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  <p class="mb-2">Every feed you generate is saved as a job. Our cron worker checks those jobs roughly every 30 minutes (host limits permitting) and regenerates the feed in the background. The feed URL stays the same so subscribers always get fresh content.</p>
                  <p class="mb-0">If a source page changes dramatically or goes offline, the job retries on the next run and logs the error for review.</p>
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header" id="q2">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2" aria-expanded="false" aria-controls="a2">
                  Do I need an account or API key to use SimpleFeedMaker?
                </button>
              </h2>
              <div id="a2" class="accordion-collapse collapse" aria-labelledby="q2" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  <p class="mb-0">No account required. Just paste the public URL you want to monitor, choose the format (RSS or JSON Feed), and click <strong>Generate feed</strong>. We never ask for API keys or passwords.</p>
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header" id="q3">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3" aria-expanded="false" aria-controls="a3">
                  What happens if the page already has a native feed?
                </button>
              </h2>
              <div id="a3" class="accordion-collapse collapse" aria-labelledby="q3" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  <p class="mb-0">Flip the <strong>Prefer native feed</strong> switch before generating. When enabled, we autodiscover the site&rsquo;s advertised feed and serve that directly. If discovery fails or the native feed is unreachable, we fall back to the custom parser.</p>
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header" id="q4">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4" aria-expanded="false" aria-controls="a4">
                  Can I delete a feed or stop auto-refreshing it?
                </button>
              </h2>
              <div id="a4" class="accordion-collapse collapse" aria-labelledby="q4" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  <p class="mb-0">Yes. Remove the generated file from the <code>/feeds</code> directory or contact us via <a href="https://disla.net/" target="_blank" rel="noopener">Disla.net</a> and we will retire the job. Old feeds that haven&rsquo;t been accessed for a while are cleaned up automatically.</p>
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header" id="q5">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a5" aria-expanded="false" aria-controls="a5">
                  What data do you log when I generate a feed?
                </button>
              </h2>
              <div id="a5" class="accordion-collapse collapse" aria-labelledby="q5" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  <p class="mb-0">We keep lightweight request logs (timestamp, IP, target URL, and status) to debug issues and prevent abuse. No personal accounts, browsing history, or analytics profiles are stored. See the <a href="/privacy/" class="link-light">privacy policy</a> for the long version.</p>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow-sm mt-4">
            <div class="card-body d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
              <div>
                <h2 class="h5 fw-semibold mb-1">Still curious?</h2>
                <p class="mb-0 text-secondary">We love suggestions—drop us a note through the <a class="link-light" href="https://disla.net/" target="_blank" rel="noopener">Disla.net</a> contact page.</p>
              </div>
              <a class="btn btn-outline-primary" href="/">Generate a feed</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
