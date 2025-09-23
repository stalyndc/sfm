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

if (!is_file($httpFile) || !is_readable($httpFile)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'Server missing includes/http.php'], JSON_UNESCAPED_SLASHES);
  exit;
}
if (!is_file($extFile) || !is_readable($extFile)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'Server missing includes/extract.php'], JSON_UNESCAPED_SLASHES);
  exit;
}

require_once $httpFile;   // http_get(), http_head(), http_multi_get(), sfm_log_event()
require_once $extFile;    // sfm_extract_items(), sfm_discover_feeds()

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------
if (!defined('APP_NAME')) {
  define('APP_NAME', 'SimpleFeedMaker');
}
const DEFAULT_FMT = 'rss';
const DEFAULT_LIM = 10;
const MAX_LIM     = 50;
const FEEDS_DIR   = __DIR__ . '/feeds';

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function json_fail(string $msg, int $http = 400, array $extra = []): void {
  if (function_exists('sfm_log_event')) {
    sfm_log_event('parse', ['phase'=>'fail','reason'=>$msg,'http'=>$http] + $extra);
  }
  http_response_code($http);
  echo json_encode(array_merge(['ok'=>false,'message'=>$msg], $extra), JSON_UNESCAPED_SLASHES);
  exit;
}

function app_url_base(): string {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/generate.php';
  $base   = rtrim(str_replace('\\','/', dirname($script)), '/.');
  return $scheme . '://' . $host . ($base ? $base : '');
}

function ensure_feeds_dir(): void {
  if (!is_dir(FEEDS_DIR)) @mkdir(FEEDS_DIR, 0775, true);
  if (!is_dir(FEEDS_DIR) || !is_writable(FEEDS_DIR)) {
    json_fail('Server cannot write to /feeds directory.', 500);
  }
}

