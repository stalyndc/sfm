<?php
/**
 * includes/extract_symfony.php
 *
 * Enhanced feed item extraction using Symfony DomCrawler and CSS Selector
 * - Backward compatible with existing sfm_extract_items function
 * - More reliable HTML parsing with better error handling
 * - Performance improvements through proper DOM manipulation
 *
 * Dependencies:
 *  - symfony/dom-crawler
 *  - symfony/css-selector
 */

declare(strict_types=1);

require_once __DIR__ . '/../secure/vendor/autoload.php';
require_once __DIR__ . '/config_env.php';

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Extract feed items from HTML using Symfony DomCrawler
 * Enhanced version of the existing extraction logic
 */
function sfm_extract_items_symfony(string $html, string $baseUrl = '', $limit = 10, array $options = [], ?array &$debug = null): array
{
    $defaults = [
        'title_selector' => 'h1, h2, h3, .title, .post-title, .entry-title, [class*="title"]',
        'content_selector' => 'p, .content, .post-content, .entry-content, .description, .summary',
        'link_selector' => 'a[href]',
        'date_selector' => 'time, .date, .posted, .published, .pubdate',
        'image_selector' => 'img[src]',
        'limit' => 50,
        'include_context' => true,
    ];
    
    // Handle backward compatibility: if limit is an array, it's actually options
    if (is_array($limit)) {
        $options = array_merge($defaults, $limit);
        $limit = 10;
    } else {
        $options = array_merge($defaults, $options);
        $options['limit'] = (int)$limit;
    }
    
    try {
        $crawler = new Crawler($html);
        $items = [];
        
        // Try to find article containers first
        $articleSelectors = [
            'article',
            '.post',
            '.item',
            '.entry',
            '.story',
            '[class*="post"]',
            '[class*="item"]',
            '[class*="article"]',
        ];
        
        $articles = null;
        foreach ($articleSelectors as $selector) {
            try {
                $articles = $crawler->filter($selector);
                if ($articles->count() > 0) {
                    break;
                }
            } catch (\Exception $e) {
                // Invalid selector, continue to next one
                continue;
            }
        }
        
        // If no articles found, try to find link groups as fallback
        if (!$articles || $articles->count() === 0) {
            $linkGroups = $crawler->filter('a[href]');
            if ($linkGroups->count() > 0) {
                $articles = $linkGroups;
            }
        }
        
        // Still no articles? Return empty array
        if (!$articles || !$articles->count()) {
            return [
                'ok' => false,
                'items' => [],
                'error' => 'No content found to extract',
                'count' => 0,
            ];
        }
        
        $count = 0;
        foreach ($articles as $element) {
            if ($count >= $options['limit']) {
                break;
            }
            
            $itemNode = new Crawler($element);
            $item = [
                'title' => '',
                'content' => '',
                'link' => '',
                'date' => '',
                'image' => '',
                'context' => [],
            ];
            
            // Extract title
            $titleFound = false;
            foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', '.title', '.post-title', '.entry-title'] as $selector) {
                try {
                    $titleNodes = $itemNode->filter($selector);
                    if ($titleNodes->count() > 0) {
                        $item['title'] = trim($titleNodes->first()->text());
                        $titleFound = true;
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // If no title found in child elements, use the element itself or its link
            if (!$titleFound) {
                if ($element instanceof DOMElement && $element->tagName === 'a') {
                    $item['title'] = trim($element->textContent);
                } else {
                    // Look for a link within the element
                    $firstLink = $itemNode->filter('a[href]')->first();
                    if ($firstLink->count() > 0) {
                        $item['title'] = trim($firstLink->text());
                        $item['link'] = urldecode($firstLink->attr('href'));
                    } else {
                        $item['title'] = trim($element->textContent);
                    }
                }
            }
            
            // Extract link (using the element itself if it's a link)
            if (empty($item['link'])) {
                if ($element instanceof DOMElement && $element->tagName === 'a') {
                    $item['link'] = urldecode($element->getAttribute('href') ?: '');
                } else {
                    $firstLink = $itemNode->filter('a[href]')->first();
                    if ($firstLink->count() > 0) {
                        $item['link'] = urldecode($firstLink->attr('href'));
                    }
                }
            }
            
            // Extract content
            $contentText = '';
            try {
                $contentNodes = $itemNode->filter($options['content_selector']);
                $contentParts = [];
                foreach ($contentNodes as $contentNode) {
                    $text = trim($contentNode->textContent);
                    if (strlen($text) > 20 && strlen($text) < 1000) { // Reasonable length
                        $contentParts[] = $text;
                    }
                }
                if (empty($contentParts)) {
                    // Fallback to element's own text content
                    $textContent = trim($element->textContent);
                    if (strlen($textContent) > strlen($item['title'])) {
                        $contentText = substr($textContent, 0, 500);
                    }
                } else {
                    $contentText = implode(' ', array_slice($contentParts, 0, 3));
                }
            } catch (\Exception $e) {
                // Use element's text content as fallback
                $textContent = trim($element->textContent);
                if (strlen($textContent) > strlen($item['title'])) {
                    $contentText = substr($textContent, 0, 500);
                }
            }
            $item['content'] = $contentText;
            
            // Extract date
            try {
                $dateNodes = $itemNode->filter($options['date_selector']);
                if ($dateNodes->count() > 0) {
                    $dateText = trim($dateNodes->first()->text());
                    $dateTime = strtotime($dateText);
                    if ($dateTime !== false) {
                        $item['date'] = date('c', $dateTime);
                    } else {
                        $item['date'] = $dateText;
                    }
                }
            } catch (\Exception $e) {
                // Skip date extraction on error
            }
            
            // Extract image
            try {
                $imageNodes = $itemNode->filter($options['image_selector']);
                if ($imageNodes->count() > 0) {
                    $imageSrc = $imageNodes->first()->attr('src');
                    if (!empty($imageSrc)) {
                        $item['image'] = urldecode($imageSrc);
                    }
                }
            } catch (\Exception $e) {
                // Skip image extraction on error
            }
            
            // Clean up and normalize URLs
            $cleanItem = sfm_clean_extracted_item($item, $baseUrl);
            
            if (!empty($cleanItem['title']) || !empty($cleanItem['link'])) {
                if ($options['include_context']) {
                    $cleanItem['context'] = [
                        'extractor' => 'symfony',
                        'selector_used' => $articleSelectors[0],
                        'element_class' => $element instanceof DOMElement ? $element->getAttribute('class') : '',
                        'element_id' => $element instanceof DOMElement ? $element->getAttribute('id') : '',
                    ];
                }
                $items[] = $cleanItem;
                $count++;
            }
        }
        
        return [
            'ok' => true,
            'items' => $items,
            'error' => null,
            'count' => count($items),
        ];
        
    } catch (\Exception $e) {
        return [
            'ok' => false,
            'items' => [],
            'error' => 'Extraction failed: ' . $e->getMessage(),
            'count' => 0,
        ];
    }
}

/**
 * Clean and normalize extracted item data
 */
function sfm_clean_extracted_item(array $item, string $baseUrl = ''): array
{
    // Clean title
    $item['title'] = trim(preg_replace('/\s+/', ' ', $item['title']));
    $item['title'] = strip_tags($item['title']);
    
    // Clean content
    $item['content'] = trim(preg_replace('/\s+/', ' ', $item['content']));
    $item['content'] = strip_tags($item['content']);
    if (strlen($item['content']) > 1000) {
        $item['content'] = substr($item['content'], 0, 1000) . '...';
    }
    
    // Normalize URLs
    if (!empty($item['link'])) {
        $item['link'] = sfm_normalize_url($item['link'], $baseUrl);
    }
    
    if (!empty($item['image'])) {
        $item['image'] = sfm_normalize_url($item['image'], $baseUrl);
    }
    
    // Remove empty fields
    return array_filter($item, function($value) {
        return $value !== '' && $value !== null;
    });
}

/**
 * Normalize relative URLs to absolute URLs
 */
function sfm_normalize_url(string $url, string $baseUrl = ''): string
{
    if (empty($url)) {
        return '';
    }
    
    // Skip if already absolute
    if (parse_url($url, PHP_URL_SCHEME)) {
        return $url;
    }
    
    if (empty($baseUrl)) {
        return $url;
    }
    
    // Use PHP's built-in URL normalization
    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

/**
 * Discover feeds from HTML using Symfony DomCrawler
 */
function sfm_discover_feeds_symfony(string $html, string $baseUrl = ''): array
{
    $feeds = [
        'rss' => [],
        'atom' => [],
        'json' => [],
    ];
    
    try {
        $crawler = new Crawler($html);
        
        // Look for link tags with RSS/Atom types
        $linkNodes = $crawler->filter('link[rel="alternate"], link[rel="feed"]');
        
        foreach ($linkNodes as $node) {
            $type = strtolower($node instanceof DOMElement ? $node->getAttribute('type') : '');
            $href = $node instanceof DOMElement ? $node->getAttribute('href') : '';
            $title = $node instanceof DOMElement ? $node->getAttribute('title') : '';
            
            if (empty($href)) {
                continue;
            }
            
            $href = sfm_normalize_url($href, $baseUrl);
            
            if (strpos($type, 'rss') !== false || strpos($title, 'rss') !== false) {
                $feeds['rss'][] = ['url' => $href, 'title' => $title ?: 'RSS Feed'];
            } elseif (strpos($type, 'atom') !== false || strpos($title, 'atom') !== false) {
                $feeds['atom'][] = ['url' => $href, 'title' => $title ?: 'Atom Feed'];
            } elseif (strpos($type, 'json') !== false || strpos($title, 'json') !== false) {
                $feeds['json'][] = ['url' => $href, 'title' => $title ?: 'JSON Feed'];
            }
        }
        
        // Also look for common feed path patterns
        $commonPaths = ['/feed', '/rss', '/atom', '/feed.xml', '/rss.xml', '/atom.xml'];
        
        foreach ($commonPaths as $path) {
            $feedUrl = sfm_normalize_url($path, $baseUrl);
            
            if (!in_array($feedUrl, array_column($feeds['rss'], 'url')) && 
                !in_array($feedUrl, array_column($feeds['atom'], 'url'))) {
                // Try to guess the type based on extension
                if (strpos($path, '.rss') !== false || strpos($path, '/rss') !== false) {
                    $feeds['rss'][] = ['url' => $feedUrl, 'title' => 'RSS Feed'];
                } elseif (strpos($path, '.atom') !== false || strpos($path, '/atom') !== false) {
                    $feeds['atom'][] = ['url' => $feedUrl, 'title' => 'Atom Feed'];
                } else {
                    // Generic feed, add to RSS by default
                    $feeds['rss'][] = ['url' => $feedUrl, 'title' => 'Feed'];
                }
            }
        }
        
        return $feeds;
        
    } catch (\Exception $e) {
        // Log error quietly - don't use logger to avoid signature conflicts
        error_log('Feed discovery failed: ' . $e->getMessage() . ' (baseUrl: ' . $baseUrl . ')');
        
        return $feeds;
    }
}

// Backward compatibility wrapper
if (!function_exists('sfm_extract_items')) {
    function sfm_extract_items(string $html, string $baseUrl = '', $limit = 10, array $options = [], ?array &$debug = null): array {
        return sfm_extract_items_symfony($html, $baseUrl, $limit, $options, $debug);
    }
}
