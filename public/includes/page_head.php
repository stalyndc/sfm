<?php
/**
 * Shared HTML <head> partial for SimpleFeedMaker pages.
 *
 * Expects optional variables defined before include:
 *   $pageTitle, $pageDescription, $pageKeywords, $canonical,
 *   $ogImage, $metaRobots, $structuredData
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$baseUrl       = rtrim(app_url_base(), '/');
$pageTitle     = $pageTitle     ?? 'SimpleFeedMaker — Create RSS or JSON feeds from any URL';
$pageDescription = $pageDescription ?? 'SimpleFeedMaker turns any web page into a feed. Paste a URL, choose RSS or JSON Feed, and get a clean, valid feed in seconds.';
$pageKeywords  = $pageKeywords  ?? 'RSS feed generator, JSON Feed, website to RSS, create RSS feed, feed builder';
$canonical     = $canonical     ?? $baseUrl . '/';
$ogImage       = $ogImage       ?? $baseUrl . '/img/simplefeedmaker-og.png';
$metaRobots    = $metaRobots    ?? 'index,follow,max-image-preview:large';

if (!isset($structuredData) || $structuredData === null) {
    $structuredData = json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'WebSite',
        'url'         => $baseUrl . '/',
        'name'        => 'SimpleFeedMaker',
        'description' => 'Create RSS or JSON feeds from any URL in seconds.',
        'inLanguage'  => 'en',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
?><!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

  <!-- Robots & basic SEO -->
  <meta name="robots" content="<?= htmlspecialchars($metaRobots, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>" />
<?php if (!empty($pageKeywords)): ?>
  <meta name="keywords" content="<?= htmlspecialchars($pageKeywords, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
<?php if (!empty($canonical)): ?>
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>

  <!-- Brand / PWA-ish niceties -->
  <meta name="theme-color" content="#0b1320" />
  <meta name="color-scheme" content="dark light" />

  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= htmlspecialchars($canonical ?: ($baseUrl . '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:site_name" content="SimpleFeedMaker">
  <meta property="og:locale" content="en_US">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="<?= htmlspecialchars($canonical ?: ($baseUrl . '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">

  <!-- Preconnects (fonts / js CDN) -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="dns-prefetch" href="//fonts.googleapis.com" />
  <link rel="dns-prefetch" href="//fonts.gstatic.com" />
  <link rel="dns-prefetch" href="//cdn.jsdelivr.net" />
  <link rel="dns-prefetch" href="//www.googletagmanager.com" />

  <!-- Work Sans + Oswald for titles -->
  <link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600&display=swap" rel="stylesheet">

  <!-- Emoji favicon 📡 -->
  <link rel="icon" href="data:image/svg+xml,
    %3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E
      %3Ctext y='0.9em' font-size='90'%3E%F0%9F%93%A1%3C/text%3E
    %3C/svg%3E">

  <!-- Bootstrap -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
    crossorigin="anonymous">

  <!-- App styles -->
  <link rel="stylesheet" href="/assets/css/style.css">

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-YZ2SN3R4PX"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() {
      dataLayer.push(arguments);
    }
    gtag('js', new Date());
    gtag('config', 'G-YZ2SN3R4PX');
  </script>

<?php if (!empty($structuredData)): ?>
  <script type="application/ld+json">
<?= $structuredData ?>
  </script>
<?php endif; ?>
</head>
