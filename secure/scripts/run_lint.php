#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 1);

$finder = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS)
);

$phpFiles = [];
foreach ($finder as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'feeds' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        $phpFiles[] = $path;
    }
}

if (!$phpFiles) {
    echo "No PHP files found to lint." . PHP_EOL;
    exit(0);
}

$binary = PHP_BINARY ?: 'php';
$errors = [];
foreach ($phpFiles as $file) {
    $cmd = escapeshellarg($binary) . ' -l ' . escapeshellarg($file);
    exec($cmd, $output, $code);
    if ($code !== 0) {
        $errors[] = [
            'file' => $file,
            'output' => implode(PHP_EOL, $output),
        ];
    }
}

if ($errors) {
    fwrite(STDERR, "PHP lint failures:" . PHP_EOL);
    foreach ($errors as $err) {
        fwrite(STDERR, '  ✖ ' . $err['file'] . PHP_EOL);
        if ($err['output'] !== '') {
            fwrite(STDERR, '    ' . str_replace(PHP_EOL, PHP_EOL . '    ', $err['output']) . PHP_EOL);
        }
    }
    exit(1);
}

echo 'PHP lint passed on ' . count($phpFiles) . ' files.' . PHP_EOL;
exit(0);
