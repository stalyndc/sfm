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
require_once __DIR__ . '/includes/feed_validator.php';
require_once __DIR__ . '/includes/enrich.php';
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

function sfm_filter_native_candidates(array $cands, string $sourceUrl): array
{
  if (!$cands) return [];

  $cands = array_values(array_filter($cands, function ($cand) {
    if (!isset($cand['href'])) return false;
    $href = (string)$cand['href'];
    if (!sfm_is_http_url($href)) return false;
    return sfm_url_is_public($href);
  }));

  if (!$cands) return [];

  $pageHost = parse_url($sourceUrl, PHP_URL_HOST);
  usort($cands, function ($a, $b) use ($pageHost) {
    $rank = function ($t) {
      $t = strtolower($t ?? '');
      if (strpos($t, 'rss') !== false)  return 3;
      if (strpos($t, 'atom') !== false) return 2;
      if (strpos($t, 'json') !== false) return 1;
      if (strpos($t, 'xml') !== false)  return 2;
      return 0;
    };

    $ah = parse_url($a['href'] ?? '', PHP_URL_HOST) ?: '';
    $bh = parse_url($b['href'] ?? '', PHP_URL_HOST) ?: '';
    $sameA = (strcasecmp($ah, $pageHost ?? '') === 0) ? 1 : 0;
    $sameB = (strcasecmp($bh, $pageHost ?? '') === 0) ? 1 : 0;
    if ($sameA !== $sameB) return $sameB <=> $sameA;
    return $rank($b['type'] ?? '') <=> $rank($a['type'] ?? '');
  });

  return $cands;
}

function sfm_attempt_native_download(string $requestedUrl, array $candidate, int $limit, bool $preferNativeFlag, string $note, bool $strict, string $logPhase = 'native'): bool
{
  $href = isset($candidate['href']) ? (string)$candidate['href'] : '';
  if ($href === '') {
    if ($strict) {
      sfm_json_fail('Native feed is missing an href.', 400);
    }
    return false;
  }

  if (!sfm_is_http_url($href)) {
    if ($strict) {
      sfm_json_fail('Native feed uses unsupported scheme.', 400);
    }
    return false;
  }
  if (!sfm_url_is_public($href)) {
    if ($strict) {
      sfm_json_fail('Native feed is not accessible.', 400);
    }
    return false;
  }

  /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $feed */
  $feed = http_get($href, [
    'accept' => 'application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml;q=0.9, */*;q=0.8'
  ]);

  if (!$feed['ok'] || $feed['status'] < 200 || $feed['status'] >= 400 || $feed['body'] === '') {
    return false;
  }

  [$fmtDetected, $ext] = detect_feed_format_and_ext($feed['body'], $feed['headers'], $href);
  $normalization = sfm_normalize_feed($feed['body'], $fmtDetected, $href);
  if ($normalization) {
    $feed['body'] = $normalization['body'];
    $fmtDetected  = $normalization['format'];
    $ext          = $normalization['ext'];
    $note        .= ' (' . $normalization['note'] . ')';
  }
  $finalFormat = $fmtDetected ?: 'rss';
  $ext         = ($finalFormat === 'jsonfeed') ? 'json' : 'xml';

  ensure_feeds_dir();
  $feedId   = md5($href . '|' . microtime(true));
  $filename = $feedId . '.' . $ext;
  $path     = FEEDS_DIR . '/' . $filename;

  if (@file_put_contents($path, $feed['body']) === false) {
    if ($strict) {
      sfm_json_fail('Failed to save native feed file.', 500);
    }
    return false;
  }

  $feedUrl = rtrim(app_url_base(), '/') . '/feeds/' . $filename;

  $job = sfm_job_register([
    'source_url'        => $requestedUrl,
    'native_source'     => $href,
    'mode'              => 'native',
    'format'            => $finalFormat,
    'limit'             => $limit,
    'feed_filename'     => $filename,
    'feed_url'          => $feedUrl,
    'prefer_native'     => $preferNativeFlag,
    'last_refresh_code' => $feed['status'],
    'last_refresh_note' => $note,
  ]);

  if (function_exists('sfm_log_event')) {
    $logData = [
      'phase'        => $logPhase,
      'source'       => $href,
      'format'       => $finalFormat,
      'saved'        => basename($filename),
      'bytes'        => strlen($feed['body']),
      'status'       => $feed['status'],
      'job_id'       => $job['job_id'] ?? null,
    ];
    if ($normalization) {
      $logData['normalized'] = $normalization['note'];
    }
    sfm_log_event('parse', $logData);
  }

  $breadcrumb = $preferNativeFlag ? 'native · just now' : 'native fallback · just now';
  if ($normalization) {
    $breadcrumb .= ' (normalized)';
  }

  echo json_encode([
    'ok'                => true,
    'feed_url'          => $feedUrl,
    'format'            => $finalFormat,
    'items'             => null,
    'used_native'       => true,
    'native_source'     => $href,
    'status_breadcrumb' => $breadcrumb,
    'job_id'            => $job['job_id'] ?? null,
    'normalized'        => $normalization['note'] ?? null,
  ], JSON_UNESCAPED_SLASHES);

  return true;
}

