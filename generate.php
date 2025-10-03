<?php

/**
 * generate.php — SimpleFeedMaker
 * - Fetch via includes/http.php (http_get)
 * - Extract via includes/extract.php (sfm_extract_items / sfm_discover_feeds)
 * - Optional native feed autodiscovery
 *
 * POST:
 *   - url (string, required)
 *   - limit (int, optional; 1..50, default 10)
 *   - format (rss|atom|jsonfeed, optional; default rss)
 *   - prefer_native (optional; "1"/"true"/"on") — try site’s advertised feed first
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* ---- DEBUG (optional)
if (defined('SFM_DEBUG') && SFM_DEBUG) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}
---- */

// ---------------------------------------------------------------------
// Includes (hard-fail with JSON if missing)
// ---------------------------------------------------------------------
$httpFile = __DIR__ . '/includes/http.php';
$extFile  = __DIR__ . '/includes/extract.php';
$secFile  = __DIR__ . '/includes/security.php';

if (!is_file($httpFile) || !is_readable($httpFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/http.php'], JSON_UNESCAPED_SLASHES);
  exit;
}
if (!is_file($extFile) || !is_readable($extFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/extract.php'], JSON_UNESCAPED_SLASHES);
  exit;
}
if (!is_file($secFile) || !is_readable($secFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/security.php'], JSON_UNESCAPED_SLASHES);
  exit;
}

require_once $secFile;    // secure_assert_post(), csrf helpers
require_once $httpFile;   // http_get(), http_head(), http_multi_get(), sfm_log_event()
require_once $extFile;    // sfm_extract_items(), sfm_discover_feeds()
require_once __DIR__ . '/includes/jobs.php';

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------
if (!defined('APP_NAME')) {
  define('APP_NAME', 'SimpleFeedMaker');
}
if (!defined('DEFAULT_FMT')) {
  define('DEFAULT_FMT', 'rss');
}
if (!defined('DEFAULT_LIM')) {
  define('DEFAULT_LIM', 10);
}
if (!defined('MAX_LIM')) {
  define('MAX_LIM', 50);
}
if (!defined('FEEDS_DIR')) {
  define('FEEDS_DIR', __DIR__ . '/feeds');
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function sfm_json_fail(string $msg, int $http = 400, array $extra = []): void
{
  if (function_exists('sfm_log_event')) {
    sfm_log_event('parse', ['phase' => 'fail', 'reason' => $msg, 'http' => $http] + $extra);
  }
  json_fail($msg, $http, $extra);
}

function app_url_base(): string
{
  $envBase = trim((string)getenv('SFM_BASE_URL'));
  if ($envBase !== '') {
    if (!preg_match('~^https?://~i', $envBase)) {
      $envBase = 'https://' . ltrim($envBase, '/');
    }
    return rtrim($envBase, '/');
  }

  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/generate.php';
  $base   = rtrim(str_replace('\\', '/', dirname($script)), '/.');
  return $scheme . '://' . $host . ($base ? $base : '');
}

function ensure_feeds_dir(): void
{
  if (!is_dir(FEEDS_DIR)) @mkdir(FEEDS_DIR, 0775, true);
  if (!is_dir(FEEDS_DIR) || !is_writable(FEEDS_DIR)) {
    sfm_json_fail('Server cannot write to /feeds directory.', 500);
  }
}

function ensure_http_url_or_fail(string $url, string $field = 'url'): void
{
  if (!sfm_is_http_url($url)) {
    sfm_json_fail('Only http:// or https:// URLs are allowed.', 400, ['field' => $field]);
  }
}

// ---------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------
secure_assert_post('generate', 2, 20);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  sfm_json_fail('Use POST.', 405);
}

$url          = trim((string)($_POST['url'] ?? ''));
$limit        = (int)($_POST['limit'] ?? DEFAULT_LIM);
$format       = strtolower(trim((string)($_POST['format'] ?? DEFAULT_FMT)));
$preferNative = isset($_POST['prefer_native']) && in_array(strtolower((string)$_POST['prefer_native']), ['1', 'true', 'on', 'yes'], true);

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
  sfm_json_fail('Please provide a valid URL (including http:// or https://).');
}
ensure_http_url_or_fail($url, 'url');
if (!sfm_url_is_public($url)) {
  sfm_json_fail('The source URL must resolve to a public host.', 400, ['field' => 'url']);
}
$limit  = max(1, min(MAX_LIM, $limit));
if (!in_array($format, ['rss', 'atom', 'jsonfeed'], true)) $format = DEFAULT_FMT;

// Same-origin guard (soft)
if (!empty($_SERVER['HTTP_ORIGIN'])) {
  $origin = $_SERVER['HTTP_ORIGIN'];
  $host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
  if (stripos($origin, $host) !== 0) sfm_json_fail('Cross-origin requests are not allowed.', 403);
}

// ---------------------------------------------------------------------
// A) Prefer native feed if requested
// ---------------------------------------------------------------------
if ($preferNative) {
  $page = http_get($url, [
    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
  ]);

  if ($page['ok'] && $page['status'] >= 200 && $page['status'] < 400 && $page['body'] !== '') {
    $cands = sfm_discover_feeds($page['body'], $url);
    if ($cands) {
      $cands = array_values(array_filter($cands, function ($cand) {
        if (!isset($cand['href'])) return false;
        $href = (string)$cand['href'];
        if (!sfm_is_http_url($href)) return false;
        return sfm_url_is_public($href);
      }));
    }
    if ($cands) {
      // Favor same-host and RSS-ish types
      $pageHost = parse_url($url, PHP_URL_HOST);
      usort($cands, function ($a, $b) use ($pageHost) {
        $ah = parse_url($a['href'], PHP_URL_HOST);
        $bh = parse_url($b['href'], PHP_URL_HOST);
        $sameA = (strcasecmp($ah ?? '', $pageHost ?? '') === 0) ? 1 : 0;
        $sameB = (strcasecmp($bh ?? '', $pageHost ?? '') === 0) ? 1 : 0;
        if ($sameA !== $sameB) return $sameB <=> $sameA;
        $rank = function ($t) {
          $t = strtolower($t ?? '');
          if (strpos($t, 'rss') !== false)  return 3;
          if (strpos($t, 'atom') !== false) return 2;
          if (strpos($t, 'json') !== false) return 1;
          if (strpos($t, 'xml') !== false)  return 2;
          return 0;
        };
        return $rank($b['type']) <=> $rank($a['type']);
      });

      $pick = $cands[0];
      if (!sfm_is_http_url($pick['href'])) {
        sfm_json_fail('Native feed uses unsupported scheme.', 400);
      }
      if (!sfm_url_is_public($pick['href'])) {
        sfm_json_fail('Native feed is not accessible.', 400);
      }

      $feed = http_get($pick['href'], [
        'accept' => 'application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml;q=0.9, */*;q=0.8'
      ]);

      if ($feed['ok'] && $feed['status'] >= 200 && $feed['status'] < 400 && $feed['body'] !== '') {
        [$fmtDetected, $ext] = detect_feed_format_and_ext($feed['body'], $feed['headers'], $pick['href']);
        $finalFormat = $fmtDetected ?: 'rss';
        $ext         = ($finalFormat === 'jsonfeed') ? 'json' : 'xml';

        ensure_feeds_dir();
        $feedId   = md5($pick['href'] . '|' . microtime(true));
        $filename = $feedId . '.' . $ext;
        $path     = FEEDS_DIR . '/' . $filename;

        if (@file_put_contents($path, $feed['body']) === false) {
          sfm_json_fail('Failed to save native feed file.', 500);
        }

        $feedUrl = rtrim(app_url_base(), '/') . '/feeds/' . $filename;

        $job = sfm_job_register([
          'source_url'        => $url,
          'native_source'     => $pick['href'],
          'mode'              => 'native',
          'format'            => $finalFormat,
          'limit'             => $limit,
          'feed_filename'     => $filename,
          'feed_url'          => $feedUrl,
          'prefer_native'     => true,
          'last_refresh_code' => $feed['status'] ?? null,
          'last_refresh_note' => 'native download',
        ]);

        if (function_exists('sfm_log_event')) {
          sfm_log_event('parse', [
            'phase'        => 'native',
            'source'       => $pick['href'],
            'format'       => $finalFormat,
            'saved'        => basename($filename),
            'bytes'        => strlen($feed['body']),
            'status'       => $feed['status'],
            'job_id'       => $job['job_id'] ?? null,
          ]);
        }

        echo json_encode([
          'ok'                => true,
          'feed_url'          => $feedUrl,
          'format'            => $finalFormat,
          'items'             => null,
          'used_native'       => true,
          'native_source'     => $pick['href'],
          'status_breadcrumb' => 'native · just now',
          'job_id'            => $job['job_id'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
      }
      // If native download fails, fall through to custom parse
    }
    // No candidates → fall through
  }
  // Page fetch failed → fall through to custom
}

// ---------------------------------------------------------------------
// B) Custom parse path
// ---------------------------------------------------------------------
$page = http_get($url, [
  'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
]);
if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
  sfm_json_fail('Failed to fetch the page.', 502, ['status' => $page['status'] ?? 0]);
}

$items = sfm_extract_items($page['body'], $url, $limit);
if (empty($items)) {
  sfm_json_fail('No items found on the given page.', 404);
}

$items = sfm_enrich_items_with_article_metadata($items, min(6, $limit));

$title = APP_NAME . ' Feed';
$desc  = 'Custom feed generated by ' . APP_NAME;

$feedId   = md5($url . '|' . microtime(true));
$ext      = ($format === 'jsonfeed') ? 'json' : 'xml';
$filename = $feedId . '.' . $ext;
$feedUrl  = rtrim(app_url_base(), '/') . '/feeds/' . $filename;

switch ($format) {
  case 'jsonfeed':
    $content = build_jsonfeed($title, $url, $desc, $items, $feedUrl);
    break;
  case 'atom':
    $content = build_atom($title, $url, $desc, $items);
    break;
  default:
    $content = build_rss($title, $url, $desc, $items);
    break;
}

ensure_feeds_dir();
$path = FEEDS_DIR . '/' . $filename;
if (@file_put_contents($path, $content) === false) {
  sfm_json_fail('Failed to save feed file.', 500);
}

$job = sfm_job_register([
  'source_url'        => $url,
  'mode'              => 'custom',
  'format'            => $format,
  'limit'             => $limit,
  'feed_filename'     => $filename,
  'feed_url'          => $feedUrl,
  'prefer_native'     => $preferNative,
  'items_count'       => count($items),
  'last_refresh_code' => 200,
  'last_refresh_note' => 'custom parse',
]);

if (function_exists('sfm_log_event')) {
  sfm_log_event('parse', [
    'phase'        => 'custom',
    'source'       => $url,
    'format'       => $format,
    'saved'        => basename($filename),
    'items'        => count($items),
    'bytes'        => strlen($content),
    'job_id'       => $job['job_id'] ?? null,
  ]);
}

echo json_encode([
  'ok'                => true,
  'feed_url'          => $feedUrl,
  'format'            => $format,
  'items'             => count($items),
  'used_native'       => false,
  'status_breadcrumb' => 'created: ' . count($items) . ' items · just now',
  'job_id'            => $job['job_id'] ?? null,
], JSON_UNESCAPED_SLASHES);
exit;

function sfm_enrich_items_with_article_metadata(array $items, int $maxFetch = 5): array
{
  $fetched = 0;

  foreach ($items as &$item) {
    $needSummary = empty($item['description']);
    $needDate    = empty($item['date']);
    $needContent = empty($item['content_html']);

    if (!$needSummary && !$needDate && !$needContent) {
      if ($fetched >= $maxFetch) {
        continue;
      }
    }

    $link = $item['link'] ?? '';
    if ($link === '' || !sfm_is_http_url($link)) continue;

    if ($fetched >= $maxFetch) break;
    $fetched++;

    /**
     * @var array{
     *   ok: bool,
     *   status: int,
     *   body: string,
     *   headers: array<string,string>,
     *   final_url?: string,
     *   from_cache: bool,
     *   was_304: bool
     * } $resp
     */
    $resp = http_get($link, [
      'accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'cache_ttl'  => 86400,
      'timeout'    => 12,
      'use_cache'  => true,
    ]);

    if (!$resp['ok'] || $resp['body'] === '') {
      continue;
    }

    $finalUrl = (isset($resp['final_url']) && $resp['final_url'] !== '') ? (string)$resp['final_url'] : $link;
    [$summary, $published, $contentHtml] = sfm_parse_article_metadata($resp['body'], $finalUrl);

    if (($needSummary || $needContent) && $summary !== '') {
      $item['description'] = $summary;
    }
    if ($needDate && $published !== '') {
      $item['date'] = sfm_clean_date($published);
    }
    if ($contentHtml !== '') {
      $item['content_html'] = $contentHtml;
      if (empty($item['description'])) {
        $item['description'] = sfm_neat_text(strip_tags($contentHtml), 400);
      }
    }
  }
  unset($item);

  return $items;
}

function sfm_parse_article_metadata(string $html, string $baseUrl): array
{
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
  $xp = new DOMXPath($doc);

  $summary = '';
  $summaryQueries = [
    "//meta[@name='description']/@content",
    "//meta[@property='og:description']/@content",
    "//meta[@name='twitter:description']/@content",
  ];
  foreach ($summaryQueries as $q) {
    $node = $xp->query($q)->item(0);
    if ($node) {
      $summary = sfm_neat_text((string)$node->nodeValue, 400);
      if ($summary !== '') break;
    }
  }

  if ($summary === '') {
    $pNodes = $xp->query('//article//p | //main//p | //div[contains(@class,"summary") or contains(@class,"dek") or contains(@class,"excerpt")]//p');
    if ($pNodes) {
      foreach ($pNodes as $p) {
        $text = sfm_neat_text($p->textContent ?? '', 400);
        if (mb_strlen($text) >= 80) {
          $summary = $text;
          break;
        }
      }
    }
  }

  $date = '';
  $dateQueries = [
    "//meta[@property='article:published_time']/@content",
    "//meta[@itemprop='datePublished']/@content",
    "//meta[@name='dc.date.issued']/@content",
    "//time[@datetime]/@datetime",
  ];
  foreach ($dateQueries as $q) {
    $node = $xp->query($q)->item(0);
    if ($node) {
      $date = trim((string)$node->nodeValue);
      if ($date !== '') break;
    }
  }

  $contentHtml = sfm_extract_article_html($xp, $baseUrl);
  return [$summary, $date, $contentHtml];
}

function sfm_extract_article_html(DOMXPath $xp, string $baseUrl): string
{
  $candidates = [
    '//article',
    '//div[contains(@class,"article-body")]',
    '//div[contains(@class,"article__body")]',
    '//div[contains(@class,"content-body")]',
    '//main//article',
    '//main[contains(@class,"content")]//div[contains(@class,"content")]'
  ];

  foreach ($candidates as $q) {
    $node = $xp->query($q)->item(0);
    if ($node) {
      $html = sfm_sanitize_article_html($node, $baseUrl);
      if (strip_tags($html) !== '' && mb_strlen(strip_tags($html)) > 120) {
        return $html;
      }
    }
  }

  $pNodes = $xp->query('//main//p | //article//p');
  if ($pNodes && $pNodes->length) {
    $buffer = '';
    $count = 0;
    foreach ($pNodes as $p) {
      $text = sfm_neat_text($p->textContent ?? '', 400);
      if ($text === '') continue;
      $buffer .= '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
      $count++;
      if ($count >= 6) break;
    }
    if ($buffer !== '') return $buffer;
  }

  return '';
}

function sfm_sanitize_article_html(DOMNode $root, string $baseUrl): string
{
  $doc = new DOMDocument();
  $wrapper = $doc->importNode($root, true);
  $container = $doc->createElement('div');
  $doc->appendChild($container);
  $container->appendChild($wrapper);

  $xp = new DOMXPath($doc);
  foreach (['//script', '//style', '//noscript', '//iframe', '//form'] as $q) {
    foreach ($xp->query($q) as $bad) {
      $bad->parentNode->removeChild($bad);
    }
  }

  $allowedTags = ['p','ul','ol','li','strong','em','b','i','a','blockquote','img','figure','figcaption','h1','h2','h3','h4','pre','code','span','div','table','thead','tbody','tr','td','th'];
  $allowedAttrs = ['href','title','alt','src','width','height','class'];

  $nodes = iterator_to_array($xp->query('//*'));
  foreach ($nodes as $node) {
    $name = strtolower($node->nodeName);
    if (!in_array($name, $allowedTags, true)) {
      sfm_remove_node_keep_children($node);
      continue;
    }

    if ($node->hasAttributes()) {
      $attrs = iterator_to_array($node->attributes);
      foreach ($attrs as $attr) {
        $attrName = strtolower($attr->nodeName);
        if (!in_array($attrName, $allowedAttrs, true)) {
          $node->removeAttributeNode($attr);
          continue;
        }
        if ($name === 'a' && $attrName === 'href') {
          $node->setAttribute('href', sfm_abs_url($attr->nodeValue ?? '', $baseUrl));
        }
        if ($name === 'img' && $attrName === 'src') {
          $node->setAttribute('src', sfm_abs_url($attr->nodeValue ?? '', $baseUrl));
        }
      }
    }
  }

  return sfm_inner_html($container);
}

function sfm_remove_node_keep_children(DOMNode $node): void
{
  if (!$node->parentNode) return;
  $parent = $node->parentNode;
  while ($node->firstChild) {
    $parent->insertBefore($node->firstChild, $node);
  }
  $parent->removeChild($node);
}

function sfm_inner_html(DOMNode $node): string
{
  $html = '';
  foreach ($node->childNodes as $child) {
    $html .= $node->ownerDocument->saveHTML($child);
  }
  return $html;
}
