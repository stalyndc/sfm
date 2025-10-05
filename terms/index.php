<?php

declare(strict_types=1);

$pageTitle       = 'SimpleFeedMaker Terms of Use';
$pageDescription = 'Understand the rules for using SimpleFeedMaker, what is expected from users, and how to contact us about the service.';
$canonical       = 'https://simplefeedmaker.com/terms/';
$metaRobots      = 'index,follow,max-image-preview:large';
$ogType          = 'article';
$articlePublishedTime = '2025-10-04T00:00:00Z';
$articleModifiedTime  = gmdate('c', filemtime(__FILE__));
$structuredData  = [
  [
    '@context'      => 'https://schema.org',
    '@type'         => 'TermsOfService',
    'url'           => $canonical,
    'name'          => $pageTitle,
    'description'   => $pageDescription,
    'inLanguage'    => 'en',
    'datePublished' => $articlePublishedTime,
    'dateModified'  => $articleModifiedTime,
    'publisher'     => [
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
        <div class="col-12 col-lg-10 col-xl-8">
          <div class="card shadow-sm mb-4">
            <div class="card-body">
              <h1 class="h3 fw-bold mb-3">Terms of Use</h1>
              <p class="text-secondary mb-4">Effective October 2025 &middot; Last updated October 2025</p>

              <h2 class="h5 fw-semibold">1. Acceptance of terms</h2>
              <p>By accessing or using SimpleFeedMaker.com you confirm that you are at least 18 years old and that you agree to comply with these Terms of Use. If you do not agree with any part of these terms, please do not use the site.</p>

              <h2 class="h5 fw-semibold mt-4">2. Description of service</h2>
              <p>SimpleFeedMaker.com allows visitors to generate RSS or JSON feeds from publicly accessible web pages. You understand and agree that the service is provided &ldquo;as is&rdquo; without warranty of any kind. Feed quality depends on third-party websites that may change, restrict access, or block requests at any time. We may modify or discontinue the service without notice.</p>

              <h2 class="h5 fw-semibold mt-4">3. Acceptable use</h2>
              <p>You agree not to use SimpleFeedMaker.com for any activity that:</p>
              <ul>
                <li>Scrapes or accesses private, password-protected, or otherwise non-public content.</li>
                <li>Violates any law, regulation, or third-party rights.</li>
                <li>Overloads or abuses the service through automated requests, bots, or excessive usage.</li>
                <li>Generates feeds from websites that explicitly forbid scraping or crawling in their robots.txt file.</li>
              </ul>
              <p>We may block or limit usage at our discretion to protect system performance and compliance.</p>

              <h2 class="h5 fw-semibold mt-4">4. Intellectual property</h2>
              <p>All code, design, and text on this website are the property of SimpleFeedMaker.com unless otherwise noted. Users retain rights to the URLs they submit and to any feed data derived from public sources.</p>

              <h2 class="h5 fw-semibold mt-4">5. Disclaimer of warranties</h2>
              <p>The service is provided &ldquo;as is&rdquo; and &ldquo;as available.&rdquo; We make no warranties or guarantees regarding availability, accuracy, or reliability of generated feeds. Use the service at your own risk.</p>

              <h2 class="h5 fw-semibold mt-4">6. Limitation of liability</h2>
              <p>In no event shall SimpleFeedMaker.com or its owners be liable for any direct, indirect, incidental, or consequential damages arising from the use or inability to use the site or its services.</p>

              <h2 class="h5 fw-semibold mt-4">7. Privacy</h2>
              <p>Please review our <a class="link-light" href="/privacy/">Privacy Policy</a> to understand how we handle operational data and logs.</p>

              <h2 class="h5 fw-semibold mt-4">8. Changes to these terms</h2>
              <p>We may update these Terms of Use at any time. Continued use of the site after updates means you accept the revised version.</p>

              <h2 class="h5 fw-semibold mt-4">9. Contact</h2>
              <p>If you have questions or concerns about these Terms, email us at <a class="link-light" href="mailto:mail@disla.net">mail@disla.net</a>.</p>

              <p class="mb-0">Thank you for using SimpleFeedMaker.com responsibly.</p>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
              <div>
                <h2 class="h5 fw-semibold mb-1">Need a recap on getting started?</h2>
                <p class="mb-0 text-secondary">Return to the generator or explore the <a class="link-light" href="/faq/">FAQ</a> for quick answers.</p>
              </div>
              <a class="btn btn-outline-primary" href="/">Back to generator</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php require __DIR__ . '/../includes/page_footer.php';
