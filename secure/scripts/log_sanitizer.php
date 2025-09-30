#!/usr/bin/env php
<?php
/**
 * log_sanitizer.php
 *
 * Redacts personal data from secure log files and archives stale artifacts.
 *  - Finds email addresses, phone numbers, and sensitive query parameters and
 *    replaces them with neutral placeholders.
 *  - Compresses sanitised logs older than the retention window into
 *    secure/logs/archive/<file>.gz to keep the footprint small.
 */

declare(strict_types=1);

$scriptDir   = __DIR__;
$secureDir   = dirname($scriptDir);
$defaultDir  = $secureDir . '/logs';

$options = parse_cli_options($argv ?? []);
$logDir  = resolve_log_dir($options['dir'] ?? $defaultDir);
if ($logDir === null) {
    fwrite(STDERR, "[ERROR] Unable to resolve log directory.\n");
    exit(1);
}

$retentionDays = max(1, (int)($options['retention'] ?? 14));
$dryRun        = !empty($options['dry-run']);
$archiveDir    = $logDir . '/archive';

$patternSet = build_pattern_set();

$logFiles = glob($logDir . '/*.log');
if ($logFiles === false) {
    fwrite(STDERR, "[ERROR] Failed to enumerate log files in {$logDir}.\n");
    exit(1);
}

$sanitised = [];
foreach ($logFiles as $logFile) {
    if (is_dir($logFile)) {
        continue;
    }

    $result = sanitise_file($logFile, $patternSet, $dryRun);
    if ($result['modified']) {
        $sanitised[] = $result;
    }
}

$archived = [];
if (!$dryRun) {
    ensure_archive_dir($archiveDir);
    $archived = archive_old_logs($logFiles, $archiveDir, $retentionDays);
}

report_summary($logDir, $sanitised, $archived, $dryRun);
exit(0);

/**
 * @param array<int,string> $argv
 * @return array<string,string|bool>
 */
function parse_cli_options(array $argv): array
{
    $options = [];
    foreach ($argv as $idx => $arg) {
        if ($idx === 0) {
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $arg, $m)) {
            $options[strtolower($m[1])] = $m[2];
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)$/i', $arg, $m)) {
            $options[strtolower($m[1])] = true;
        }
    }
    return $options;
}

function resolve_log_dir(string $path): ?string
{
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        return null;
    }
    if (!is_readable($real) || !is_writable($real)) {
        fwrite(STDERR, "[WARN] Log directory {$real} must be readable and writable.\n");
    }
    return rtrim($real, '/\\');
}

/**
 * @return array<string,callable>
 */
function build_pattern_set(): array
{
    return [
        'email' => function (string $input): array {
            $count = 0;
            $output = preg_replace_callback(
                '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
                static function () use (&$count) {
                    $count++;
                    return '[email]';
                },
                $input
            );
            return [$output ?? $input, $count];
        },
        'phone' => function (string $input): array {
            $pattern = '/(?<!\d)(\+?\d[\d()\s.-]{6,}\d)/';
            $count   = 0;
            $output  = preg_replace_callback(
                $pattern,
                static function ($matches) use (&$count) {
                    $digits = preg_replace('/\D+/', '', $matches[1]);
                    if (strlen($digits) < 9) {
                        return $matches[0];
                    }
                    if (!preg_match('/[\s().-]/', $matches[1])) {
                        return $matches[0];
                    }
                    $count++;
                    return '[phone]';
                },
                $input
            );
            return [$output ?? $input, $count];
        },
        'query' => function (string $input): array {
            $sensitiveKeys = '(token|key|secret|password|pass|auth|code|email)';
            $pattern = '/((?:\?|&)' . $sensitiveKeys . '=)([^&\s]+)/i';
            $count   = 0;
            $output  = preg_replace_callback(
                $pattern,
                static function ($matches) use (&$count) {
                    $count++;
                    return $matches[1] . '[redacted]';
                },
                $input
            );
            return [$output ?? $input, $count];
        },
    ];
}

