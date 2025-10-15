<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/jobs.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

 $limitParam = (int)($_GET['limit'] ?? 6);
 $limit      = max(1, min(15, $limitParam));
 $sourceUrl  = isset($_GET['source']) ? trim((string)$_GET['source']) : null;

 echo sfm_recent_feeds_card_html($sourceUrl ?: null, $limit);
