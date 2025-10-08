<?php
/**
 * includes/enrich.php
 *
 * Shared enrichment helpers that fetch article pages to collect metadata
 * such as summaries, publication dates, authors, hero images, and tags.
 */

declare(strict_types=1);

if (!function_exists('sfm_enrich_items_with_article_metadata')) {
    function sfm_enrich_items_with_article_metadata(array $items, int $maxFetch = 5): array
    {
        $fetched = 0;

        foreach ($items as &$item) {
            $needSummary = empty($item['description']);
            $needDate    = empty($item['date']);
            $needContent = empty($item['content_html']);
            $needAuthor  = empty(trim((string)($item['author'] ?? '')));
            $currentImage = $item['image'] ?? '';
            $needImage   = empty($currentImage);
            $existingTags = $item['tags'] ?? [];
            if (!is_array($existingTags)) {
                $existingTags = array_filter([trim((string)$existingTags)]);
            }
            $needTags = empty($existingTags);

            if (!$needSummary && !$needDate && !$needContent && !$needAuthor && !$needImage && !$needTags) {
                continue;
            }

            $link = $item['link'] ?? '';
            if ($link === '' || !sfm_is_http_url($link)) {
                continue;
            }

            if ($fetched >= $maxFetch) {
                break;
            }
            $fetched++;

            /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool} $resp */
            $resp = http_get($link, [
                'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'cache_ttl' => 86400,
                'timeout'   => 12,
                'use_cache' => true,
            ]);

            if (!$resp['ok'] || $resp['body'] === '') {
                continue;
            }

            $finalUrl = $resp['final_url'] !== '' ? (string)$resp['final_url'] : $link;
            $meta     = sfm_parse_article_metadata($resp['body'], $finalUrl);

            if (($needSummary || $needContent) && ($meta['summary'] ?? '') !== '') {
                $item['description'] = $meta['summary'];
            }
            if ($needDate && ($meta['date'] ?? '') !== '') {
                $item['date'] = sfm_clean_date($meta['date']);
            }
            if (!empty($meta['content_html'])) {
                $item['content_html'] = $meta['content_html'];
                if (empty($item['description'])) {
                    $item['description'] = sfm_neat_text(strip_tags($meta['content_html']), 400);
                }
            }
            if ($needAuthor && !empty($meta['author'])) {
                $item['author'] = $meta['author'];
            }
            if ($needImage && !empty($meta['image'])) {
                $item['image'] = $meta['image'];
            }
            if (!empty($meta['tags']) && is_array($meta['tags'])) {
                $combined = array_merge($existingTags, $meta['tags']);
                $combined = array_filter(array_map(function ($tag) {
                    return trim((string)$tag);
                }, $combined), static function (string $val): bool {
                    return $val !== '';
                });
                $combined = array_values(array_unique($combined));
                if ($combined) {
                    $item['tags'] = $combined;
                }
            }
        }
        unset($item);

        return $items;
    }
}

if (!function_exists('sfm_parse_article_metadata')) {
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
        $author      = sfm_detect_article_author($xp);
        $image       = sfm_detect_article_image($xp, $baseUrl);
        $tags        = sfm_collect_article_tags($xp);

        return [
            'summary'      => $summary,
            'date'         => $date,
            'content_html' => $contentHtml,
            'author'       => $author,
            'image'        => $image,
            'tags'         => $tags,
        ];
    }
}

