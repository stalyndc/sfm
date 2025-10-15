<?php

/**
 * generate.php — SimpleFeedMaker
 * - Fetch via includes/http.php (http_get)
 * - Extract via includes/extract.php (sfm_extract_items / sfm_discover_feeds)
 * - Optional native feed autodiscovery
 *
 * POST:
 *   - url (string, required)
 *   - limit (int, optional; 1..50, default 10)
 *   - format (rss|atom|jsonfeed, optional; default rss)
 *   - prefer_native (optional; "1"/"true"/"on") — try site’s advertised feed first
 */

declare(strict_types=1);

/* ---- DEBUG (optional)
if (defined('SFM_DEBUG') && SFM_DEBUG) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}
---- */

// ---------------------------------------------------------------------
// Includes (hard-fail with JSON if missing)
// ---------------------------------------------------------------------
$httpFile  = __DIR__ . '/includes/http.php';
$extFile   = __DIR__ . '/includes/extract.php';
$secFile   = __DIR__ . '/includes/security.php';
$cacheFile = __DIR__ . '/includes/feed_cache.php';

if (!is_file($httpFile) || !is_readable($httpFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/http.php'], JSON_UNESCAPED_SLASHES);
  exit;
}
if (!is_file($extFile) || !is_readable($extFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/extract.php'], JSON_UNESCAPED_SLASHES);
  exit;
}
if (!is_file($secFile) || !is_readable($secFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/security.php'], JSON_UNESCAPED_SLASHES);
  exit;
}
if (!is_file($cacheFile) || !is_readable($cacheFile)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Server missing includes/feed_cache.php'], JSON_UNESCAPED_SLASHES);
  exit;
}

require_once $secFile;    // secure_assert_post(), csrf helpers
require_once $httpFile;   // http_get(), http_head(), http_multi_get(), sfm_log_event()
require_once $extFile;    // sfm_extract_items(), sfm_discover_feeds()
require_once $cacheFile;  // sfm_feed_cache_* helpers
require_once __DIR__ . '/includes/feed_validator.php';
require_once __DIR__ . '/includes/enrich.php';
require_once __DIR__ . '/includes/jobs.php';

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------
if (!defined('APP_NAME')) {
  define('APP_NAME', 'SimpleFeedMaker');
}
if (!defined('DEFAULT_FMT')) {
  define('DEFAULT_FMT', 'rss');
}
if (!defined('DEFAULT_LIM')) {
  define('DEFAULT_LIM', 10);
}
if (!defined('MAX_LIM')) {
  define('MAX_LIM', 50);
}
if (!defined('FEEDS_DIR')) {
  define('FEEDS_DIR', __DIR__ . '/feeds');
}

$__sfmResponseMode = sfm_detect_response_mode();
$__sfmCacheKey      = null;
$__sfmCacheEntry    = null;
$__sfmCacheTtl      = max(0, sfm_resolve_feed_cache_ttl());
$__sfmCacheEnabled  = $__sfmCacheTtl > 0;

function sfm_detect_response_mode(): string
{
  $explicitSources = [
    $_GET['response_mode'] ?? null,
    $_POST['response_mode'] ?? null,
    $_GET['response'] ?? null,
    $_POST['response'] ?? null,
  ];

  foreach ($explicitSources as $explicit) {
    if ($explicit === null) {
      continue;
    }
    $normalized = strtolower(trim((string) $explicit));
    if ($normalized === 'json' || $normalized === 'fragment' || $normalized === 'page') {
      return $normalized;
    }
  }

  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  if (strpos($accept, 'application/json') !== false) {
    return 'json';
  }

  if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    return 'fragment';
  }

  if (isset($_POST['_sfm_page']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    return 'page';
  }

  $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  $looksBrowser = $ua !== '' && (strpos($ua, 'mozilla') !== false || strpos($ua, 'safari') !== false || strpos($ua, 'chrome') !== false || strpos($ua, 'edge') !== false);
  if ($looksBrowser && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    return 'page';
  }

  return 'json';
}

function sfm_prepare_response_headers(string $mode, int $status): void
{
  http_response_code($status);
  header('Cache-Control: no-store, max-age=0, must-revalidate');
  if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
  } else {
    header('Content-Type: text/html; charset=utf-8');
  }
}

function sfm_human_time_diff(int $epoch, ?int $now = null): string
{
  $now  = $now ?? time();
  $diff = max(0, $now - $epoch);

  if ($diff < 45) {
    return 'just now';
  }
  if ($diff < 90) {
    return 'a minute ago';
  }
  if ($diff < 2700) {
    $mins = max(2, (int) round($diff / 60));
    return $mins . ' minutes ago';
  }
  if ($diff < 5400) {
    return 'an hour ago';
  }
  if ($diff < 86400) {
    $hours = max(2, (int) round($diff / 3600));
    return $hours . ' hours ago';
  }
  if ($diff < 172800) {
    return 'yesterday';
  }
  if ($diff < 2592000) {
    $days = max(2, (int) round($diff / 86400));
    return $days . ' days ago';
  }
  if ($diff < 5184000) {
    return 'a month ago';
  }
  if ($diff < 31536000) {
    $months = max(2, (int) round($diff / 2592000));
    return $months . ' months ago';
  }

  $years = max(1, (int) round($diff / 31536000));
  return $years === 1 ? 'a year ago' : $years . ' years ago';
}

function sfm_validator_link(string $feedUrl, string $format): string
{
  $format = strtolower($format);
  $isJson = $format === 'jsonfeed'
    || str_ends_with(strtolower($feedUrl), '.json')
    || str_contains(strtolower($feedUrl), '.json?');

  $target = $isJson
    ? 'https://validator.jsonfeed.org/?url='
    : 'https://validator.w3.org/feed/check.cgi?url=';

  return $target . rawurlencode($feedUrl);
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $health
 * @param array<string, mixed> $context
 */
function sfm_finalize_payload(array $payload, array $health, array $context = []): array
{
  $now       = time();
  $fromCache = !empty($context['cache_hit']);
  $isStale   = !empty($context['stale']);
  $refreshTs = isset($health['last_refresh_epoch']) ? (int) $health['last_refresh_epoch'] : null;

  $origin = $payload['status_origin'] ?? ($payload['used_native'] ?? false ? 'native feed' : 'custom parse');
  if ($isStale) {
    $origin = 'stale cache';
  } elseif ($fromCache && strpos($origin, 'cached') === false) {
    $origin .= ' (cached)';
  }

  $timeLabel = $refreshTs ? sfm_human_time_diff($refreshTs, $now) : 'just now';
  $payload['status_breadcrumb'] = $origin . ' · ' . $timeLabel;
  $payload['from_cache'] = $fromCache;
  $payload['is_stale']   = $isStale;

  if (!empty($context['stale_reason'])) {
    $payload['stale_reason'] = $context['stale_reason'];
  } elseif (!empty($health['stale_reason']) && $isStale) {
    $payload['stale_reason'] = (string) $health['stale_reason'];
  }

  return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $health
 * @param array<string, mixed> $context
 */
function sfm_render_result_fragment(array $payload, array $health, array $context = []): string
{
  $feedUrl      = (string) ($payload['feed_url'] ?? '');
  $format       = strtolower((string) ($payload['format'] ?? 'rss'));
  $items        = $payload['items'] ?? null;
  $status       = (string) ($payload['status_breadcrumb'] ?? '');
  $usedNative   = !empty($payload['used_native']);
  $nativeSource = (string) ($payload['native_source'] ?? '');
  $fromCache    = !empty($payload['from_cache']);
  $isStale      = !empty($payload['is_stale']);
  $staleReason  = (string) ($payload['stale_reason'] ?? ($health['stale_reason'] ?? ''));

  $warnings = [];
  if (isset($payload['validation']['warnings']) && is_array($payload['validation']['warnings'])) {
    $warnings = $payload['validation']['warnings'];
  }

  $validatorUrl = sfm_validator_link($feedUrl, $format);
  $itemsLabel   = $items === null ? '—' : (string) $items;

  $lastRefreshTs = isset($health['last_refresh_epoch']) ? (int) $health['last_refresh_epoch'] : null;
  $lastAttemptTs = isset($health['last_attempt_epoch']) ? (int) $health['last_attempt_epoch'] : null;
  $lastError     = isset($health['last_error_message']) ? trim((string) $health['last_error_message']) : '';
  $lastErrorCode = isset($health['last_error_code']) ? trim((string) $health['last_error_code']) : '';

  $refreshIso = $lastRefreshTs ? gmdate('c', $lastRefreshTs) : '';
  $attemptIso = $lastAttemptTs ? gmdate('c', $lastAttemptTs) : '';

  $refreshLabel = $lastRefreshTs ? sfm_human_time_diff($lastRefreshTs) : 'n/a';
  $attemptLabel = $lastAttemptTs ? sfm_human_time_diff($lastAttemptTs) : 'n/a';

  $formatLabel = strtoupper($format ?: 'RSS');

  ob_start();
  ?>
  <div id="resultRegion" class="vstack gap-3">
    <div id="resultCard" class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3">
          <h2 class="h5 fw-semibold mb-0">Feed ready</h2>
          <?php if ($isStale): ?>
            <span class="badge bg-warning text-dark">STALE</span>
          <?php elseif ($fromCache): ?>
            <span class="badge bg-secondary-subtle text-secondary">CACHED</span>
          <?php endif; ?>
        </div>
        <div id="resultBox" class="vstack gap-3">
          <div class="mb-2">
            <label class="form-label">Your feed URL</label>
            <div class="input-group">
              <input
                type="text"
                class="form-control mono"
                value="<?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>"
                readonly
                data-focus-target
              >
              <button
                type="button"
                class="btn btn-outline-secondary copy-btn"
                data-copy-text="<?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>"
                data-copy-label="Copy"
                data-copy-done-label="Copied!"
              >Copy</button>
              <a class="btn btn-outline-success" href="<?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open</a>
            </div>
          </div>

          <div class="d-flex flex-wrap align-items-center gap-2">
            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($validatorUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Validate feed</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-reset-feed data-reset-url="/">Start new feed</button>
          </div>

          <div class="muted mt-2 small">
            <span>Format: <span class="mono text-uppercase"><?= htmlspecialchars($formatLabel, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <?php if ($itemsLabel !== '—'): ?>
              <span class="ms-2">Items: <span class="mono"><?= htmlspecialchars($itemsLabel, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <?php endif; ?>
            <?php if ($status !== ''): ?>
              <span class="ms-2"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
          </div>

          <?php if ($usedNative && $nativeSource !== ''): ?>
            <div class="muted small">Using site feed: <a class="link-highlight" href="<?= htmlspecialchars($nativeSource, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?= htmlspecialchars($nativeSource, ENT_QUOTES, 'UTF-8'); ?></a></div>
          <?php endif; ?>

          <?php if ($isStale && $staleReason !== ''): ?>
            <div class="alert alert-warning mt-2 mb-0 small">Showing cached results while we retry. Last attempt: <?= htmlspecialchars($staleReason, ENT_QUOTES, 'UTF-8'); ?>.</div>
          <?php endif; ?>

          <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning mt-3 mb-0 d-flex align-items-center gap-2">
              <span class="badge bg-warning text-dark">Validator</span>
              <div class="small mb-0">Validator spotted <?= count($warnings) === 1 ? 'a warning' : 'some warnings'; ?>. Use <em>Validate feed</em> for details.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card result-health">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3">
          <h3 class="h6 fw-semibold mb-0">Feed health</h3>
          <?php if ($isStale): ?>
            <span class="badge bg-warning text-dark">STALE</span>
          <?php else: ?>
            <span class="badge bg-success-subtle text-success">FRESH</span>
          <?php endif; ?>
        </div>
        <dl class="row small mb-0">
          <dt class="col-5">Last refresh</dt>
          <dd class="col-7 text-secondary">
            <?php if ($lastRefreshTs): ?>
              <time datetime="<?= htmlspecialchars($refreshIso, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($refreshLabel, ENT_QUOTES, 'UTF-8'); ?></time>
            <?php else: ?>
              <span>n/a</span>
            <?php endif; ?>
          </dd>
          <dt class="col-5">Items parsed</dt>
          <dd class="col-7 text-secondary"><?= htmlspecialchars($itemsLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
          <dt class="col-5">Last attempt</dt>
          <dd class="col-7 text-secondary">
            <?php if ($lastAttemptTs): ?>
              <time datetime="<?= htmlspecialchars($attemptIso, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($attemptLabel, ENT_QUOTES, 'UTF-8'); ?></time>
            <?php else: ?>
              <span>n/a</span>
            <?php endif; ?>
          </dd>
          <dt class="col-5">Last error</dt>
          <dd class="col-7 text-secondary">
            <?php if ($lastError !== ''): ?>
              <?= htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8'); ?>
              <?php if ($lastErrorCode !== ''): ?>
                <span class="ms-1 text-uppercase text-muted">(<?= htmlspecialchars($lastErrorCode, ENT_QUOTES, 'UTF-8'); ?>)</span>
              <?php endif; ?>
            <?php else: ?>
              <span>None</span>
            <?php endif; ?>
          </dd>
        </dl>
      </div>
    </div>
    <?= sfm_recent_feeds_card_html($payload['source_url'] ?? null); ?>
  </div>
  <?php
  return ob_get_clean();
}

function sfm_render_error_fragment(string $message, array $details = []): string
{
  ob_start();
  ?>
  <div id="resultRegion" class="vstack gap-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h5 fw-semibold mb-3">We hit a snag</h2>
        <div class="alert alert-danger mb-3">
          <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php if (!empty($details['hints']) && is_array($details['hints'])): ?>
          <ul class="small text-secondary mb-3">
            <?php foreach ($details['hints'] as $hint): ?>
              <li><?= htmlspecialchars((string) $hint, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-reset-feed data-reset-url="/">Try again</button>
        </div>
      </div>
    </div>
    <?= sfm_recent_feeds_card_html(); ?>
  </div>
  <?php
  return ob_get_clean();
}

function sfm_render_full_page(array $payload, array $health, array $context = []): string
{
  $pageTitle = 'Feed ready — SimpleFeedMaker';
  $activeNav = '';

  ob_start();
  require __DIR__ . '/includes/page_head.php';
  require __DIR__ . '/includes/page_header.php';
  ?>
  <main class="py-4 py-md-5 hero-section">
    <div class="container">
      <div class="row g-4 justify-content-center">
        <div class="col-12 col-lg-10 col-xl-8">
          <?= sfm_render_result_fragment($payload, $health, $context); ?>
        </div>
        <div class="col-12 col-lg-10 col-xl-8">
          <div class="card mt-3">
            <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
              <div>
                <strong>Need another feed?</strong>
                <div class="small text-secondary">Head back to the generator to start fresh.</div>
              </div>
              <a class="btn btn-outline-primary" href="/">Create another feed</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://unpkg.com/htmx.org@1.9.12" crossorigin="anonymous" defer></script>
  <script src="/assets/js/main.js" defer></script>

  <?php require __DIR__ . '/includes/page_footer.php'; ?>
  <?php
  return ob_get_clean();
}

function sfm_render_error_page(string $message, array $details = []): string
{
  $pageTitle = 'Feed error — SimpleFeedMaker';
  $activeNav = '';

  ob_start();
  require __DIR__ . '/includes/page_head.php';
  require __DIR__ . '/includes/page_header.php';
  ?>
  <main class="py-5 hero-section">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-8 col-xl-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h1 class="h4 fw-semibold mb-3">We couldn’t generate that feed</h1>
              <div class="alert alert-danger">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
              </div>
              <?php if (!empty($details['hints']) && is_array($details['hints'])): ?>
                <ul class="small text-secondary mb-3">
                  <?php foreach ($details['hints'] as $hint): ?>
                    <li><?= htmlspecialchars((string) $hint, ENT_QUOTES, 'UTF-8'); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <a class="btn btn-outline-primary" href="/">Back to generator</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <?php require __DIR__ . '/includes/page_footer.php'; ?>
  <?php
  return ob_get_clean();
}

function sfm_output_success(array $payload, array $health, array $context = []): void
{
  global $__sfmResponseMode;

  $mode    = $__sfmResponseMode ?? 'json';
  $payload = sfm_finalize_payload($payload, $health, $context);

  sfm_prepare_response_headers($mode, 200);

  if ($mode === 'json') {
    $body = $payload;
    $body['health'] = $health;
    if (!empty($context)) {
      $body['meta'] = $context;
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
  } elseif ($mode === 'fragment') {
    echo sfm_render_result_fragment($payload, $health, $context);
  } else {
    echo sfm_render_full_page($payload, $health, $context);
  }
  exit;
}

function sfm_try_cache_fallback(string $message, int $http, array $details = []): bool
{
  global $__sfmCacheEntry, $__sfmCacheKey;

  if (!$__sfmCacheEntry || !is_array($__sfmCacheEntry)) {
    return false;
  }
  if (empty($__sfmCacheEntry['payload']) || empty($__sfmCacheEntry['health'])) {
    return false;
  }

  $payload = $__sfmCacheEntry['payload'];
  $health  = $__sfmCacheEntry['health'];

  if (!is_array($payload) || !is_array($health)) {
    return false;
  }

  $payload['status_origin'] = 'stale cache';
  $payload['stale_reason']  = $message;
  $payload['ok']            = true;

  $health['is_stale']         = true;
  $health['stale_reason']     = $message;
  $health['last_error_message']= $message;
  if (isset($details['error_code'])) {
    $health['last_error_code'] = $details['error_code'];
  }
  $health['last_attempt_epoch'] = time();

  sfm_feed_cache_update_health($__sfmCacheKey, $__sfmCacheEntry, $health);

  $context = [
    'cache_hit'    => true,
    'stale'        => true,
    'stale_reason' => $message,
  ];
  if (isset($details['error_code'])) {
    $context['error_code'] = $details['error_code'];
  }

  sfm_output_success($payload, $health, $context);
  return true;
}

function sfm_output_error(string $message, int $http = 400, array $details = []): void
{
  global $__sfmResponseMode;

  if (sfm_try_cache_fallback($message, $http, $details)) {
    return;
  }

  $mode = $__sfmResponseMode ?? 'json';
  sfm_prepare_response_headers($mode, $http);

  if ($mode === 'json') {
    $body = ['ok' => false, 'message' => $message];
    if (!empty($details)) {
      $body['details'] = $details;
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
  } elseif ($mode === 'fragment') {
    echo sfm_render_error_fragment($message, $details);
  } else {
    echo sfm_render_error_page($message, $details);
  }
  exit;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $context
 */
function sfm_emit_result(array $result, array $context = []): void
{
  global $__sfmCacheKey, $__sfmCacheTtl, $__sfmCacheEnabled;

  $payload = $result['payload'] ?? null;
  $health  = $result['health'] ?? null;

  if (!is_array($payload) || !is_array($health)) {
    sfm_output_error('Internal server error (malformed response).', 500);
  }

  if ($__sfmCacheEnabled && !empty($__sfmCacheKey)) {
    sfm_feed_cache_store($__sfmCacheKey, $payload, $health, $__sfmCacheTtl);
    $GLOBALS['__sfmCacheEntry'] = [
      'payload'    => $payload,
      'health'     => $health,
      'expires_at' => time() + $__sfmCacheTtl,
    ];
  }

  $context = array_merge(['cache_hit' => false, 'stale' => false], $context);
  sfm_output_success($payload, $health, $context);
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function sfm_json_fail(string $msg, int $http = 400, array $extra = []): void
{
  if (function_exists('sfm_log_event')) {
    sfm_log_event('parse', ['phase' => 'fail', 'reason' => $msg, 'http' => $http] + $extra);
  }
  sfm_output_error($msg, $http, $extra);
}

function ensure_feeds_dir(): void
{
  if (!is_dir(FEEDS_DIR)) @mkdir(FEEDS_DIR, 0775, true);
  if (!is_dir(FEEDS_DIR) || !is_writable(FEEDS_DIR)) {
    sfm_json_fail('Server cannot write to /feeds directory.', 500);
  }
}

function ensure_http_url_or_fail(string $url, string $field = 'url'): void
{
  if (!sfm_is_http_url($url)) {
    sfm_json_fail('Only http:// or https:// URLs are allowed.', 400, ['field' => $field]);
  }
}

function sfm_filter_native_candidates(array $cands, string $sourceUrl): array
{
  if (!$cands) return [];

  $cands = array_values(array_filter($cands, function ($cand) {
    if (!isset($cand['href'])) return false;
    $href = (string)$cand['href'];
    if (!sfm_is_http_url($href)) return false;
    return sfm_url_is_public($href);
  }));

  if (!$cands) return [];

  $pageHost = parse_url($sourceUrl, PHP_URL_HOST);
  usort($cands, function ($a, $b) use ($pageHost) {
    $rank = function ($t) {
      $t = strtolower($t ?? '');
      if (strpos($t, 'rss') !== false)  return 3;
      if (strpos($t, 'atom') !== false) return 2;
      if (strpos($t, 'json') !== false) return 1;
      if (strpos($t, 'xml') !== false)  return 2;
      return 0;
    };

    $ah = parse_url($a['href'] ?? '', PHP_URL_HOST) ?: '';
    $bh = parse_url($b['href'] ?? '', PHP_URL_HOST) ?: '';
    $sameA = (strcasecmp($ah, $pageHost ?? '') === 0) ? 1 : 0;
    $sameB = (strcasecmp($bh, $pageHost ?? '') === 0) ? 1 : 0;
    if ($sameA !== $sameB) return $sameB <=> $sameA;
    return $rank($b['type'] ?? '') <=> $rank($a['type'] ?? '');
  });

  return $cands;
}

function sfm_attempt_native_download(string $requestedUrl, array $candidate, int $limit, bool $preferNativeFlag, string $note, bool $strict, string $logPhase = 'native'): ?array
{
  $href = isset($candidate['href']) ? (string)$candidate['href'] : '';
  if ($href === '') {
    if ($strict) {
      sfm_json_fail('Native feed is missing an href.', 400);
    }
    return null;
  }

  if (!sfm_is_http_url($href)) {
    if ($strict) {
      sfm_json_fail('Native feed uses unsupported scheme.', 400);
    }
    return null;
  }
  if (!sfm_url_is_public($href)) {
    if ($strict) {
      sfm_json_fail('Native feed is not accessible.', 400);
    }
    return null;
  }

  /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $feed */
  $feed = http_get($href, [
    'accept' => 'application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml;q=0.9, */*;q=0.8'
  ]);

  if (!$feed['ok'] || $feed['status'] < 200 || $feed['status'] >= 400 || $feed['body'] === '') {
    return null;
  }

  [$fmtDetected, $ext] = detect_feed_format_and_ext($feed['body'], $feed['headers'], $href);
  $normalization = sfm_normalize_feed($feed['body'], $fmtDetected, $href);
  if ($normalization) {
    $feed['body'] = $normalization['body'];
    $fmtDetected  = $normalization['format'];
    $ext          = $normalization['ext'];
    $note        .= ' (' . $normalization['note'] . ')';
  }
  $finalFormat = $fmtDetected ?: 'rss';
  $ext         = ($finalFormat === 'jsonfeed') ? 'json' : 'xml';

  ensure_feeds_dir();
  $feedId   = md5($href . '|' . microtime(true));
  $filename = $feedId . '.' . $ext;
  $path     = FEEDS_DIR . '/' . $filename;

  if (@file_put_contents($path, $feed['body']) === false) {
    if ($strict) {
      sfm_json_fail('Failed to save native feed file.', 500);
    }
    return null;
  }

  $feedUrl = rtrim(app_url_base(), '/') . '/feeds/' . $filename;

  $job = sfm_job_register([
    'source_url'        => $requestedUrl,
    'native_source'     => $href,
    'mode'              => 'native',
    'format'            => $finalFormat,
    'limit'             => $limit,
    'feed_filename'     => $filename,
    'feed_url'          => $feedUrl,
    'prefer_native'     => $preferNativeFlag,
    'last_refresh_code' => $feed['status'],
    'last_refresh_note' => $note,
  ]);

  if (function_exists('sfm_log_event')) {
    $logData = [
      'phase'        => $logPhase,
      'source'       => $href,
      'format'       => $finalFormat,
      'saved'        => basename($filename),
      'bytes'        => strlen($feed['body']),
      'status'       => $feed['status'],
      'job_id'       => $job['job_id'] ?? null,
    ];
    if ($normalization) {
      $logData['normalized'] = $normalization['note'];
    }
    sfm_log_event('parse', $logData);
  }

  $now = time();
  $statusOrigin = $preferNativeFlag ? 'native feed' : 'native fallback';
  if ($normalization && !empty($normalization['note'])) {
    $statusOrigin .= ' (normalized)';
  }

  $payload = [
    'ok'            => true,
    'feed_url'      => $feedUrl,
    'format'        => $finalFormat,
    'items'         => null,
    'used_native'   => true,
    'native_source' => $href,
    'status_origin' => $statusOrigin,
    'job_id'        => $job['job_id'] ?? null,
    'normalized'    => $normalization['note'] ?? null,
    'source_url'    => $requestedUrl,
  ];

  $health = [
    'last_refresh_epoch'  => $now,
    'last_attempt_epoch'  => $now,
    'items_count'         => null,
    'last_error_message'  => null,
    'last_error_code'     => null,
    'is_stale'            => false,
    'stale_reason'        => null,
    'source_url'          => $requestedUrl,
    'format'              => $finalFormat,
    'limit'               => $limit,
    'prefer_native'       => $preferNativeFlag,
    'native_source'       => $href,
    'mode'                => 'native',
    'last_refresh_note'   => $note,
  ];

  return [
    'payload' => $payload,
    'health'  => $health,
  ];
}

function sfm_try_native_fallback(string $html, string $requestedUrl, int $limit): ?array
{
  $cands = sfm_discover_feeds($html, $requestedUrl);
  $cands = sfm_filter_native_candidates($cands, $requestedUrl);
  if (!$cands) return null;

  $pick = $cands[0];
  return sfm_attempt_native_download($requestedUrl, $pick, $limit, false, 'native fallback', false, 'native-fallback');
}

// ---------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------
secure_assert_post('generate', 2, 20);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  sfm_json_fail('Use POST.', 405);
}

$url              = trim((string)($_POST['url'] ?? ''));
$limit            = (int)($_POST['limit'] ?? DEFAULT_LIM);
$format           = strtolower(trim((string)($_POST['format'] ?? DEFAULT_FMT)));
$preferNative     = isset($_POST['prefer_native']) && in_array(strtolower((string)($_POST['prefer_native'])), ['1', 'true', 'on', 'yes'], true);
$itemSelectorCss  = trim((string)($_POST['item_selector'] ?? ''));
$titleSelectorCss = trim((string)($_POST['title_selector'] ?? ''));
$summarySelectorCss = trim((string)($_POST['summary_selector'] ?? ''));

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
  sfm_json_fail('Please provide a valid URL (including http:// or https://).');
}
ensure_http_url_or_fail($url, 'url');
if (!sfm_url_is_public($url)) {
  sfm_json_fail('The source URL must resolve to a public host.', 400, ['field' => 'url']);
}
$limit  = max(1, min(MAX_LIM, $limit));
if (!in_array($format, ['rss', 'atom', 'jsonfeed'], true)) $format = DEFAULT_FMT;

$extractionOptions = [];
if ($itemSelectorCss !== '') {
  $itemSelectorXpath = sfm_css_to_xpath($itemSelectorCss, false);
  if ($itemSelectorXpath === null) {
    sfm_json_fail('Unsupported CSS selector for item_selector.', 400, [
      'field'      => 'item_selector',
      'error_code' => 'invalid_selector',
    ]);
  }
  $extractionOptions['item_selector'] = $itemSelectorCss;
  $extractionOptions['item_selector_xpath'] = $itemSelectorXpath;
}

if ($titleSelectorCss !== '') {
  $titleSelectorXpath = sfm_css_to_xpath($titleSelectorCss, true);
  if ($titleSelectorXpath === null) {
    sfm_json_fail('Unsupported CSS selector for title_selector.', 400, [
      'field'      => 'title_selector',
      'error_code' => 'invalid_selector',
    ]);
  }
  $extractionOptions['title_selector'] = $titleSelectorCss;
  $extractionOptions['title_selector_xpath'] = $titleSelectorXpath;
}

if ($summarySelectorCss !== '') {
  $summarySelectorXpath = sfm_css_to_xpath($summarySelectorCss, true);
  if ($summarySelectorXpath === null) {
    sfm_json_fail('Unsupported CSS selector for summary_selector.', 400, [
      'field'      => 'summary_selector',
      'error_code' => 'invalid_selector',
    ]);
  }
  $extractionOptions['summary_selector'] = $summarySelectorCss;
  $extractionOptions['summary_selector_xpath'] = $summarySelectorXpath;
}

$cacheOptions = [
  'item_selector'   => $extractionOptions['item_selector'] ?? '',
  'title_selector'  => $extractionOptions['title_selector'] ?? '',
  'summary_selector'=> $extractionOptions['summary_selector'] ?? '',
];

$cacheKey = sfm_feed_cache_key($url, $format, $limit, $preferNative, $cacheOptions);
$__sfmCacheKey = $cacheKey;
$cacheEntry = ($__sfmCacheEnabled) ? sfm_feed_cache_read($cacheKey) : null;
$__sfmCacheEntry = $cacheEntry;

if (is_array($cacheEntry) && sfm_feed_cache_is_fresh($cacheEntry)) {
  $cachedPayload = $cacheEntry['payload'] ?? null;
  $cachedHealth  = $cacheEntry['health'] ?? null;
  if (is_array($cachedPayload) && is_array($cachedHealth)) {
    if (!isset($cachedPayload['status_origin'])) {
      $cachedPayload['status_origin'] = !empty($cachedPayload['used_native']) ? 'native feed' : 'custom parse';
    }
    $cachedHealth['last_attempt_epoch'] = time();
    $cachedHealth['is_stale'] = false;
    if ($__sfmCacheEnabled) {
      sfm_feed_cache_update_health($cacheKey, $cacheEntry, $cachedHealth);
      $updated = sfm_feed_cache_read($cacheKey);
      if (is_array($updated)) {
        $cachedPayload = $updated['payload'] ?? $cachedPayload;
        $cachedHealth  = $updated['health'] ?? $cachedHealth;
        $GLOBALS['__sfmCacheEntry'] = $updated;
      }
    }
    sfm_output_success($cachedPayload, $cachedHealth, ['cache_hit' => true, 'stale' => false]);
  }
}

// Same-origin guard (soft)
if (!empty($_SERVER['HTTP_ORIGIN'])) {
  $origin = $_SERVER['HTTP_ORIGIN'];
  if (!sfm_origin_is_allowed($origin, app_url_base())) sfm_json_fail('Cross-origin requests are not allowed.', 403);
}

// ---------------------------------------------------------------------
// A) Prefer native feed if requested
// ---------------------------------------------------------------------
if ($preferNative) {
  /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $pageResp */
  $pageResp = http_get($url, [
    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
  ]);

  if ($pageResp['ok'] && $pageResp['status'] >= 200 && $pageResp['status'] < 400 && $pageResp['body'] !== '') {
    $cands = sfm_discover_feeds($pageResp['body'], $url);
    $cands = sfm_filter_native_candidates($cands, $url);
    if ($cands) {
      $pick = $cands[0];
      $nativeResult = sfm_attempt_native_download($url, $pick, $limit, true, 'native download', true, 'native');
      if ($nativeResult !== null) {
        sfm_emit_result($nativeResult);
      }
    }
  }
  // Page fetch failed or no native match → fall through
}

// ---------------------------------------------------------------------
// B) Custom parse path
// ---------------------------------------------------------------------
/** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $page */
$page = http_get($url, [
  'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
]);

if (!$page['ok'] || $page['status'] < 200 || $page['status'] >= 400 || $page['body'] === '') {
  $status = (int)$page['status'];
  $details = ['status' => $status];
  if (!empty($page['error'])) {
    $details['error'] = (string)$page['error'];
  }
  if ($page['error'] === 'body_too_large') {
    $limitBytes = defined('SFM_HTTP_MAX_BYTES') ? (int) SFM_HTTP_MAX_BYTES : 0;
    if ($limitBytes > 0) {
      $details['size_limit_bytes'] = $limitBytes;
      $message = 'The page is larger than the allowed download size (' . round($limitBytes / 1048576, 1) . ' MB).';
    } else {
      $message = 'The page is larger than the allowed download size.';
    }
    $details['error_code'] = 'body_too_large';
    sfm_json_fail($message, 502, $details);
  }
  $message = 'Failed to fetch the page.';
  $errorCode = 'fetch_failed';

  if (!$page['ok'] && $status === 0) {
    $message = 'The request timed out or was blocked before receiving a response.';
    $errorCode = 'network_error';
  } elseif ($status === 0) {
    $message = 'The server returned an unexpected response.';
  } elseif ($status === 401 || $status === 403) {
    $message = 'The site returned HTTP ' . $status . ' (access denied). It may require login or block bots.';
    $errorCode = 'http_' . $status;
  } elseif ($status === 404) {
    $message = 'The page could not be found (HTTP 404).';
    $errorCode = 'http_404';
  } elseif ($status === 410 || $status === 451) {
    $message = 'The page is no longer available (HTTP ' . $status . ').';
    $errorCode = 'http_' . $status;
  } elseif ($status === 429) {
    $message = 'The site rate-limited our request (HTTP 429). Try again later.';
    $errorCode = 'http_429';
  } elseif ($status >= 500 && $status < 600) {
    $message = 'The site returned an error (HTTP ' . $status . ').';
    $errorCode = 'http_' . $status;
  }

  if ($page['body'] === '') {
    $message .= ' The response body was empty.';
    $errorCode = $errorCode === 'fetch_failed' ? 'empty_body' : $errorCode;
  }

  $details['error_code'] = $errorCode;
  sfm_json_fail($message, 502, $details);
}

$extractDebug = [];
$items = sfm_extract_items($page['body'], $url, $limit, $extractionOptions, $extractDebug);

if (count($items) < $limit) {
  $extra = sfm_collect_paginated_items($page['body'], $url, $limit, $items, $extractionOptions);
  if ($extra) {
    $items = array_merge($items, $extra);
    $items = sfm_unique_items($items, $limit);
  }
}

if (empty($items)) {
  if (!$preferNative && $page['ok'] && $page['status'] >= 200 && $page['status'] < 400 && $page['body'] !== '') {
    $nativeFallback = sfm_try_native_fallback($page['body'], $url, $limit);
    if ($nativeFallback !== null) {
      sfm_emit_result($nativeFallback, ['fallback' => true]);
    }
  }
  sfm_fail_with_extraction_diagnostics($extractDebug, $extractionOptions);
}

$items = sfm_enrich_items_with_article_metadata($items, min(6, $limit));
$items = sfm_unique_items($items, $limit);

$title = APP_NAME . ' Feed';
$desc  = 'Custom feed generated by ' . APP_NAME;

$feedId   = md5($url . '|' . microtime(true));
$ext      = ($format === 'jsonfeed') ? 'json' : 'xml';
$filename = $feedId . '.' . $ext;
$feedUrl  = rtrim(app_url_base(), '/') . '/feeds/' . $filename;

switch ($format) {
  case 'jsonfeed':
    $content = build_jsonfeed($title, $url, $desc, $items, $feedUrl);
    break;
  case 'atom':
    $content = build_atom($title, $url, $desc, $items);
    break;
  default:
    $content = build_rss($title, $url, $desc, $items);
    break;
}

$validation = sfm_validate_feed($format, $content);
if (!$validation['ok']) {
  $primary = $validation['errors'][0] ?? 'Feed validation failed.';
  sfm_json_fail('Generated feed failed validation: ' . $primary, 500, [
    'error_code' => 'feed_validation_failed',
    'validation' => $validation,
  ]);
}

ensure_feeds_dir();
$path = FEEDS_DIR . '/' . $filename;
if (@file_put_contents($path, $content) === false) {
  sfm_json_fail('Failed to save feed file.', 500);
}

$validationSnapshot = null;
if (!empty($validation['warnings'])) {
  $validationSnapshot = [
    'warnings'   => $validation['warnings'],
    'checked_at' => sfm_job_now_iso(),
  ];
}

$job = sfm_job_register([
  'source_url'        => $url,
  'mode'              => 'custom',
  'format'            => $format,
  'limit'             => $limit,
  'feed_filename'     => $filename,
  'feed_url'          => $feedUrl,
  'prefer_native'     => $preferNative,
  'items_count'       => count($items),
  'last_refresh_code' => 200,
  'last_refresh_note' => 'custom parse',
  'last_validation'   => $validationSnapshot,
]);

if (function_exists('sfm_log_event')) {
  sfm_log_event('parse', [
    'phase'        => 'custom',
    'source'       => $url,
    'format'       => $format,
    'saved'        => basename($filename),
    'items'        => count($items),
    'bytes'        => strlen($content),
    'job_id'       => $job['job_id'] ?? null,
    'validation'   => empty($validation['warnings']) ? null : ($validation['warnings'][0] ?? null),
  ]);
}

$now = time();
$payload = [
  'ok'            => true,
  'feed_url'      => $feedUrl,
  'format'        => $format,
  'items'         => count($items),
  'used_native'   => false,
  'status_origin' => 'custom parse',
  'job_id'        => $job['job_id'] ?? null,
  'source_url'    => $url,
];

if (!empty($validation['warnings'])) {
  $payload['validation'] = [
    'warnings' => $validation['warnings'],
  ];
}

$health = [
  'last_refresh_epoch'  => $now,
  'last_attempt_epoch'  => $now,
  'items_count'         => count($items),
  'last_error_message'  => null,
  'last_error_code'     => null,
  'is_stale'            => false,
  'stale_reason'        => null,
  'source_url'          => $url,
  'format'              => $format,
  'limit'               => $limit,
  'prefer_native'       => $preferNative,
  'mode'                => 'custom',
  'last_refresh_note'   => 'custom parse',
];

sfm_emit_result([
  'payload' => $payload,
  'health'  => $health,
]);

function sfm_fail_with_extraction_diagnostics(array $debug, array $options): void
{
  $jsonLdCount    = $debug['jsonld_count'] ?? 0;
  $domCount       = $debug['dom_count'] ?? 0;
  $customMatches  = $debug['custom_selector_matches'] ?? null;
  $customSelector = $options['item_selector'] ?? null;

  $hints = [];
  if ($customSelector !== null && $customSelector !== '' && $customMatches === 0) {
    $hints[] = 'Your custom item_selector matched 0 elements. Double-check the CSS selector.';
  }
  if ($jsonLdCount === 0) {
    $hints[] = 'No JSON-LD ItemList or Article metadata was detected.';
  }
  if ($domCount === 0) {
    $hints[] = 'Heuristic scanning found no article-style links. The page may load content via JavaScript.';
  }

  $details = [
    'jsonld_items' => $jsonLdCount,
    'dom_items'    => $domCount,
  ];

  if ($customSelector !== null && $customSelector !== '') {
    $details['item_selector'] = $customSelector;
  }
  if ($customMatches !== null) {
    $details['custom_selector_matches'] = $customMatches;
  }

  $message = 'Could not detect any feed items on the page.';
  if ($hints) {
    $message .= ' ' . implode(' ', $hints);
  }

  sfm_json_fail($message, 422, [
    'error_code' => 'no_items_found',
    'hints'      => $hints,
    'details'    => $details,
  ]);
}

function sfm_collect_paginated_items(string $html, string $sourceUrl, int $limit, array $currentItems, array $options = []): array
{
  $nextUrls = sfm_detect_pagination_links($html, $sourceUrl, 3);
  if (!$nextUrls) {
    return [];
  }

  $extras    = [];
  $seenLinks = [];
  foreach ($currentItems as $it) {
    $href = strtolower($it['link'] ?? '');
    if ($href !== '') {
      $seenLinks[$href] = true;
    }
  }

  foreach ($nextUrls as $nextUrl) {
    if (count($currentItems) + count($extras) >= $limit) {
      break;
    }
    if (!sfm_is_http_url($nextUrl) || isset($seenLinks[strtolower($nextUrl)])) {
      continue;
    }

    /** @phpstan-var array{ok:bool,status:int,headers:array<string,string>,body:string,final_url:string,from_cache:bool,was_304:bool,error:?string} $resp */
    $resp = http_get($nextUrl, [
      'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'cache_ttl' => 600,
      'timeout'   => 10,
      'use_cache' => true,
    ]);

    if (!$resp['ok'] || $resp['body'] === '') {
      continue;
    }

    $pageItems = sfm_extract_items($resp['body'], $nextUrl, $limit, $options);
    if (!$pageItems) {
      continue;
    }

    foreach ($pageItems as $item) {
      $href = strtolower($item['link'] ?? '');
      if ($href === '' || isset($seenLinks[$href])) {
        continue;
      }
      $extras[] = $item;
      $seenLinks[$href] = true;
      if (count($currentItems) + count($extras) >= $limit) {
        break 2;
      }
    }
  }

  return $extras;
}


function sfm_detect_pagination_links(string $html, string $sourceUrl, int $max = 2): array
{
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
  $xp = new DOMXPath($doc);

  $base = sfm_base_from_html($html, $sourceUrl);

  $seen = [];
  $out  = [];

  $add = static function (?string $href) use (&$seen, &$out, $base, $sourceUrl, $max) {
    if ($href === null) {
      return;
    }
    $href = trim($href);
    if ($href === '') {
      return;
    }
    $abs = sfm_abs_url($href, $base);
    if ($abs === '' || strcasecmp($abs, $sourceUrl) === 0) {
      return;
    }
    $key = strtolower($abs);
    if (isset($seen[$key])) {
      return;
    }
    if (count($out) >= $max) {
      return;
    }
    $seen[$key] = true;
    $out[] = $abs;
  };

  $rels = $xp->query("//link[translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='next'][@href]");
  if ($rels) {
  foreach ($rels as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $add($node->getAttribute('href'));
  }
  }

  $anchors = $xp->query("//a[@href][translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='next']");
  if ($anchors) {
  foreach ($anchors as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $add($node->getAttribute('href'));
  }
  }

  $dataSelectors = $xp->query('//*[@data-next-url or @data-next or @data-next-page or @data-load-more-url or @data-pagination-url]');
  if ($dataSelectors) {
  foreach ($dataSelectors as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      foreach (['data-next-url','data-next','data-next-page','data-load-more-url','data-pagination-url'] as $attr) {
        if ($node->hasAttribute($attr)) {
          $add($node->getAttribute($attr));
        }
      }
  }
  }

  $textCandidates = $xp->query('//a[@href]');
  if ($textCandidates) {
  foreach ($textCandidates as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $text = strtolower(trim($node->textContent ?? ''));
      if ($text === '') {
        continue;
      }
      if (preg_match('/\b(next|older|more|load\s*more)\b/', $text)) {
        $add($node->getAttribute('href'));
      }
      if (count($out) >= $max) {
        break;
      }
    }
  }

  return $out;
}

function sfm_unique_items(array $items, int $limit): array
{
  $seen = [];
  $uniq = [];
  foreach ($items as $item) {
    $href = strtolower($item['link'] ?? '');
    if ($href === '' || isset($seen[$href])) {
      continue;
    }
    $seen[$href] = true;
    $uniq[] = $item;
    if (count($uniq) >= $limit) {
      break;
    }
  }
  return $uniq;
}
