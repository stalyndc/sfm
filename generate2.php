<?php
/**
 * generate2.php — test endpoint with hardened JSON output + optional logging
 */

declare(strict_types=1);

// Always return JSON, never partial HTML
header('Content-Type: application/json; charset=utf-8');
$SFM_DEBUG = getenv('SFM_DEBUG') === '1';

header('Cache-Control: no-store');
ob_start();

// ---- hardened error handling so we ALWAYS emit JSON on failure ----
$__fatal = null;
set_error_handler(function($severity, $message, $file, $line) {
  // Respect @-operator
  if (!(error_reporting() & $severity)) return false;

  // Ignore deprecations so PHP 8.2+ notices (e.g., HTML-ENTITIES with mb_convert_encoding)
  // don’t break the request. We’ll still log them when logger2 is enabled.
  if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
    return false; // let PHP handle it silently
  }

  // Convert everything else to exceptions so we can emit clean JSON
  throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() use (&$__fatal, $SFM_DEBUG) {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);

    $payload = [
      'ok'      => false,
      'message' => 'Server error (fatal).',
      'hint'    => 'This is visible only because DEBUG is on.',
    ];
    if (!empty($SFM_DEBUG)) {
      $payload['fatal'] = [
        'type' => $err['type'],
        'message' => $err['message'],
        'file' => $err['file'],
        'line' => $err['line'],
      ];
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  }
});

// ---------- soft-require includes (logger is optional) ----------
$httpFile = __DIR__ . '/includes/http.php';
$extFile  = __DIR__ . '/includes/extract.php';
$logFile  = __DIR__ . '/includes/logger2.php';
$secFile  = __DIR__ . '/includes/security.php';

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
if (!is_file($secFile) || !is_readable($secFile)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'Server missing includes/security.php'], JSON_UNESCAPED_SLASHES);
  exit;
}

require_once $httpFile;
require_once $extFile;
require_once $secFile;
require_once __DIR__ . '/includes/feed_validator.php';

// logger is optional — if missing, we run without it
$__logEnabled = false;
if (is_file($logFile) && is_readable($logFile)) {
  require_once $logFile; // defines sfm_log_begin/sfm_log_end/sfm_log_error if present
  $__logEnabled = function_exists('sfm_log_begin') && function_exists('sfm_log_end');
}

// ---------- config / constants ----------
if (!defined('APP_NAME')) {
  define('APP_NAME', 'SimpleFeedMaker');
}
const DEFAULT_FMT = 'rss';
const DEFAULT_LIM = 10;
const MAX_LIM     = 50;
const FEEDS_DIR   = __DIR__ . '/feeds';

// ---------- small helpers ----------
$__span = null;
function sfm_json_fail(string $msg, int $http = 400, array $extra = []): void {
  global $__span, $__logEnabled;
  if ($__logEnabled && is_array($__span)) {
    if (function_exists('sfm_log_error')) {
      sfm_log_error($__span, 'fail', ['http'=>$http, 'reason'=>$msg] + $extra);
    }
  }
  while (ob_get_level()) ob_end_clean();
  http_response_code($http);
  echo json_encode(array_merge(['ok'=>false,'message'=>$msg], $extra), JSON_UNESCAPED_SLASHES);
  exit;
}