function xml_safe(string $s): string {
  return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function uuid_v5(string $name, string $namespace = '6ba7b811-9dad-11d1-80b4-00c04fd430c8'): string {
  $nhex = str_replace(['-','{','}'], '', $namespace);
  $nstr = '';
  for ($i = 0; $i < 32; $i += 2) $nstr .= chr(hexdec(substr($nhex, $i, 2)));
  $hash = sha1($nstr . $name);
  return sprintf('%08s-%04s-%04x-%04x-%12s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
    (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
    substr($hash, 20, 12)
  );
}

function to_rfc3339(?string $str): ?string {
  if (!$str) return null;
  $ts = strtotime($str);
  return $ts ? date('c', $ts) : null;
}

// ---------------------------------------------------------------------
// Feed builders
// ---------------------------------------------------------------------
function build_rss(string $title, string $link, string $desc, array $items): string {
  $xml = new SimpleXMLElement('<rss version="2.0"/>');
  $channel = $xml->addChild('channel');
  $channel->addChild('title', xml_safe($title));
  $channel->addChild('link', xml_safe($link));
  $channel->addChild('description', xml_safe($desc));
  $channel->addChild('lastBuildDate', date(DATE_RSS));

  foreach ($items as $it) {
    $i = $channel->addChild('item');
    $i->addChild('title', xml_safe($it['title'] ?? 'Untitled'));
    $i->addChild('link', xml_safe($it['link'] ?? ''));
    $i->addChild('description', xml_safe($it['description'] ?? ''));
    if (!empty($it['date'])) {
      $ts = strtotime($it['date']);
      if ($ts) $i->addChild('pubDate', date(DATE_RSS, $ts));
    }
    $guid = $it['link'] ?? md5(($it['title'] ?? '').($it['description'] ?? ''));
    $i->addChild('guid', xml_safe($guid));
  }
  return $xml->asXML();
}

function build_atom(string $title, string $link, string $desc, array $items): string {
  $xml = new SimpleXMLElement('<feed xmlns="http://www.w3.org/2005/Atom"/>');
  $xml->addChild('title', xml_safe($title));
  $xml->addChild('updated', date(DATE_ATOM));
  $alink = $xml->addChild('link');
  $alink->addAttribute('rel', 'alternate');
  $alink->addAttribute('href', $link);
  $xml->addChild('id', 'urn:uuid:' . uuid_v5('feed:' . md5($link.$title)));

  foreach ($items as $it) {
    $e = $xml->addChild('entry');
    $e->addChild('title', xml_safe($it['title'] ?? 'Untitled'));
    $el = $e->addChild('link');
    $el->addAttribute('rel', 'alternate');
    $el->addAttribute('href', $it['link'] ?? '');
    $e->addChild('id', 'urn:uuid:' . uuid_v5('item:' . md5(($it['link'] ?? '').($it['title'] ?? ''))));
    $u = !empty($it['date']) && strtotime($it['date']) ? date(DATE_ATOM, strtotime($it['date'])) : date(DATE_ATOM);
    $e->addChild('updated', $u);
    $e->addChild('summary', xml_safe($it['description'] ?? ''));
  }
  return $xml->asXML();
}

function build_jsonfeed(string $title, string $link, string $desc, array $items, string $feedUrl): string {
  $feed = [
    'version'       => 'https://jsonfeed.org/version/1',
    'title'         => $title,
    'home_page_url' => $link,
    'feed_url'      => $feedUrl,
    'description'   => $desc,
    'items'         => [],
  ];
  foreach ($items as $it) {
    $id  = $it['link'] ?? md5(($it['title'] ?? '').($it['description'] ?? ''));
    $url = $it['link'] ?? '';
    $ttl = $it['title'] ?? 'Untitled';
    $body = trim((string)($it['description'] ?? ''));
    if ($body === '') $body = $ttl ?: $url;

    $item = ['id'=>$id,'url'=>$url,'title'=>$ttl];
    if ($body !== strip_tags($body)) $item['content_html'] = $body;
    else                             $item['content_text'] = $body;

    if (!empty($it['date'])) {
      $iso = to_rfc3339($it['date']);
      if ($iso) $item['date_published'] = $iso;
    }
    if (!empty($it['description'])) {
      $plain = trim(strip_tags($it['description']));
      if ($plain !== '' && $plain !== $body) {
        $item['summary'] = mb_strlen($plain) > 220 ? mb_substr($plain, 0, 219) . '…' : $plain;
      }
    }
    $feed['items'][] = $item;
  }
  return json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------------------
// Helper: detect feed format from headers/body/URL
// ---------------------------------------------------------------------
function detect_feed_format_and_ext(string $body, array $headersAssoc, string $srcUrl = ''): array {
  $ct = strtolower($headersAssoc['content-type'] ?? '');
  if (strpos($ct, 'json') !== false) return ['jsonfeed', 'json'];
  if (strpos($ct, 'atom') !== false) return ['atom', 'xml'];
  if (strpos($ct, 'xml')  !== false) return ['rss', 'xml'];

  $head = substr(ltrim($body), 0, 4000);
  if (stripos($head, '"version":"https://jsonfeed.org/version') !== false) return ['jsonfeed', 'json'];
  if (stripos($head, '<feed') !== false && stripos($head, 'http://www.w3.org/2005/Atom') !== false) return ['atom', 'xml'];
  if (stripos($head, '<rss') !== false) return ['rss', 'xml'];

  if ($srcUrl) {
    $ext = strtolower(pathinfo(parse_url($srcUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    if ($ext === 'json') return ['jsonfeed', 'json'];
    if ($ext === 'xml')  return ['rss', 'xml'];
  }
  return ['rss', 'xml'];
}

// ---------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_fail('Use POST.', 405);
}

$url          = trim((string)($_POST['url'] ?? ''));
$limit        = (int)($_POST['limit'] ?? DEFAULT_LIM);
$format       = strtolower(trim((string)($_POST['format'] ?? DEFAULT_FMT)));
$preferNative = isset($_POST['prefer_native']) && in_array(strtolower((string)$_POST['prefer_native']), ['1','true','on','yes'], true);

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
  json_fail('Please provide a valid URL (including http:// or https://).');
}
$limit  = max(1, min(MAX_LIM, $limit));
if (!in_array($format, ['rss','atom','jsonfeed'], true)) $format = DEFAULT_FMT;

// Same-origin guard (soft)
if (!empty($_SERVER['HTTP_ORIGIN'])) {
  $origin = $_SERVER['HTTP_ORIGIN'];
  $host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
  if (stripos($origin, $host) !== 0) json_fail('Cross-origin requests are not allowed.', 403);
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
      // Favor same-host and RSS-ish types
      $pageHost = parse_url($url, PHP_URL_HOST);
      usort($cands, function($a, $b) use ($pageHost) {
        $ah = parse_url($a['href'], PHP_URL_HOST);
        $bh = parse_url($b['href'], PHP_URL_HOST);
        $sameA = (strcasecmp($ah ?? '', $pageHost ?? '') === 0) ? 1 : 0;
        $sameB = (strcasecmp($bh ?? '', $pageHost ?? '') === 0) ? 1 : 0;
        if ($sameA !== $sameB) return $sameB <=> $sameA;
        $rank = function($t) {
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
          json_fail('Failed to save native feed file.', 500);
        }

        $feedUrl = rtrim(app_url_base(), '/') . '/feeds/' . $filename;

        if (function_exists('sfm_log_event')) {
          sfm_log_event('parse', [
            'phase'        => 'native',
            'source'       => $pick['href'],
            'format'       => $finalFormat,
            'saved'        => basename($filename),
            'bytes'        => strlen($feed['body']),
            'status'       => $feed['status'],
          ]);
        }

        echo json_encode([
          'ok'                => true,
          'feed_url'          => $feedUrl,
          'format'            => $finalFormat,
          'items'             => null,
          'used_native'       => true,
          'native_source'     => $pick['href'],
          'status_breadcrumb' => 'native · just now'
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
  json_fail('Failed to fetch the page.', 502, ['status'=>$page['status'] ?? 0]);
}

$items = sfm_extract_items($page['body'], $url, $limit);
if (empty($items)) {
  json_fail('No items found on the given page.', 404);
}

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
  json_fail('Failed to save feed file.', 500);
}

if (function_exists('sfm_log_event')) {
  sfm_log_event('parse', [
    'phase'        => 'custom',
    'source'       => $url,
    'format'       => $format,
    'saved'        => basename($filename),
    'items'        => count($items),
    'bytes'        => strlen($content),
  ]);
}

echo json_encode([
  'ok'                => true,
  'feed_url'          => $feedUrl,
  'format'            => $format,
  'items'             => count($items),
  'used_native'       => false,
  'status_breadcrumb' => 'created: ' . count($items) . ' items · just now'
], JSON_UNESCAPED_SLASHES);
exit;