/**
 * @param string   $file
 * @param array<string,callable> $patternSet
 * @param bool     $dryRun
 * @return array{file:string,modified:bool,replacements:array<string,int>}
 */
function sanitise_file(string $file, array $patternSet, bool $dryRun): array
{
    $original = @file_get_contents($file);
    if ($original === false) {
        fwrite(STDERR, "[WARN] Unable to read {$file}; skipping.\n");
        return ['file' => $file, 'modified' => false, 'replacements' => []];
    }

    $content      = $original;
    $replacements = [];

    foreach ($patternSet as $name => $handler) {
        [$content, $count] = $handler($content);
        if ($count > 0) {
            $replacements[$name] = ($replacements[$name] ?? 0) + $count;
        }
    }

    if ($content === $original) {
        return ['file' => $file, 'modified' => false, 'replacements' => []];
    }

    if (!$dryRun) {
        if (@file_put_contents($file, $content) === false) {
            fwrite(STDERR, "[ERROR] Failed to write sanitised contents back to {$file}.\n");
        }
    }

    return ['file' => $file, 'modified' => true, 'replacements' => $replacements];
}

function ensure_archive_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "[ERROR] Unable to create archive directory {$dir}.\n");
    }
}

/**
 * @param array<int,string> $logFiles
 * @param string            $archiveDir
 * @param int               $retentionDays
 * @return array<int,array{file:string,target:string}>
 */
function archive_old_logs(array $logFiles, string $archiveDir, int $retentionDays): array
{
    $cutoff = time() - ($retentionDays * 86400);
    $archived = [];

    foreach ($logFiles as $file) {
        if (!is_file($file)) {
            continue;
        }
        $mtime = @filemtime($file);
        if ($mtime === false || $mtime >= $cutoff) {
            continue;
        }

        $target = $archiveDir . '/' . basename($file) . '.gz';
        if (file_exists($target)) {
            continue;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            fwrite(STDERR, "[WARN] Unable to read {$file} for archiving.\n");
            continue;
        }

        $compressed = gzencode($contents, 9);
        if ($compressed === false) {
            fwrite(STDERR, "[WARN] Failed to compress {$file}.\n");
            continue;
        }

        if (@file_put_contents($target, $compressed) === false) {
            fwrite(STDERR, "[WARN] Unable to write archive {$target}.\n");
            continue;
        }

        @chmod($target, 0640);
        if (!@unlink($file)) {
            fwrite(STDERR, "[WARN] Archive created but failed to remove {$file}.\n");
        }

        $archived[] = ['file' => $file, 'target' => $target];
    }

    return $archived;
}

/**
 * @param string $logDir
 * @param array<int,array{file:string,modified:bool,replacements:array<string,int>}> $sanitised
 * @param array<int,array{file:string,target:string}> $archived
 */
function report_summary(string $logDir, array $sanitised, array $archived, bool $dryRun): void
{
    fwrite(STDOUT, sprintf("Log Sanitizer scanned %s\n", $logDir));

    if ($sanitised) {
        foreach ($sanitised as $item) {
            $parts = [];
            foreach ($item['replacements'] as $pattern => $count) {
                $parts[] = $pattern . ':' . $count;
            }
            fwrite(STDOUT, sprintf("  - Redacted %s (%s)\n", basename($item['file']), implode(', ', $parts)));
        }
    } else {
        fwrite(STDOUT, "  - No redactions were required.\n");
    }

    if ($dryRun) {
        fwrite(STDOUT, "Dry-run mode: no files were modified or archived.\n");
        return;
    }

    if ($archived) {
        foreach ($archived as $item) {
            fwrite(STDOUT, sprintf("  - Archived %s -> %s\n", basename($item['file']), basename($item['target'])));
        }
    } else {
        fwrite(STDOUT, "  - No logs met the archive threshold.\n");
    }
}
