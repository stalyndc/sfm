#!/usr/bin/env php
<?php
/**
 * deploy_courier.php
 *
 * Automates the Deploy Courier playbook:
 *  - Runs `composer install --no-dev` so `secure/vendor/` is ready for upload.
 *  - Optionally triggers a frontend build (`npm run build`) when requested.
 *  - Packages the project into a versioned zip while excluding runtime folders.
 */

declare(strict_types=1);

$scriptDir   = __DIR__;
$secureDir   = dirname($scriptDir);
$projectRoot = dirname($secureDir);

$options = parse_cli_options($argv ?? []);

if (!empty($options['help']) || !empty($options['h'])) {
    echo <<<TXT
Deploy Courier - release helper
Usage: php secure/scripts/deploy_courier.php [options]

Options:
  --dry-run                 Preview actions without writing files or running commands.
  --skip-composer           Skip `composer install --no-dev`.
  --skip-tests              Skip `composer test`.
  --build-assets            Run `npm run build` after composer steps.
  --output-dir=PATH         Override build/releases directory.
  --name=FILENAME           Custom zip filename.
  --stage-dir=PATH          Copy the generated zip into this directory.
  --upload-cmd="...{file}" Run a custom upload command (placeholder {file}).
  --upload-sftp-host=HOST   Upload via SFTP using curl (requires curl CLI).
  --upload-sftp-user=USER
  --upload-sftp-path=/remote/path
  --upload-sftp-port=PORT   (default 22)
  --upload-sftp-password=PWD
  --upload-sftp-pass-env=ENVNAME (read password from env var)
  --upload-sftp-key=/path/to/key (for key-based auth; requires curl 7.58+)
  --post-deploy-url=URL     Fetch URL after upload (health check).
  --post-deploy-timeout=N   Timeout in seconds for post-deploy URL (default 20).

Examples:
  php secure/scripts/deploy_courier.php --build-assets \\
      --upload-sftp-host=ssh.simplefeedmaker.com \\
      --upload-sftp-user=deploy --upload-sftp-path=/public_html/releases \\
      --upload-sftp-pass-env=SFM_SFTP_PASSWORD \\
      --post-deploy-url=https://simplefeedmaker.com/health.php

TXT;
    exit(0);
}
$dryRun          = !empty($options['dry-run']);
$skipComposer    = !empty($options['skip-composer']);
$skipTests       = !empty($options['skip-tests']);
$runAssets       = !empty($options['build-assets']);
$outputDirOption = $options['output-dir'] ?? ($projectRoot . '/build/releases');
$zipNameOption   = $options['name'] ?? null;
$stageDirOption  = $options['stage-dir'] ?? null;
$uploadCmdOption = $options['upload-cmd'] ?? null;
$uploadSftpHost  = $options['upload-sftp-host'] ?? null;
$uploadSftpUser  = $options['upload-sftp-user'] ?? null;
$uploadSftpPath  = $options['upload-sftp-path'] ?? null;
$uploadSftpPort  = isset($options['upload-sftp-port']) ? (int)$options['upload-sftp-port'] : 22;
$uploadSftpPass  = $options['upload-sftp-password'] ?? null;
$uploadSftpPassEnv = $options['upload-sftp-pass-env'] ?? null;
$uploadSftpKey   = $options['upload-sftp-key'] ?? null;
$postDeployUrl   = $options['post-deploy-url'] ?? null;
$postDeployTimeout = isset($options['post-deploy-timeout']) ? (int)$options['post-deploy-timeout'] : 20;

$composerBinary  = $options['composer'] ?? 'composer';
$npmBinary       = $options['npm'] ?? 'npm';

$excludeDirs = [
    '.git',
    '.github',
    'build',
    'node_modules',
    'storage',
    'feeds',
    'secure/logs',
    'secure/ratelimits',
    'secure/tmp',
];

if (!extension_loaded('zip')) {
    fwrite(STDERR, "[ERROR] PHP extension ext-zip is required.\n");
    exit(1);
}

