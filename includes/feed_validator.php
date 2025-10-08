<?php
/**
 * includes/feed_validator.php
 *
 * Lightweight validation helpers to sanity-check generated feeds before they
 * are persisted. Designed for shared hosting: no external binaries required.
 */

declare(strict_types=1);

if (!function_exists('sfm_validate_feed')) {
    /**
     * Validate an RSS/Atom/JSON Feed payload.
     *
     * @param string $format  Expected format (rss|atom|jsonfeed)
     * @param string $content Rendered feed contents
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    function sfm_validate_feed(string $format, string $content): array
    {
        $format = strtolower(trim($format));
        $result = [
            'ok'       => true,
            'errors'   => [],
            'warnings' => [],
        ];

        $maxMessages = 8;
        $addError = function (string $message) use (&$result, $maxMessages): void {
            if (count($result['errors']) >= $maxMessages) {
                return;
            }
            $result['errors'][] = $message;
        };
        $addWarning = function (string $message) use (&$result, $maxMessages): void {
            if (count($result['warnings']) >= $maxMessages) {
                return;
            }
            $result['warnings'][] = $message;
        };

        if ($format === 'jsonfeed') {
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $msg = json_last_error() === JSON_ERROR_NONE
                    ? 'Feed JSON did not decode to an object.'
                    : 'Invalid JSON: ' . json_last_error_msg();
                $addError($msg);
                $result['ok'] = false;
                return $result;
            }

            if (empty($data['version'])) {
                $addError('Missing required "version" field.');
            }
            if (!array_key_exists('title', $data) || $data['title'] === '') {
                $addError('Missing required "title" field.');
            }
            if (!isset($data['items']) || !is_array($data['items'])) {
                $addError('"items" must be an array.');
            } else {
                foreach ($data['items'] as $idx => $item) {
                    if (!is_array($item)) {
                        $addError('Item #' . ($idx + 1) . ' is not an object.');
                        continue;
                    }
                    if (!array_key_exists('id', $item) || (string)$item['id'] === '') {
                        $addError('Item #' . ($idx + 1) . ' is missing an "id".');
                    }
                    if (empty($item['content_html']) && empty($item['content_text']) && empty($item['url'])) {
                        $addWarning('Item #' . ($idx + 1) . ' has no content or URL.');
                    }
                    if (count($result['errors']) >= $maxMessages) {
                        break;
                    }
                }
            }

            if (!empty($result['errors'])) {
                $result['ok'] = false;
            }

            return $result;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom       = new DOMDocument();
        $loaded    = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $errors    = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($errors as $err) {
            $message = trim((string)$err->message);
            $location = [];
            if (!empty($err->line)) {
                $location[] = 'line ' . (int)$err->line;
            }
            if (!empty($err->column)) {
                $location[] = 'col ' . (int)$err->column;
            }
            if ($location) {
                $message .= ' (' . implode(', ', $location) . ')';
            }
            if (!empty($err->code)) {
                $message .= ' [code ' . (int)$err->code . ']';
            }

            if ($err->level === LIBXML_ERR_WARNING) {
                $addWarning($message);
            } else {
                $addError($message);
            }
        }

        if (!empty($result['errors'])) {
            $hard = [];
            foreach ($result['errors'] as $msg) {
                if (stripos($msg, 'svg') !== false || stripos($msg, 'symbol') !== false || stripos($msg, 'path invalid') !== false) {
                    $result['warnings'][] = $msg;
                } else {
                    $hard[] = $msg;
                }
            }
            $result['errors'] = $hard;
        }

        if (!$loaded || !empty($result['errors'])) {
            $result['ok'] = false;
            return $result;
        }

        $root = $dom->documentElement;
        if (!$root) {
            $addError('Feed is missing a root element.');
            $result['ok'] = false;
            return $result;
        }

        $local = strtolower((string)$root->localName);
        if ($format === 'atom') {
            if ($local !== 'feed') {
                $addWarning('Expected <feed> root for Atom, found <' . $root->nodeName . '>.');
            }
            $entries = $root->getElementsByTagName('entry');
            if ($entries->length === 0) {
                $addWarning('Atom feed contains no <entry> elements.');
            }
        } else { // default to RSS-style checks
            if ($local !== 'rss') {
                $addWarning('Expected <rss> root, found <' . $root->nodeName . '>.');
            }
            $channels = $dom->getElementsByTagName('channel');
            if ($channels->length === 0) {
                $addError('RSS feed is missing a <channel> element.');
                $result['ok'] = false;
                return $result;
            }
            $channel = $channels->item(0);
            if ($channel instanceof DOMElement) {
                if ($channel->getElementsByTagName('title')->length === 0) {
                    $addWarning('RSS channel is missing a <title>.');
                }
                if ($channel->getElementsByTagName('description')->length === 0) {
                    $addWarning('RSS channel is missing a <description>.');
                }
                if ($channel->getElementsByTagName('link')->length === 0) {
                    $addWarning('RSS channel is missing a <link>.');
                }
                if ($channel->getElementsByTagName('item')->length === 0) {
                    $addWarning('RSS feed contains no <item> entries.');
                }
            }
        }

        return $result;
    }
}
