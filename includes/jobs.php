<?php
/**
 * includes/jobs.php
 *
 * Minimal file-based job registry for scheduled feed refreshes.
 * Each job is stored as JSON under STORAGE_ROOT/jobs/{job_id}.json.
 *
 * Job schema (keys may extend in the future):
 *   job_id              string  unique identifier
 *   source_url          string  original page URL provided by user
 *   native_source       string  (optional) discovered feed URL when prefer_native used
 *   mode                string  'native'|'custom'
 *   format              string  rss|atom|jsonfeed (desired output format)
 *   limit               int     item cap for custom mode
 *   feed_filename       string  filename under /feeds
 *   feed_url            string  public URL served to user
 *   prefer_native       bool
 *   refresh_interval    int     seconds between refresh attempts
 *   refresh_count       int
 *   last_refresh_at     string  ISO8601 timestamp
 *   last_refresh_status string  'ok'|'fail'
 *   last_refresh_note   string  human readable breadcrumb
 *   last_refresh_code   int     HTTP status (where available)
 *   last_refresh_error  string  failure detail
 *   allow_empty         bool    permit successful refresh when no items are found
 *   auto_allow_empty_at string  ISO8601 timestamp when auto allow-empty triggered
 *   created_at          string  ISO8601 timestamp
 *   updated_at          string  ISO8601 timestamp
 *   created_ip          string  (optional) client IP at creation time
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php'; // for client_ip()
require_once __DIR__ . '/feed_builder.php';

if (!function_exists('sfm_refresh_log_path')) {
  function sfm_refresh_log_path(): string
  {
    $root = defined('STORAGE_ROOT') ? STORAGE_ROOT : (dirname(__DIR__) . '/storage');
    $dir = rtrim($root . '/logs', '/');
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    return $dir . '/cron_refresh.log';
  }
}

if (!function_exists('sfm_job_trim_string')) {
  function sfm_job_trim_string(?string $value, int $limit = 400): string
  {
    $value = trim((string)$value);
    if ($value === '') {
      return '';
    }
    if (function_exists('mb_strlen')) {
      if (mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
      }
      return $value;
    }
    if (strlen($value) > $limit) {
      return substr($value, 0, $limit - 1) . '…';
    }
    return $value;
  }
}

function sfm_jobs_dir(): string
{
  $dir = rtrim(SFM_JOBS_DIR, '/');
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function sfm_jobs_lock_file(): string
{
  return sfm_jobs_dir() . '/.refresh.lock';
}

function sfm_jobs_acquire_lock()
{
  $lockFile = sfm_jobs_lock_file();
  $fh = fopen($lockFile, 'c+');
  if (!$fh) {
    return false;
  }
  if (!flock($fh, LOCK_EX | LOCK_NB)) {
    fclose($fh);
    return false;
  }
  return $fh;
}

function sfm_jobs_release_lock($handle): void
{
  if (is_resource($handle)) {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
}

function sfm_job_path(string $jobId): string
{
  $slug = preg_replace('~[^a-z0-9_-]~i', '_', $jobId);
  return sfm_jobs_dir() . '/' . $slug . '.json';
}

function sfm_job_generate_id(): string
{
  return substr(bin2hex(random_bytes(16)), 0, 24);
}

function sfm_job_now_iso(): string
{
  return gmdate('c');
}

function sfm_job_default_interval(): int
{
  return max((int)SFM_MIN_REFRESH_INTERVAL, (int)SFM_DEFAULT_REFRESH_INTERVAL);
}

function sfm_job_write(string $jobId, array $payload): bool
{
  $path = sfm_job_path($jobId);
  $tmp  = $path . '.tmp';
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
    return false;
  }
  return @rename($tmp, $path);
}

function sfm_job_register(array $data): array
{
  $jobId = sfm_job_generate_id();
  $nowIso = sfm_job_now_iso();
  $interval = isset($data['refresh_interval']) ? (int)$data['refresh_interval'] : sfm_job_default_interval();
  $interval = max((int)SFM_MIN_REFRESH_INTERVAL, $interval);

  $job = [
    'job_id'             => $jobId,
    'source_url'         => (string)($data['source_url'] ?? ''),
    'native_source'      => $data['native_source'] ?? null,
    'mode'               => (string)($data['mode'] ?? 'custom'),
    'format'             => (string)($data['format'] ?? DEFAULT_FMT),
    'limit'              => (int)($data['limit'] ?? DEFAULT_LIM),
    'feed_filename'      => (string)($data['feed_filename'] ?? ''),
    'feed_url'           => (string)($data['feed_url'] ?? ''),
    'prefer_native'      => (bool)($data['prefer_native'] ?? false),
    'allow_empty'        => (bool)($data['allow_empty'] ?? false),
    'auto_allow_empty_at'=> isset($data['auto_allow_empty_at']) && is_string($data['auto_allow_empty_at'])
      ? $data['auto_allow_empty_at']
      : null,
    'refresh_interval'   => $interval,
    'refresh_count'      => 0,
    'last_refresh_at'    => $nowIso,
    'last_refresh_status'=> 'ok',
    'last_refresh_note'  => (string)($data['last_refresh_note'] ?? 'created'),
    'last_refresh_code'  => $data['last_refresh_code'] ?? null,
    'last_refresh_error' => null,
    'created_at'         => $nowIso,
    'updated_at'         => $nowIso,
    'created_ip'         => client_ip(),
    'items_count'        => $data['items_count'] ?? null,
    'last_validation'    => isset($data['last_validation']) && is_array($data['last_validation']) ? $data['last_validation'] : null,
    'diagnostics'        => null,
    'include_keywords'   => sfm_job_normalize_keywords($data['include_keywords'] ?? null),
    'exclude_keywords'   => sfm_job_normalize_keywords($data['exclude_keywords'] ?? null),
  ];

  if (!sfm_job_write($jobId, $job)) {
    error_log('SimpleFeedMaker: failed to persist job ' . $jobId);
  }
  return $job;
}

function sfm_job_load(string $jobId): ?array
{
  $path = sfm_job_path($jobId);
  if (!is_file($path)) {
    return null;
  }
  $json = @file_get_contents($path);
  if ($json === false) {
    return null;
  }
  $data = json_decode($json, true);
  return is_array($data) ? $data : null;
}

function sfm_job_update(string $jobId, array $fields): ?array
{
  $current = sfm_job_load($jobId);
  if (!$current) {
    return null;
  }

  if (array_key_exists('include_keywords', $fields)) {
    $fields['include_keywords'] = sfm_job_normalize_keywords($fields['include_keywords']);
  }
  if (array_key_exists('exclude_keywords', $fields)) {
    $fields['exclude_keywords'] = sfm_job_normalize_keywords($fields['exclude_keywords']);
  }
  if (array_key_exists('allow_empty', $fields)) {
    $fields['allow_empty'] = (bool)$fields['allow_empty'];
  }
  if (array_key_exists('auto_allow_empty_at', $fields)) {
    $fields['auto_allow_empty_at'] = $fields['auto_allow_empty_at'] !== null
      ? (string)$fields['auto_allow_empty_at']
      : null;
  }

  $updated = $current;
  foreach ($fields as $key => $value) {
    if ($value === null) {
      unset($updated[$key]);
      continue;
    }
    $updated[$key] = $value;
  }
  $updated['updated_at'] = sfm_job_now_iso();

  if (!sfm_job_write($jobId, $updated)) {
    return null;
  }
  return $updated;
}

function sfm_job_delete(string $jobId): bool
{
  $path = sfm_job_path($jobId);
  if (is_file($path)) {
    return @unlink($path);
  }
  return true;
}

function sfm_job_list(): array
{
  $jobs = [];
  foreach (glob(sfm_jobs_dir() . '/*.json') as $file) {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || empty($data['job_id'])) {
      continue;
    }
    if (!isset($data['include_keywords']) || !is_array($data['include_keywords'])) {
      $data['include_keywords'] = [];
    }
    if (!isset($data['exclude_keywords']) || !is_array($data['exclude_keywords'])) {
      $data['exclude_keywords'] = [];
    }
    $data['allow_empty'] = !empty($data['allow_empty']);
    if (!isset($data['auto_allow_empty_at']) || !is_string($data['auto_allow_empty_at'])) {
      $data['auto_allow_empty_at'] = null;
    }
    $jobs[] = $data;
  }

  usort($jobs, function ($a, $b) {
    $aTs = strtotime($a['last_refresh_at'] ?? '') ?: 0;
    $bTs = strtotime($b['last_refresh_at'] ?? '') ?: 0;
    if ($aTs === $bTs) {
      return strcmp($a['job_id'], $b['job_id']);
    }
    return $aTs <=> $bTs;
  });

  return $jobs;
}

function sfm_job_normalize_keywords($value): array
{
  if ($value === null) {
    return [];
  }

  if (is_string($value)) {
    $parts = preg_split('/[,\n]+/', $value) ?: [];
  } elseif (is_array($value)) {
    $parts = $value;
  } else {
    return [];
  }

  $keywords = [];
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part === '') {
      continue;
    }
    if (function_exists('mb_strtolower')) {
      $part = mb_strtolower($part, 'UTF-8');
    } else {
      $part = strtolower($part);
    }
    if (!in_array($part, $keywords, true)) {
      $keywords[] = $part;
    }
    if (count($keywords) >= 20) {
      break;
    }
  }

  return $keywords;
}

function sfm_job_validation_snapshot(array $job): ?array
{
  if (empty($job['last_validation']) || !is_array($job['last_validation'])) {
    return null;
  }

  $raw = $job['last_validation'];
  $warningsRaw = $raw['warnings'] ?? [];
  if (is_string($warningsRaw)) {
    $warningsRaw = [$warningsRaw];
  }
  $warnings = [];
  if (is_array($warningsRaw)) {
    foreach ($warningsRaw as $warning) {
      if (is_string($warning) && trim($warning) !== '') {
        $warnings[] = $warning;
      }
    }
  }

  $checkedAt = $raw['checked_at'] ?? null;
  if (!is_string($checkedAt) || trim($checkedAt) === '') {
    $checkedAt = null;
  }

  return [
    'warnings'   => $warnings,
    'checked_at' => $checkedAt,
  ];
}

function sfm_job_is_due(array $job, int $nowTs = null): bool
{
  $nowTs = $nowTs ?? time();
  $last = strtotime($job['last_refresh_at'] ?? '') ?: 0;
  $interval = (int)($job['refresh_interval'] ?? sfm_job_default_interval());
  $interval = max((int)SFM_MIN_REFRESH_INTERVAL, $interval);
  return $nowTs - $last >= $interval;
}

function sfm_job_mark_success(array $job, int $bytes, int $httpStatus = 200, ?int $items = null, ?string $note = null, ?array $validation = null, array $extraFields = []): ?array
{
  $fields = [
    'last_refresh_at'    => sfm_job_now_iso(),
    'last_refresh_status'=> 'ok',
    'last_refresh_code'  => $httpStatus,
    'last_refresh_error' => null,
    'last_refresh_note'  => $note ?? 'ok',
    'refresh_count'      => ($job['refresh_count'] ?? 0) + 1,
    'items_count'        => $items,
    'failure_streak'     => 0,
    'diagnostics'        => null,
  ];

  if (is_array($validation)) {
    $warningsRaw = $validation['warnings'] ?? [];
    if (is_string($warningsRaw)) {
      $warningsRaw = [$warningsRaw];
    }
    $warnings = [];
    if (is_array($warningsRaw)) {
      foreach ($warningsRaw as $warning) {
        if (is_string($warning) && trim($warning) !== '') {
          $warnings[] = $warning;
        }
      }
    }

    $checkedAt = $validation['checked_at'] ?? null;
    if (!is_string($checkedAt) || trim($checkedAt) === '') {
      $checkedAt = sfm_job_now_iso();
    }

    $fields['last_validation'] = [
      'warnings'   => $warnings,
      'checked_at' => $checkedAt,
    ];
  } else {
    $fields['last_validation'] = null;
  }

  foreach ($extraFields as $key => $value) {
    if ($value === null) {
      $fields[$key] = null;
    } else {
      $fields[$key] = $value;
    }
  }

  return sfm_job_update($job['job_id'], $fields);
}

function sfm_job_mark_failure(array $job, string $error, ?int $httpStatus = null): ?array
{
  $fields = [
    'last_refresh_at'    => sfm_job_now_iso(),
    'last_refresh_status'=> 'fail',
    'last_refresh_code'  => $httpStatus,
    'last_refresh_error' => $error,
    'last_refresh_note'  => 'fail',
    'last_validation'    => null,
    'failure_streak'     => ($job['failure_streak'] ?? 0) + 1,
  ];

  return sfm_job_update($job['job_id'], $fields);
}

function sfm_job_should_purge(array $job, int $nowTs = null): bool
{
  $nowTs = $nowTs ?? time();
  $retentionDays = (int)SFM_JOB_RETENTION_DAYS;
  if ($retentionDays <= 0) {
    return false;
  }
  $cut = $nowTs - ($retentionDays * 86400);

  $feedFile = isset($job['feed_filename']) ? FEEDS_DIR . '/' . $job['feed_filename'] : null;
  $lastSeen = 0;
  if ($feedFile && is_file($feedFile)) {
    $lastSeen = max(@filemtime($feedFile) ?: 0, @fileatime($feedFile) ?: 0);
  }
  if (!$lastSeen) {
    $lastSeen = strtotime($job['updated_at'] ?? '') ?: strtotime($job['created_at'] ?? '') ?: 0;
  }

  return $lastSeen > 0 && $lastSeen < $cut;
}

if (!function_exists('sfm_job_attach_diagnostics')) {
  function sfm_job_attach_diagnostics(string $jobId, array $data, ?array $details = null): ?array
  {
    $job = sfm_job_load($jobId);
    if (!$job) {
      return null;
    }

    $diagnostics = [
      'captured_at'    => $data['captured_at'] ?? sfm_job_now_iso(),
      'failure_streak' => (int)($data['failure_streak'] ?? ($job['failure_streak'] ?? 0)),
      'error'          => sfm_job_trim_string($data['error'] ?? ($job['last_refresh_error'] ?? ''), 800),
      'source_url'     => $data['source_url'] ?? ($job['source_url'] ?? ''),
      'mode'           => $data['mode'] ?? ($job['mode'] ?? ''),
    ];

    if (array_key_exists('http_status', $data) || isset($job['last_refresh_code'])) {
      $diagnostics['http_status'] = $data['http_status'] ?? ($job['last_refresh_code'] ?? null);
    }
    if (!empty($data['note']) || !empty($job['last_refresh_note'])) {
      $diagnostics['note'] = sfm_job_trim_string((string)($data['note'] ?? $job['last_refresh_note']), 200);
    }

    if (is_array($details) && !empty($details)) {
      $clean = [];
      foreach ($details as $key => $value) {
        if (!is_string($key)) {
          continue;
        }
        if (is_scalar($value)) {
          $clean[$key] = sfm_job_trim_string((string)$value, 400);
        } elseif (is_array($value)) {
          $subset = array_slice($value, 0, 5);
          $clean[$key] = $subset;
        }
      }
      if ($clean) {
        $diagnostics['details'] = $clean;
      }
    }

    return sfm_job_update($jobId, ['diagnostics' => $diagnostics]);
  }
}

if (!function_exists('sfm_job_clear_diagnostics')) {
  function sfm_job_clear_diagnostics(string $jobId): ?array
  {
    return sfm_job_update($jobId, ['diagnostics' => null]);
  }
}

function sfm_jobs_statistics(?array $jobs = null, int $warnThreshold = 3): array
{
  $jobs = $jobs ?? sfm_job_list();
  $total = count($jobs);
  $failing = 0;
  $critical = 0;
  $native = 0;

  foreach ($jobs as $job) {
    if (($job['mode'] ?? '') === 'native') {
      $native++;
    }
    $streak = (int)($job['failure_streak'] ?? 0);
    if ($streak > 0) {
      $failing++;
      if ($streak >= $warnThreshold) {
        $critical++;
      }
    }
  }

  return [
    'total'     => $total,
    'native'    => $native,
    'failing'   => $failing,
    'critical'  => $critical,
    'threshold' => $warnThreshold,
  ];
}

function sfm_refresh_recent_logs(int $limit = 5): array
{
  $path = sfm_refresh_log_path();
  if (!is_file($path) || $limit <= 0) {
    return [];
  }

  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines) || !$lines) {
    return [];
  }

  $slice = array_slice($lines, -1 * $limit);
  $entries = [];
  foreach (array_reverse($slice) as $line) {
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
      continue;
    }
    $entries[] = $decoded;
  }
  return $entries;
}

if (!function_exists('sfm_jobs_list_recent')) {
  /**
   * Return the most recently updated jobs (up to $limit) with minimal metadata.
   *
   * @return array<int, array<string, mixed>>
   */
  function sfm_jobs_list_recent(int $limit = 6): array
  {
    $limit = max(1, min(20, $limit));
    $dir   = sfm_jobs_dir();

    $files = glob($dir . '/*.json');
    if (!$files) {
      return [];
    }

    usort($files, static function (string $a, string $b): int {
      $ta = @filemtime($a) ?: 0;
      $tb = @filemtime($b) ?: 0;
      return $tb <=> $ta;
    });

    $out = [];
    foreach ($files as $path) {
      if (count($out) >= $limit) {
        break;
      }
      $raw = @file_get_contents($path);
      if ($raw === false) {
        continue;
      }
      $job = json_decode($raw, true);
      if (!is_array($job)) {
        continue;
      }
      $feedUrl   = isset($job['feed_url']) ? (string)$job['feed_url'] : '';
      $sourceUrl = isset($job['source_url']) ? (string)$job['source_url'] : '';
      if ($feedUrl === '' && $sourceUrl === '') {
        continue;
      }
      $lastRefreshAt = null;
      foreach (['last_refresh_at', 'updated_at', 'created_at'] as $candidate) {
        if (!empty($job[$candidate]) && is_string($job[$candidate])) {
          $lastRefreshAt = $job[$candidate];
          break;
        }
      }
      $out[] = [
        'job_id'            => (string)($job['job_id'] ?? basename($path, '.json')),
        'feed_url'          => $feedUrl,
        'source_url'        => $sourceUrl,
        'mode'              => isset($job['mode']) ? (string)$job['mode'] : 'custom',
        'format'            => isset($job['format']) ? (string)$job['format'] : 'rss',
        'items_count'       => isset($job['items_count']) ? (int)$job['items_count'] : null,
        'last_refresh_at'   => $lastRefreshAt,
        'last_refresh_note' => isset($job['last_refresh_note']) ? (string)$job['last_refresh_note'] : null,
        'prefer_native'     => !empty($job['prefer_native']),
        'native_source'     => isset($job['native_source']) ? (string)$job['native_source'] : null,
      ];
    }
    return $out;
  }
}

