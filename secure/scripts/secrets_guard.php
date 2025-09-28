#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * secrets_guard.php
 *
 * Sanity checks to ensure secret files stay out of git while templates exist.
 * - Confirms .gitignore covers required secure/* files and storage dirs.
 * - Verifies example templates are present so teammates know what to fill.
 * - Fails if any real secret file is currently tracked by git.
 */

$scriptDir = __DIR__;
$secureDir = dirname($scriptDir);
$repoRoot  = dirname($secureDir);

$requiredIgnores = [
    'secure/admin-credentials.php',
    'secure/admin-credentials.local.php',
    'secure/db-credentials.php',
    'secure/db-credentials.local.php',
    'secure/sfm-secrets.php',
    'secure/config.php',
    'secure/admin-password-rehash.todo',
    'secure/vendor/',
    'secure/logs/*',
    '!secure/logs/.gitignore',
    'secure/ratelimits/*',
    '!secure/ratelimits/.gitignore',
    'storage/*',
    '!storage/.gitignore',
];

$requiredExamples = [
    $secureDir . '/admin-credentials.example.php',
    $secureDir . '/db-credentials.example.php',
    $secureDir . '/config.example.php',
    $secureDir . '/sfm-secrets.example.php',
];

$realSecrets = [
    'secure/admin-credentials.php',
    'secure/db-credentials.php',
    'secure/sfm-secrets.php',
    'secure/config.php',
];

$issues = [];
$passes = [];

$gitignorePath = $repoRoot . '/.gitignore';
if (!is_file($gitignorePath)) {
    $issues[] = 'Missing .gitignore at repo root.';
} else {
    $gitignoreLines = file($gitignorePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($requiredIgnores as $pattern) {
        $found = false;
        foreach ($gitignoreLines as $line) {
            $line = trim($line);
            if ($line === $pattern) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $passes[] = "Found .gitignore entry: {$pattern}";
        } else {
            $issues[] = "Missing .gitignore entry: {$pattern}";
        }
    }
}

foreach ($requiredExamples as $template) {
    if (is_file($template)) {
        $passes[] = 'Template present: ' . relative_path($template, $repoRoot);
    } else {
        $issues[] = 'Template missing: ' . relative_path($template, $repoRoot);
    }
}

foreach ($realSecrets as $path) {
    $tracked = git_is_tracked($path, $repoRoot);
    if ($tracked) {
        $issues[] = 'Secret file tracked by git: ' . $path;
    } else {
        $passes[] = 'Secret file not tracked: ' . $path;
    }
}

if ($passes) {
    fwrite(STDOUT, "Secrets Guard checks:\n");
    foreach ($passes as $msg) {
        fwrite(STDOUT, "  ✔ {$msg}\n");
    }
}

if ($issues) {
    fwrite(STDERR, "\nIssues found:\n");
    foreach ($issues as $msg) {
        fwrite(STDERR, "  ✖ {$msg}\n");
    }
    exit(1);
}

fwrite(STDOUT, "\nAll Secrets Guard checks passed.\n");
exit(0);

function git_is_tracked(string $path, string $repoRoot): bool
{
    $cmd = sprintf('cd %s && git ls-files --error-unmatch %s >/dev/null 2>&1', escapeshellarg($repoRoot), escapeshellarg($path));
    exec($cmd, $out, $code);
    return $code === 0;
}

function relative_path(string $abs, string $base): string
{
    $absReal  = realpath($abs) ?: $abs;
    $baseReal = realpath($base) ?: $base;
    if (strpos($absReal, $baseReal) === 0) {
        return ltrim(substr($absReal, strlen($baseReal)), DIRECTORY_SEPARATOR) ?: '.';
    }
    return $abs;
}