if (!is_dir($projectRoot . '/secure/vendor')) {
    @mkdir($projectRoot . '/secure/vendor', 0775, true);
}

if (!$skipComposer) {
    run_step('Composer install (no-dev)', $dryRun, function () use ($composerBinary, $projectRoot) {
        $cmd = sprintf('%s install --no-dev --prefer-dist --no-progress --no-interaction', escapeshellcmd($composerBinary));
        return run_command($cmd, $projectRoot);
    });
} else {
    fwrite(STDOUT, "[SKIP] Composer install skipped by flag.\n");
}

if (!$skipTests) {
    run_step('Composer test', $dryRun, function () use ($composerBinary, $projectRoot) {
        $cmd = sprintf('%s test', escapeshellcmd($composerBinary));
        return run_command($cmd, $projectRoot);
    });
} else {
    fwrite(STDOUT, "[SKIP] Composer test skipped by flag.\n");
}

if ($runAssets) {
    run_step('npm run build', $dryRun, function () use ($npmBinary, $projectRoot) {
        if (!is_file($projectRoot . '/package.json')) {
            return [false, "package.json not found; nothing to build."];
        }
        $cmd = sprintf('%s run build', escapeshellcmd($npmBinary));
        return run_command($cmd, $projectRoot);
    });
} else {
    if (is_file($projectRoot . '/package.json')) {
        fwrite(STDOUT, "[INFO] package.json detected. Run with --build-assets to execute npm run build.\n");
    }
}

report_assets_summary($projectRoot);

$zipPath = prepare_zip_destination($outputDirOption, $zipNameOption, $dryRun);

run_step('Create release zip', $dryRun, function () use ($projectRoot, $zipPath, $excludeDirs) {
    $filesAdded = create_release_zip($projectRoot, $zipPath, $excludeDirs);
    if ($filesAdded === 0) {
        return [false, 'No files were added to the archive.'];
    }
    return [true, sprintf('Packaged %d files into %s', $filesAdded, $zipPath)];
});

fwrite(STDOUT, "Deploy Courier completed." . ($dryRun ? ' (dry-run)' : '') . "\n");

if ($stageDirOption !== null) {
    stage_release($zipPath, $stageDirOption, $projectRoot, $dryRun);
}

if ($uploadCmdOption !== null) {
    process_upload_command($uploadCmdOption, $zipPath, $projectRoot, $dryRun);
}

if ($uploadSftpHost !== null) {
    $password = $uploadSftpPass;
    if ($password === null && $uploadSftpPassEnv !== null) {
        $password = getenv($uploadSftpPassEnv) ?: null;
    }
    perform_sftp_upload([
        'host'     => $uploadSftpHost,
        'user'     => $uploadSftpUser,
        'path'     => $uploadSftpPath,
        'port'     => $uploadSftpPort,
        'password' => $password,
        'key'      => $uploadSftpKey,
    ], $zipPath, $projectRoot, $dryRun);
}

if ($postDeployUrl !== null) {
    run_post_deploy_check($postDeployUrl, $postDeployTimeout, $dryRun);
}

exit(0);

/**
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

function run_step(string $label, bool $dryRun, callable $callback): void
{
    fwrite(STDOUT, sprintf("[STEP] %s\n", $label));
    if ($dryRun) {
        fwrite(STDOUT, "        Dry-run: skipped.\n");
        return;
    }

    [$ok, $message] = $callback();
    if ($ok) {
        fwrite(STDOUT, "        ✔ " . $message . "\n");
    } else {
        fwrite(STDERR, "        ✖ " . $message . "\n");
        exit(1);
    }
}

/**
 * @return array{0:bool,1:string}
 */
function run_command(string $command, string $cwd): array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptor, $pipes, $cwd);
    if (!is_resource($process)) {
        return [false, 'Failed to start process.'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        return [false, trim($stderr !== '' ? $stderr : $stdout) ?: sprintf('Command failed with exit code %d.', $exitCode)];
    }

    return [true, trim($stdout) ?: 'Completed successfully.'];
}

