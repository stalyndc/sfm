<?php
/**
 * includes/functions.php
 * Shared helpers for fetching pages, parsing items, and building feeds.
 *
 * This file is intentionally dependency-free (DOMDocument, cURL only) so it
 * runs well on shared hosting.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* ========================================================================
   INPUT / URL HELPERS
   ======================================================================== */

/**
 * Returns true if $s looks like a bare domain/path (no scheme).
 * Example: "example.com/news"
 */
function is_domainish(string $s): bool {
  $s = trim($s);
  if ($s === '') return false;
  if (preg_match('~^[a-z][a-z0-9\-]+\.[a-z]{2,}(/.*)?$~i', $s)) return true;
  return false;
}

/**
 * Normalize and validate a user-provided URL.
 * - Adds https:// if user pasted a bare domain.
 * - Returns empty string if invalid.
 */
function sanitize_url(string $raw): string {
  $raw = trim($raw);
  if ($raw === '') return '';
  if (is_domainish($raw)) {
    $raw = 'https://' . $raw;
  }
  if (filter_var($raw, FILTER_VALIDATE_URL)) {
    return $raw;
  }
  return '';
}

/* ========================================================================
   NETWORK
   ======================================================================== */

/**
 * Fetch HTML (or general text) from a URL using cURL.
 * Returns [true, string] on success, [false, "error message"] on failure.
 */
function fetch_html(string $url): array {
  $ch = curl_init();
  $ua = APP_NAME . ' Bot/1.0 (+'. app_url_base() .')';

  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => TIMEOUT_S,
    CURLOPT_USERAGENT      => $ua,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: en-US,en;q=0.9',
    ],
  ]);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($err)         return [false, 'Network error: ' . $err];
  if ($body === false) return [false, 'Empty response'];
  if ($code >= 400) return [false, 'HTTP error ' . $code];

  return [true, (string)$body];
}

/* ========================================================================
   STRING / XML UTILITIES
   ======================================================================== */

function neat_text(?string $s, int $max = 500): string {
  $s = trim((string)$s);
  $s = preg_replace('/\s+/', ' ', $s);
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max - 1) . '…';
  return $s;
}

