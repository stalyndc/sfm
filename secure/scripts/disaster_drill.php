#!/usr/bin/env php
<?php
/**
 * disaster_drill.php
 *
 * Verifies that a fresh checkout of SimpleFeedMaker can be restored quickly.
 * Performs structural checks, confirms template files exist, and optionally
 * inspects backup snapshots. Use this script during quarterly disaster drills
 * to surface missing prerequisites before you need them.
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
$outputJson    = !empty($options['json']);
$dryRun        = !empty($options['dry-run']);
$noRecord      = !empty($options['no-record']);
$backupsDir    = $options['backups'] ?? null;
$checksumFile  = $options['checksum-file'] ?? null;
$recordPathOpt = $options['record'] ?? null;

if ($recordPathOpt !== null) {
    $recordPath = $recordPathOpt;
} elseif (defined('SFM_DRILL_STATUS_FILE')) {
    $recordPath = SFM_DRILL_STATUS_FILE;
} elseif (defined('STORAGE_ROOT')) {
    $recordPath = STORAGE_ROOT . '/logs/disaster_drill.json';
} else {
    $recordPath = $projectRoot . '/storage/logs/disaster_drill.json';
}

if ($backupsDir === null && defined('SFM_BACKUPS_DIR') && SFM_BACKUPS_DIR !== '') {
    $backupsDir = SFM_BACKUPS_DIR;
}

$checks   = [];
$checks[] = run_check('composer_manifest', function () use ($projectRoot): array {
    $paths = [
        $projectRoot . '/composer.json',
        $projectRoot . '/composer.lock',
    ];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            return fail('Missing file: ' . relative_path($path, $projectRoot));
        }
    }
    return ok('Composer manifest present.');
});

$checks[] = run_check('secure_templates', function () use ($secureDir, $projectRoot): array {
    $templates = [
        $secureDir . '/admin-credentials.example.php',
        $secureDir . '/db-credentials.example.php',
        $secureDir . '/config.example.php',
        $secureDir . '/sfm-secrets.example.php',
    ];
    $missing = [];
    foreach ($templates as $path) {
        if (!is_file($path)) {
            $missing[] = relative_path($path, $projectRoot);
        }
    }
    if ($missing) {
        return fail('Missing secret templates: ' . implode(', ', $missing));
    }
    return ok('Secret templates available for provisioning.');
});

$checks[] = run_check('secure_scripts', function () use ($secureDir, $projectRoot): array {
    $dir = $secureDir . '/scripts';
    if (!is_dir($dir)) {
        return fail('secure/scripts directory not found.');
    }
    $files = glob($dir . '/*.php');
    if (!$files) {
        return fail('secure/scripts contains no PHP utilities.');
    }
    $names = array_map(static fn($path) => basename($path), $files);
    return ok('Available scripts: ' . implode(', ', $names));
});

$checks[] = run_check('storage_structure', function () use ($projectRoot): array {
    $storage = $projectRoot . '/storage';
    $required = ['logs', 'ratelimits', 'httpcache', 'jobs', 'sessions'];
    $missing = [];
    foreach ($required as $sub) {
        if (!is_dir($storage . '/' . $sub)) {
            $missing[] = 'storage/' . $sub;
        }
    }
    if ($missing) {
        return warn('Missing storage subdirectories: ' . implode(', ', $missing));
    }
    return ok('Storage scaffolding present.');
});

$checks[] = run_check('feeds_protection', function () use ($projectRoot): array {
    $feedsDir = $projectRoot . '/feeds';
    if (!is_dir($feedsDir)) {
        return fail('feeds directory missing.');
    }
    $htaccess = $feedsDir . '/.htaccess';
    if (!is_file($htaccess)) {
        return warn('feeds/.htaccess not found; verify directory protection.');
    }
    return ok('feeds directory ready with .htaccess.');
});

$checks[] = run_check('vendor_autoload', function () use ($secureDir, $projectRoot): array {
    $autoload = $secureDir . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return warn('secure/vendor/autoload.php missing. Run `composer install --no-dev`.');
    }
    return ok('Composer autoloader present.');
});

$checks[] = run_check('health_endpoint', function () use ($projectRoot): array {
    $health = $projectRoot . '/health.php';
    if (!is_file($health)) {
        return warn('health.php missing; uptime monitors may fail.');
    }
    return ok('health.php present.');
});

if ($backupsDir !== null && $backupsDir !== '') {
    $checks[] = run_check('backups_directory', function () use ($backupsDir, $projectRoot): array {
        $real = realpath($backupsDir);
        if ($real === false || !is_dir($real)) {
            return fail('Backups directory not found: ' . $backupsDir);
        }
        $files = glob($real . '/*');
        $count = $files ? count($files) : 0;
        return ok(sprintf('Backups directory reachable (%d items).', $count));
    });
}

if ($checksumFile !== null) {
    $checks[] = run_check('backup_checksums', function () use ($checksumFile, $projectRoot): array {
        $real = realpath($checksumFile);
        if ($real === false || !is_file($checksumFile)) {
            return fail('Checksum log not found: ' . $checksumFile);
        }
        $contents = file_get_contents($checksumFile);
        if ($contents === false || $contents === '') {
            return warn('Checksum log is empty.');
        }
        json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return warn('Checksum log is not valid JSON: ' . json_last_error_msg());
        }
        return ok('Checksum log present and parses as JSON.');
    });
}

$summary = summarise_checks($checks);
$summary['meta'] = [
    'backups_dir'   => $backupsDir,
    'checksum_file' => $checksumFile,
];

if ($outputJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    render_human_summary($summary, $projectRoot, $dryRun, $recordPath, $noRecord);
}

if (!$dryRun && !$noRecord && $recordPath !== '') {
    persist_drill_summary($recordPath, $summary);
} elseif ($dryRun && !$noRecord && $recordPath !== '') {
    fwrite(STDOUT, "Dry-run: would record drill summary at {$recordPath}\n");
}

if ($summary['failures'] > 0) {
    exit(1);
}
exit(0);

/**
 * @param array<int,string> $argv
 * @return array<string,string|bool>
 */
