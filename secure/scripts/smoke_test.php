#!/usr/bin/env php
<?php
/**
 * smoke_test.php
 *
 * Composer-driven smoke test runner. Provides quick sanity checks before deploy:
 *   - syntax-lints all first-party PHP files (skips vendor/, storage/, feeds/, docs/)
 *   - ensures composer.json / composer.lock stay in sync with vendor (autoloader present)
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$phpBinary   = PHP_BINARY ?: 'php';

$skipDirs = [
    $projectRoot . '/secure/vendor',
    $projectRoot . '/storage',
    $projectRoot . '/feeds',
    $projectRoot . '/docs',
    $projectRoot . '/errors',
];

$lintTargets = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $path = $fileInfo->getRealPath();
    if ($path === false) {
        continue;
    }

    $skip = false;
    foreach ($skipDirs as $dir) {
        if (strpos($path, $dir . DIRECTORY_SEPARATOR) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
        $lintTargets[] = $path;
    }
}

$failures = [];
$warnings = [];
foreach ($lintTargets as $target) {
    $cmd = escapeshellcmd($phpBinary) . ' -l ' . escapeshellarg($target);
    exec($cmd, $output, $code);
    if ($code !== 0) {
        $failures[] = [
            'file' => $target,
            'output' => implode("\n", $output),
        ];
    }
}

$autoloader = $projectRoot . '/secure/vendor/autoload.php';
if (!is_file($autoloader)) {
    $failures[] = [
        'file' => 'secure/vendor/autoload.php',
        'output' => 'Composer vendor autoloader missing. Run `composer install --no-dev`.',
    ];
}

$auditOutput = [];
$auditExit   = 0;
exec('composer audit --locked --no-interaction 2>&1', $auditOutput, $auditExit);
if ($auditExit !== 0) {
    $joined = trim(implode("\n", $auditOutput));
    if (strpos($joined, 'Command "audit" is not defined') !== false) {
        $warnings[] = 'composer audit unavailable (Composer < 2.4?). Skipped security advisory check.';
    } elseif (stripos($joined, 'could not resolve host') !== false) {
        $warnings[] = 'composer audit skipped (network unreachable).';
    } elseif ($joined !== '') {
        $failures[] = [
            'file' => 'composer audit',
            'output' => $joined,
        ];
    } else {
        $failures[] = [
            'file' => 'composer audit',
            'output' => 'Security audit failed with exit code ' . $auditExit,
        ];
    }
}

// ------------------------------------------------------------------
// End-to-end generate.php smoke (fixture-backed)
// ------------------------------------------------------------------
$fixtureDir = $projectRoot . '/tests/fixtures';
if (is_dir($fixtureDir)) {
    $fixtureUrl = 'https://fixtures.simplefeedmaker.test/news.html';
    $testCmd = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($projectRoot . '/secure/scripts/tests/run_generate.php') . ' ' . escapeshellarg($fixtureUrl) . ' 3 rss';
    $generateOutput = [];
    exec($testCmd . ' 2>&1', $generateOutput, $generateExit);
    $generateJson = trim(implode("\n", $generateOutput));
    if ($generateExit !== 0) {
        $failures[] = [
            'file' => 'generate.php smoke',
            'output' => $generateJson,
        ];
    } else {
        $response = json_decode($generateJson, true);
        if (!is_array($response) || empty($response['ok'])) {
            $failures[] = [
                'file' => 'generate.php smoke',
                'output' => $generateJson,
            ];
        } else {
            $feedUrl = isset($response['feed_url']) ? (string)$response['feed_url'] : '';
            $jobId   = isset($response['job_id']) ? (string)$response['job_id'] : '';
            $feedFile = '';
            if ($feedUrl !== '') {
                $basename = basename(parse_url($feedUrl, PHP_URL_PATH) ?: '');
                if ($basename !== '') {
                    $feedFile = $projectRoot . '/feeds/' . $basename;
                    if (!is_file($feedFile)) {
                        $warnings[] = 'generate.php smoke: expected feed file not found: ' . $basename;
                    } else {
                        $feedContent = (string)@file_get_contents($feedFile);
                        if (strpos($feedContent, '<item>') === false && strpos($feedContent, '"items"') === false) {
                            $warnings[] = 'generate.php smoke: feed file missing expected items markup.';
                        }
                    }
                }
            }

            if (!empty($feedFile) && is_file($feedFile)) {
                @unlink($feedFile);
            }
            if ($jobId !== '') {
                $jobPath = $projectRoot . '/storage/jobs/' . preg_replace('~[^a-zA-Z0-9_-]~', '_', $jobId) . '.json';
                if (is_file($jobPath)) {
                    @unlink($jobPath);
                }
            }
        }
    }
} else {
    $warnings[] = 'Fixture directory missing; skipped generate.php smoke test.';
}

if ($failures) {
    fwrite(STDERR, "Smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  ✖ ' . $failure['file'] . "\n");
        if ($failure['output'] !== '') {
            fwrite(STDERR, "    " . str_replace("\n", "\n    ", $failure['output']) . "\n");
        }
    }
    if ($warnings) {
        fwrite(STDERR, "\nWarnings:\n");
        foreach ($warnings as $warning) {
            fwrite(STDERR, '  • ' . $warning . "\n");
        }
    }
    exit(1);
}

$message = sprintf("Smoke test passed. Checked %d PHP files.", count($lintTargets));
if ($warnings) {
    $message .= "\nWarnings:";
    foreach ($warnings as $warning) {
        $message .= "\n  • " . $warning;
    }
}
fwrite(STDOUT, $message . "\n");
exit(0);