function prepare_zip_destination(string $outputDir, ?string $name, bool $dryRun): string
{
    $outputDir = rtrim($outputDir, '/\\');
    if (!is_dir($outputDir)) {
        if ($dryRun) {
            fwrite(STDOUT, sprintf('[DRY] Would create output directory %s\n', $outputDir));
        } else {
            if (!@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
                fwrite(STDERR, sprintf('[ERROR] Unable to create output directory %s\n', $outputDir));
                exit(1);
            }
        }
    }

    if ($name === null || $name === '') {
        $timestamp = date('Ymd-His');
        $name = sprintf('simplefeedmaker-%s.zip', $timestamp);
    }

    return $outputDir . '/' . $name;
}

/**
 * @param string   $projectRoot
 * @param string   $zipPath
 * @param string[] $excludeDirs
 */
function create_release_zip(string $projectRoot, string $zipPath, array $excludeDirs): int
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open zip for writing: ' . $zipPath);
    }

    $baseLen = strlen($projectRoot) + 1;
    $filesAdded = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $relative = substr($path, $baseLen);
        if ($relative === false) {
            continue;
        }

        if (should_exclude($relative, $excludeDirs)) {
            continue;
        }

        if (!$zip->addFile($path, $relative)) {
            throw new RuntimeException('Failed to add file to zip: ' . $relative);
        }
        $filesAdded++;
    }

    $zip->close();
    return $filesAdded;
}

/**
 * @param string   $relativePath
 * @param string[] $excludeDirs
 */
function should_exclude(string $relativePath, array $excludeDirs): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);
    foreach ($excludeDirs as $dir) {
        $dir = trim($dir, '/');
        if ($dir === '') {
            continue;
        }
        if (strtolower(substr($relativePath, 0, strlen($dir))) === strtolower($dir)) {
            if ($relativePath === $dir || strpos($relativePath, $dir . '/') === 0) {
                return true;
            }
        }
    }
    return false;
}

function stage_release(string $zipPath, string $stageDirOption, string $projectRoot, bool $dryRun): void
{
    $stageDir = $stageDirOption;
    if ($stageDir !== '' && $stageDir[0] !== '/' && !preg_match('/^[A-Za-z]:\\\\/', $stageDir)) {
        $stageDir = $projectRoot . '/' . ltrim($stageDir, '/');
    }
    $stageDir = rtrim($stageDir, '/\\');

    if ($dryRun) {
        fwrite(STDOUT, sprintf('[DRY] Would stage release to %s\n', $stageDir));
        return;
    }

    if (!is_dir($stageDir) && !@mkdir($stageDir, 0775, true) && !is_dir($stageDir)) {
        fwrite(STDERR, sprintf('[WARN] Unable to create stage directory %s\n', $stageDir));
        return;
    }

    $target = $stageDir . '/' . basename($zipPath);
    if (!@copy($zipPath, $target)) {
        fwrite(STDERR, sprintf('[WARN] Failed to copy %s -> %s\n', $zipPath, $target));
        return;
    }
    fwrite(STDOUT, sprintf('[INFO] Staged release at %s\n', $target));
}

function process_upload_command(string $template, string $zipPath, string $projectRoot, bool $dryRun): void
{
    if (strpos($template, '{file}') === false) {
        fwrite(STDERR, "[WARN] --upload-cmd must contain {file} placeholder. Skipping upload.\n");
        return;
    }

    $command = str_replace('{file}', escapeshellarg($zipPath), $template);

    if ($dryRun) {
        fwrite(STDOUT, '[DRY] Would run upload command: ' . $command . "\n");
        return;
    }

    [$ok, $message] = run_command($command, $projectRoot);
    if ($ok) {
        fwrite(STDOUT, '[INFO] Upload command succeeded.' . ($message !== '' ? ' ' . $message : '') . "\n");
    } else {
        fwrite(STDERR, '[WARN] Upload command failed: ' . $message . "\n");
    }
}

