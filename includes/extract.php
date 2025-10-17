<?php
/**
 * includes/extract.php
 *
 * Centralized “smart extraction” helpers for SimpleFeedMaker.
 * - Finds official (owner-published) feeds advertised on a page
 *   via <link rel="alternate" type="…">.
 * - Extracts likely article items from a listing page using:
 *     1) JSON-LD (ItemList, Article/BlogPosting in @graph or arrays)
 *     2) DOM heuristics (articles/cards/headings/lists)
 *     3) Gentle de-duplication and trimming
 *
 * All functions are pure helpers — no I/O here.
 * Keep the return shape stable so generate.php can rely on it.
 *
 * Item shape (array):
 *   [
 *     'title'       => string,
 *     'link'        => string,
 *     'description' => string,   // may be empty
 *     'date'        => string,   // ISO-ish string; generate.php will normalize
 *   ]
 */

declare(strict_types=1);

// ---------------------------
// Small utilities
// ---------------------------

/** Trim, collapse whitespace, and hard-cap length. */
function sfm_neat_text(?string $s, int $max = 500): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/u', ' ', $s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max - 1) . '…';
    return $s;
}

if (!function_exists('sfm_clean_text_field')) {
    /** Strip inline scripts/styles, decode entities, and normalize to tidy text. */
    function sfm_clean_text_field(?string $value, int $max = 500): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '<') !== false) {
            $value = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $value) ?? $value;
            $value = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $value) ?? $value;
            $value = preg_replace('~<noscript\b[^>]*>.*?</noscript>~is', '', $value) ?? $value;
            $value = strip_tags($value);
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($decoded !== '') {
            $value = $decoded;
        }

        return sfm_neat_text($value, $max);
    }
}

/** Lowercase + collapse spaces for duplicate detection. */
function sfm_title_key(string $s): string {
    return preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
}

/** Resolve a possibly-relative URL against a base. */
function sfm_abs_url(string $href, string $base): string {
    $href = trim($href);
    if ($href === '') return '';
    if (parse_url($href, PHP_URL_SCHEME)) return $href; // already absolute

    $bp = parse_url($base);
    if (!$bp) return $href;

    $scheme = ($bp['scheme'] ?? 'https') . '://';
    $host   = $bp['host'] ?? '';
    $port   = isset($bp['port']) ? ':' . $bp['port'] : '';

    if (strpos($href, '/') === 0) return $scheme . $host . $port . $href;

    $path = $bp['path'] ?? '/';
    if ($path === '' || substr($path, -1) !== '/') {
        $path = preg_replace('#/[^/]*$#', '/', $path);
        if ($path === null || $path === '') $path = '/';
    }
    $abs = $scheme . $host . $port . $path . $href;
    $abs = preg_replace('#/\.(/|$)#', '/', $abs);
    while (strpos($abs, '../') !== false) {
        $abs = preg_replace('#/[^/]+/\.\./#', '/', $abs, 1);
        if ($abs === null) break;
    }
    return $abs;
}

/** Extract a good base URL for resolving relatives (from <base> or canonical). */
function sfm_base_from_html(string $html, string $sourceUrl): string {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp = new DOMXPath($doc);

    $href = '';
    $base = $xp->query('//base[@href]')->item(0);
    if ($base instanceof DOMElement) $href = trim($base->getAttribute('href'));

    if ($href === '') {
        $canon = $xp->query('//link[@rel="canonical"][@href]')->item(0);
        if ($canon instanceof DOMElement) $href = trim($canon->getAttribute('href'));
    }
    return $href !== '' ? $href : $sourceUrl;
}

/** Rough check that a URL looks like an article permalink. */
function sfm_looks_like_article(string $href): bool {
    $p = parse_url($href);
    if (!$p || empty($p['path'])) return false;
    $path = $p['path'];

    if (strlen($path) < 10 && substr_count($path, '-') === 0) return false;
    if (preg_match('#/\d{4}/\d{2}/\d{2}/#', $path)) return true;          // /2025/08/30/foo/
    if (substr_count($path, '-') >= 1 && strlen($path) > 20) return true; // some-slug-long-enough
    foreach (['/news/','/tech/','/science/','/review','/blog/','/deals/','/how-to/','/article/'] as $sec) {
        if (strpos($path, $sec) !== false && strlen($path) > 15) return true;
    }
    return false;
}

