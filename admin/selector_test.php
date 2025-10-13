<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/extract.php';

sfm_admin_boot();

header('Content-Type: application/json; charset=UTF-8');

if (!sfm_admin_is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorised.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST.']);
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!csrf_validate($token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid session token.']);
    exit;
}

$sourceUrl = trim((string)($_POST['source_url'] ?? ''));
$limit = (int)($_POST['limit'] ?? DEFAULT_LIM);
$itemSelectorCss = trim((string)($_POST['item_selector'] ?? ''));
$titleSelectorCss = trim((string)($_POST['title_selector'] ?? ''));
$summarySelectorCss = trim((string)($_POST['summary_selector'] ?? ''));

if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please provide a valid URL.']);
    exit;
}

$reason = null;
if (!sfm_http_url_is_allowed($sourceUrl, $reason)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'URL is not allowed: ' . ($reason ?? 'unknown')]);
    exit;
}

$limit = max(1, min(20, $limit));

$options = [];
if ($itemSelectorCss !== '') {
    $xpath = sfm_css_to_xpath($itemSelectorCss, false);
    if ($xpath === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid item selector.']);
        exit;
    }
    $options['item_selector'] = $itemSelectorCss;
    $options['item_selector_xpath'] = $xpath;
}

if ($titleSelectorCss !== '') {
    $xpath = sfm_css_to_xpath($titleSelectorCss, true);
    if ($xpath === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid title selector.']);
        exit;
    }
    $options['title_selector'] = $titleSelectorCss;
    $options['title_selector_xpath'] = $xpath;
}

if ($summarySelectorCss !== '') {
    $xpath = sfm_css_to_xpath($summarySelectorCss, true);
    if ($xpath === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid summary selector.']);
        exit;
    }
    $options['summary_selector'] = $summarySelectorCss;
    $options['summary_selector_xpath'] = $xpath;
}

try {
    /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $page */
    $page = http_get($sourceUrl, [
        'use_cache' => false,
        'timeout'   => TIMEOUT_S,
        'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Fetch failed: ' . $e->getMessage()]);
    exit;
}

if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
    http_response_code(400);
    $status = $page['status'] ?? 0;
    echo json_encode(['ok' => false, 'error' => 'Source fetch failed (HTTP ' . (int)$status . ').']);
    exit;
}

$debug = [];
$items = sfm_extract_items($page['body'], $sourceUrl, $limit, $options, $debug);

if (!$items) {
    echo json_encode([
        'ok'    => false,
        'error' => 'No items detected with the current selectors.',
        'debug' => $debug,
    ]);
    exit;
}

$preview = [];
foreach ($items as $item) {
    $title = trim((string)($item['title'] ?? 'Untitled'));
    $link  = trim((string)($item['link'] ?? ''));
    $desc  = trim((string)($item['description'] ?? ''));
    if ($desc === '' && !empty($item['content_html'])) {
        $desc = trim(strip_tags((string)$item['content_html']));
    }
    if (function_exists('mb_strlen') && mb_strlen($desc) > 240) {
        $desc = mb_substr($desc, 0, 237) . '…';
    } elseif (strlen($desc) > 240) {
        $desc = substr($desc, 0, 237) . '…';
    }
    $preview[] = [
        'title' => $title,
        'link'  => $link,
        'summary' => $desc,
    ];
}

echo json_encode([
    'ok'     => true,
    'items'  => $preview,
    'debug'  => $debug,
]);
