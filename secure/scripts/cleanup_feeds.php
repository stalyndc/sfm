#!/usr/bin/env php
<?php
/**
 * cleanup_feeds.php
 *
 * Housekeeping script for SimpleFeedMaker.
 * Deletes old / oversized feed files from /public_html/feeds.
 *
 * Place at:  /home/<account>/secure/scripts/cleanup_feeds.php
 * Run via:   php /home/<account>/secure/scripts/cleanup_feeds.php --days=14 --max-mb=500 --max-files=2000
 * Cron ex.:  0 3 * * * /usr/bin/php /home/<account>/secure/scripts/cleanup_feeds.php --days=14 >> /home/<account>/secure/logs/cleanup_feeds.log 2>&1
 *
 * Flags:
 *   --days=N         Delete files older than N days (default 14)
 *   --max-age=3d     Alternative duration syntax (Xm, Xh, Xd, Xw)
 *   --max-mb=N       Keep total size under N megabytes (default 500)
 *   --max-files=N    Keep at most N files (default 2000)
 *   --dry-run        Print what would be deleted, do not delete
 *   --quiet          Only print warnings/errors and final summary
 *   --verbose        Chatty logs
 */

declare(strict_types=1);

// ---------- Resolve paths (portable for Hostinger layout) ----------
$SECURE_DIR = dirname(__DIR__);             // /home/<account>/secure or project/secure
$HOME_DIR   = dirname($SECURE_DIR);         // /home/<account>

$rootCandidates = [];
$envWebRoot = trim((string)getenv('SFM_WEB_ROOT'));
if ($envWebRoot !== '') {
    $rootCandidates[] = $envWebRoot;
}
$rootCandidates[] = $HOME_DIR . '/public_html';
$rootCandidates[] = $HOME_DIR . '/www';
$rootCandidates[] = $HOME_DIR . '/public';
$rootCandidates[] = dirname($SECURE_DIR); // when secure/ lives inside project root

$WEB_ROOT = null;
foreach ($rootCandidates as $candidate) {
    $path = realpath($candidate) ?: $candidate;
    if (!is_dir($path)) {
        continue;
    }
    if (is_file($path . '/index.php') || is_dir($path . '/includes')) {
        $WEB_ROOT = rtrim($path, '/\\');
        break;
    }
}

if ($WEB_ROOT === null) {
    fwrite(STDERR, "[ERROR] Unable to locate web root. Set SFM_WEB_ROOT or adjust cleanup script.\n");
    exit(1);
}

$FEEDS_DIR   = $WEB_ROOT . '/feeds';        // where feed files are written
$STORAGE_DIR = $HOME_DIR . '/storage';      // runtime storage lives alongside account root
if (!is_dir($STORAGE_DIR)) {
    $altStorage = $WEB_ROOT . '/storage';
    if (is_dir($altStorage)) {
        $STORAGE_DIR = $altStorage;
    }
}

$LOG_DIR    = $SECURE_DIR . '/logs';
$TMP_DIR    = $SECURE_DIR . '/tmp';
$STORAGE_LOG_DIR = $STORAGE_DIR . '/logs';
@is_dir($LOG_DIR) || @mkdir($LOG_DIR, 0775, true);
@is_dir($TMP_DIR) || @mkdir($TMP_DIR, 0775, true);
if (!is_dir($STORAGE_LOG_DIR)) {
    @mkdir($STORAGE_LOG_DIR, 0775, true);
}

// ---------- Simple CLI arg parser ----------
$args = [];
foreach ($argv ?? [] as $i => $a) {
    if ($i === 0) continue;
    if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $a, $m)) {
        $args[strtolower($m[1])] = $m[2];
    } elseif (preg_match('/^--([a-z0-9\-]+)$/i', $a, $m)) {
        $args[strtolower($m[1])] = true;
    }
}
$MAX_MB     = max(1, (int)($args['max-mb']    ?? 500));    // total size budget
$MAX_FILES  = max(1, (int)($args['max-files'] ?? 2000));   // max files budget
$DRY_RUN    = !empty($args['dry-run']);
$QUIET      = !empty($args['quiet']);
$VERBOSE    = !empty($args['verbose']);