/** Normalize/trim an ISO date-ish string; returns '' if not usable. */
function sfm_clean_date(?string $s): string {
    if (!$s) return '';
    $s = trim($s);
    if ($s === '') return '';
    // Try strtotime and keep original (generate.php will format to RFC3339/RSS)
    $ts = strtotime($s);
    return $ts ? date('c', $ts) : $s;
}

/** Convert Tumblr NPF content blocks into basic HTML. */
function sfm_tumblr_content_to_html($blocks): string
{
    if (!is_array($blocks) || !$blocks) {
        return '';
    }

    $parts = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = strtolower((string)($block['type'] ?? ''));
        if ($type === 'text') {
            $text = trim((string)($block['text'] ?? ''));
            if ($text !== '') {
                $parts[] = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        } elseif ($type === 'image') {
            $url = '';
            foreach ((array) ($block['media'] ?? []) as $media) {
                $media = (array) $media;
                if (!empty($media['url'])) {
                    $url = (string) $media['url'];
                    break;
                }
            }
            if ($url !== '') {
                $parts[] = '<p><img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt=""></p>';
            }
        } elseif ($type === 'link') {
            $url = (string)($block['url'] ?? $block['href'] ?? '');
            if ($url !== '') {
                $title = sfm_neat_text($block['title'] ?? ($block['display_url'] ?? $url), 200);
                if ($title === '') {
                    $title = $url;
                }
                $parts[] = '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
            }
        } elseif ($type === 'quote') {
            $text = trim((string)($block['text'] ?? ''));
            if ($text !== '') {
                $parts[] = '<blockquote>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</blockquote>';
            }
        } elseif (in_array($type, ['audio', 'video', 'poll'], true)) {
            $url = (string)($block['url'] ?? '');
            if ($url !== '') {
                $parts[] = '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
            }
        }
    }

    return implode("\n", $parts);
}