if (!function_exists('sfm_recent_feeds_card_html')) {
  /**
   * Render a reusable recent-feeds card.
   *
   * @param ?string $excludeSource Optional source URL to omit from the list.
   * @param int     $limit         Max items to show (1..50).
   * @param array   $options       Additional options: title, note, empty, button_label, refreshing_label.
   */
  function sfm_recent_feeds_card_html(?string $excludeSource = null, int $limit = 6, array $options = []): string
  {
    $limit = max(1, min(10, $limit));
    $jobs  = sfm_jobs_list_recent($limit + 6);

    if ($excludeSource !== null && $excludeSource !== '') {
      $jobs = array_values(array_filter($jobs, static function ($job) use ($excludeSource) {
        return empty($job['source_url']) || strcasecmp((string)$job['source_url'], $excludeSource) !== 0;
      }));
    }

    if (count($jobs) > $limit) {
      $jobs = array_slice($jobs, 0, $limit);
    }

    $title            = isset($options['title']) ? (string)$options['title'] : 'Recent feeds';
    $note             = isset($options['note']) ? trim((string)$options['note']) : '';
    $emptyState       = isset($options['empty']) ? (string)$options['empty'] : 'No feeds yet. Generate one to see it listed here.';
    $buttonLabel      = isset($options['button_label']) ? (string)$options['button_label'] : 'Refresh';
    $refreshingLabel  = isset($options['refreshing_label']) ? (string)$options['refreshing_label'] : 'Refreshing…';

    try {
    $indicatorId = 'rf-ind-' . substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
      $indicatorId = 'rf-ind-' . substr(uniqid('', true), -8);
    }

    $now = time();
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

    $refreshParams = http_build_query(array_filter([
      'limit'  => $limit,
      'source' => $excludeSource ?: null,
    ]));
    $refreshUrl = '/recent_feeds.php' . ($refreshParams ? '?' . $refreshParams : '');

    ob_start();
    ?>
    <div class="card shadow-sm recent-feeds-card">
      <div class="card-body recent-feeds-body">
        <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
          <h3 class="h6 fw-semibold mb-0"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="d-flex align-items-center gap-2">
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              hx-get="<?= htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8'); ?>"
              hx-target="closest .recent-feeds-card"
              hx-swap="outerHTML"
              hx-indicator="#<?= htmlspecialchars($indicatorId, ENT_QUOTES, 'UTF-8'); ?>"
              aria-label="Refresh recent feeds"
            >
              <?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <div id="<?= htmlspecialchars($indicatorId, ENT_QUOTES, 'UTF-8'); ?>" class="htmx-indicator small text-secondary"><?= htmlspecialchars($refreshingLabel, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>
        <?php if ($note !== ''): ?>
          <p class="text-secondary small mb-2"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if (!$jobs): ?>
          <p class="text-secondary small mb-0"><?= htmlspecialchars($emptyState, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
          <ul class="list-unstyled recent-feeds-list mb-0">
            <?php foreach ($jobs as $job): ?>
              <?php
                $feedUrl    = (string)($job['feed_url'] ?? '');
                $sourceUrl  = (string)($job['source_url'] ?? '');
                $format     = strtoupper((string)($job['format'] ?? 'rss'));
                $itemsCount = $job['items_count'] ?? null;
                $noteText   = (string)($job['last_refresh_note'] ?? '');
                $mode       = (string)($job['mode'] ?? 'custom');
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
                        <span><?= (int)$itemsCount; ?> items</span>
                      <?php endif; ?>
                      <?php if ($host): ?>
                        <span class="ms-1">· <?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="text-end small text-secondary">
                    <div><?= htmlspecialchars($lastAt, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($noteText !== ''): ?>
                      <div class="text-muted"><?= htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return trim((string)ob_get_clean());
  }
}