if (!function_exists('sfm_detect_article_author')) {
    function sfm_detect_article_author(DOMXPath $xp): string
    {
        $queries = [
            "//meta[@name='author']/@content",
            "//meta[@property='article:author']/@content",
            "//meta[@property='og:article:author']/@content",
            "//meta[@name='twitter:creator']/@content",
            "//meta[@name='dc.creator']/@content",
            "//meta[@itemprop='author']/@content",
            "//meta[@name='byl']/@content",
        ];

        foreach ($queries as $q) {
            $node = $xp->query($q)->item(0);
            if (!$node) continue;
            $candidate = sfm_clean_author_name($node->nodeValue ?? '');
            if ($candidate !== '' && stripos($candidate, 'http') !== 0) {
                return $candidate;
            }
        }

        $relAuthor = $xp->query("//a[contains(concat(' ', normalize-space(@rel), ' '), ' author ')]")->item(0);
        if ($relAuthor) {
            $candidate = sfm_clean_author_name($relAuthor->textContent ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach ($xp->query("//*[@itemprop='author']") as $node) {
            $candidate = '';
            if ($node->attributes && $node->attributes->getNamedItem('content')) {
                $candidate = $node->attributes->getNamedItem('content')->nodeValue ?? '';
            }
            if ($candidate === '') {
                $nameNode = $xp->query('.//*[@itemprop="name"]', $node)->item(0);
                if ($nameNode) {
                    $candidate = $nameNode->textContent ?? '';
                } else {
                    $candidate = $node->textContent ?? '';
                }
            }
            $candidate = sfm_clean_author_name($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $classQueries = [
            "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'byline')]",
            "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'author')]",
        ];
        foreach ($classQueries as $q) {
            foreach ($xp->query($q) as $node) {
                $candidate = sfm_clean_author_name($node->textContent ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }
}

if (!function_exists('sfm_detect_article_image')) {
    function sfm_detect_article_image(DOMXPath $xp, string $baseUrl): string
    {
        $queries = [
            "//meta[@property='og:image:secure_url']/@content",
            "//meta[@property='og:image:url']/@content",
            "//meta[@property='og:image']/@content",
            "//meta[@name='twitter:image']/@content",
            "//meta[@name='twitter:image:src']/@content",
            "//meta[@itemprop='image']/@content",
            "//link[@rel='image_src']/@href",
        ];

        foreach ($queries as $q) {
            $node = $xp->query($q)->item(0);
            if (!$node) continue;
            $candidate = sfm_normalize_media_url($node->nodeValue ?? '', $baseUrl);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $fallbacks = [
            "(//figure[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'hero') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'featured')]//img[@src])[1]/@src",
            "(//article//img[@src])[1]/@src",
            "(//main//img[@src])[1]/@src",
        ];

        foreach ($fallbacks as $q) {
            $node = $xp->query($q)->item(0);
            if (!$node) continue;
            $candidate = sfm_normalize_media_url($node->nodeValue ?? '', $baseUrl);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('sfm_collect_article_tags')) {
    function sfm_collect_article_tags(DOMXPath $xp): array
    {
        $tags = [];
        $seen = [];
        $push = function (?string $value) use (&$tags, &$seen): void {
            $clean = sfm_neat_text($value ?? '', 80);
            $clean = trim($clean, " \t\n\r\0\x0B•-—|·");
            if ($clean === '') return;
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $tags[] = $clean;
        };

        foreach ($xp->query("//meta[@property='article:tag']/@content") as $node) {
            $push($node->nodeValue ?? '');
        }

        $keywords = $xp->query("//meta[@name='keywords']/@content")->item(0);
        if ($keywords) {
            foreach (preg_split('/[,;]+/', $keywords->nodeValue ?? '') as $part) {
                $push($part);
            }
        }

        foreach ($xp->query("//a[contains(concat(' ', normalize-space(@rel), ' '), ' tag ')]") as $node) {
            $push($node->textContent ?? '');
        }

        $classBased = [
            "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'tag')]/a",
            "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'keyword')]/a",
            "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'tag')]/span",
        ];
        foreach ($classBased as $q) {
            foreach ($xp->query($q) as $node) {
                $push($node->textContent ?? '');
            }
        }

        if (count($tags) > 12) {
            $tags = array_slice($tags, 0, 12);
        }

        return $tags;
    }
}

if (!function_exists('sfm_clean_author_name')) {
    function sfm_clean_author_name(?string $value): string
    {
        $name = sfm_neat_text($value ?? '', 120);
        if ($name === '') return '';
        $name = preg_replace('/^by\s+/iu', '', $name) ?? $name;
        $name = preg_replace('/\s*\|.*$/u', '', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B•-—|·");
        return $name;
    }
}

if (!function_exists('sfm_normalize_media_url')) {
    function sfm_normalize_media_url(string $candidate, string $baseUrl): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') return '';
        $absolute = sfm_abs_url($candidate, $baseUrl);
        if ($absolute === '') return '';
        if (stripos($absolute, 'data:') === 0) return '';
        if (!sfm_is_http_url($absolute)) return '';
        return $absolute;
    }
}

if (!function_exists('sfm_extract_article_html')) {
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
            $count  = 0;
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
}

if (!function_exists('sfm_sanitize_article_html')) {
    function sfm_sanitize_article_html(DOMNode $root, string $baseUrl): string
    {
        $doc = new DOMDocument();
        $wrapper   = $doc->importNode($root, true);
        $container = $doc->createElement('div');
        $doc->appendChild($container);
        $container->appendChild($wrapper);

        $xp = new DOMXPath($doc);
        foreach (['//script', '//style', '//noscript', '//iframe', '//form', '//svg'] as $q) {
            foreach ($xp->query($q) as $bad) {
                $bad->parentNode->removeChild($bad);
            }
        }

        $allowedTags = ['p','ul','ol','li','strong','em','b','i','a','blockquote','img','figure','figcaption','h1','h2','h3','h4','pre','code','span','div','table','thead','tbody','tr','td','th','picture','source'];
        $allowedAttrs = ['href','title','alt','src','width','height','class','srcset','type','media','loading','sizes'];

        $nodes = iterator_to_array($xp->query('//*'));
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $name = strtolower($node->nodeName);
            if (!in_array($name, $allowedTags, true)) {
                sfm_remove_node_keep_children($node);
                continue;
            }

            if ($name === 'img') {
                sfm_normalize_lazy_image($node, $baseUrl);
            } elseif ($name === 'source') {
                sfm_normalize_lazy_source($node, $baseUrl);
            }

            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    if (!$attr instanceof DOMAttr) {
                        continue;
                    }
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
                    if (in_array($name, ['img','source'], true) && $attrName === 'srcset') {
                        $node->setAttribute('srcset', sfm_normalize_srcset($attr->nodeValue ?? '', $baseUrl));
                    }
                }
            }
        }

        return sfm_inner_html($container);
    }
}

if (!function_exists('sfm_normalize_lazy_image')) {
    function sfm_normalize_lazy_image(DOMElement $node, string $baseUrl): void
    {
        $sourceAttrs = ['src','data-src','data-original','data-lazy','data-lazy-src','data-image','data-url','data-img','data-srcset'];
        $srcValue = '';
        foreach ($sourceAttrs as $attr) {
            if (!$node->hasAttribute($attr)) {
                continue;
            }
            $candidate = trim((string)$node->getAttribute($attr));
            if ($candidate === '') {
                continue;
            }
            if (strpos($attr, 'srcset') !== false) {
                $candidate = sfm_pick_first_src_from_srcset($candidate);
            }
            if ($candidate !== '') {
                $srcValue = $candidate;
                break;
            }
        }

        if ($srcValue === '' && $node->hasAttribute('srcset')) {
            $srcValue = sfm_pick_first_src_from_srcset((string)$node->getAttribute('srcset'));
        }

        if ($srcValue !== '') {
            $node->setAttribute('src', sfm_abs_url($srcValue, $baseUrl));
        }

        if ($node->hasAttribute('srcset')) {
            $node->setAttribute('srcset', sfm_normalize_srcset((string)$node->getAttribute('srcset'), $baseUrl));
        }

        foreach (['data-src','data-original','data-lazy','data-lazy-src','data-image','data-url','data-img','data-srcset'] as $attr) {
            if ($node->hasAttribute($attr)) {
                $node->removeAttribute($attr);
            }
        }
    }
}

if (!function_exists('sfm_normalize_lazy_source')) {
    function sfm_normalize_lazy_source(DOMElement $node, string $baseUrl): void
    {
        if ($node->hasAttribute('data-srcset') && !$node->hasAttribute('srcset')) {
            $node->setAttribute('srcset', $node->getAttribute('data-srcset'));
        }
        if ($node->hasAttribute('srcset')) {
            $node->setAttribute('srcset', sfm_normalize_srcset((string)$node->getAttribute('srcset'), $baseUrl));
        }
        foreach (['data-srcset','data-src'] as $attr) {
            if ($node->hasAttribute($attr)) {
                $node->removeAttribute($attr);
            }
        }
    }
}

if (!function_exists('sfm_normalize_srcset')) {
    function sfm_normalize_srcset(string $srcset, string $baseUrl): string
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return '';
        }

        $entries = array_filter(array_map('trim', explode(',', $srcset)));
        if (!$entries) {
            return '';
        }

        $normalized = [];
        foreach ($entries as $entry) {
            $parts = preg_split('/\s+/', $entry, 2);
            $url = sfm_abs_url($parts[0] ?? '', $baseUrl);
            if ($url === '') {
                continue;
            }
            $descriptor = $parts[1] ?? '';
            $normalized[] = $descriptor !== '' ? ($url . ' ' . $descriptor) : $url;
        }

        return implode(', ', $normalized);
    }
}

if (!function_exists('sfm_pick_first_src_from_srcset')) {
    function sfm_pick_first_src_from_srcset(string $srcset): string
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return '';
        }
        $entries = array_filter(array_map('trim', explode(',', $srcset)));
        foreach ($entries as $entry) {
            $parts = preg_split('/\s+/', $entry, 2);
            $candidate = trim($parts[0] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('sfm_remove_node_keep_children')) {
    function sfm_remove_node_keep_children(DOMNode $node): void
    {
        if (!$node->parentNode) return;
        $parent = $node->parentNode;
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}

if (!function_exists('sfm_inner_html')) {
    function sfm_inner_html(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }
}
