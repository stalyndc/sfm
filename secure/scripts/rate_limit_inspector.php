#!/usr/bin/env php
<?php
/**
 * rate_limit_inspector.php
 *
 * Watches the rate-limit directory (storage/ratelimits or secure/ratelimits)
 * and reports burst traffic. When the number of distinct IPs within the
 * configured window exceeds the threshold, the top offenders are logged to the
 * secure/ratelimits README for follow-up.
 */

declare(strict_types=1);

$scriptDir   = __DIR__;
$secureDir   = dirname($scriptDir);
$projectRoot = dirname($secureDir);

$configPath = $projectRoot . '/includes/config.php';
if (is_file($configPath)) {
    require_once $configPath;
}

$options = parse_cli_options($argv ?? []);

$windowSeconds    = max(60, (int)($options['window'] ?? 3600));
$uniqueThreshold  = max(1, (int)($options['threshold'] ?? 100));
$topCount         = max(1, (int)($options['top'] ?? 5));
$dryRun           = !empty($options['dry-run']);
$blockEnabled     = !empty($options['block']) || isset($options['block-file']);
$blockTarget      = resolve_block_target($options, $projectRoot);
$explicitNotify   = !empty($options['notify']) || isset($options['notify-to']);
$notifySuppressed = !empty($options['no-notify']);
$notifyRecipients = resolve_alert_recipients($options['notify-to'] ?? null);
$shouldNotify     = !$dryRun && !$notifySuppressed && ($explicitNotify || $notifyRecipients);

if ($blockEnabled && $blockTarget === null) {
    fwrite(STDERR, "[ERROR] --block specified but target .htaccess could not be resolved.\n");
    exit(1);
}

$rateLimitDir = resolve_rate_limit_dir($options['dir'] ?? null, $projectRoot, $secureDir);
if ($rateLimitDir === null) {
    fwrite(STDERR, "[ERROR] Could not locate rate limit directory.\n");
    exit(1);
}

[$ipStats, $uniqueCount] = collect_ip_stats($rateLimitDir, $windowSeconds);

if ($uniqueCount === 0) {
    fwrite(STDOUT, "No recent rate limit activity in {$rateLimitDir}.\n");
    exit(0);
}

$windowLabel = human_duration($windowSeconds);
$message = sprintf(
    "Observed %d unique IP(s) in the last %s (threshold %d).",
    $uniqueCount,
    $windowLabel,
    $uniqueThreshold
);
fwrite(STDOUT, $message . "\n");

if ($uniqueCount <= $uniqueThreshold) {
    fwrite(STDOUT, "Unique IP volume is under threshold; no abuse log entry required.\n");
    exit(0);
}

$sorted = $ipStats;
usort($sorted, static function (array $a, array $b): int {
    return $b['hits'] <=> $a['hits'];
});
$offenders = array_slice($sorted, 0, min($topCount, count($sorted)));

$logPath = locate_abuse_log($secureDir);
if ($logPath === null) {
    fwrite(STDERR, "[ERROR] Unable to determine rate limit README location.\n");
    exit(1);
}

$timestamp = gmdate('c') . 'Z';
$logEntries = [];
foreach ($offenders as $entry) {
    $ip      = $entry['ip'];
    $hits    = $entry['hits'];
    $buckets = format_bucket_summary($entry['buckets']);
    $logEntries[] = sprintf(
        '- %s — %s recorded %d requests (%s) within the last %s; unique IPs %d > %d',
        $timestamp,
        $ip,
        $hits,
        $buckets,
        $windowLabel,
        $uniqueCount,
        $uniqueThreshold
    );
}

$logBody = "\n" . implode("\n", $logEntries) . "\n";

if ($dryRun) {
    fwrite(STDOUT, "Dry-run: would append to {$logPath}:\n{$logBody}");
    if ($blockEnabled) {
        $ips = array_column($offenders, 'ip');
        fwrite(
            STDOUT,
            sprintf(
                "Dry-run: would update blocklist in %s for IPs: %s\n",
                $blockTarget,
                implode(', ', $ips)
            )
        );
    }
    if (!$notifySuppressed && ($explicitNotify || $notifyRecipients)) {
        fwrite(STDOUT, "Dry-run: would email alert to " . implode(', ', $notifyRecipients ?: ['(none configured)']) . "\n");
    }
    exit(0);
}

