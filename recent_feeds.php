<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/jobs.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$limitParam = (int)($_GET['limit'] ?? 6);
$limit      = max(1, min(15, $limitParam));
$sourceUrl  = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$jobs       = sfm_jobs_list_recent($limit);
$filtered   = [];

if ($sourceUrl !== '') {
    foreach ($jobs as $job) {
        if (!empty($job['source_url']) && $job['source_url'] === $sourceUrl) {
            continue;
        }
        $filtered[] = $job;
    }
    $jobs = $filtered ?: $jobs;
}

$now        = time();

$humanTime = static function (?string $iso) use ($now): string {
    if (!is_string($iso) || $iso === '') {
        return '—';
    }
    $timestamp = strtotime($iso);
    if ($timestamp === false || $timestamp <= 0) {
        return '—';
    }

    $diff = max(0, $now - $timestamp);
    if ($diff < 45) return 'just now';
    if ($diff < 90) return '1 min ago';
    if ($diff < 2700) return (int)round($diff / 60) . ' mins ago';
    if ($diff < 5400) return '1 hr ago';
    if ($diff < 86400) return (int)round($diff / 3600) . ' hrs ago';
    if ($diff < 172800) return 'yesterday';
    if ($diff < 604800) return (int)round($diff / 86400) . ' days ago';
    if ($diff < 2419200) return (int)round($diff / 604800) . ' wks ago';
    return date('Y-m-d', $timestamp);
};

?>
<div class="card shadow-sm recent-feeds-card">
  <div class="card-body recent-feeds-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h3 class="h6 fw-semibold mb-0">Recent feeds</h3>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        hx-get="recent_feeds.php?limit=<?= $limit; ?><?= $sourceUrl !== '' ? '&amp;source=' . rawurlencode($sourceUrl) : ''; ?>"
        hx-target="closest .recent-feeds-card"
        hx-swap="outerHTML"
        aria-label="Refresh recent feeds"
      >
        Refresh
      </button>
    </div>
    <?php if (!$jobs): ?>
      <p class="text-secondary small mb-0">No feeds yet. Generate one to see it listed here.</p>
    <?php else: ?>
      <ul class="list-unstyled recent-feeds-list mb-0">
        <?php foreach ($jobs as $job): ?>
          <?php
            $feedUrl    = (string) ($job['feed_url'] ?? '');
            $sourceUrl  = (string) ($job['source_url'] ?? '');
            $format     = strtoupper((string) ($job['format'] ?? 'rss'));
            $itemsCount = $job['items_count'] ?? null;
            $note       = (string) ($job['last_refresh_note'] ?? '');
            $mode       = (string) ($job['mode'] ?? 'custom');
            $preferNative = !empty($job['prefer_native']);
            $lastAt     = $humanTime($job['last_refresh_at'] ?? null);
            $host       = '';
            if ($sourceUrl !== '') {
              $host = parse_url($sourceUrl, PHP_URL_HOST) ?: '';
            }
          ?>
          <li class="recent-feed-item py-2 border-top">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
              <div class="flex-grow-1">
                <?php if ($feedUrl !== ''): ?>
                  <a class="recent-feed-link mono" href="<?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                    <?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                <?php elseif ($sourceUrl !== ''): ?>
                  <span class="recent-feed-link mono text-secondary"><?= htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <div class="small text-secondary mt-1">
                  <span class="badge rounded-pill text-bg-dark me-1"><?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php if ($mode === 'native'): ?>
                    <span class="badge rounded-pill text-bg-success me-1">Native</span>
                  <?php elseif ($preferNative): ?>
                    <span class="badge rounded-pill text-bg-info me-1">Auto</span>
                  <?php endif; ?>
                  <?php if ($itemsCount !== null): ?>
                    <span><?= (int) $itemsCount; ?> items</span>
                  <?php endif; ?>
                  <?php if ($host): ?>
                    <span class="ms-1">· <?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="text-end small text-secondary">
                <div><?= htmlspecialchars($lastAt, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($note !== ''): ?>
                  <div class="text-muted"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