$maxAgeSeconds = null;
if (isset($args['days'])) {
    $maxAgeSeconds = max(1, (int)$args['days']) * 86400;
}
if (isset($args['max-age'])) {
    $parsed = parse_duration_to_seconds((string)$args['max-age']);
    if ($parsed !== null) {
        $maxAgeSeconds = $parsed;
    } else {
        log_line("Invalid --max-age value '{$args['max-age']}', falling back to days.", 'WARNING');
    }
}
if ($maxAgeSeconds === null) {
    $maxAgeSeconds = 14 * 86400; // default 14 days
}
$DAYS = max(1, (int)ceil($maxAgeSeconds / 86400));

// ---------- Logging helpers ----------
function log_line(string $msg, string $lvl = 'INFO'): void {
    global $QUIET;
    if ($QUIET && $lvl === 'INFO') return;
    $ts = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[$ts] [$lvl] $msg\n");
}
function human_bytes(int $b): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
    return sprintf('%.2f %s', $v, $u[$i]);
}
function human_duration(int $seconds): string {
    $map = [
        'day'    => 86400,
        'hour'   => 3600,
        'minute' => 60,
        'second' => 1,
    ];
    $parts = [];
    foreach ($map as $label => $unitSeconds) {
        if ($seconds < $unitSeconds && empty($parts)) {
            continue;
        }
        $value = intdiv($seconds, $unitSeconds);
        if ($value > 0) {
            $parts[] = $value . ' ' . $label . ($value !== 1 ? 's' : '');
            $seconds -= $value * $unitSeconds;
        }
        if (count($parts) === 2) {
            break;
        }
    }
    return $parts ? implode(', ', $parts) : '0 seconds';
}
function parse_duration_to_seconds(string $value): ?int {
    $value = strtolower(trim($value));
    if ($value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $seconds = (int)$value;
        return $seconds > 0 ? $seconds : null;
    }
    if (!preg_match('/^(\d+)([smhdw])$/', $value, $m)) {
        return null;
    }
    $number = (int)$m[1];
    $unit   = $m[2];
    if ($number <= 0) {
        return null;
    }
    switch ($unit) {
        case 's': return $number;
        case 'm': return $number * 60;
        case 'h': return $number * 3600;
        case 'd': return $number * 86400;
        case 'w': return $number * 604800;
    }
    return null;
}
function write_run_summary(string $message): void {
    global $STORAGE_LOG_DIR, $LOG_DIR;
    $ts = date('c');
    $line = "[$ts] cleanup_feeds.php $message\n";
    $target = $STORAGE_LOG_DIR . '/cleanup.log';
    if (@file_put_contents($target, $line, FILE_APPEND | LOCK_EX) === false) {
        @file_put_contents($LOG_DIR . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}

// ---------- Safety checks ----------
$realFeeds = realpath($FEEDS_DIR) ?: '';
$realRoot  = realpath($WEB_ROOT)  ?: '';
if ($realFeeds === '' || !is_dir($realFeeds)) {
    log_line("Feeds dir missing: $FEEDS_DIR", 'ERROR');
    exit(1);
}
if (strpos($realFeeds, $realRoot) !== 0 || basename($realFeeds) !== 'feeds') {
    log_line("Safety check failed. Aborting to avoid deleting outside /public_html/feeds.", 'ERROR');
    exit(1);
}
if (!is_readable($realFeeds) || !is_writable($realFeeds)) {
    log_line("Feeds directory is not readable/writable: $realFeeds", 'ERROR');
    exit(1);
}

// ---------- Lock (avoid concurrent runs) ----------
$lockFile = $TMP_DIR . '/cleanup_feeds.lock';
$lfh = @fopen($lockFile, 'c+');
if ($lfh === false) {
    log_line("Cannot open lock file: $lockFile", 'ERROR');
    exit(1);
}
if (!flock($lfh, LOCK_EX | LOCK_NB)) {
    log_line("Another cleanup_feeds.php is already running. Exiting.", 'INFO');
    exit(0);
}

// ---------- Scan directory ----------
$now         = time();
$cutoffTs    = $now - $maxAgeSeconds;
$totalBytes  = 0;
$files       = []; // list of ['path'=>, 'mtime'=>, 'size'=>]

$dir = new DirectoryIterator($realFeeds);
foreach ($dir as $f) {
    if ($f->isDot() || !$f->isFile()) continue;

    $name = $f->getFilename();
    // keep .htaccess or other control files
    if ($name === '.htaccess' || $name === 'index.html' || $name === 'index.php') continue;

    // only consider JSON / XML feeds we created
    if (!preg_match('/\.(json|xml)$/i', $name)) continue;

    $path  = $f->getPathname();
    $mtime = $f->getMTime();
    $size  = $f->getSize();

    $files[] = ['path' => $path, 'mtime' => $mtime, 'size' => $size];
    $totalBytes += (int)$size;
}

usort($files, static function($a,$b){ return $a['mtime'] <=> $b['mtime']; }); // oldest first

$initialCount = count($files);
$policyAge = human_duration($maxAgeSeconds);
log_line("Scanned feeds: $initialCount file(s), total " . human_bytes($totalBytes), 'INFO');
log_line("Policy: older than {$policyAge} OR keep under {$MAX_MB}MB and {$MAX_FILES} files" . ($DRY_RUN ? " [DRY RUN]" : ""), 'INFO');

$deletedCount = 0;
$deletedBytes = 0;

// ---------- Rule 1: delete files older than cutoff ----------
foreach ($files as $i => $meta) {
    if ($meta['mtime'] <= $cutoffTs) {
        $ageDays = (int) floor(($now - $meta['mtime']) / 86400);
        $bytes   = (int)$meta['size'];
        if ($DRY_RUN) {
            log_line("DRY: would delete (age {$ageDays}d) {$meta['path']} (" . human_bytes($bytes) . ")", 'INFO');
        } else {
            if (@unlink($meta['path'])) {
                log_line("Deleted (age {$ageDays}d): {$meta['path']} (" . human_bytes($bytes) . ")", 'INFO');
                $deletedCount++;
                $deletedBytes += $bytes;
                $totalBytes   -= $bytes;
                unset($files[$i]);
            } else {
                log_line("Failed to delete: {$meta['path']}", 'WARNING');
            }
        }
    }
}
// Reindex after deletions
$files = array_values($files);

// ---------- Rule 2: cap total file count ----------
if (count($files) > $MAX_FILES) {
    $toRemove = count($files) - $MAX_FILES;
    log_line("Capping count: removing $toRemove oldest file(s) to meet limit {$MAX_FILES}.", 'INFO');

    for ($i = 0; $i < $toRemove && isset($files[$i]); $i++) {
        $meta  = $files[$i];
        $bytes = (int)$meta['size'];
        if ($DRY_RUN) {
            log_line("DRY: would delete (count cap) {$meta['path']} (" . human_bytes($bytes) . ")", 'INFO');
        } else {
            if (@unlink($meta['path'])) {
                log_line("Deleted (count cap): {$meta['path']} (" . human_bytes($bytes) . ")", 'INFO');
                $deletedCount++;
                $deletedBytes += $bytes;
                $totalBytes   -= $bytes;
                unset($files[$i]);
            } else {
                log_line("Failed to delete: {$meta['path']}", 'WARNING');
            }
        }
    }
    $files = array_values($files);
}

// ---------- Rule 3: cap total size ----------
$maxBytes = $MAX_MB * 1024 * 1024;
if ($totalBytes > $maxBytes) {
    log_line("Capping size: current " . human_bytes($totalBytes) . " > limit " . human_bytes($maxBytes) . ". Deleting oldest until under limit.", 'INFO');

    // files are oldest-first already
    $idx = 0;
    while ($totalBytes > $maxBytes && isset($files[$idx])) {
        $meta  = $files[$idx];
        $bytes = (int)$meta['size'];
        if ($DRY_RUN) {
            log_line("DRY: would delete (size cap) {$meta['path']} (" . human_bytes($bytes) . ")", 'INFO');
        } else {
            if (@unlink($meta['path'])) {
                log_line("Deleted (size cap): {$meta['path']} (" . human_bytes($bytes) . ")", 'INFO');
                $deletedCount++;
                $deletedBytes += $bytes;
                $totalBytes   -= $bytes;
                unset($files[$idx]);
            } else {
                log_line("Failed to delete: {$meta['path']}", 'WARNING');
            }
        }
        $idx++;
    }
    $files = array_values($files);
}

// ---------- Summary ----------
$finalCount = count($files);
log_line("Done. Deleted $deletedCount file(s), freed " . human_bytes($deletedBytes) . ".", 'INFO');
log_line("Remaining: $finalCount file(s), total " . human_bytes($totalBytes) . ".", 'INFO');

$summary = sprintf(
    'deleted=%d freed=%s remaining=%d remaining_size=%s%s',
    $deletedCount,
    human_bytes($deletedBytes),
    $finalCount,
    human_bytes($totalBytes),
    $DRY_RUN ? ' dry-run' : ''
);
write_run_summary($summary);

// Keep lock until exit
exit(0);