ensure_abuse_log_header($logPath);
if (@file_put_contents($logPath, $logBody, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "[ERROR] Failed to write to abuse log at {$logPath}.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Logged %d offender(s) to %s.\n", count($offenders), $logPath));

if ($blockEnabled) {
    $ips = array_column($offenders, 'ip');
    $result = apply_blocklist($blockTarget, $ips);
    fwrite(STDOUT, $result . "\n");
}

if ($shouldNotify) {
    $subject = sprintf('[SimpleFeedMaker] Rate limit alert (%d unique IPs)', $uniqueCount);
    $bodyParts = [];
    $bodyParts[] = sprintf('Unique IPs observed: %d (threshold %d)', $uniqueCount, $uniqueThreshold);
    $bodyParts[] = sprintf('Window: %s', $windowLabel);
    $bodyParts[] = '';
    $bodyParts[] = "Top offenders:";
    foreach ($offenders as $entry) {
        $bodyParts[] = sprintf(' - %s (%d hits) %s', $entry['ip'], $entry['hits'], format_bucket_summary($entry['buckets']));
    }
    if ($blockEnabled && isset($ips)) {
        $bodyParts[] = '';
        $bodyParts[] = 'Blocklist updated at: ' . $blockTarget;
    }
    $bodyParts[] = '';
    $bodyParts[] = 'Logged to: ' . $logPath;

    $body = implode("\n", $bodyParts);
    $sent = send_alert_email($notifyRecipients, $subject, $body);
    fwrite(STDOUT, ($sent ? '[ALERT]' : '[WARN]') . ' Email notification ' . ($sent ? 'sent.' : 'failed.') . "\n");
} elseif ($explicitNotify && $notifySuppressed) {
    fwrite(STDOUT, "[INFO] Notification suppressed by --no-notify.\n");
}

exit(0);

/**
 * Parse simple CLI options of the form --key=value or --flag.
 *
 * @param array<int,string> $argv
 * @return array<string,string|bool>
 */
function parse_cli_options(array $argv): array
{
    $options = [];
    foreach ($argv as $index => $arg) {
        if ($index === 0) {
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $arg, $matches)) {
            $options[strtolower($matches[1])] = $matches[2];
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)$/i', $arg, $matches)) {
            $options[strtolower($matches[1])] = true;
        }
    }
    return $options;
}

/**
 * Determine which rate limit directory to inspect.
 */