function xml_safe(?string $s): string {
  return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function normalize_title(?string $s): string {
  $s = mb_strtolower(trim((string)$s));
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?: '';
}

/** Return ISO date (YYYY-MM-DD) when possible; else '' */
function parse_date_iso(?string $raw): string {
  $raw = trim((string)$raw);
  if ($raw === '') return '';
  $ts = strtotime($raw);
  if ($ts !== false) return date('Y-m-d', $ts);

  if (preg_match('#\b(\d{1,2})/(\d{1,2})/(\d{2,4})\b#', $raw, $m)) {
    $y = (int)$m[3];
    if ($y < 100) $y += 2000;
    return sprintf('%04d-%02d-%02d', $y, (int)$m[1], (int)$m[2]);
  }
  if (preg_match('#\b(\d{4})-(\d{2})-(\d{2})\b#', $raw, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  return '';
}

/* ========================================================================
   URL REWRITING
   ======================================================================== */

function get_base_url(string $html, ?string $sourceUrl = null): ?string {
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
  $xp = new DOMXPath($doc);

  $base = null;
  $tag = $xp->query('//base[@href]')->item(0);
  if ($tag instanceof DOMElement) $base = trim($tag->getAttribute('href'));

  if (!$base) {
    $canon = $xp->query('//link[@rel="canonical"][@href]')->item(0);
    if ($canon instanceof DOMElement) $base = trim($canon->getAttribute('href'));
  }
  if (!$base && $sourceUrl) $base = $sourceUrl;
  if (!$base) return null;

  $p = parse_url($base);
  if (!$p) return null;

  $scheme = isset($p['scheme']) ? $p['scheme'].'://' : '';
  $host   = $p['host'] ?? '';
  $port   = isset($p['port']) ? ':'.$p['port'] : '';
  $path   = $p['path'] ?? '/';
  if (substr($path, -1) !== '/') {
    $path = preg_replace('#/[^/]*$#', '/', $path);
    if (!$path) $path = '/';
  }
  return $scheme.$host.$port.$path;
}

function make_absolute_url(string $href, ?string $base): string {
  $href = trim($href);
  if ($href === '') return '';
  if (parse_url($href, PHP_URL_SCHEME)) return $href;
  if (!$base) return $href;

  if (strpos($href, '/') === 0) {
    $p = parse_url($base);
    if (!$p) return $href;
    $scheme = isset($p['scheme']) ? $p['scheme'].'://' : '';
    $host   = $p['host'] ?? '';
    $port   = isset($p['port']) ? ':'.$p['port'] : '';
    return $scheme.$host.$port.$href;
  }

  $abs = $base.$href;
  $abs = preg_replace('#/\.(/|$)#', '/', $abs);
  while (strpos($abs, '../') !== false) {
    $abs = preg_replace('#/[^/]+/\.\./#', '/', $abs, 1);
    if ($abs === null) break;
  }
  return $abs;
}

/* ========================================================================
   HEURISTICS FOR LINK DISCOVERY
   ======================================================================== */

function is_nav_context(DOMNode $node): bool {
  for ($n = $node; $n; $n = $n->parentNode) {
    if ($n->nodeType !== XML_ELEMENT_NODE) continue;
    $name = strtolower($n->nodeName);
    if (in_array($name, ['nav','header','footer','aside'])) return true;

    if ($n->attributes && $n->attributes->getNamedItem('class')) {
      $cls = strtolower($n->attributes->getNamedItem('class')->nodeValue);
      foreach (['nav','menu','breadcrumb','footer','header','subscribe','promo','social'] as $bad) {
        if (strpos($cls, $bad) !== false) return true;
      }
    }
  }
  return false;
}

function looks_like_article_url(string $href, string $siteHost): bool {
  $p = parse_url($href);
  if (!$p || empty($p['path'])) return false;
  $path = $p['path'];

  if (strlen($path) < 10 && substr_count($path, '-') === 0) return false;
  if (preg_match('#/\d{4}/\d{2}/\d{2}/#', $path)) return true;
  if (substr_count($path, '-') >= 1 && strlen($path) > 20) return true;

  foreach (['/news/','/tech/','/science/','/reviews/','/culture/','/deals/','/how-to/','/blog/','/article/'] as $sec) {
    if (strpos($path, $sec) !== false && strlen($path) > 15) return true;
  }
  return false;
}

function find_local_description(DOMXPath $xpath, DOMNode $linkNode): string {
  $p = $linkNode->parentNode ? $linkNode->parentNode->nextSibling : null;
  while ($p && $p->nodeType !== XML_ELEMENT_NODE) $p = $p->nextSibling;
  if ($p && in_array(strtolower($p->nodeName), ['p','div'])) {
    $t = neat_text($p->textContent, 400);
    if ($t !== '') return $t;
  }

  $cand = $xpath->query('.//p | .//*[contains(@class,"dek") or contains(@class,"summary") or contains(@class,"teaser")]', $linkNode->parentNode);
  if ($cand && $cand->length) {
    $t = neat_text($cand->item(0)->textContent, 400);
    if ($t !== '') return $t;
  }
  return '';
}

function find_local_date(DOMXPath $xpath, DOMNode $linkNode): string {
  $time = $xpath->query('.//time', $linkNode->parentNode)->item(0);
  if ($time) {
    $dt = $time->attributes && $time->attributes->getNamedItem('datetime')
        ? $time->attributes->getNamedItem('datetime')->nodeValue
        : $time->textContent;
    return neat_text($dt, 100);
  }
  $cand = $xpath->query('.//*[contains(@class,"date") or contains(@class,"time")]', $linkNode->parentNode)->item(0);
  if ($cand) return neat_text($cand->textContent, 100);
  return '';
}

/* ========================================================================
   JSON-LD EXTRACTION
   ======================================================================== */

function extract_jsonld_items(DOMXPath $xpath, ?string $baseUrl, int $limit = 10): array {
  $items = [];
  $scripts = $xpath->query('//script[@type="application/ld+json"]');
  if (!$scripts || !$scripts->length) return $items;

  foreach ($scripts as $s) {
    $raw = trim($s->textContent);
    if ($raw === '') continue;

    $data = json_decode($raw, true);
    if (!$data) {
      $raw2 = preg_replace('/,\s*}/', '}', $raw);
      $raw2 = preg_replace('/,\s*]/', ']', $raw2);
      $data = json_decode($raw2, true);
    }
    if (!$data) continue;

    $queue = is_array($data) ? [$data] : [$data];
    while ($queue) {
      $obj = array_shift($queue);

      if (isset($obj['@graph']) && is_array($obj['@graph'])) {
        foreach ($obj['@graph'] as $g) $queue[] = $g;
        continue;
      }
      if (isset($obj[0]) && is_array($obj)) {
        foreach ($obj as $g) $queue[] = $g;
        continue;
      }

      $type = '';
      if (isset($obj['@type'])) {
        $type = is_array($obj['@type']) ? implode(',', $obj['@type']) : (string)$obj['@type'];
        $type = strtolower($type);
      }

      if ($type === 'itemlist' && !empty($obj['itemListElement'])) {
        foreach ($obj['itemListElement'] as $el) {
          $l = $el['url'] ?? ($el['item']['url'] ?? null);
          $t = $el['name'] ?? ($el['item']['name'] ?? null);
          $d = $el['description'] ?? ($el['item']['description'] ?? '');
          $dt= $el['datePublished'] ?? ($el['item']['datePublished'] ?? '');
          if ($l && $t) {
            $l = make_absolute_url($l, $baseUrl);
            $items[] = [
              'title' => neat_text($t, 200),
              'link'  => $l,
              'description' => neat_text($d, 400),
              'date'  => neat_text($dt, 100)
            ];
            if (count($items) >= $limit) return $items;
          }
        }
        continue;
      }

      if (strpos($type, 'article') !== false || $type === 'blogposting') {
        $t  = $obj['headline'] ?? $obj['name'] ?? null;
        $l  = $obj['url'] ?? ($obj['mainEntityOfPage']['@id'] ?? null);
        $d  = $obj['description'] ?? '';
        $dt = $obj['datePublished'] ?? ($obj['dateModified'] ?? '');

        if ($t && $l) {
          $l = make_absolute_url($l, $baseUrl);
          $items[] = [
            'title' => neat_text($t, 200),
            'link'  => $l,
            'description' => neat_text($d, 400),
            'date'  => neat_text($dt, 100)
          ];
          if (count($items) >= $limit) return $items;
        }
      }
    }
  }
  return $items;
}

/* ========================================================================
   MAIN PARSER (JSON-LD first, then DOM heuristics)
   ======================================================================== */

function parse_items(string $html, int $limit, ?string $sourceUrl = null): array {
  $items = [];
  $seen  = [];

  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
  $xp = new DOMXPath($doc);

  $base = get_base_url($html, $sourceUrl);
  $siteHost = $sourceUrl ? (parse_url($sourceUrl, PHP_URL_HOST) ?? '') : '';

  // 1) JSON-LD
  foreach (extract_jsonld_items($xp, $base ?: $sourceUrl, $limit) as $it) {
    $href = $it['link'];
    $key  = md5($href);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $iso = parse_date_iso($it['date'] ?? '');
    $items[] = [
      'title'       => $it['title'],
      'link'        => $href,
      'description' => $it['description'] ?? '',
      'date'        => $iso,
    ];
    if (count($items) >= $limit) return sort_items_by_date($items);
  }

  // 2) DOM heuristics
  $queries = [
    '//article//a[@href]',
    '//*[contains(@class,"card") or contains(@class,"story") or contains(@class,"item") or contains(@class,"post")]//a[@href]',
    '//h1//a[@href] | //h2//a[@href] | //h3//a[@href]',
    '//li//a[@href]'
  ];

  foreach ($queries as $q) {
    $nodes = $xp->query($q);
    if (!$nodes || !$nodes->length) continue;

    foreach ($nodes as $a) {
      if (!($a instanceof DOMElement) || is_nav_context($a)) continue;

      $title = neat_text($a->textContent, 200);
      if ($title === '' || mb_strlen($title) < 6) continue;
      if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $title)) continue;
      if (preg_match('/^by\s+[a-z\'\- ]+$/i', $title)) continue;

      $href = trim($a->getAttribute('href'));
      if ($href === '' || stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) continue;

      $href = make_absolute_url($href, $base ?: $sourceUrl);
      if (!looks_like_article_url($href, $siteHost)) continue;

      $norm = normalize_title($title);
      $key  = md5($href.'|'.$norm);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;

      $desc = find_local_description($xp, $a);
      $dtRaw= find_local_date($xp, $a);
      $iso  = parse_date_iso($dtRaw);

      $items[] = [
        'title'       => $title,
        'link'        => $href,
        'description' => $desc,
        'date'        => $iso
      ];
      if (count($items) >= $limit) return sort_items_by_date($items);
    }
  }

  return sort_items_by_date($items);
}

/** Sort by date (desc), unknown dates last. */
function sort_items_by_date(array $items): array {
  usort($items, function ($a, $b) {
    $da = $a['date'] ?? '';
    $db = $b['date'] ?? '';
    if ($da === $db) return 0;
    if ($da === '') return 1;
    if ($db === '') return -1;
    return strcmp($db, $da); // desc
  });
  return $items;
}

function sfm_plain_text(string $html, int $max = 0): string {
  $text = trim(strip_tags($html));
  if ($max > 0 && mb_strlen($text) > $max) {
    $text = mb_substr($text, 0, $max - 1) . '…';
  }
  return $text;
}

/* ========================================================================
   FEED BUILDERS
   ======================================================================== */

function build_rss(string $feed_title, string $feed_link, string $feed_desc, array $items): string {
  $xml = new SimpleXMLElement('<rss version="2.0"/>');
  $channel = $xml->addChild('channel');
  $channel->addChild('title', xml_safe($feed_title));
  $channel->addChild('link', xml_safe($feed_link));
  $channel->addChild('description', xml_safe($feed_desc));
  $channel->addChild('lastBuildDate', date(DATE_RSS));

  foreach ($items as $item) {
    $it = $channel->addChild('item');
    $it->addChild('title', xml_safe($item['title'] ?? 'Untitled'));
    $it->addChild('link', xml_safe($item['link'] ?? ''));
    $desc = '';
    foreach (['description','content_html','title','link'] as $key) {
      if (!empty($item[$key])) {
        $desc = (string)$item[$key];
        if (trim($desc) !== '') break;
      }
    }
    $it->addChild('description', xml_safe(sfm_plain_text($desc, 400)));
    if (!empty($item['date'])) {
      $ts = strtotime($item['date']);
      if ($ts) $it->addChild('pubDate', date(DATE_RSS, $ts));
    }
    $guid = $item['link'] ?? md5(($item['title'] ?? '').($item['description'] ?? ''));
    $it->addChild('guid', xml_safe($guid));
  }
  return $xml->asXML();
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

function build_atom(string $feed_title, string $feed_link, string $feed_desc, array $items): string {
  $xml = new SimpleXMLElement('<feed xmlns="http://www.w3.org/2005/Atom"/>');
  $xml->addChild('title', xml_safe($feed_title));
  $updated = date(DATE_ATOM);
  $xml->addChild('updated', $updated);

  $link = $xml->addChild('link');
  $link->addAttribute('rel', 'alternate');
  $link->addAttribute('href', $feed_link);

  $xml->addChild('id', 'urn:uuid:' . uuid_v5('feed:'.md5($feed_link.$feed_title)));

  foreach ($items as $item) {
    $entry = $xml->addChild('entry');
    $entry->addChild('title', xml_safe($item['title'] ?? 'Untitled'));

    $eLink = $entry->addChild('link');
    $eLink->addAttribute('rel', 'alternate');
    $eLink->addAttribute('href', $item['link'] ?? '');

    $entry->addChild('id', 'urn:uuid:' . uuid_v5('item:'.md5(($item['link'] ?? '').($item['title'] ?? ''))));

    $u = !empty($item['date']) && strtotime($item['date']) ? date(DATE_ATOM, strtotime($item['date'])) : $updated;
    $entry->addChild('updated', $u);

    $summary = '';
    foreach (['description','content_html','title','link'] as $key) {
      if (!empty($item[$key])) {
        $summary = (string)$item[$key];
        if (trim($summary) !== '') break;
      }
    }
    $entry->addChild('summary', xml_safe(sfm_plain_text($summary, 400)));
  }
  return $xml->asXML();
}

function build_jsonfeed($feed_title, $feed_link, $feed_desc, $items, $feed_url = '') {
    $feed = [
        'version'       => 'https://jsonfeed.org/version/1',
        'title'         => $feed_title ?: 'Feed',
        'home_page_url' => $feed_link ?: '',
        'description'   => $feed_desc ?: '',
        // We'll only add feed_url if provided (recommended by the spec)
    ];
    if (!empty($feed_url)) {
        $feed['feed_url'] = $feed_url;
    }

    $outItems = [];
    foreach ($items as $item) {
        $id    = $item['link'] ?? '';
        if ($id === '') {
            // Fallback ID if link missing
            $id = md5(($item['title'] ?? '') . ($item['description'] ?? ''));
        }

        $entry = [
            'id'    => $id,
            'url'   => $item['link'] ?? '',
            'title' => $item['title'] ?? 'Untitled',
        ];

        $summary = '';
        foreach (['description','content_html','title','link'] as $key) {
            if (!empty($item[$key])) {
                $summary = (string)$item[$key];
                if (trim($summary) !== '') break;
            }
        }
        $summary = sfm_plain_text($summary, 400);
        if ($summary !== '') {
            $entry['content_text'] = $summary;
            $entry['summary'] = $summary;
        }

        // Only include date_published when parsable → RFC3339/ISO8601
        if (!empty($item['date'])) {
            $ts = strtotime($item['date']);
            if ($ts !== false) {
                $entry['date_published'] = date('c', $ts);
            }
        }

        $outItems[] = $entry;
    }

    $feed['items'] = $outItems;

    return json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}


/* ========================================================================
   FILE I/O
   ======================================================================== */

function save_feed_file(string $filename, string $content): string {
  ensure_feeds_dir();
  $path = FEEDS_DIR . '/' . $filename;
  file_put_contents($path, $content);
  return $path;
}
