<?php
/**
 * includes/feed_builder.php
 *
 * Shared helpers for building RSS/Atom/JSON feeds.
 * Wrapped in function_exists guards so older includes/functions.php definitions
 * do not conflict when that file is still required elsewhere.
 */

declare(strict_types=1);

if (!function_exists('xml_safe')) {
  function xml_safe(string $s): string
  {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
  }
}

if (!function_exists('sfm_is_http_url')) {
  function sfm_is_http_url(string $url): bool
  {
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
  }
}

if (!function_exists('uuid_v5')) {
  function uuid_v5(string $name, string $namespace = '6ba7b811-9dad-11d1-80b4-00c04fd430c8'): string
  {
    $nhex = str_replace(['-', '{', '}'], '', $namespace);
    $nstr = '';
    for ($i = 0; $i < 32; $i += 2) {
      $nstr .= chr(hexdec(substr($nhex, $i, 2)));
    }
    $hash = sha1($nstr . $name);
    return sprintf(
      '%08s-%04s-%04x-%04x-%12s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
      substr($hash, 20, 12)
    );
  }
}

if (!function_exists('to_rfc3339')) {
  function to_rfc3339(?string $str): ?string
  {
    if (!$str) return null;
    $ts = strtotime($str);
    return $ts ? date('c', $ts) : null;
  }
}

if (!function_exists('build_rss')) {
  function build_rss(string $title, string $link, string $desc, array $items): string
  {
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
        if ($ts) {
          $i->addChild('pubDate', date(DATE_RSS, $ts));
        }
      }
      $guid = $it['link'] ?? md5(($it['title'] ?? '') . ($it['description'] ?? ''));
      $i->addChild('guid', xml_safe($guid));
    }

    return $xml->asXML();
  }
}

if (!function_exists('build_atom')) {
  function build_atom(string $title, string $link, string $desc, array $items): string
  {
    $xml = new SimpleXMLElement('<feed xmlns="http://www.w3.org/2005/Atom"/>');
    $xml->addChild('title', xml_safe($title));
    $xml->addChild('updated', date(DATE_ATOM));

    $alink = $xml->addChild('link');
    $alink->addAttribute('rel', 'alternate');
    $alink->addAttribute('href', $link);

    $xml->addChild('id', 'urn:uuid:' . uuid_v5('feed:' . md5($link . $title)));

    foreach ($items as $it) {
      $e = $xml->addChild('entry');
      $e->addChild('title', xml_safe($it['title'] ?? 'Untitled'));
      $el = $e->addChild('link');
      $el->addAttribute('rel', 'alternate');
      $el->addAttribute('href', $it['link'] ?? '');
      $e->addChild('id', 'urn:uuid:' . uuid_v5('item:' . md5(($it['link'] ?? '') . ($it['title'] ?? ''))));
      $u = !empty($it['date']) && strtotime($it['date']) ? date(DATE_ATOM, strtotime($it['date'])) : date(DATE_ATOM);
      $e->addChild('updated', $u);
      $e->addChild('summary', xml_safe($it['description'] ?? ''));
    }

    return $xml->asXML();
  }
}

if (!function_exists('build_jsonfeed')) {
  function build_jsonfeed(string $title, string $link, string $desc, array $items, string $feedUrl): string
  {
    $feed = [
      'version'       => 'https://jsonfeed.org/version/1',
      'title'         => $title,
      'home_page_url' => $link,
      'feed_url'      => $feedUrl,
      'description'   => $desc,
      'items'         => [],
    ];

    foreach ($items as $it) {
      $id  = $it['link'] ?? md5(($it['title'] ?? '') . ($it['description'] ?? ''));
      $url = $it['link'] ?? '';
      $ttl = $it['title'] ?? 'Untitled';
      $body = trim((string)($it['description'] ?? ''));
      if ($body === '') {
        $body = $ttl ?: $url;
      }

      $item = ['id' => $id, 'url' => $url, 'title' => $ttl];
      if ($body !== strip_tags($body)) {
        $item['content_html'] = $body;
      } else {
        $item['content_text'] = $body;
      }

      if (!empty($it['date'])) {
        $iso = to_rfc3339($it['date']);
        if ($iso) {
          $item['date_published'] = $iso;
        }
      }
      if (!empty($it['description'])) {
        $plain = trim(strip_tags($it['description']));
        if ($plain !== '' && $plain !== $body) {
          $item['summary'] = mb_strlen($plain) > 220
            ? mb_substr($plain, 0, 219) . '…'
            : $plain;
        }
      }

      $feed['items'][] = $item;
    }

    return json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }
}

if (!function_exists('detect_feed_format_and_ext')) {
  function detect_feed_format_and_ext(string $body, array $headersAssoc, string $srcUrl = ''): array
  {
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
}