function sfm_try_native_fallback(string $html, string $requestedUrl, int $limit): bool
{
  $cands = sfm_discover_feeds($html, $requestedUrl);
  $cands = sfm_filter_native_candidates($cands, $requestedUrl);
  if (!$cands) return false;

  $pick = $cands[0];
  if (sfm_attempt_native_download($requestedUrl, $pick, $limit, false, 'native fallback', false, 'native-fallback')) {
    exit;
  }
  return false;
}

// ---------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------
secure_assert_post('generate', 2, 20);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  sfm_json_fail('Use POST.', 405);
}

$url              = trim((string)($_POST['url'] ?? ''));
$limit            = (int)($_POST['limit'] ?? DEFAULT_LIM);
$format           = strtolower(trim((string)($_POST['format'] ?? DEFAULT_FMT)));
$preferNative     = isset($_POST['prefer_native']) && in_array(strtolower((string)($_POST['prefer_native'])), ['1', 'true', 'on', 'yes'], true);
$itemSelectorCss  = trim((string)($_POST['item_selector'] ?? ''));
$titleSelectorCss = trim((string)($_POST['title_selector'] ?? ''));
$summarySelectorCss = trim((string)($_POST['summary_selector'] ?? ''));

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
  sfm_json_fail('Please provide a valid URL (including http:// or https://).');
}
ensure_http_url_or_fail($url, 'url');
if (!sfm_url_is_public($url)) {
  sfm_json_fail('The source URL must resolve to a public host.', 400, ['field' => 'url']);
}
$limit  = max(1, min(MAX_LIM, $limit));
if (!in_array($format, ['rss', 'atom', 'jsonfeed'], true)) $format = DEFAULT_FMT;

$extractionOptions = [];
if ($itemSelectorCss !== '') {
  $itemSelectorXpath = sfm_css_to_xpath($itemSelectorCss, false);
  if ($itemSelectorXpath === null) {
    sfm_json_fail('Unsupported CSS selector for item_selector.', 400, [
      'field'      => 'item_selector',
      'error_code' => 'invalid_selector',
    ]);
  }
  $extractionOptions['item_selector'] = $itemSelectorCss;
  $extractionOptions['item_selector_xpath'] = $itemSelectorXpath;
}

if ($titleSelectorCss !== '') {
  $titleSelectorXpath = sfm_css_to_xpath($titleSelectorCss, true);
  if ($titleSelectorXpath === null) {
    sfm_json_fail('Unsupported CSS selector for title_selector.', 400, [
      'field'      => 'title_selector',
      'error_code' => 'invalid_selector',
    ]);
  }
  $extractionOptions['title_selector'] = $titleSelectorCss;
  $extractionOptions['title_selector_xpath'] = $titleSelectorXpath;
}

