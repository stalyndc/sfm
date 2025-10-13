<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/jobs.php';
require_once __DIR__ . '/../includes/job_refresh.php';

sfm_admin_boot();

if (isset($_GET['logout'])) {
    sfm_admin_logout();
    header('Location: /admin/?logged-out=1');
    exit;
}

$errors = [];
$notice = '';
$isLoggedIn = sfm_admin_is_logged_in();

if (!$isLoggedIn) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $token    = (string)($_POST['csrf_token'] ?? '');

        if (!csrf_validate($token)) {
            $errors[] = 'Invalid session token. Please try again.';
        } elseif ($username === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        } elseif (!sfm_admin_login($username, $password)) {
            $errors[] = 'Invalid credentials.';
        } else {
            header('Location: /admin/');
            exit;
        }
    }

    $pageTitle       = 'Admin Login — SimpleFeedMaker';
    $metaRobots      = 'noindex, nofollow';
    $structuredData  = [];

    require __DIR__ . '/../includes/page_head.php';
    ?>
    <body>
      <main class="py-5">
        <div class="container" style="max-width: 420px;">
          <div class="card shadow-sm">
            <div class="card-body">
              <h1 class="h4 fw-bold mb-3">Admin sign in</h1>
              <?php if ($errors): ?>
                <div class="alert alert-danger">
                  <?php foreach ($errors as $err): ?>
                    <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <form method="post" class="vstack gap-3">
                <?= csrf_input(); ?>
                <div>
                  <label for="username" class="form-label">Username</label>
                  <input type="text" class="form-control" id="username" name="username" required autofocus>
                </div>
                <div>
                  <label for="password" class="form-label">Password</label>
                  <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign in</button>
              </form>
            </div>
          </div>
        </div>
      </main>
    </body>
    </html>
    <?php
    exit;
}

if (!empty($_SESSION['sfm_admin_flash'])) {
    $flash = $_SESSION['sfm_admin_flash'];
    unset($_SESSION['sfm_admin_flash']);
    if (!empty($flash['message'])) {
        if ($flash['type'] === 'error') {
            $errors[] = (string)$flash['message'];
        } else {
            $notice = (string)$flash['message'];
        }
    }
}

if ($isLoggedIn && isset($_GET['download']) && $_GET['download'] === 'cron_refresh_log') {
    $path = sfm_refresh_log_path();
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Refresh log not found.";
        exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cron_refresh.log"');
    readfile($path);
    exit;
}

$perPageOptions = [10, 25, 50, 100];
$defaultPerPage = 25;
$currentPage = admin_get_int_param('page', 1);
$perPage = admin_get_int_param('per_page', $defaultPerPage);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = $defaultPerPage;
}

$statusOptions = ['all', 'ok', 'fail', 'pending'];
$statusFilter = admin_get_string_param('status', 'all');
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = 'all';
}

$modeOptions = ['all', 'native', 'custom'];
$modeFilter = admin_get_string_param('mode', 'all');
if (!in_array($modeFilter, $modeOptions, true)) {
    $modeFilter = 'all';
}

