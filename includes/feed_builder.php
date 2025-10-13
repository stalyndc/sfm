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

if (!function_exists('sfm_add_cdata')) {
  function sfm_add_cdata(SimpleXMLElement $node, string $value): void
  {
    $dom = dom_import_simplexml($node);
    $owner = $dom->ownerDocument;
    if (!$owner) {
      return;
    }
    $dom->appendChild($owner->createCDATASection($value));
  }
}

if (!function_exists('sfm_is_http_url')) {
  function sfm_is_http_url(string $url): bool
  {
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
  }
}

if (!function_exists('sfm_clean_content_html')) {
  function sfm_clean_content_html(string $html): string
  {
    if ($html === '') return '';

    $patterns = [
      '~<svg\b[^>]*>.*?</svg>~is',
      '~<symbol\b[^>]*>.*?</symbol>~is',
      '~<path\b[^>]*>~is',
      '~</svg>~i',
      '~</symbol>~i',
      '~<script\b[^>]*>.*?</script>~is',
      '~<style\b[^>]*>.*?</style>~is',
    ];
    foreach ($patterns as $pat) {
      $html = preg_replace($pat, '', $html) ?? $html;
    }

    $allowed = '<p><br><ul><ol><li><strong><em><b><i><a><blockquote><pre><code><img><figure><figcaption><hr><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><td><th><span><div><section>'; 
    $clean = strip_tags($html, $allowed);
    $clean = str_replace(["\r\n", "\r"], "\n", $clean);
    $clean = str_replace("\xC2\xA0", ' ', $clean); // nbsp
    $clean = trim($clean);
    if ($clean === '') {
      return '';
    }

    if (preg_match('/<[^>]+>/', $clean) !== 1) {
      $text = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
      $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
      $paragraphs = array_map(static function ($p) {
        $p = trim($p);
        if ($p === '') {
          return '';
        }
        $p = htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p = preg_replace('/\n/', '<br>', $p) ?? $p;
        return '<p>' . $p . '</p>';
      }, $paragraphs);
      $paragraphs = array_filter($paragraphs, static function ($p) {
        return $p !== '';
      });
      return implode('\n', $paragraphs);
    }

    return preg_replace('/\n{3,}/', "\n\n", $clean) ?? $clean;
  }
}

if (!function_exists('sfm_feed_plain_text')) {
  function sfm_feed_plain_text(string $html, int $limit = 400): string
  {
    $text = trim(strip_tags($html));
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
      return '';
    }
    if ($limit > 0 && function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($text) > $limit) {
        return mb_substr($text, 0, $limit - 1) . '…';
      }
      return $text;
    }
    if ($limit > 0 && strlen($text) > $limit) {
      return substr($text, 0, $limit - 1) . '…';
    }
    return $text;
  }
}

if (!function_exists('sfm_feed_item_content_html')) {
  function sfm_feed_item_content_html(array $item): string
  {
    $candidates = [
      $item['content_html'] ?? '',
      $item['description'] ?? '',
    ];

    foreach ($candidates as $candidate) {
      $candidate = trim((string)$candidate);
      if ($candidate === '') {
        continue;
      }
      $clean = sfm_clean_content_html($candidate);
      if ($clean !== '') {
        return $clean;
      }
    }

    return '';
  }
}