if ($summarySelectorCss !== '') {
  $summarySelectorXpath = sfm_css_to_xpath($summarySelectorCss, true);
  if ($summarySelectorXpath === null) {
    sfm_json_fail('Unsupported CSS selector for summary_selector.', 400, [
      'field'      => 'summary_selector',
      'error_code' => 'invalid_selector',
    ]);
  }
  $extractionOptions['summary_selector'] = $summarySelectorCss;
  $extractionOptions['summary_selector_xpath'] = $summarySelectorXpath;
}

// Same-origin guard (soft)
if (!empty($_SERVER['HTTP_ORIGIN'])) {
  $origin = $_SERVER['HTTP_ORIGIN'];
  if (!sfm_origin_is_allowed($origin, app_url_base())) sfm_json_fail('Cross-origin requests are not allowed.', 403);
}

// ---------------------------------------------------------------------
// A) Prefer native feed if requested
// ---------------------------------------------------------------------
if ($preferNative) {
  /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $pageResp */
  $pageResp = http_get($url, [
    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
  ]);

  if ($pageResp['ok'] && $pageResp['status'] >= 200 && $pageResp['status'] < 400 && $pageResp['body'] !== '') {
    $cands = sfm_discover_feeds($pageResp['body'], $url);
    $cands = sfm_filter_native_candidates($cands, $url);
    if ($cands) {
      $pick = $cands[0];
      if (sfm_attempt_native_download($url, $pick, $limit, true, 'native download', true, 'native')) {
        exit;
      }
    }
  }
  // Page fetch failed or no native match → fall through
}

// ---------------------------------------------------------------------
// B) Custom parse path
// ---------------------------------------------------------------------
/** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $page */
$page = http_get($url, [
  'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
]);

if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
  $status = (int)$page['status'];
  $details = ['status' => $status];
  if (!empty($page['error'])) {
    $details['error'] = (string)$page['error'];
  }
  if ($page['error'] === 'body_too_large') {
    $limitBytes = defined('SFM_HTTP_MAX_BYTES') ? (int) SFM_HTTP_MAX_BYTES : 0;
    if ($limitBytes > 0) {
      $details['size_limit_bytes'] = $limitBytes;
      $message = 'The page is larger than the allowed download size (' . round($limitBytes / 1048576, 1) . ' MB).';
    } else {
      $message = 'The page is larger than the allowed download size.';
    }
    $details['error_code'] = 'body_too_large';
    sfm_json_fail($message, 502, $details);
  }
  $message = 'Failed to fetch the page.';
  $errorCode = 'fetch_failed';

  if (!$page['ok'] && $status === 0) {
    $message = 'The request timed out or was blocked before receiving a response.';
    $errorCode = 'network_error';
  } elseif ($status === 0) {
    $message = 'The server returned an unexpected response.';
  } elseif ($status === 401 || $status === 403) {
    $message = 'The site returned HTTP ' . $status . ' (access denied). It may require login or block bots.';
    $errorCode = 'http_' . $status;
  } elseif ($status === 404) {
    $message = 'The page could not be found (HTTP 404).';
    $errorCode = 'http_404';
  } elseif ($status === 410 || $status === 451) {
    $message = 'The page is no longer available (HTTP ' . $status . ').';
    $errorCode = 'http_' . $status;
  } elseif ($status === 429) {
    $message = 'The site rate-limited our request (HTTP 429). Try again later.';
    $errorCode = 'http_429';
  } elseif ($status >= 500 && $status < 600) {
    $message = 'The site returned an error (HTTP ' . $status . ').';
    $errorCode = 'http_' . $status;
  }

  if ($page['body'] === '') {
    $message .= ' The response body was empty.';
    $errorCode = $errorCode === 'fetch_failed' ? 'empty_body' : $errorCode;
  }

  $details['error_code'] = $errorCode;
  sfm_json_fail($message, 502, $details);
}