function perform_sftp_upload(array $config, string $zipPath, string $projectRoot, bool $dryRun): void
{
    $host = $config['host'] ?? null;
    $user = $config['user'] ?? null;
    $remotePath = $config['path'] ?? null;
    $port = (int)($config['port'] ?? 22);
    $password = $config['password'] ?? null;
    $keyFile = $config['key'] ?? null;

    if ($host === null || $remotePath === null) {
        fwrite(STDERR, "[WARN] --upload-sftp-host and --upload-sftp-path are required for SFTP upload.\n");
        return;
    }

    $remoteDir = trim($remotePath);
    $remoteFile = basename($zipPath);
    $pathSegment = '';
    if ($remoteDir !== '' && $remoteDir !== '/') {
        $pathSegment = '/' . trim($remoteDir, '/');
    }
    $remoteUrl = sprintf('sftp://%s:%d%s/%s', $host, $port, $pathSegment, $remoteFile);

    if ($dryRun) {
        $userNote = $user ? ' user=' . $user : '';
        fwrite(STDOUT, sprintf('[DRY] Would upload %s to %s via SFTP.%s%s', $zipPath, $remoteUrl, $userNote, PHP_EOL));
        return;
    }

    if (!is_file($zipPath)) {
        fwrite(STDERR, '[WARN] Zip file not found for SFTP upload: ' . $zipPath . "\n");
        return;
    }

    $commandParts = ['curl', '--silent', '--show-error', '--fail', '--ftp-create-dirs', '-T', escapeshellarg($zipPath), '--connect-timeout', '30'];

    if ($user !== null && ($password !== null || $keyFile !== null)) {
        if ($password !== null) {
            $commandParts[] = '--user';
            $commandParts[] = escapeshellarg($user . ':' . $password);
        } elseif ($keyFile !== null) {
            $commandParts[] = '--key';
            $commandParts[] = escapeshellarg($keyFile);
            $commandParts[] = '--user';
            $commandParts[] = escapeshellarg($user . ':');
        }
    } elseif ($user !== null) {
        $remoteUrl = sprintf('sftp://%s@%s:%d%s/%s', $user, $host, $port, $pathSegment, $remoteFile);
    }

    $commandParts[] = escapeshellarg($remoteUrl);

    $command = implode(' ', $commandParts);
    [$ok, $message] = run_command($command, $projectRoot);
    if ($ok) {
        fwrite(STDOUT, '[INFO] Uploaded release via SFTP to ' . $remoteUrl . "\n");
    } else {
        fwrite(STDERR, '[WARN] SFTP upload failed: ' . $message . "\n");
    }
}

function run_post_deploy_check(string $url, int $timeout, bool $dryRun): void
{
    if ($dryRun) {
        fwrite(STDOUT, '[DRY] Would request post-deploy URL: ' . $url . "\n");
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, '[WARN] Unable to initialise curl for post-deploy check.' . "\n");
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_USERAGENT => 'DeployCourier/1.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($error !== '' || $status >= 400 || $status === 0) {
        fwrite(STDERR, sprintf('[WARN] Post-deploy check failed (%d): %s\n', $status, $error !== '' ? $error : 'Unexpected response'));
        return;
    }

    fwrite(STDOUT, sprintf('[INFO] Post-deploy check succeeded (%d).\n', $status));
}

function report_assets_summary(string $projectRoot): void
{
    $assetsDir = $projectRoot . '/assets';
    if (!is_dir($assetsDir)) {
        return;
    }
    $bytes = directory_size($assetsDir);
    $human = human_bytes($bytes);
    fwrite(STDOUT, sprintf("[INFO] assets/ footprint: %s (%d bytes)\n", $human, $bytes));
}

function directory_size(string $dir): int
{
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $size += $fileInfo->getSize();
        }
    }
    return $size;
}

function human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return sprintf('%.2f %s', $value, $units[$i]);
}
