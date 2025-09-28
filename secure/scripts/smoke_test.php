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

if ($failures) {
    fwrite(STDERR, "Smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  ✖ ' . $failure['file'] . "\n");
        if ($failure['output'] !== '') {
            fwrite(STDERR, "    " . str_replace("\n", "\n    ", $failure['output']) . "\n");
        }
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Smoke test passed. Checked %d PHP files.\n", count($lintTargets)));
exit(0);