function parse_cli_options(array $argv): array
{
    $options = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0) {
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

/**
 * @param string   $name
 * @param callable $closure
 * @return array<string,mixed>
 */
function run_check(string $name, callable $closure): array
{
    try {
        $result = $closure();
        $result['name'] = $name;
        return $result;
    } catch (Throwable $e) {
        return [
            'name'    => $name,
            'status'  => 'fail',
            'message' => 'Unhandled exception: ' . $e->getMessage(),
        ];
    }
}

function ok(string $message): array
{
    return ['status' => 'ok', 'message' => $message];
}

function warn(string $message): array
{
    return ['status' => 'warn', 'message' => $message];
}

function fail(string $message): array
{
    return ['status' => 'fail', 'message' => $message];
}

/**
 * @param string $path
 * @param string $base
 */
function relative_path(string $path, string $base): string
{
    $realBase = realpath($base) ?: $base;
    $realPath = realpath($path) ?: $path;
    if (strpos($realPath, $realBase) === 0) {
        return ltrim(substr($realPath, strlen($realBase)), DIRECTORY_SEPARATOR) ?: basename($realPath);
    }
    return $path;
}

/**
 * @param array<int,array<string,mixed>> $checks
 * @return array<string,mixed>
 */
function summarise_checks(array $checks): array
{
    $failures = 0;
    $warnings = 0;
    foreach ($checks as $check) {
        if ($check['status'] === 'fail') {
            $failures++;
        } elseif ($check['status'] === 'warn') {
            $warnings++;
        }
    }

    $status = 'ok';
    if ($failures > 0) {
        $status = 'fail';
    } elseif ($warnings > 0) {
        $status = 'warn';
    }

    return [
        'status'      => $status,
        'failures'    => $failures,
        'warnings'    => $warnings,
        'generated_at'=> gmdate('c'),
        'checks'      => $checks,
    ];
}

/**
 * @param array<string,mixed> $summary
 */
function render_human_summary(array $summary, string $projectRoot, bool $dryRun, string $recordPath, bool $noRecord): void
{
    $failures = $summary['failures'];
    $warnings = $summary['warnings'];
    $status   = $summary['status'] ?? 'ok';
    $checks   = $summary['checks'];

    fwrite(STDOUT, "Disaster Drill Report\n");
    fwrite(STDOUT, str_repeat('=', 22) . "\n");

    foreach ($checks as $check) {
        $symbol = match ($check['status']) {
            'ok'   => '✔',
            'warn' => '⚠',
            'fail' => '✖',
            default => '-',
        };
        fwrite(STDOUT, sprintf(" %s %-18s %s\n", $symbol, '[' . $check['name'] . ']', $check['message']));
    }

    fwrite(STDOUT, "\nSummary: " . $failures . ' failure(s), ' . $warnings . " warning(s).\n");
    if ($failures > 0) {
        fwrite(STDOUT, "Address the ✖ items before considering the drill successful.\n");
    } elseif ($warnings > 0) {
        fwrite(STDOUT, "Review ⚠ items to tighten readiness.\n");
    } else {
        fwrite(STDOUT, "All checks passed. Document the drill results.\n");
    }

    if ($dryRun) {
        fwrite(STDOUT, "(Dry-run mode: summary not recorded.)\n");
    } elseif ($noRecord) {
        fwrite(STDOUT, "Recording disabled via --no-record.\n");
    } else {
        fwrite(STDOUT, "Summary status: {$status}. Record path: {$recordPath}\n");
    }
}

function persist_drill_summary(string $path, array $summary): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "[WARN] Unable to create drill log directory: {$dir}\n");
        return;
    }

    $summary['generated_at_ts'] = time();
    $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "[WARN] Failed to encode drill summary to JSON.\n");
        return;
    }
    if (@file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        fwrite(STDERR, "[WARN] Unable to write drill summary to {$path}.\n");
    } else {
        fwrite(STDOUT, "Recorded drill summary at {$path}\n");
    }
}