if (!function_exists('sfm_guess_mime_from_url')) {
  function sfm_guess_mime_from_url(string $url): string
  {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $ext  = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
      case 'png': return 'image/png';
      case 'gif': return 'image/gif';
      case 'webp': return 'image/webp';
      case 'svg': return 'image/svg+xml';
      case 'avif': return 'image/avif';
      default:     return 'image/jpeg';
    }
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
    $xml = new SimpleXMLElement('<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:media="http://search.yahoo.com/mrss/" xmlns:dc="http://purl.org/dc/elements/1.1/"/>');
    $channel = $xml->addChild('channel');
    $channel->addChild('title', xml_safe($title));
    $channel->addChild('link', xml_safe($link));
    $channel->addChild('description', xml_safe($desc));
    $channel->addChild('lastBuildDate', date(DATE_RSS));

    foreach ($items as $it) {
      $i = $channel->addChild('item');
      $i->addChild('title', xml_safe($it['title'] ?? 'Untitled'));
      $i->addChild('link', xml_safe($it['link'] ?? ''));
      $rawSummary = '';
      foreach (['description', 'content_html', 'title', 'link'] as $key) {
        if (!empty($it[$key])) {
          $rawSummary = (string)$it[$key];
          if (trim($rawSummary) !== '') {
            break;
          }
        }
      }
      $descPlain = sfm_feed_plain_text($rawSummary, 400);
      if ($descPlain === '') {
        $descPlain = 'Feed item';
      }
      $i->addChild('description', xml_safe($descPlain));

      $contentHtml = sfm_feed_item_content_html($it);
      if ($contentHtml !== '') {
        $encoded = $i->addChild('content:encoded', null, 'http://purl.org/rss/1.0/modules/content/');
        sfm_add_cdata($encoded, $contentHtml);
      }
      if (!empty($it['date'])) {
        $ts = strtotime($it['date']);
        if ($ts) {
          $i->addChild('pubDate', date(DATE_RSS, $ts));
        }
      }
      $guid = $it['link'] ?? md5(($it['title'] ?? '') . ($it['description'] ?? ''));
      $i->addChild('guid', xml_safe($guid));

      if (!empty($it['author'])) {
        $i->addChild('dc:creator', xml_safe((string)$it['author']), 'http://purl.org/dc/elements/1.1/');
      }

      if (!empty($it['image']) && sfm_is_http_url((string)$it['image'])) {
        $media = $i->addChild('media:content', null, 'http://search.yahoo.com/mrss/');
        $media->addAttribute('url', (string)$it['image']);
        $media->addAttribute('medium', 'image');
        $media->addAttribute('type', sfm_guess_mime_from_url((string)$it['image']));
      }

      if (!empty($it['tags']) && is_array($it['tags'])) {
        foreach ($it['tags'] as $tag) {
          $tag = trim((string)$tag);
          if ($tag === '') continue;
          $i->addChild('category', xml_safe($tag));
        }
      }
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
      $rawSummary = '';
      foreach (['description','content_html','title','link'] as $key) {
        if (!empty($it[$key])) {
          $rawSummary = (string)$it[$key];
          if (trim($rawSummary) !== '') {
            break;
          }
        }
      }
      $e->addChild('summary', xml_safe(sfm_feed_plain_text($rawSummary, 400)));

      $contentHtml = sfm_feed_item_content_html($it);
      if ($contentHtml !== '') {
        $contentNode = $e->addChild('content');
        $contentNode->addAttribute('type', 'html');
        sfm_add_cdata($contentNode, $contentHtml);
      }

      if (!empty($it['author'])) {
        $author = $e->addChild('author');
        $author->addChild('name', xml_safe((string)$it['author']));
      }
      if (!empty($it['image']) && sfm_is_http_url((string)$it['image'])) {
        $enclosure = $e->addChild('link');
        $enclosure->addAttribute('rel', 'enclosure');
        $enclosure->addAttribute('href', (string)$it['image']);
        $enclosure->addAttribute('type', sfm_guess_mime_from_url((string)$it['image']));
      }
      if (!empty($it['tags']) && is_array($it['tags'])) {
        foreach ($it['tags'] as $tag) {
          $tag = trim((string)$tag);
          if ($tag === '') continue;
          $cat = $e->addChild('category');
          $cat->addAttribute('term', $tag);
        }
      }
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
      $item = ['id' => $id, 'url' => $url, 'title' => $ttl];

      $contentHtml = sfm_feed_item_content_html($it);
      if ($contentHtml !== '') {
        $item['content_html'] = $contentHtml;
      }

      $summarySource = $contentHtml;
      if ($summarySource === '') {
        $summarySource = (string)($it['description'] ?? '');
      }
      if ($summarySource === '') {
        foreach (['title', 'link'] as $key) {
          if (empty($it[$key])) {
            continue;
          }
          $candidate = trim((string)$it[$key]);
          if ($candidate === '') {
            continue;
          }
          $summarySource = $candidate;
          break;
        }
      }

      $summaryText = sfm_feed_plain_text($summarySource, 400);
      if ($summaryText === '') {
        $summaryText = $ttl ?: $url;
      }
      $item['content_text'] = $summaryText;
      if ($summaryText !== '') {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
          $item['summary'] = mb_strlen($summaryText) > 220 ? mb_substr($summaryText, 0, 219) . '…' : $summaryText;
        } else {
          $item['summary'] = strlen($summaryText) > 220 ? substr($summaryText, 0, 219) . '…' : $summaryText;
        }
      }

      if (!empty($it['date'])) {
        $iso = to_rfc3339($it['date']);
        if ($iso) {
          $item['date_published'] = $iso;
        }
      }

      if (!empty($it['author'])) {
        $authorName = trim((string)$it['author']);
        if ($authorName !== '') {
          $authorObj = ['name' => $authorName];
          $item['authors'] = [$authorObj];
          $item['author']  = $authorObj;
        }
      }

      if (!empty($it['image']) && sfm_is_http_url((string)$it['image'])) {
        $item['image'] = $it['image'];
      }

      if (!empty($it['tags']) && is_array($it['tags'])) {
        $tags = array_map(function ($tag) {
          return trim((string)$tag);
        }, $it['tags']);
        $tags = array_values(array_filter($tags, static function (string $val): bool {
          return $val !== '';
        }));
        if ($tags) {
          $item['tags'] = $tags;
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
    if (stripos($head, '<feed') !== false && stripos($head, 'www.w3.org/2005/atom') !== false) return ['atom', 'xml'];
    if (stripos($head, '<rss') !== false) return ['rss', 'xml'];

    $trimmed = ltrim($body);
    if ($trimmed !== '' && $trimmed[0] === '<') {
      if (stripos($trimmed, '<?xml') === 0) {
        $afterDecl = preg_replace('/^<\?xml[^>]*>\s*/i', '', $trimmed, 1);
        $trimmed = is_string($afterDecl) ? ltrim($afterDecl) : $trimmed;
      }

      if ($trimmed !== '' && $trimmed[0] === '<' && preg_match('/^<([a-z0-9:_-]+)/i', $trimmed, $match)) {
        $root = strtolower($match[1]);
        $local = strpos($root, ':') !== false ? substr($root, strpos($root, ':') + 1) : $root;
        if ($local === 'feed') {
          $snippet = substr($trimmed, 0, 1500);
          if (preg_match('/xmlns(:[a-z0-9]+)?="[^"]*atom/i', $snippet)) {
            return ['atom', 'xml'];
          }
        }
        if ($local === 'rss' || $local === 'rdf') {
          return ['rss', 'xml'];
        }
      }
    }

    if ($srcUrl) {
      $ext = strtolower(pathinfo(parse_url($srcUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
      if ($ext === 'json') return ['jsonfeed', 'json'];
      if ($ext === 'xml')  return ['rss', 'xml'];
    }

    return ['rss', 'xml'];
  }
}

if (!function_exists('sfm_normalize_feed')) {
  /**
   * Attempt lightweight normalization of known third-party feeds.
   * Returns null when no changes are performed.
   *
   * @return array{body:string,format:string,ext:string,note:string}|null
   */
  function sfm_normalize_feed(string $body, ?string $detectedFormat, string $sourceUrl): ?array
  {
    $trimmed = ltrim($body);

    $isJsonCandidate = ($detectedFormat === 'jsonfeed');
    if (!$isJsonCandidate && $trimmed !== '') {
      $first = $trimmed[0];
      if ($first === '{' || $first === '[') {
        $isJsonCandidate = true;
      }
    }

    if ($isJsonCandidate) {
      $data = json_decode($body, true);
      if (is_array($data)) {
        $changed = false;
        if (!isset($data['items']) || !is_array($data['items'])) {
          $data['items'] = [];
          $changed = true;
        }

        if (empty($data['items']) && stripos($sourceUrl, 'news.google.com/topics/') !== false) {
          $rssUrl = preg_replace('/\/topics\//i', '/rss/topics/', $sourceUrl, 1, $replaced);
          if ($replaced > 0) {
            $fallback = @file_get_contents($rssUrl);
            if (is_string($fallback) && $fallback !== '') {
              return [
                'body'   => $fallback,
                'format' => 'rss',
                'ext'    => 'xml',
                'note'   => 'google topics rss fallback',
              ];
            }
          }
        }

        if (!isset($data['version']) || trim((string)$data['version']) === '') {
          $data['version'] = 'https://jsonfeed.org/version/1';
          $changed = true;
        }

        if ($changed) {
          $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
          if (is_string($json)) {
            return [
              'body'   => $json,
              'format' => 'jsonfeed',
              'ext'    => 'json',
              'note'   => 'jsonfeed normalized (added version)',
            ];
          }
        }
      }
    }

    $isYoutube = stripos($sourceUrl, 'youtube.com') !== false
      || stripos($body, 'xmlns:yt="http://www.youtube.com/xml/schemas/2015"') !== false
      || stripos($body, 'yt:channelId') !== false;
    if (!$isYoutube) {
      return null;
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadXML($body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$dom->documentElement) {
      return null;
    }

    $rootName = strtolower($dom->documentElement->localName ?? '');
    if ($rootName !== 'feed') {
      return null;
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xp->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
    $xp->registerNamespace('media', 'http://search.yahoo.com/mrss/');

    if ((int)$xp->evaluate('count(/atom:feed/yt:channelId)') === 0) {
      return null;
    }

    $channelTitle = trim((string)$xp->evaluate('string(/atom:feed/atom:title)'));
    $channelLink = trim((string)$xp->evaluate('string(/atom:feed/atom:link[@rel="alternate"][1]/@href)'));
    if (!isset($channelLink[0])) {
      $channelLink = trim((string)$xp->evaluate('string(/atom:feed/atom:link[1]/@href)'));
    }
    if (!isset($channelLink[0])) {
      $channelLink = $sourceUrl;
    }
    $channelDesc = trim((string)$xp->evaluate('string(/atom:feed/atom:subtitle)'));
    if (!isset($channelDesc[0])) {
      $channelDesc = 'Videos fetched from YouTube.';
    }
    if (!isset($channelTitle[0])) {
      $channelTitle = 'YouTube Channel';
    }

    $entries = $xp->query('/atom:feed/atom:entry');
    if (!$entries || $entries->length === 0) {
      return null;
    }

    $items = [];
    foreach ($entries as $entry) {
      if (!$entry instanceof DOMElement) {
        continue;
      }
      $title = trim((string)$xp->evaluate('string(atom:title)', $entry));
      $linkHref = trim((string)$xp->evaluate('string((atom:link[@rel="alternate"] | atom:link[not(@rel)])[1]/@href)', $entry));
      if (!isset($linkHref[0])) {
        $videoId = trim((string)$xp->evaluate('string(yt:videoId)', $entry));
        if (strlen($videoId) > 0) {
          $linkHref = 'https://www.youtube.com/watch?v=' . $videoId;
        }
      }
      if (!isset($linkHref[0]) && !isset($title[0])) {
        continue;
      }

      $desc = trim((string)$xp->evaluate('string(media:group/media:description)', $entry));
      if (!isset($desc[0])) {
        $desc = trim((string)$xp->evaluate('string(atom:summary)', $entry));
      }
      $published = trim((string)$xp->evaluate('string(atom:published)', $entry));
      if (!isset($published[0])) {
        $published = trim((string)$xp->evaluate('string(atom:updated)', $entry));
      }
      $image = trim((string)$xp->evaluate('string(media:group/media:thumbnail[1]/@url)', $entry));
      $author = trim((string)$xp->evaluate('string(atom:author/atom:name)', $entry));

      if (!isset($title[0])) {
        $title = !isset($linkHref[0]) ? 'Video' : $linkHref;
      }
      if (!isset($linkHref[0])) {
        $linkHref = $channelLink;
      }
      $authorName = !isset($author[0]) ? null : $author;
      $imageUrl = !isset($image[0]) ? null : $image;

      $items[] = [
        'title'       => $title,
        'link'        => $linkHref,
        'description' => $desc,
        'date'        => $published,
        'author'      => $authorName,
        'image'       => $imageUrl,
      ];
    }

    if (!$items) {
      return null;
    }

    $rss = build_rss($channelTitle, $channelLink, $channelDesc, $items);
    if ($rss === '') {
      return null;
    }

    return [
      'body'   => $rss,
      'format' => 'rss',
      'ext'    => 'xml',
      'note'   => 'youtube normalized',
    ];
  }
}