function app_url_base(): string {
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
  $script = $_SERVER['SCRIPT_NAME'] ?? '/generate2.php';
  $base   = rtrim(str_replace('\\','/', dirname($script)), '/.');
  return $scheme . '://' . $host . ($base ? $base : '');
}
function ensure_feeds_dir(): void {
  if (!is_dir(FEEDS_DIR)) @mkdir(FEEDS_DIR, 0775, true);
  if (!is_dir(FEEDS_DIR) || !is_writable(FEEDS_DIR)) {
    sfm_json_fail('Server cannot write to /feeds directory.', 500);
  }
}
function xml_safe(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function uuid_v5(string $name, string $ns = '6ba7b811-9dad-11d1-80b4-00c04fd430c8'): string {
  $nhex = str_replace(['-','{','}'], '', $ns); $nstr=''; for ($i=0;$i<32;$i+=2) $nstr.=chr(hexdec(substr($nhex,$i,2)));
  $hash = sha1($nstr.$name);
  return sprintf('%08s-%04s-%04x-%04x-%12s', substr($hash,0,8), substr($hash,8,4),
    (hexdec(substr($hash,12,4)) & 0x0fff) | 0x5000,
    (hexdec(substr($hash,16,4)) & 0x3fff) | 0x8000,
    substr($hash,20,12));
}
function to_rfc3339(?string $str): ?string { if(!$str) return null; $ts=strtotime($str); return $ts?date('c',$ts):null; }

function sfm_is_http_url(string $url): bool {
  $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
  return in_array($scheme, ['http','https'], true);
}

// Builders
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
    if (!empty($it['date'])) { $ts = strtotime($it['date']); if ($ts) $i->addChild('pubDate', date(DATE_RSS,$ts)); }
    $guid = $it['link'] ?? md5(($it['title'] ?? '').($it['description'] ?? ''));
    $i->addChild('guid', xml_safe($guid));
  }
  return $xml->asXML();
}
function build_atom(string $title, string $link, string $desc, array $items): string {
  $xml = new SimpleXMLElement('<feed xmlns="http://www.w3.org/2005/Atom"/>');
  $xml->addChild('title', xml_safe($title));
  $xml->addChild('updated', date(DATE_ATOM));
  $alink = $xml->addChild('link'); $alink->addAttribute('rel','alternate'); $alink->addAttribute('href',$link);
  $xml->addChild('id', 'urn:uuid:' . uuid_v5('feed:' . md5($link.$title)));
  foreach ($items as $it) {
    $e = $xml->addChild('entry');
    $e->addChild('title', xml_safe($it['title'] ?? 'Untitled'));
    $el = $e->addChild('link'); $el->addAttribute('rel','alternate'); $el->addAttribute('href',$it['link'] ?? '');
    $e->addChild('id', 'urn:uuid:' . uuid_v5('item:' . md5(($it['link'] ?? '').($it['title'] ?? ''))));
    $u = !empty($it['date']) && strtotime($it['date']) ? date(DATE_ATOM, strtotime($it['date'])) : date(DATE_ATOM);
    $e->addChild('updated', $u);
    $e->addChild('summary', xml_safe($it['description'] ?? ''));
  }
  return $xml->asXML();
}
function build_jsonfeed(string $title, string $link, string $desc, array $items, string $feedUrl): string {
  $feed = ['version'=>'https://jsonfeed.org/version/1','title'=>$title,'home_page_url'=>$link,'feed_url'=>$feedUrl,'description'=>$desc,'items'=>[]];
  foreach ($items as $it) {
    $id  = $it['link'] ?? md5(($it['title'] ?? '').($it['description'] ?? ''));
    $url = $it['link'] ?? '';
    $ttl = $it['title'] ?? 'Untitled';
    $body = trim((string)($it['description'] ?? ''));
    if ($body === '') $body = $ttl ?: $url;
    $item = ['id'=>$id,'url'=>$url,'title'=>$ttl];
    if ($body !== strip_tags($body)) $item['content_html'] = $body; else $item['content_text'] = $body;
    if (!empty($it['date'])) { $iso = to_rfc3339($it['date']); if ($iso) $item['date_published'] = $iso; }
    if (!empty($it['description'])) {
      $plain = trim(strip_tags($it['description']));
      if ($plain !== '' && $plain !== $body) $item['summary'] = mb_strlen($plain) > 220 ? mb_substr($plain,0,219).'…' : $plain;
    }
    $feed['items'][] = $item;
  }
  return json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// Autodiscovery (fallback if extract.php doesn’t provide helpers)
function make_absolute_url(string $href, string $base): string {
  if ($href === '') return ''; if (parse_url($href, PHP_URL_SCHEME)) return $href;
  $bp = parse_url($base); if (!$bp) return $href;
  $scheme = ($bp['scheme'] ?? 'https') . '://'; $host = $bp['host'] ?? ''; $port = isset($bp['port'])?':'.$bp['port']:'';
  if (strpos($href,'/')===0) return $scheme.$host.$port.$href;
  $path = $bp['path'] ?? '/'; if ($path==='' || substr($path,-1)!=='/') { $path = preg_replace('#/[^/]*$#','/',$path); if ($path===null||$path==='') $path='/'; }
  $abs = $scheme.$host.$port.$path.$href; $abs = preg_replace('#/\.(/|$)#','/',$abs);
  while (strpos($abs,'../')!==false) { $abs = preg_replace('#/[^/]+/\.\./#','/',$abs,1); if ($abs===null) break; }
  return $abs;
}
function get_base_from_html(string $html, string $sourceUrl): string {
  libxml_use_internal_errors(true); $doc = new DOMDocument(); @$doc->loadHTML(mb_convert_encoding($html,'HTML-ENTITIES','UTF-8'));
  $xp = new DOMXPath($doc); $href='';
  $base = $xp->query('//base[@href]')->item(0); if ($base) $href = trim($base->getAttribute('href'));
  if (!$href) { $canon = $xp->query('//link[@rel="canonical"][@href]')->item(0); if ($canon) $href = trim($canon->getAttribute('href')); }
  return $href ?: $sourceUrl;
}
function fallback_discover_native_feeds(string $html, string $pageUrl): array {
  libxml_use_internal_errors(true); $doc = new DOMDocument(); @$doc->loadHTML(mb_convert_encoding($html,'HTML-ENTITIES','UTF-8')); $xp = new DOMXPath($doc);
  $base = get_base_from_html($html,$pageUrl); $nodes = $xp->query('//link[@rel="alternate"][@type and @href]'); if (!$nodes||!$nodes->length) return [];
  $out=[]; foreach ($nodes as $n) { $type=strtolower(trim($n->getAttribute('type')??'')); if (strpos($type,'rss')===false && strpos($type,'atom')===false && strpos($type,'xml')===false && strpos($type,'json')===false) continue;
    $href=trim($n->getAttribute('href')??''); if ($href==='') continue; $abs=make_absolute_url($href,$base); $title=trim((string)($n->getAttribute('title')??'')); $out[]=['href'=>$abs,'type'=>$type,'title'=>$title]; }
  $seen=[]; $uniq=[]; foreach ($out as $f) { $k=strtolower($f['href']); if (isset($seen[$k])) continue; $seen[$k]=true; $uniq[]=$f; } return $uniq;
}
function detect_feed_format_and_ext(string $body, array $headersAssoc, string $srcUrl = ''): array {
  $ct = strtolower($headersAssoc['content-type'] ?? '');
  if (strpos($ct,'json')!==false) return ['jsonfeed','json'];
  if (strpos($ct,'atom')!==false) return ['atom','xml'];
  if (strpos($ct,'xml') !==false) return ['rss','xml'];
  $head = substr(ltrim($body),0,4000);
  if (stripos($head,'"version":"https://jsonfeed.org/version')!==false) return ['jsonfeed','json'];
  if (stripos($head,'<feed')!==false && stripos($head,'http://www.w3.org/2005/Atom')!==false) return ['atom','xml'];
  if (stripos($head,'<rss')!==false) return ['rss','xml'];
  if ($srcUrl) { $ext=strtolower(pathinfo(parse_url($srcUrl,PHP_URL_PATH)??'',PATHINFO_EXTENSION)); if ($ext==='json') return ['jsonfeed','json']; if ($ext==='xml') return ['rss','xml']; }
  return ['rss','xml'];
}

// ---------- inputs ----------
try {
  secure_assert_post('generate', 2, 20);
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sfm_json_fail('Use POST.', 405);

  $url          = trim((string)($_POST['url'] ?? ''));
  $limit        = (int)($_POST['limit'] ?? DEFAULT_LIM);
  $format       = strtolower(trim((string)($_POST['format'] ?? DEFAULT_FMT)));
  $preferNative = isset($_POST['prefer_native']) && in_array(strtolower((string)$_POST['prefer_native']), ['1','true','on','yes'], true);

  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) sfm_json_fail('Please provide a valid URL (including http:// or https://).');
  if (!sfm_is_http_url($url)) sfm_json_fail('Only http:// or https:// URLs are allowed.', 400, ['field' => 'url']);
  if (!sfm_url_is_public($url)) sfm_json_fail('The source URL must resolve to a public host.', 400, ['field' => 'url']);
  $limit = max(1, min(MAX_LIM, $limit));
  if (!in_array($format, ['rss','atom','jsonfeed'], true)) $format = DEFAULT_FMT;

  if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    $host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    if (stripos($origin, $host) !== 0) sfm_json_fail('Cross-origin requests are not allowed.', 403);
  }

  // start log span (optional)
  if ($__logEnabled) {
    $__span = sfm_log_begin('generate2', [
      'url'=>$url,'limit'=>$limit,'format'=>$format,'prefer_native'=>$preferNative?1:0
    ]);
  }

  // ---- A) prefer native if requested ----
  if ($preferNative) {
    $page = http_get($url, ['accept'=>'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
    if ($page['ok'] && $page['status'] >= 200 && $page['status'] < 400 && $page['body'] !== '') {
      $cands = function_exists('sfm_discover_feeds')
        ? sfm_discover_feeds($page['body'], $url)
        : fallback_discover_native_feeds($page['body'], $url);
      if ($cands) {
      $cands = array_values(array_filter($cands, function ($cand) {
          if (!isset($cand['href'])) return false;
          $href = (string)$cand['href'];
          if (!sfm_is_http_url($href)) return false;
          return sfm_url_is_public($href);
      }));
      }
      if ($cands) {
        $pageHost = parse_url($url, PHP_URL_HOST);
        usort($cands, function($a, $b) use ($pageHost) {
          $ah = parse_url($a['href'], PHP_URL_HOST); $bh = parse_url($b['href'], PHP_URL_HOST);
          $sameA = (strcasecmp($ah ?? '', $pageHost ?? '') === 0) ? 1 : 0;
          $sameB = (strcasecmp($bh ?? '', $pageHost ?? '') === 0) ? 1 : 0;
          if ($sameA !== $sameB) return $sameB <=> $sameA;
          $rank = function($t){ $t=strtolower($t??''); if (strpos($t,'rss')!==false) return 3; if (strpos($t,'atom')!==false)return 2; if (strpos($t,'json')!==false)return 1; if (strpos($t,'xml')!==false)return 2; return 0; };
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
          'accept'=>'application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml;q=0.9, */*;q=0.8',
        ]);
        if ($feed['ok'] && $feed['status']>=200 && $feed['status']<400 && $feed['body']!=='') {
          [$fmtDetected, $ext] = detect_feed_format_and_ext($feed['body'], $feed['headers'], $pick['href']);
          $finalFormat = $fmtDetected ?: 'rss';
          $ext = ($finalFormat === 'jsonfeed') ? 'json' : 'xml';

          $validation = sfm_validate_feed($finalFormat, $feed['body']);
          if (!$validation['ok']) {
            $primary = $validation['errors'][0] ?? 'Native feed failed validation.';
            sfm_json_fail('Native feed failed validation: ' . $primary, 502, [
              'error_code' => 'feed_validation_failed',
              'validation' => $validation,
            ]);
          }

          ensure_feeds_dir();
          $feedId   = md5($pick['href'].'|'.microtime(true));
          $filename = $feedId.'.'.$ext;
          $path     = FEEDS_DIR.'/'.$filename;
          if (@file_put_contents($path, $feed['body']) === false) sfm_json_fail('Failed to save native feed file.', 500);

          $feedUrl = rtrim(app_url_base(), '/').'/feeds/'.$filename;

          if ($__logEnabled) {
            $logMeta = ['status'=>'ok','used_native'=>true,'items'=>null];
            if (!empty($validation['warnings'])) {
              $logMeta['validation'] = $validation['warnings'][0] ?? null;
            }
            sfm_log_end($__span, $logMeta);
          }
          while (ob_get_level()) ob_end_clean();
          $payload = [
            'ok'              => true,
            'feed_url'        => $feedUrl,
            'format'          => $finalFormat,
            'items'           => null,
            'used_native'     => true,
            'native_source'   => $pick['href'],
            'status_breadcrumb' => 'native · just now',
          ];
          if (!empty($validation['warnings'])) {
            $payload['validation'] = ['warnings' => $validation['warnings']];
          }
          echo json_encode($payload, JSON_UNESCAPED_SLASHES);
          exit;
        }
      }
    }
    // fall through to custom parse
  }

  // ---- B) custom parse ----
  $page = http_get($url, ['accept'=>'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
  if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
    sfm_json_fail('Failed to fetch the page.', 502, ['status'=>$page['status'] ?? 0]);
  }

  // pick whichever extractor your extract.php provides
  if (function_exists('extract_items_from_html')) {
    $items = extract_items_from_html($page['body'], $url, $limit);
  } elseif (function_exists('sfm_extract_items')) {
    $items = sfm_extract_items($page['body'], $url, $limit);
  } else {
    sfm_json_fail('Server missing extract function.', 500);
  }
  if (empty($items)) sfm_json_fail('No items found on the given page.', 404);

  $title = APP_NAME.' Feed';
  $desc  = 'Custom feed generated by '.APP_NAME;

  $feedId   = md5($url.'|'.microtime(true));
  $ext      = ($format === 'jsonfeed') ? 'json' : 'xml';
  $filename = $feedId.'.'.$ext;
  $feedUrl  = rtrim(app_url_base(), '/').'/feeds/'.$filename;

  switch ($format) {
    case 'jsonfeed': $content = build_jsonfeed($title,$url,$desc,$items,$feedUrl); break;
    case 'atom':     $content = build_atom($title,$url,$desc,$items); break;
    default:         $content = build_rss($title,$url,$desc,$items); break;
  }

  $validation = sfm_validate_feed($format, $content);
  if (!$validation['ok']) {
    $primary = $validation['errors'][0] ?? 'Generated feed failed validation.';
    sfm_json_fail('Generated feed failed validation: ' . $primary, 500, [
      'error_code' => 'feed_validation_failed',
      'validation' => $validation,
    ]);
  }

  ensure_feeds_dir();
  $path = FEEDS_DIR.'/'.$filename;
  if (@file_put_contents($path, $content) === false) sfm_json_fail('Failed to save feed file.', 500);

  if ($__logEnabled) {
    $logMeta = ['status'=>'ok','used_native'=>false,'items'=>count($items)];
    if (!empty($validation['warnings'])) {
      $logMeta['validation'] = $validation['warnings'][0] ?? null;
    }
    sfm_log_end($__span, $logMeta);
  }

  while (ob_get_level()) ob_end_clean();
  $payload = [
    'ok'                => true,
    'feed_url'          => $feedUrl,
    'format'            => $format,
    'items'             => count($items),
    'used_native'       => false,
    'status_breadcrumb' => 'created: '.count($items).' items · just now',
  ];
  if (!empty($validation['warnings'])) {
    $payload['validation'] = ['warnings' => $validation['warnings']];
  }
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;

} catch (Throwable $e) {
  // Convert any PHP warning/notice/exception into clean JSON
  sfm_json_fail('Server error.', 500, ['reason'=>$e->getMessage()]);
}