$searchTerm = trim(admin_get_string_param('search', ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $redirectUrl = admin_jobs_url($currentPage, $perPage, $defaultPerPage, $statusFilter, $modeFilter, $searchTerm);
    if (!csrf_validate($token)) {
        $_SESSION['sfm_admin_flash'] = [
            'type'    => 'error',
            'message' => 'Invalid session token. Please try again.',
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = (string)($_POST['admin_action'] ?? '');
    $jobId  = trim((string)($_POST['job_id'] ?? ''));

    if ($jobId === '' || $action === '') {
        $_SESSION['sfm_admin_flash'] = [
            'type'    => 'error',
            'message' => 'Missing action or job id.',
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $job = sfm_job_load($jobId);
    if (!$job) {
        $_SESSION['sfm_admin_flash'] = [
            'type'    => 'error',
            'message' => 'Job not found.',
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'refresh') {
        $ok = sfm_refresh_job($job, function_exists('sfm_log_event'));
        $_SESSION['sfm_admin_flash'] = [
            'type'    => $ok ? 'success' : 'error',
            'message' => $ok ? 'Job refreshed.' : 'Refresh failed. Check logs for details.',
        ];
    } elseif ($action === 'update_filters') {
        $includeRaw = $_POST['include_keywords'] ?? '';
        $excludeRaw = $_POST['exclude_keywords'] ?? '';
        $updated = sfm_job_update($job['job_id'], [
            'include_keywords' => $includeRaw,
            'exclude_keywords' => $excludeRaw,
        ]);
        if ($updated) {
            $_SESSION['sfm_admin_flash'] = [
                'type'    => 'success',
                'message' => 'Filters updated.',
            ];
        } else {
            $_SESSION['sfm_admin_flash'] = [
                'type'    => 'error',
                'message' => 'Failed to update filters.',
            ];
        }
    } elseif ($action === 'delete') {
        $feedFile = $job['feed_filename'] ?? '';
        if ($feedFile) {
            $path = FEEDS_DIR . '/' . $feedFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        sfm_job_delete($jobId);
        $_SESSION['sfm_admin_flash'] = [
            'type'    => 'success',
            'message' => 'Job deleted.',
        ];
    } else {
        $_SESSION['sfm_admin_flash'] = [
            'type'    => 'error',
            'message' => 'Unknown action.',
        ];
    }

    header('Location: ' . $redirectUrl);
    exit;
}
$jobs = sfm_job_list();
$failureThreshold = max(1, (int)(getenv('SFM_REFRESH_ALERT_THRESHOLD') ?: 3));
$jobStats = sfm_jobs_statistics($jobs, $failureThreshold);
$refreshLogs = sfm_refresh_recent_logs(5);
$lastRefreshLog = $refreshLogs[0] ?? null;
$lastRefreshTs = is_array($lastRefreshLog) ? ($lastRefreshLog['ts'] ?? null) : null;
$lastRefreshSummary = is_array($lastRefreshLog) ? ($lastRefreshLog['summary'] ?? '') : '';
$nextRefreshEta = '—';
if ($lastRefreshTs) {
    $ts = strtotime($lastRefreshTs);
    if ($ts) {
        $nextRefreshEta = gmdate('Y-m-d H:i:s', $ts + (int)SFM_DEFAULT_REFRESH_INTERVAL) . ' UTC';
    }
}
$refreshMaxPerRun = (int)SFM_REFRESH_MAX_PER_RUN;

$hotlist = array_values(array_filter($jobs, static function (array $job): bool {
    return (int)($job['failure_streak'] ?? 0) > 0;
}));
usort($hotlist, static function (array $a, array $b): int {
    return (int)($b['failure_streak'] ?? 0) <=> (int)($a['failure_streak'] ?? 0);
});
$hotlist = array_slice($hotlist, 0, 8);

if ($statusFilter !== 'all' || $modeFilter !== 'all' || $searchTerm !== '') {
    $jobs = array_values(array_filter($jobs, function (array $job) use ($statusFilter, $modeFilter, $searchTerm) {
        $status = strtolower((string)($job['last_refresh_status'] ?? ''));
        $mode   = strtolower((string)($job['mode'] ?? ''));

        if ($statusFilter !== 'all') {
            if ($statusFilter === 'pending') {
                if ($status === 'ok' || $status === 'fail') {
                    return false;
                }
            } elseif ($status !== $statusFilter) {
                return false;
            }
        }

        if ($modeFilter !== 'all' && $mode !== $modeFilter) {
            return false;
        }

        if ($searchTerm !== '') {
            $haystack = ((string)($job['source_url'] ?? '')) . ' ' . ((string)($job['feed_url'] ?? ''));
            if (stripos($haystack, $searchTerm) === false) {
                return false;
            }
        }

        return true;
    }));
}

$jobs = array_reverse($jobs);

$totalJobs = count($jobs);
$totalPages = max(1, (int)ceil($totalJobs / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;
$jobsPage = array_slice($jobs, $offset, $perPage);
$showingStart = $totalJobs > 0 ? $offset + 1 : 0;
$showingEnd = $totalJobs > 0 ? min($offset + count($jobsPage), $totalJobs) : 0;

function fmt_datetime(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    try {
        $dt = new DateTime($iso);
        return $dt->format('Y-m-d H:i:s') . ' UTC';
    } catch (Throwable $e) {
        return $iso;
    }
}

function admin_get_int_param(string $key, int $default): int
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (!is_scalar($value)) {
        return $default;
    }
    $int = (int)$value;
    return $int > 0 ? $int : $default;
}

function admin_get_string_param(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (!is_scalar($value)) {
        return $default;
    }
    $str = trim((string)$value);
    return $str !== '' ? $str : $default;
}

function admin_jobs_url(int $page, int $perPage, int $defaultPerPage, string $status, string $mode, string $search): string
{
    $page = max(1, $page);
    $params = ['page' => $page];
    if ($perPage !== $defaultPerPage) {
        $params['per_page'] = $perPage;
    }
    if ($status !== 'all') {
        $params['status'] = $status;
    }
    if ($mode !== 'all') {
        $params['mode'] = $mode;
    }
    if ($search !== '') {
        $params['search'] = $search;
    }
    $query = http_build_query($params);
    return '/admin/' . ($query ? '?' . $query : '');
}

function admin_format_interval(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }

    if ($seconds >= 86400) {
        $days = $seconds / 86400;
        if ($days >= 2) {
            return sprintf('%.1f days', $days);
        }
        return '1 day';
    }

    if ($seconds >= 3600) {
        $hours = $seconds / 3600;
        if ($hours >= 2) {
            return sprintf('%.1f h', $hours);
        }
        return '60 min';
    }

    $minutes = max(1, round($seconds / 60));
    return $minutes . ' min';
}

$pageTitle      = 'Admin Jobs — SimpleFeedMaker';
$metaRobots     = 'noindex, nofollow';
$structuredData = [];

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
<main class="py-4">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 fw-bold mb-1">Feed jobs</h1>
        <div class="text-secondary">Monitor generated feeds, refresh them manually, or retire jobs.</div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/admin/tools.php">Selector playground</a>
        <a class="btn btn-outline-secondary" href="/admin/?logout=1">Sign out</a>
      </div>
    </div>

    <?php
      $customCount = max(0, $jobStats['total'] - $jobStats['native']);
      $lastRunDisplay = fmt_datetime($lastRefreshTs ?? null);
      $lastRunSummaryText = $lastRefreshSummary !== '' ? $lastRefreshSummary : 'No refresh runs recorded yet.';
      $logsPreview = array_slice($refreshLogs, 0, 3);
      $intervalSum = 0;
      $intervalCount = 0;
      foreach ($jobs as $statsJob) {
        $interval = isset($statsJob['refresh_interval']) ? (int)$statsJob['refresh_interval'] : (int)SFM_DEFAULT_REFRESH_INTERVAL;
        if ($interval > 0) {
          $intervalSum += $interval;
          $intervalCount++;
        }
      }
      $avgIntervalSeconds = $intervalCount > 0 ? (int)round($intervalSum / $intervalCount) : (int)SFM_DEFAULT_REFRESH_INTERVAL;
      $avgIntervalLabel = admin_format_interval($avgIntervalSeconds);
      $healthyCount = max(0, $jobStats['total'] - $jobStats['failing']);
    ?>

    <div class="admin-metrics">
      <div class="metrics-pill">
        <span class="label">Total jobs</span>
        <span class="value"><?= number_format($jobStats['total']); ?></span>
      </div>
      <div class="metrics-pill<?= $jobStats['failing'] > 0 ? ' warn' : ''; ?>">
        <span class="label">Failing streaks</span>
        <span class="value"><?= number_format($jobStats['failing']); ?></span>
      </div>
      <div class="metrics-pill">
        <span class="label">Healthy jobs</span>
        <span class="value"><?= number_format($healthyCount); ?></span>
      </div>
      <div class="metrics-pill">
        <span class="label">Avg refresh interval</span>
        <span class="value"><?= htmlspecialchars($avgIntervalLabel, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="metrics-pill">
        <span class="label">Last refresh run</span>
        <span class="value" style="font-size:1rem;"><?= htmlspecialchars($lastRunDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="text-uppercase small text-secondary mb-1">Active jobs</div>
            <div class="h2 fw-bold mb-0"><?= number_format($jobStats['total']); ?></div>
            <div class="small text-secondary mt-2">
              Custom: <?= number_format($customCount); ?> &middot; Native: <?= number_format($jobStats['native']); ?><br>
              Max per cron run: <?= number_format($refreshMaxPerRun); ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="text-uppercase small text-secondary mb-1">Failure streaks</div>
            <div class="h2 fw-bold mb-0"><?= number_format($jobStats['failing']); ?></div>
            <div class="small text-secondary mt-2">
              Critical (≥<?= (int)$failureThreshold; ?>): <?= number_format($jobStats['critical']); ?><br>
              Monitor the list below for quick recoveries.
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body d-flex flex-column">
            <div class="text-uppercase small text-secondary mb-1">Last refresh run</div>
            <div class="fw-semibold"><?= htmlspecialchars($lastRunDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="small text-secondary mt-2"><?= htmlspecialchars($lastRunSummaryText, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="small text-secondary mt-2">Next run (est.): <?= htmlspecialchars($nextRefreshEta, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($logsPreview): ?>
              <ul class="small text-secondary mb-0 mt-3 ps-3">
                <?php foreach ($logsPreview as $preview): ?>
                  <li><?= htmlspecialchars(($preview['ts'] ?? '—') . ' · ' . ($preview['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="mt-3">
              <a class="btn btn-sm btn-outline-secondary" href="/admin/?download=cron_refresh_log">Download refresh log</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($hotlist): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3 flex-column flex-lg-row gap-2">
            <h2 class="h5 fw-semibold mb-0">Jobs to watch</h2>
            <span class="badge bg-danger-subtle text-danger">Failing <?= number_format($jobStats['failing']); ?> job<?= $jobStats['failing'] === 1 ? '' : 's'; ?></span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Source</th>
                  <th scope="col">Streak</th>
                  <th scope="col">Last error</th>
                  <th scope="col">Last refresh</th>
                  <th scope="col">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($hotlist as $alertJob): ?>
                  <?php
                    $alertJobId = (string)$alertJob['job_id'];
                    $alertSource = (string)($alertJob['source_url'] ?? '');
                    $alertFeed = (string)($alertJob['feed_url'] ?? '');
                    $alertStreak = (int)($alertJob['failure_streak'] ?? 0);
                    $alertError = trim((string)($alertJob['last_refresh_error'] ?? ''));
                    $diagnostics = isset($alertJob['diagnostics']) && is_array($alertJob['diagnostics']) ? $alertJob['diagnostics'] : null;
                    if ($diagnostics && !empty($diagnostics['error'])) {
                      $alertError = (string)$diagnostics['error'];
                    }
                    if (function_exists('mb_strlen') && mb_strlen($alertError) > 140) {
                      $alertError = mb_substr($alertError, 0, 137) . '…';
                    } elseif (strlen($alertError) > 140) {
                      $alertError = substr($alertError, 0, 137) . '…';
                    }
                    if ($alertError === '') {
                      $alertError = '—';
                    }
                  ?>
                  <tr>
                    <td style="min-width: 200px;">
                      <div class="fw-semibold text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($alertSource, ENT_QUOTES, 'UTF-8'); ?>">
                        <?= htmlspecialchars($alertSource, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                      <div class="small text-secondary text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($alertFeed, ENT_QUOTES, 'UTF-8'); ?>">
                        <?= htmlspecialchars($alertFeed, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                    </td>
                    <td><span class="badge bg-danger text-white"><?= $alertStreak; ?>×</span></td>
                    <td class="small" style="max-width: 280px;">
                      <?= htmlspecialchars($alertError, ENT_QUOTES, 'UTF-8'); ?>
                      <?php if ($diagnostics): ?>
                        <div class="small text-secondary mt-1">
                          Captured: <?= htmlspecialchars(fmt_datetime($diagnostics['captured_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                          <?php if (!empty($diagnostics['http_status'])): ?>
                            · HTTP <?= htmlspecialchars((string)$diagnostics['http_status'], ENT_QUOTES, 'UTF-8'); ?>
                          <?php endif; ?>
                        </div>
                        <?php if (!empty($diagnostics['note'])): ?>
                          <div class="small text-secondary">Note: <?= htmlspecialchars((string)$diagnostics['note'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($diagnostics['details']) && is_array($diagnostics['details'])): ?>
                          <ul class="small text-secondary mt-1 mb-0 ps-3">
                            <?php $detailCount = 0; foreach ($diagnostics['details'] as $dKey => $dVal): if ($detailCount >= 3) break; ?>
                              <?php
                                $detailCount++;
                                if (is_array($dVal)) {
                                  $detailValue = json_encode($dVal, JSON_UNESCAPED_SLASHES);
                                } else {
                                  $detailValue = (string)$dVal;
                                }
                              ?>
                              <li><span class="text-muted"><?= htmlspecialchars((string)$dKey, ENT_QUOTES, 'UTF-8'); ?>:</span> <?= htmlspecialchars($detailValue, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars(fmt_datetime($alertJob['last_refresh_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="width: 120px;">
                      <form method="post" class="d-inline">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="job_id" value="<?= htmlspecialchars($alertJobId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="admin_action" value="refresh">
                        <input type="hidden" name="page" value="<?= (int)$currentPage; ?>">
                        <input type="hidden" name="per_page" value="<?= (int)$perPage; ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="mode" value="<?= htmlspecialchars($modeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Refresh</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($notice): ?>
      <div class="alert alert-success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-body">
        <?php
          $chipDefinitions = [
            [
              'label' => 'All jobs',
              'status' => 'all',
              'mode' => 'all',
              'count' => $jobStats['total'] ?? null,
            ],
            [
              'label' => 'Failing',
              'status' => 'fail',
              'mode' => 'all',
              'count' => $jobStats['failing'] ?? null,
            ],
            [
              'label' => 'Native',
              'status' => 'all',
              'mode' => 'native',
              'count' => $jobStats['native'] ?? null,
            ],
            [
              'label' => 'Custom',
              'status' => 'all',
              'mode' => 'custom',
              'count' => isset($jobStats['total'], $jobStats['native']) ? (int)$jobStats['total'] - (int)$jobStats['native'] : null,
            ],
          ];
        ?>
        <div class="filter-chips d-flex flex-wrap gap-2 mb-3">
          <?php foreach ($chipDefinitions as $chip):
            $isActive = ($statusFilter === $chip['status'] && $modeFilter === $chip['mode'] && $searchTerm === '');
            $chipHref = admin_jobs_url(1, $perPage, $defaultPerPage, $chip['status'], $chip['mode'], '');
          ?>
            <a href="<?= htmlspecialchars($chipHref, ENT_QUOTES, 'UTF-8'); ?>" class="filter-chip <?= $isActive ? 'is-active' : ''; ?>">
              <?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8'); ?>
              <?php if ($chip['count'] !== null): ?>
                <span class="chip-count"><?= number_format((int)$chip['count']); ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="d-flex flex-column gap-3 mb-3">
          <div class="small text-secondary">
            Showing <?= number_format($showingStart); ?>–<?= number_format($showingEnd); ?> of <?= number_format($totalJobs); ?> job<?= $totalJobs === 1 ? '' : 's'; ?>
          </div>
          <form method="get" class="row gx-2 gy-2 align-items-end">
            <input type="hidden" name="page" value="1">
            <div class="col-12 col-md-4">
              <label for="admin-search" class="form-label small mb-1">Search source or feed URL</label>
              <input type="search" name="search" id="admin-search" class="form-control form-control-sm" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="example.com">
            </div>
            <div class="col-6 col-md-2">
              <label for="admin-status" class="form-label small mb-1">Status</label>
              <select name="status" id="admin-status" class="form-select form-select-sm">
                <?php foreach ($statusOptions as $option): ?>
                  <option value="<?= $option; ?>" <?= $option === $statusFilter ? 'selected' : ''; ?>><?= ucfirst($option); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label for="admin-mode" class="form-label small mb-1">Mode</label>
              <select name="mode" id="admin-mode" class="form-select form-select-sm">
                <?php foreach ($modeOptions as $option): ?>
                  <option value="<?= $option; ?>" <?= $option === $modeFilter ? 'selected' : ''; ?>><?= ucfirst($option); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2 col-lg-1">
              <label for="admin-per-page" class="form-label small mb-1">Per page</label>
              <select name="per_page" id="admin-per-page" class="form-select form-select-sm">
                <?php foreach ($perPageOptions as $option): ?>
                  <option value="<?= $option; ?>" <?= $option === $perPage ? 'selected' : ''; ?>><?= $option; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            </div>
            <?php if ($searchTerm !== '' || $statusFilter !== 'all' || $modeFilter !== 'all'): ?>
              <div class="col-auto">
                <a href="<?= htmlspecialchars(admin_jobs_url(1, $perPage, $defaultPerPage, 'all', 'all', ''), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-link text-decoration-none">Reset</a>
              </div>
            <?php endif; ?>
          </form>
        </div>
        <?php if ($totalJobs === 0): ?>
          <div class="alert alert-info mb-0">
            <?php if ($searchTerm !== '' || $statusFilter !== 'all' || $modeFilter !== 'all'): ?>
              No jobs match your filters. <a href="<?= htmlspecialchars(admin_jobs_url(1, $perPage, $defaultPerPage, 'all', 'all', ''), ENT_QUOTES, 'UTF-8'); ?>">View all jobs</a>.
            <?php else: ?>
              No jobs yet. Generate a feed to see it here.
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="table-responsive jobs-table-container">
            <table class="table align-middle mb-0 admin-jobs-table table-sticky">
              <thead>
                <tr>
                  <th scope="col">Source</th>
                  <th scope="col">Mode</th>
                  <th scope="col">Format</th>
                  <th scope="col">Items</th>
                  <th scope="col">Last refresh</th>
                  <th scope="col">Status</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($jobsPage as $job): ?>
                  <?php
                    $jobId    = (string)$job['job_id'];
                    $statusRaw= (string)($job['last_refresh_status'] ?? '');
                    $status   = strtolower($statusRaw);
                    $note     = (string)($job['last_refresh_note'] ?? '');
                    $badgeCls = $status === 'ok' ? 'bg-success' : ($status === 'fail' ? 'bg-danger' : 'bg-secondary');
                    $itemsCnt = $job['items_count'] ?? null;
                    $failureStreak = (int)($job['failure_streak'] ?? 0);
                    $validationWarns = [];
                    $validationChecked = null;
                    $filtersDomId = 'filters-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId);
                    $includeKeywords = isset($job['include_keywords']) && is_array($job['include_keywords']) ? $job['include_keywords'] : sfm_job_normalize_keywords($job['include_keywords'] ?? []);
                    $excludeKeywords = isset($job['exclude_keywords']) && is_array($job['exclude_keywords']) ? $job['exclude_keywords'] : sfm_job_normalize_keywords($job['exclude_keywords'] ?? []);
                    if (!empty($job['last_validation']) && is_array($job['last_validation'])) {
                        $rawWarnings = $job['last_validation']['warnings'] ?? [];
                        if (is_array($rawWarnings)) {
                            $validationWarns = array_values(array_filter($rawWarnings, 'is_string'));
                        }
                        if (!empty($job['last_validation']['checked_at'])) {
                            $validationChecked = (string)$job['last_validation']['checked_at'];
                        }
                    }
                  ?>
                  <tr>
                    <td style="min-width: 220px;">
                      <div class="fw-semibold text-truncate" style="max-width: 320px;" title="<?= htmlspecialchars($job['source_url'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?= htmlspecialchars($job['source_url'], ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                      <div class="small text-secondary text-truncate" style="max-width: 320px;" title="<?= htmlspecialchars($job['feed_url'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?= htmlspecialchars($job['feed_url'], ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                    </td>
                    <td>
                      <span class="badge bg-info text-dark text-uppercase"><?= htmlspecialchars($job['mode'] ?? 'custom', ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php if (!empty($job['prefer_native'])): ?>
                        <span class="badge bg-secondary">prefers native</span>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(strtoupper((string)($job['format'] ?? 'rss'))); ?></td>
                    <td>
                      <div>Limit: <?= (int)($job['limit'] ?? DEFAULT_LIM); ?></div>
                      <div class="small text-secondary">Last: <?= $itemsCnt === null ? '—' : (int)$itemsCnt; ?></div>
                    </td>
                    <td>
                      <div><?= htmlspecialchars(fmt_datetime($job['last_refresh_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="small text-secondary">Code: <?= htmlspecialchars((string)($job['last_refresh_code'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </td>
                    <td>
                      <span class="badge <?= $badgeCls; ?> text-uppercase"><?= htmlspecialchars(strtoupper($statusRaw === '' ? 'pending' : $statusRaw), ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php if ($note): ?>
                        <div class="small text-secondary mt-1 text-wrap" style="max-width: 200px;"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></div>
                      <?php endif; ?>
                      <?php if ($failureStreak >= 3): ?>
                        <span class="badge rounded-pill bg-danger-subtle text-danger mt-1">Failed <?= (int)$failureStreak; ?>×</span>
                      <?php elseif ($failureStreak === 2): ?>
                        <span class="badge rounded-pill bg-warning-subtle text-warning mt-1">Failed 2×</span>
                      <?php endif; ?>
                      <?php if ($validationWarns): ?>
                        <?php
                          $warnDisplay = array_slice($validationWarns, 0, 3);
                          $warnHtml = implode(' • ', array_map(function ($msg) {
                              return htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
                          }, $warnDisplay));
                        ?>
                        <div class="small text-warning mt-1 text-wrap" style="max-width: 200px;">
                          Validation warning<?= count($validationWarns) > 1 ? 's' : ''; ?>: <?= $warnHtml; ?>
                          <?php if ($validationChecked): ?>
                            <span class="d-block text-muted mt-1">Checked: <?= htmlspecialchars($validationChecked, ENT_QUOTES, 'UTF-8'); ?></span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex flex-column gap-2">
                        <button class="btn btn-sm btn-outline-light w-100" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>" aria-expanded="false" aria-controls="<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>">
                          Filters
                        </button>
                        <form method="post">
                          <?= csrf_input(); ?>
                          <input type="hidden" name="job_id" value="<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="admin_action" value="refresh">
                          <input type="hidden" name="page" value="<?= (int)$currentPage; ?>">
                          <input type="hidden" name="per_page" value="<?= (int)$perPage; ?>">
                          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="mode" value="<?= htmlspecialchars($modeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Refresh</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this job?');">
                          <?= csrf_input(); ?>
                          <input type="hidden" name="job_id" value="<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="admin_action" value="delete">
                          <input type="hidden" name="page" value="<?= (int)$currentPage; ?>">
                          <input type="hidden" name="per_page" value="<?= (int)$perPage; ?>">
                          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="mode" value="<?= htmlspecialchars($modeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                  <tr class="filters-row">
                    <td colspan="7" class="border-0 p-0">
                      <div id="<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>" class="collapse">
                        <div class="card shadow-sm mx-3 mb-3 mt-0">
                          <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-column flex-lg-row gap-2">
                              <div>
                                <h3 class="h6 fw-semibold mb-1">Content filters</h3>
                                <p class="small text-secondary mb-0">Match keywords against the item title, description, or enriched content. Matching is case-insensitive.</p>
                              </div>
                              <form method="post" class="d-flex flex-column flex-lg-row gap-3 align-items-start" style="min-width:280px;">
                                <?= csrf_input(); ?>
                                <input type="hidden" name="job_id" value="<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="admin_action" value="update_filters">
                                <input type="hidden" name="page" value="<?= (int)$currentPage; ?>">
                                <input type="hidden" name="per_page" value="<?= (int)$perPage; ?>">
                                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="mode" value="<?= htmlspecialchars($modeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="flex-grow-1" style="min-width:220px;">
                                  <label for="include-<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>" class="form-label">Only include when matching</label>
                                  <textarea id="include-<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>" name="include_keywords" class="form-control" rows="3" placeholder="Example: technology, earnings call"><?= htmlspecialchars(implode("\n", $includeKeywords), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                  <div class="form-text">Separate with commas or new lines. Leave blank to include everything.</div>
                                </div>
                                <div class="flex-grow-1" style="min-width:220px;">
                                  <label for="exclude-<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>" class="form-label">Exclude items containing</label>
                                  <textarea id="exclude-<?= htmlspecialchars($filtersDomId, ENT_QUOTES, 'UTF-8'); ?>" name="exclude_keywords" class="form-control" rows="3" placeholder="Example: privacy policy, terms of use"><?= htmlspecialchars(implode("\n", $excludeKeywords), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                  <div class="form-text">Keywords are case-insensitive. Items matching any exclusion will be dropped.</div>
                                </div>
                                <div class="align-self-end">
                                  <button type="submit" class="btn btn-primary">Save filters</button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($totalPages > 1): ?>
            <?php
              $range = 2;
              $prevDisabled = $currentPage <= 1;
              $nextDisabled = $currentPage >= $totalPages;
              $prevHref = $prevDisabled ? '#' : admin_jobs_url($currentPage - 1, $perPage, $defaultPerPage, $statusFilter, $modeFilter, $searchTerm);
              $nextHref = $nextDisabled ? '#' : admin_jobs_url($currentPage + 1, $perPage, $defaultPerPage, $statusFilter, $modeFilter, $searchTerm);
              $start = max(1, $currentPage - $range);
              $end   = min($totalPages, $currentPage + $range);
            ?>
            <nav aria-label="Feed job pagination" class="mt-3">
              <ul class="pagination pagination-sm mb-0 justify-content-center">
                <li class="page-item <?= $prevDisabled ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?= htmlspecialchars($prevHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous" <?= $prevDisabled ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>Prev</a>
                </li>
                <?php if ($start > 1): ?>
                  <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars(admin_jobs_url(1, $perPage, $defaultPerPage, $statusFilter, $modeFilter, $searchTerm), ENT_QUOTES, 'UTF-8'); ?>">1</a>
                  </li>
                  <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                  <?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                  <li class="page-item <?= $p === $currentPage ? 'active' : ''; ?>">
                    <a class="page-link" href="<?= htmlspecialchars(admin_jobs_url($p, $perPage, $defaultPerPage, $statusFilter, $modeFilter, $searchTerm), ENT_QUOTES, 'UTF-8'); ?>"><?= $p; ?></a>
                  </li>
                <?php endfor; ?>
                <?php if ($end < $totalPages): ?>
                  <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                  <?php endif; ?>
                  <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars(admin_jobs_url($totalPages, $perPage, $defaultPerPage, $statusFilter, $modeFilter, $searchTerm), ENT_QUOTES, 'UTF-8'); ?>"><?= $totalPages; ?></a>
                  </li>
                <?php endif; ?>
                <li class="page-item <?= $nextDisabled ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?= htmlspecialchars($nextHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next" <?= $nextDisabled ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>Next</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<?php require __DIR__ . '/../includes/page_footer.php';