$extractDebug = [];
$items = sfm_extract_items($page['body'], $url, $limit, $extractionOptions, $extractDebug);

if (count($items) < $limit) {
  $extra = sfm_collect_paginated_items($page['body'], $url, $limit, $items, $extractionOptions);
  if ($extra) {
    $items = array_merge($items, $extra);
    $items = sfm_unique_items($items, $limit);
  }
}

if (empty($items)) {
  if (!$preferNative && $page['ok'] && $page['status'] >= 200 && $page['status'] < 400 && $page['body'] !== '') {
    sfm_try_native_fallback($page['body'], $url, $limit);
  }
  sfm_fail_with_extraction_diagnostics($extractDebug, $extractionOptions);
}

$items = sfm_enrich_items_with_article_metadata($items, min(6, $limit));
$items = sfm_unique_items($items, $limit);

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

$validation = sfm_validate_feed($format, $content);
if (!$validation['ok']) {
  $primary = $validation['errors'][0] ?? 'Feed validation failed.';
  sfm_json_fail('Generated feed failed validation: ' . $primary, 500, [
    'error_code' => 'feed_validation_failed',
    'validation' => $validation,
  ]);
}

ensure_feeds_dir();
$path = FEEDS_DIR . '/' . $filename;
if (@file_put_contents($path, $content) === false) {
  sfm_json_fail('Failed to save feed file.', 500);
}

$validationSnapshot = null;
if (!empty($validation['warnings'])) {
  $validationSnapshot = [
    'warnings'   => $validation['warnings'],
    'checked_at' => sfm_job_now_iso(),
  ];
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
  'last_validation'   => $validationSnapshot,
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
    'validation'   => empty($validation['warnings']) ? null : ($validation['warnings'][0] ?? null),
  ]);
}

$response = [
  'ok'                => true,
  'feed_url'          => $feedUrl,
  'format'            => $format,
  'items'             => count($items),
  'used_native'       => false,
  'status_breadcrumb' => 'created: ' . count($items) . ' items · just now',
  'job_id'            => $job['job_id'] ?? null,
];