function resolve_rate_limit_dir(?string $override, string $projectRoot, string $secureDir): ?string
{
    if ($override) {
        $real = realpath($override);
        if ($real !== false && is_dir($real)) {
            return $real;
        }
        fwrite(STDERR, "[WARN] Override directory {$override} not found.\n");
    }

    $candidates = [];
    $envDir = getenv('SFM_RATE_LIMIT_DIR');
    if ($envDir !== false && $envDir !== '') {
        $candidates[] = $envDir;
    }

    $candidates[] = $projectRoot . '/storage/ratelimits';
    $candidates[] = $projectRoot . '/secure/ratelimits';

    // Allow shared-host layout where secure/ lives beside public_html
    $candidates[] = dirname($projectRoot) . '/secure/ratelimits';

    foreach ($candidates as $candidate) {
        $path = realpath($candidate);
        if ($path !== false && is_dir($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Inspect JSON files within the rate limit directory.
 *
 * @param string $dir
 * @param int    $windowSeconds
 * @return array{0:array<int,array<string,mixed>>,1:int}
 */
function collect_ip_stats(string $dir, int $windowSeconds): array
{
    $files = glob(rtrim($dir, '/\\') . '/*.json');
    if (!$files) {
        return [[], 0];
    }

    $cutoff = time() - $windowSeconds;
    $stats = [];

    foreach ($files as $file) {
        $basename = basename($file, '.json');
        if ($basename === '') {
            continue;
        }

        $parts = explode('__', $basename, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$bucket, $ip] = $parts;
        $ip = trim($ip);
        if ($ip === '') {
            continue;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $recent = [];
        foreach ($data as $value) {
            if (is_int($value) && $value >= $cutoff) {
                $recent[] = $value;
            }
        }
        $hitCount = count($recent);
        if ($hitCount === 0) {
            continue;
        }

        if (!isset($stats[$ip])) {
            $stats[$ip] = [
                'ip'      => $ip,
                'hits'    => 0,
                'buckets' => [],
            ];
        }

        $stats[$ip]['hits'] += $hitCount;
        $stats[$ip]['buckets'][$bucket] = ($stats[$ip]['buckets'][$bucket] ?? 0) + $hitCount;
    }

    return [array_values($stats), count($stats)];
}

/**
 * Attempt to locate the README file that records rate limit incidents.
 */
function locate_abuse_log(string $secureDir): ?string
{
    $dir = $secureDir . '/ratelimits';
    $candidates = [
        $dir . '/README.md',
        $dir . '/README.txt',
    ];

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    // If no README exists yet, default to README.md
    $target = $dir . '/README.md';
    if (@touch($target)) {
        return $target;
    }

    return null;
}

/**
 * Make sure the abuse log has a header section before appending entries.
 */
function ensure_abuse_log_header(string $path): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $contents = '';
    }
    if (strpos($contents, '## Abuse Log') !== false) {
        return;
    }

    $intro = "# Rate Limit Notes\n\nThis log records automated findings from `rate_limit_inspector.php`.\n\n## Abuse Log\n";
    if ($contents === '') {
        @file_put_contents($path, $intro);
        return;
    }

    @file_put_contents($path, rtrim($contents, "\r\n") . "\n\n## Abuse Log\n");
}

/**
 * Format bucket statistics for log output.
 *
 * @param array<string,int> $buckets
 */
function format_bucket_summary(array $buckets): string
{
    ksort($buckets);
    $parts = [];
    foreach ($buckets as $bucket => $count) {
        $parts[] = $bucket . ':' . $count;
    }
    return implode(', ', $parts);
}

function human_duration(int $seconds): string
{
    if ($seconds <= 60) {
        return $seconds . ' seconds';
    }
    $units = [
        86400 => 'day',
        3600  => 'hour',
        60    => 'minute',
    ];
    $parts = [];
    foreach ($units as $unitSeconds => $label) {
        if ($seconds < $unitSeconds && !$parts) {
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
    return implode(', ', $parts);
}

function resolve_block_target(array $options, string $projectRoot): ?string
{
    $blockSpecified = !empty($options['block']) || isset($options['block-file']);
    if (!$blockSpecified) {
        return null;
    }

    $target = $options['block-file'] ?? null;
    if ($target === null) {
        $blockValue = $options['block'] ?? '';
        if ($blockValue === true || $blockValue === '' || $blockValue === '1') {
            $target = $projectRoot . '/.htaccess';
        } else {
            $target = (string)$blockValue;
        }
    }

    if (!is_string($target) || $target === '') {
        return null;
    }

    if ($target[0] !== '/' && !preg_match('/^[A-Za-z]:\\\\/', $target)) {
        $target = $projectRoot . '/' . ltrim($target, '/');
    }

    $dir = dirname($target);
    if (!is_dir($dir)) {
        return null;
    }

    if (!file_exists($target)) {
        if (@touch($target) === false) {
            return null;
        }
    }

    if (!is_writable($target)) {
        return null;
    }

    $real = realpath($target);
    return $real !== false ? $real : $target;
}

function apply_blocklist(string $targetPath, array $newIps): string
{
    $newIps = array_values(array_unique(array_filter($newIps, static function ($ip) {
        return is_string($ip) && $ip !== '';
    })));

    if (!$newIps) {
        return 'No new IPs detected for blocking; blocklist unchanged.';
    }

    $contents = @file_get_contents($targetPath);
    if ($contents === false) {
        $contents = '';
    }

    $beginMarker = '# BEGIN RateLimitInspector';
    $endMarker   = '# END RateLimitInspector';

    $existingIps = extract_blocklist_ips($contents, $beginMarker, $endMarker);
    $merged      = array_values(array_unique(array_merge($existingIps, $newIps)));
    sort($merged);

    $blockSection = build_block_section($merged, $beginMarker, $endMarker);

    if ($contents === '') {
        $updated = $blockSection . "\n";
    } elseif (strpos($contents, $beginMarker) !== false && strpos($contents, $endMarker) !== false) {
        $pattern = sprintf('/%s.*?%s/s', preg_quote($beginMarker, '/'), preg_quote($endMarker, '/'));
        $updated = preg_replace($pattern, $blockSection, $contents, 1);
    } else {
        $updated = rtrim($contents, "\r\n") . "\n\n" . $blockSection . "\n";
    }

    if ($updated === null) {
        return '[ERROR] Failed to assemble updated blocklist contents.';
    }

    if (@file_put_contents($targetPath, $updated) === false) {
        return sprintf('[ERROR] Unable to write blocklist updates to %s.', $targetPath);
    }

    return sprintf('Updated %s blocklist with %d IP(s).', $targetPath, count($merged));
}

function extract_blocklist_ips(string $contents, string $beginMarker, string $endMarker): array
{
    $pattern = sprintf('/%s(.*?)%s/s', preg_quote($beginMarker, '/'), preg_quote($endMarker, '/'));
    if (!preg_match($pattern, $contents, $match)) {
        return [];
    }

    $section = $match[1];
    $ips = [];

    if (preg_match_all('/Require\s+not\s+ip\s+([\w\.:]+)/i', $section, $requireMatches)) {
        foreach ($requireMatches[1] as $ip) {
            $ips[] = trim($ip);
        }
    }
    if (preg_match_all('/Deny\s+from\s+([\w\.:]+)/i', $section, $denyMatches)) {
        foreach ($denyMatches[1] as $ip) {
            $ips[] = trim($ip);
        }
    }

    return array_values(array_unique(array_filter($ips)));
}

function build_block_section(array $ips, string $beginMarker, string $endMarker): string
{
    $lines = [];
    $lines[] = $beginMarker;
    $lines[] = '<IfModule mod_authz_core.c>';
    $lines[] = '  <RequireAll>';
    $lines[] = '    Require all granted';
    foreach ($ips as $ip) {
        $lines[] = '    Require not ip ' . $ip;
    }
    $lines[] = '  </RequireAll>';
    $lines[] = '</IfModule>';
    $lines[] = '<IfModule !mod_authz_core.c>';
    $lines[] = '  Order allow,deny';
    $lines[] = '  Allow from all';
    foreach ($ips as $ip) {
        $lines[] = '  Deny from ' . $ip;
    }
    $lines[] = '</IfModule>';
    $lines[] = $endMarker;
    return implode("\n", $lines);
}

function resolve_alert_recipients(?string $override): array
{
    $candidates = [];
    if ($override !== null && trim($override) !== '') {
        $candidates = array_merge($candidates, split_recipients($override));
    }
    if (defined('SFM_ALERT_EMAIL')) {
        $candidates[] = SFM_ALERT_EMAIL;
    }
    $envSingle = getenv('SFM_ALERT_EMAIL');
    if ($envSingle !== false && $envSingle !== '') {
        $candidates = array_merge($candidates, split_recipients($envSingle));
    }
    $envMulti = getenv('SFM_ALERT_EMAILS');
    if ($envMulti !== false && $envMulti !== '') {
        $candidates = array_merge($candidates, split_recipients($envMulti));
    }

    $filtered = [];
    foreach ($candidates as $candidate) {
        $email = filter_var(trim($candidate), FILTER_VALIDATE_EMAIL);
        if ($email) {
            $filtered[$email] = true;
        }
    }
    return array_keys($filtered);
}

function split_recipients(string $value): array
{
    return preg_split('/[;,]+/', $value) ?: [];
}

function send_alert_email(array $recipients, string $subject, string $body): bool
{
    if (!$recipients) {
        return false;
    }
    $success = true;
    foreach ($recipients as $recipient) {
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        if (!mail($recipient, $subject, $body, $headers)) {
            $success = false;
        }
    }
    return $success;
}