/** Get a reasonable text snippet from Tumblr post data. */
function sfm_tumblr_first_text(array $post): string
{
    if (!empty($post['content']) && is_array($post['content'])) {
        foreach ($post['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (strtolower((string)($block['type'] ?? '')) === 'text') {
                $text = sfm_neat_text($block['text'] ?? '', 220);
                if ($text !== '') {
                    return $text;
                }
            }
        }
    }

    if (!empty($post['trail']) && is_array($post['trail'])) {
        foreach ($post['trail'] as $trail) {
            if (!is_array($trail) || empty($trail['content']) || !is_array($trail['content'])) {
                continue;
            }
            foreach ($trail['content'] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (strtolower((string)($block['type'] ?? '')) === 'text') {
                    $text = sfm_neat_text($block['text'] ?? '', 220);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }
    }

    if (!empty($post['title'])) {
        $text = sfm_neat_text($post['title'], 220);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

/** Convert a Tumblr timeline entry into a feed item. */
function sfm_tumblr_entry_to_item(array $entry, string $sourceUrl): ?array
{
    $post = $entry['post'] ?? $entry;
    if (!is_array($post)) {
        return null;
    }

    $objectType = strtolower((string)($entry['objectType'] ?? $post['objectType'] ?? ''));
    if ($objectType !== '' && $objectType !== 'post') {
        return null;
    }

    $url = '';
    foreach (['postUrl', 'post_url', 'permalink_url', 'shortUrl', 'short_url'] as $field) {
        if (!empty($post[$field]) && is_string($post[$field])) {
            $url = (string) $post[$field];
            break;
        }
    }
    if ($url === '' && !empty($post['legacy']['url']) && is_string($post['legacy']['url'])) {
        $url = (string) $post['legacy']['url'];
    }
    if ($url === '') {
        return null;
    }
    if (!parse_url($url, PHP_URL_SCHEME)) {
        $url = sfm_abs_url($url, $sourceUrl);
    }

    $title = sfm_neat_text($post['summary'] ?? '', 220);
    if ($title === '') {
        $title = sfm_tumblr_first_text($post);
    }
    if ($title === '' && !empty($post['blogName'])) {
        $title = 'Post from ' . sfm_neat_text($post['blogName'], 180);
    }
    if ($title === '') {
        $title = $url;
    }

    $description = sfm_tumblr_content_to_html($post['content'] ?? []);
    if ($description === '' && !empty($post['summary'])) {
        $description = '<p>' . htmlspecialchars($post['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    $tags = [];
    if (!empty($post['tags']) && is_array($post['tags'])) {
        foreach ($post['tags'] as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = '#' . trim($tag);
            } elseif (is_array($tag) && !empty($tag['name'])) {
                $tags[] = '#' . trim((string) $tag['name']);
            }
        }
    }
    if ($tags) {
        $description .= ($description !== '' ? "\n" : '') . '<p>Tags: ' . htmlspecialchars(implode(' ', $tags), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    $date = '';
    if (!empty($post['timestamp'])) {
        $date = date('c', (int) $post['timestamp']);
    } elseif (!empty($post['date'])) {
        $date = sfm_clean_date($post['date']);
    }

    return [
        'title'       => $title,
        'link'        => $url,
        'description' => $description,
        'date'        => $date,
    ];
}

/** Extract Tumblr search/tag posts via embedded state JSON. */
function sfm_extract_items_from_tumblr_state(DOMXPath $xp, string $sourceUrl, int $limit): array
{
    $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
    if ($host === '') {
        return [];
    }
    if ($host !== 'tumblr.com' && $host !== 'www.tumblr.com' && substr($host, -11) !== '.tumblr.com') {
        return [];
    }

    $script = $xp->query('//script[@id="___INITIAL_STATE___"]')->item(0);
    if (!$script instanceof DOMElement) {
        return [];
    }

    $json = trim($script->textContent ?? '');
    if ($json === '') {
        return [];
    }

    $state = json_decode($json, true);
    if (!is_array($state)) {
        return [];
    }

    $querySets = $state['queries']['queries'] ?? [];
    if (!is_array($querySets) || !$querySets) {
        return [];
    }

    $items = [];
    $seen = [];
    foreach ($querySets as $query) {
        if (!is_array($query)) {
            continue;
        }
        $keyList = $query['queryKey'] ?? [];
        if (!is_array($keyList) || !$keyList) {
            continue;
        }
        $key = strtolower((string) $keyList[0]);
        if (strpos($key, 'timeline') === false) {
            continue;
        }

        $data = $query['state']['data'] ?? null;
        if (!is_array($data) || empty($data['pages']) || !is_array($data['pages'])) {
            continue;
        }

        foreach ($data['pages'] as $page) {
            if (!is_array($page) || empty($page['items']) || !is_array($page['items'])) {
                continue;
            }
            foreach ($page['items'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $item = sfm_tumblr_entry_to_item($entry, $sourceUrl);
                if ($item === null) {
                    continue;
                }
                $linkKey = strtolower((string) ($item['link'] ?? ''));
                if ($linkKey === '' || isset($seen[$linkKey])) {
                    continue;
                }
                $seen[$linkKey] = true;
                $items[] = $item;
                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }
    }

    return $items;
}

// ---------------------------
// Feed autodiscovery
// ---------------------------

/**
 * Discover official feeds via <link rel="alternate" type="…"> in <head>.
 * Returns array of:
 *   [ 'href' => absolute URL, 'type' => mime, 'title' => string ]
 */
function sfm_discover_official_feeds(string $html, string $baseUrl): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp = new DOMXPath($doc);

    $out = [];
    $seen = [];

    // Common feed MIME types (plus a few real-world variants)
    $types = implode('|', [
        'application/rss\+xml',
        'application/atom\+xml',
        'application/json',
        'application/feed\+json',
        'text/xml',
        'application/xml',
    ]);

    $nodes = $xp->query("//link[translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='alternate'][@href][@type]");
    foreach ($nodes as $n) {
        /** @var DOMElement $n */
        $type = trim($n->getAttribute('type'));
        if (!preg_match("#^($types)$#i", $type)) continue;

        $href = sfm_abs_url($n->getAttribute('href'), $baseUrl);
        if ($href === '') continue;

        $key = strtolower($href);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $out[] = [
            'href'  => $href,
            'type'  => strtolower($type),
            'title' => sfm_neat_text($n->getAttribute('title') ?: ''),
        ];
    }
    return $out;
}

// ---------------------------
// JSON-LD extraction
// ---------------------------

/**
 * Extract items from JSON-LD blocks:
 *  - ItemList (itemListElement / item)
 *  - Article / BlogPosting (including in @graph arrays)
 */
function sfm_items_from_jsonld(DOMXPath $xp, string $baseUrl, int $limit): array {
    $items = [];
    $seen  = [];

    $scripts = $xp->query('//script[@type="application/ld+json"]');
    if (!$scripts || !$scripts->length) return $items;

    foreach ($scripts as $s) {
        $raw = trim($s->textContent);
        if ($raw === '') continue;

        $decoded = json_decode($raw, true);
        if (!$decoded) {
            // tolerate trailing commas in sloppy JSON
            $raw2 = preg_replace('/,\s*([}\]])/', '$1', $raw);
            $decoded = json_decode($raw2, true);
        }
        if (!$decoded) continue;

        $queue = [ $decoded ];
        while ($queue) {
            $obj = array_shift($queue);

            if (isset($obj['@graph']) && is_array($obj['@graph'])) {
                foreach ($obj['@graph'] as $g) $queue[] = $g;
                continue;
            }
            if (is_array($obj) && array_keys($obj) === range(0, count($obj)-1)) {
                foreach ($obj as $g) $queue[] = $g;
                continue;
            }

            $type = $obj['@type'] ?? '';
            $type = is_array($type) ? strtolower(implode(',', $type)) : strtolower((string)$type);

            // 1) ItemList
            if ($type === 'itemlist' && !empty($obj['itemListElement'])) {
                foreach ($obj['itemListElement'] as $el) {
                    $link = $el['url'] ?? ($el['item']['url'] ?? null);
                    $tit  = $el['name'] ?? ($el['item']['name'] ?? null);
                    $desc = $el['description'] ?? ($el['item']['description'] ?? '');
                    $dt   = $el['datePublished'] ?? ($el['item']['datePublished'] ?? '');
                    if ($link && $tit) {
                        $href = sfm_abs_url($link, $baseUrl);
                        $k = md5($href);
                        if (isset($seen[$k])) continue;
                        $seen[$k] = true;

                    $items[] = [
                        'title'       => sfm_clean_text_field($tit, 220),
                        'link'        => $href,
                        'description' => sfm_clean_text_field($desc, 400),
                            'date'        => sfm_clean_date($dt),
                        ];
                        if (count($items) >= $limit) return $items;
                    }
                }
                continue;
            }

            // 2) Article-like (Article, NewsArticle, BlogPosting, etc.)
            if ($type !== '' && (strpos($type, 'article') !== false || $type === 'blogposting')) {
                $tit = $obj['headline'] ?? $obj['name'] ?? null;
                $lnk = $obj['url'] ?? ($obj['mainEntityOfPage']['@id'] ?? null);
                $des = $obj['description'] ?? '';
                $dt  = $obj['datePublished'] ?? ($obj['dateModified'] ?? '');
                if ($tit && $lnk) {
                    $href = sfm_abs_url($lnk, $baseUrl);
                    $k = md5($href);
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;

                    $items[] = [
                        'title'       => sfm_clean_text_field($tit, 220),
                        'link'        => $href,
                        'description' => sfm_clean_text_field($des, 400),
                        'date'        => sfm_clean_date($dt),
                    ];
                    if (count($items) >= $limit) return $items;
                }
            }
        }
    }
    return $items;
}

// ---------------------------
// DOM heuristics
// ---------------------------

/**
 * Use structural hints to find article links & titles when JSON-LD is absent/weak.
 */
function sfm_items_from_dom(
    DOMXPath $xp,
    string $baseUrl,
    int $limit,
    array $seed = [],
    array $options = [],
    ?array &$debug = null
): array {
    $items = $seed;
    $seen  = [];

    foreach ($seed as $it) {
        $seen[ md5(($it['link'] ?? '') . '|' . sfm_title_key($it['title'] ?? '')) ] = true;
    }

    $domAdds = 0;

    // Common containers that hold links to stories
    $queries = [
        '//article[.//a[@href]]//a[@href]',
        '//*[contains(@class,"card") or contains(@class,"story") or contains(@class,"item") or contains(@class,"post")]//a[@href]',
        '//a[contains(@class,"gnt_m_he") or contains(@class,"gnt_m_flm_a") or contains(@class,"gnt_m_th_a") or contains(@class,"gnt_em_gl")][@href]',
        '//h1//a[@href] | //h2//a[@href] | //h3//a[@href]',
        '//li//a[@href]',
    ];

    foreach ($queries as $q) {
        $nodes = $xp->query($q);
        if (!$nodes || !$nodes->length) continue;

        foreach ($nodes as $a) {
            /** @var DOMElement $a */
            $title = sfm_clean_text_field($a->textContent, 200);
            if ($title === '' || mb_strlen($title) < 6) continue;

            $href = trim($a->getAttribute('href'));
            if ($href === '' || stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) continue;

            $href = sfm_abs_url($href, $baseUrl);
            if (!sfm_looks_like_article($href)) continue;

            $key = md5($href . '|' . sfm_title_key($title));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $items[] = [
                'title'       => $title,
                'link'        => $href,
                'description' => '',
                'date'        => '',
            ];
            $domAdds++;
            if (count($items) >= $limit) break 2;
        }
    }

    // Light post-process: if we got many items with empty descriptions, try to
    // fill from sibling snippets (very conservative).
    if (!empty($items)) {
        foreach ($items as &$it) {
            if ($it['description'] !== '') continue;

            // Try to find a nearby <p> or small summary for this link (best-effort).
            // (This is intentionally minimal — heavy scraping can be added later.)
            // No DOM traversal here to keep performance predictable.
        }
        unset($it);
    }

    if ($debug !== null) {
        $debug['dom_count'] = ($debug['dom_count'] ?? 0) + $domAdds;
    }

    return $items;
}

function sfm_items_from_custom_selector(
    DOMXPath $xp,
    string $baseUrl,
    int $limit,
    array $options,
    array $seed = [],
    ?array &$debug = null
): array {
    if (empty($options['item_selector_xpath'])) {
        if ($debug !== null && isset($options['item_selector'])) {
            $debug['custom_selector'] = $options['item_selector'];
            $debug['custom_selector_matches'] = null;
        }
        return $seed;
    }

    $items = $seed;
    $seen  = [];

    foreach ($seed as $it) {
        $seen[ md5(($it['link'] ?? '') . '|' . sfm_title_key($it['title'] ?? '')) ] = true;
    }

    $nodes = $xp->query($options['item_selector_xpath']);
    $matches = $nodes ? $nodes->length : 0;
    if ($debug !== null) {
        $debug['custom_selector'] = $options['item_selector'] ?? $options['item_selector_xpath'];
        $debug['custom_selector_matches'] = $matches;
    }

    if (!$nodes || !$nodes->length) {
        return $items;
    }

    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $linkEl = $node->nodeName === 'a'
            ? $node
            : $xp->query('.//a[@href]', $node)->item(0);

        if (!$linkEl instanceof DOMElement) {
            continue;
        }

        $href = trim($linkEl->getAttribute('href'));
        if ($href === '' || stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) {
            continue;
        }

        $href = sfm_abs_url($href, $baseUrl);
        if ($href === '') {
            continue;
        }

        $title = sfm_clean_text_field($linkEl->textContent ?? '', 220);
        if (isset($options['title_selector_xpath'])) {
            $titleNode = $xp->query($options['title_selector_xpath'], $node)->item(0);
            if ($titleNode) {
                $candidate = sfm_clean_text_field($titleNode->textContent ?? '', 220);
                if ($candidate !== '') {
                    $title = $candidate;
                }
            }
        }
        if ($title === '') {
            $title = $href;
        }

        $summary = '';
        if (isset($options['summary_selector_xpath'])) {
            $summaryNode = $xp->query($options['summary_selector_xpath'], $node)->item(0);
            if ($summaryNode) {
                $summary = sfm_clean_text_field($summaryNode->textContent ?? '', 400);
            }
        }

        $date = '';
        $timeNode = $xp->query('.//time[@datetime]', $node)->item(0);
        if ($timeNode instanceof DOMElement) {
            $date = sfm_clean_date($timeNode->getAttribute('datetime'));
        }

        $key = md5($href . '|' . sfm_title_key($title));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $items[] = [
            'title'       => $title,
            'link'        => $href,
            'description' => $summary,
            'date'        => $date,
        ];

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

// ---------------------------
// Public API (for generate.php)
// ---------------------------

/**
 * High-level: given raw HTML + the page URL, return best-effort items.
 *
 * @param string $html      Raw HTML bytes from the source page
 * @param string $sourceUrl The URL that was fetched (used to resolve relatives)
 * @param int    $limit     Max number of items
 * @return array            Array of item arrays (see top-of-file shape)
 */
function sfm_extract_items(string $html, string $sourceUrl, int $limit = 10, array $options = [], ?array &$debug = null): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp  = new DOMXPath($doc);

    $base = sfm_base_from_html($html, $sourceUrl);

    // 1) Prefer JSON-LD when present (cleaner titles/links/dates)
    $items = sfm_items_from_jsonld($xp, $base, $limit);
    if ($debug !== null) {
        $debug['jsonld_count'] = count($items);
    }

    $existingLinks = [];
    foreach ($items as $it) {
        $link = strtolower((string) ($it['link'] ?? ''));
        if ($link !== '') {
            $existingLinks[$link] = true;
        }
    }

    // Tumblr embeds full post data in ___INITIAL_STATE___ — prefer that when available.
    $tumblrItems = sfm_extract_items_from_tumblr_state($xp, $sourceUrl, $limit);
    if ($debug !== null) {
        $debug['tumblr_state_count'] = count($tumblrItems);
    }
    if ($tumblrItems) {
        foreach ($tumblrItems as $tumblrItem) {
            $link = strtolower((string) ($tumblrItem['link'] ?? ''));
            if ($link === '' || isset($existingLinks[$link])) {
                continue;
            }
            $existingLinks[$link] = true;
            $items[] = $tumblrItem;
            if (count($items) >= $limit) {
                break;
            }
        }
    }

    // 2) Optional custom selector override
    if (count($items) < $limit) {
        $items = sfm_items_from_custom_selector($xp, $base, $limit, $options, $items, $debug);
    }

    // 3) Fall back to DOM heuristics to reach $limit
    if (count($items) < $limit) {
        $items = sfm_items_from_dom($xp, $base, $limit, $items, $options, $debug);
    }

    // Gentle uniq by link (keep first occurrence)
    $seen = [];
    $uniq = [];
    foreach ($items as $it) {
        $href = strtolower($it['link'] ?? '');
        if ($href === '' || isset($seen[$href])) continue;
        $seen[$href] = true;
        $uniq[] = $it;
        if (count($uniq) >= $limit) break;
    }
    return $uniq;
}

function sfm_css_to_xpath(string $css, bool $relative = false): ?string
{
    $css = trim($css);
    if ($css === '') {
        return null;
    }

    $selectors = array_filter(array_map('trim', explode(',', $css)));
    if (!$selectors) {
        return null;
    }

    $paths = [];
    foreach ($selectors as $selector) {
        $path = sfm_css_selector_to_xpath($selector, $relative);
        if ($path === null) {
            return null;
        }
        $paths[] = $path;
    }

    return implode(' | ', $paths);
}

function sfm_css_selector_to_xpath(string $selector, bool $relative): ?string
{
    $selector = trim($selector);
    if ($selector === '') {
        return null;
    }

    $selector = str_replace('>', ' > ', $selector);
    $normalized = preg_replace('/\s+/', ' ', $selector);
    $selector = trim(is_string($normalized) ? $normalized : '');
    $tokens = array_filter(explode(' ', $selector), static function ($token) {
        return $token !== '';
    });
    if (!$tokens) {
        return null;
    }

    $xpath = $relative ? '.' : '';
    $combinator = $relative ? '//' : '//';
    $first = true;

    foreach ($tokens as $token) {
        if ($token === '>') {
            $combinator = '/';
            continue;
        }

        $segment = sfm_css_simple_selector_to_xpath($token);
        if ($segment === null) {
            return null;
        }

        if ($first) {
            $xpath = ($relative ? './/' : '//') . $segment;
            $first = false;
        } else {
            $xpath .= $combinator . $segment;
        }
        $combinator = '//';
    }

    return $xpath;
}

function sfm_css_simple_selector_to_xpath(string $selector): ?string
{
    $selector = trim($selector);
    if ($selector === '') {
        return null;
    }

    $tag = '*';
    $conditions = [];

    if (preg_match('/^[a-z][a-z0-9_-]*/i', $selector, $match)) {
        $tag = strtolower($match[0]);
        $selector = substr($selector, strlen($match[0]));
    }

    while ($selector !== '') {
        $prefix = $selector[0];
        if ($prefix === '#') {
            $selector = substr($selector, 1);
            if (!preg_match('/^[a-zA-Z0-9_-]+/', $selector, $m)) {
                return null;
            }
            $value = $m[0];
            $selector = substr($selector, strlen($value));
            $conditions[] = '@id=' . sfm_xpath_literal($value);
        } elseif ($prefix === '.') {
            $selector = substr($selector, 1);
            if (!preg_match('/^[a-zA-Z0-9_-]+/', $selector, $m)) {
                return null;
            }
            $value = $m[0];
            $selector = substr($selector, strlen($value));
            $conditions[] = 'contains(concat(' . sfm_xpath_literal(' ') . ', normalize-space(@class), ' . sfm_xpath_literal(' ') . '), ' . sfm_xpath_literal(' ' . $value . ' ') . ')';
        } else {
            return null;
        }
    }

    if (!$conditions) {
        return $tag;
    }

    return $tag . '[' . implode(' and ', $conditions) . ']';
}

function sfm_xpath_literal(string $value): string
{
    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }
    if (strpos($value, '"') === false) {
        return '"' . $value . '"';
    }

    $parts = explode("'", $value);
    $pieces = [];
    foreach ($parts as $index => $part) {
        if ($part !== '') {
            $pieces[] = "'" . $part . "'";
        }
        if ($index !== count($parts) - 1) {
            $pieces[] = "\"'\"";
        }
    }

    return 'concat(' . implode(', ', $pieces) . ')';
}

/**
 * Public: find official/owner feeds on the page (RSS/Atom/JSON Feed).
 *
 * @param string $html      Raw HTML
 * @param string $sourceUrl Base URL used to resolve relative @href
 * @return array            Each item: ['href'=>..., 'type'=>..., 'title'=>...]
 */
function sfm_discover_feeds(string $html, string $sourceUrl): array {
    $base = sfm_base_from_html($html, $sourceUrl);
    return sfm_discover_official_feeds($html, $base);
}
