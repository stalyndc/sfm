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

$perPageOptions = [10, 25, 50, 100];
$defaultPerPage = 25;
$currentPage = admin_get_int_param('page', 1);
$perPage = admin_get_int_param('per_page', $defaultPerPage);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = $defaultPerPage;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $redirectUrl = admin_jobs_url($currentPage, $perPage, $defaultPerPage);
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

function admin_jobs_url(int $page, int $perPage, int $defaultPerPage): string
{
    $page = max(1, $page);
    $params = ['page' => $page];
    if ($perPage !== $defaultPerPage) {
        $params['per_page'] = $perPage;
    }
    $query = http_build_query($params);
    return '/admin/' . ($query ? '?' . $query : '');
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
      <div>
        <a class="btn btn-outline-secondary" href="/admin/?logout=1">Sign out</a>
      </div>
    </div>

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
        <?php if ($totalJobs === 0): ?>
          <p class="mb-0">No jobs yet. Generate a feed to see it here.</p>
        <?php else: ?>
          <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
            <div class="small text-secondary">
              Showing <?= number_format($showingStart); ?>–<?= number_format($showingEnd); ?> of <?= number_format($totalJobs); ?> job<?= $totalJobs === 1 ? '' : 's'; ?>
            </div>
            <form method="get" class="d-flex align-items-center gap-2">
              <input type="hidden" name="page" value="1">
              <label for="admin-per-page" class="form-label small mb-0">Per page</label>
              <select name="per_page" id="admin-per-page" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                <?php foreach ($perPageOptions as $option): ?>
                  <option value="<?= $option; ?>" <?= $option === $perPage ? 'selected' : ''; ?>><?= $option; ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0 table-dark">
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
                    $status   = (string)($job['last_refresh_status'] ?? '');
                    $note     = (string)($job['last_refresh_note'] ?? '');
                    $badgeCls = $status === 'ok' ? 'bg-success' : ($status === 'fail' ? 'bg-danger' : 'bg-secondary');
                    $itemsCnt = $job['items_count'] ?? null;
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
                      <span class="badge <?= $badgeCls; ?> text-uppercase"><?= htmlspecialchars($status ?: 'unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php if ($note): ?>
                        <div class="small text-secondary mt-1 text-wrap" style="max-width: 200px;"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex flex-column gap-2">
                        <form method="post">
                          <?= csrf_input(); ?>
                          <input type="hidden" name="job_id" value="<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="admin_action" value="refresh">
                          <input type="hidden" name="page" value="<?= (int)$currentPage; ?>">
                          <input type="hidden" name="per_page" value="<?= (int)$perPage; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-primary w-100">Refresh</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this job?');">
                          <?= csrf_input(); ?>
                          <input type="hidden" name="job_id" value="<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="admin_action" value="delete">
                          <input type="hidden" name="page" value="<?= (int)$currentPage; ?>">
                          <input type="hidden" name="per_page" value="<?= (int)$perPage; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete</button>
                        </form>
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
              $prevHref = $prevDisabled ? '#' : admin_jobs_url($currentPage - 1, $perPage, $defaultPerPage);
              $nextHref = $nextDisabled ? '#' : admin_jobs_url($currentPage + 1, $perPage, $defaultPerPage);
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
                    <a class="page-link" href="<?= htmlspecialchars(admin_jobs_url(1, $perPage, $defaultPerPage), ENT_QUOTES, 'UTF-8'); ?>">1</a>
                  </li>
                  <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                  <?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                  <li class="page-item <?= $p === $currentPage ? 'active' : ''; ?>">
                    <a class="page-link" href="<?= htmlspecialchars(admin_jobs_url($p, $perPage, $defaultPerPage), ENT_QUOTES, 'UTF-8'); ?>"><?= $p; ?></a>
                  </li>
                <?php endfor; ?>
                <?php if ($end < $totalPages): ?>
                  <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                  <?php endif; ?>
                  <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars(admin_jobs_url($totalPages, $perPage, $defaultPerPage), ENT_QUOTES, 'UTF-8'); ?>"><?= $totalPages; ?></a>
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
