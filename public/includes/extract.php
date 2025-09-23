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
    if ($base) $href = trim($base->getAttribute('href'));

    if ($href === '') {
        $canon = $xp->query('//link[@rel="canonical"][@href]')->item(0);
        if ($canon) $href = trim($canon->getAttribute('href'));
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
                            'title'       => sfm_neat_text($tit, 220),
                            'link'        => $href,
                            'description' => sfm_neat_text($desc, 400),
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
                        'title'       => sfm_neat_text($tit, 220),
                        'link'        => $href,
                        'description' => sfm_neat_text($des, 400),
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
function sfm_items_from_dom(DOMXPath $xp, string $baseUrl, int $limit, array $seed = []): array {
    $items = $seed;
    $seen  = [];

    foreach ($seed as $it) {
        $seen[ md5(($it['link'] ?? '') . '|' . sfm_title_key($it['title'] ?? '')) ] = true;
    }

    // Common containers that hold links to stories
    $queries = [
        '//article[.//a[@href]]//a[@href]',
        '//*[contains(@class,"card") or contains(@class,"story") or contains(@class,"item") or contains(@class,"post")]//a[@href]',
        '//h1//a[@href] | //h2//a[@href] | //h3//a[@href]',
        '//li//a[@href]',
    ];

    foreach ($queries as $q) {
        $nodes = $xp->query($q);
        if (!$nodes || !$nodes->length) continue;

        foreach ($nodes as $a) {
            /** @var DOMElement $a */
            $title = sfm_neat_text($a->textContent, 200);
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
function sfm_extract_items(string $html, string $sourceUrl, int $limit = 10): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp  = new DOMXPath($doc);

    $base = sfm_base_from_html($html, $sourceUrl);

    // 1) Prefer JSON-LD when present (cleaner titles/links/dates)
    $items = sfm_items_from_jsonld($xp, $base, $limit);
    if (count($items) >= $limit) return $items;

    // 2) Fall back to DOM heuristics to reach $limit
    $items = sfm_items_from_dom($xp, $base, $limit, $items);

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