if (!empty($validation['warnings'])) {
  $response['validation'] = [
    'warnings' => $validation['warnings'],
  ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;

function sfm_fail_with_extraction_diagnostics(array $debug, array $options): void
{
  $jsonLdCount    = $debug['jsonld_count'] ?? 0;
  $domCount       = $debug['dom_count'] ?? 0;
  $customMatches  = $debug['custom_selector_matches'] ?? null;
  $customSelector = $options['item_selector'] ?? null;

  $hints = [];
  if ($customSelector !== null && $customSelector !== '' && $customMatches === 0) {
    $hints[] = 'Your custom item_selector matched 0 elements. Double-check the CSS selector.';
  }
  if ($jsonLdCount === 0) {
    $hints[] = 'No JSON-LD ItemList or Article metadata was detected.';
  }
  if ($domCount === 0) {
    $hints[] = 'Heuristic scanning found no article-style links. The page may load content via JavaScript.';
  }

  $details = [
    'jsonld_items' => $jsonLdCount,
    'dom_items'    => $domCount,
  ];

  if ($customSelector !== null && $customSelector !== '') {
    $details['item_selector'] = $customSelector;
  }
  if ($customMatches !== null) {
    $details['custom_selector_matches'] = $customMatches;
  }

  $message = 'Could not detect any feed items on the page.';
  if ($hints) {
    $message .= ' ' . implode(' ', $hints);
  }

  sfm_json_fail($message, 422, [
    'error_code' => 'no_items_found',
    'hints'      => $hints,
    'details'    => $details,
  ]);
}

function sfm_collect_paginated_items(string $html, string $sourceUrl, int $limit, array $currentItems, array $options = []): array
{
  $nextUrls = sfm_detect_pagination_links($html, $sourceUrl, 3);
  if (!$nextUrls) {
    return [];
  }

  $extras    = [];
  $seenLinks = [];
  foreach ($currentItems as $it) {
    $href = strtolower($it['link'] ?? '');
    if ($href !== '') {
      $seenLinks[$href] = true;
    }
  }

  foreach ($nextUrls as $nextUrl) {
    if (count($currentItems) + count($extras) >= $limit) {
      break;
    }
    if (!sfm_is_http_url($nextUrl) || isset($seenLinks[strtolower($nextUrl)])) {
      continue;
    }

    /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $resp */
    $resp = http_get($nextUrl, [
      'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'cache_ttl' => 600,
      'timeout'   => 10,
      'use_cache' => true,
    ]);

    if (!$resp['ok'] || $resp['body'] === '') {
      continue;
    }

    $pageItems = sfm_extract_items($resp['body'], $nextUrl, $limit, $options);
    if (!$pageItems) {
      continue;
    }

    foreach ($pageItems as $item) {
      $href = strtolower($item['link'] ?? '');
      if ($href === '' || isset($seenLinks[$href])) {
        continue;
      }
      $extras[] = $item;
      $seenLinks[$href] = true;
      if (count($currentItems) + count($extras) >= $limit) {
        break 2;
      }
    }
  }

  return $extras;
}


function sfm_detect_pagination_links(string $html, string $sourceUrl, int $max = 2): array
{
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
  $xp = new DOMXPath($doc);

  $base = sfm_base_from_html($html, $sourceUrl);

  $seen = [];
  $out  = [];

  $add = static function (?string $href) use (&$seen, &$out, $base, $sourceUrl, $max) {
    if ($href === null) {
      return;
    }
    $href = trim($href);
    if ($href === '') {
      return;
    }
    $abs = sfm_abs_url($href, $base);
    if ($abs === '' || strcasecmp($abs, $sourceUrl) === 0) {
      return;
    }
    $key = strtolower($abs);
    if (isset($seen[$key])) {
      return;
    }
    if (count($out) >= $max) {
      return;
    }
    $seen[$key] = true;
    $out[] = $abs;
  };

  $rels = $xp->query("//link[translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='next'][@href]");
  if ($rels) {
  foreach ($rels as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $add($node->getAttribute('href'));
  }
  }

  $anchors = $xp->query("//a[@href][translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='next']");
  if ($anchors) {
  foreach ($anchors as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $add($node->getAttribute('href'));
  }
  }

  $dataSelectors = $xp->query('//*[@data-next-url or @data-next or @data-next-page or @data-load-more-url or @data-pagination-url]');
  if ($dataSelectors) {
  foreach ($dataSelectors as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      foreach (['data-next-url','data-next','data-next-page','data-load-more-url','data-pagination-url'] as $attr) {
        if ($node->hasAttribute($attr)) {
          $add($node->getAttribute($attr));
        }
      }
  }
  }

  $textCandidates = $xp->query('//a[@href]');
  if ($textCandidates) {
  foreach ($textCandidates as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $text = strtolower(trim($node->textContent ?? ''));
      if ($text === '') {
        continue;
      }
      if (preg_match('/\b(next|older|more|load\s*more)\b/', $text)) {
        $add($node->getAttribute('href'));
      }
      if (count($out) >= $max) {
        break;
      }
    }
  }

  return $out;
}

function sfm_unique_items(array $items, int $limit): array
{
  $seen = [];
  $uniq = [];
  foreach ($items as $item) {
    $href = strtolower($item['link'] ?? '');
    if ($href === '' || isset($seen[$href])) {
      continue;
    }
    $seen[$href] = true;
    $uniq[] = $item;
    if (count($uniq) >= $limit) {
      break;
    }
  }
  return $uniq;
}
