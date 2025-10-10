#!/usr/bin/env php
<?php

declare(strict_types=1);

$defaultUrl = getenv('SFM_HEALTH_URL') ?: 'https://simplefeedmaker.com/health.php';
$alertRecipients = resolve_recipients();

$options = parse_cli_options($argv ?? []);
$url = $options['url'] ?? $defaultUrl;
$quiet = !empty($options['quiet']);
$failWarn = !empty($options['warn-only']);
$timeout = isset($options['timeout']) ? max(1, (int)$options['timeout']) : 20;
if (!empty($options['no-email'])) {
    $alertRecipients = [];
}

$result = fetch_health($url, $timeout);

if (!$result['success']) {
    $message = '[Health] Request failed: ' . $result['error'];
    if (!$quiet) {
        fwrite(STDERR, $message . PHP_EOL);
    }
    send_alert($alertRecipients, '[SimpleFeedMaker] Health check failure', $message . PHP_EOL);
    exit(1);
}

$payload = $result['payload'];
$statusSummary = summarise_payload($payload);

if ($statusSummary['ok']) {
    if (!$quiet) {
        fwrite(STDOUT, '[OK] Health endpoint reports ok.' . PHP_EOL);
    }
    exit(0);
}

$subject = '[SimpleFeedMaker] Health warning';
if ($statusSummary['critical']) {
    $subject = '[SimpleFeedMaker] Health failure';
}

$bodyLines = [];
$bodyLines[] = 'URL: ' . $url;
$bodyLines[] = 'Status: ' . ($payload['ok'] ? 'ok' : 'FAIL');
$bodyLines[] = 'Time: ' . ($payload['time'] ?? 'unknown');
$bodyLines[] = '';
foreach ($statusSummary['messages'] as $line) {
    $bodyLines[] = '- ' . $line;
}

$bodyLines[] = '';
$bodyLines[] = 'Raw response:';
$bodyLines[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$body = implode(PHP_EOL, $bodyLines);

if (!$quiet) {
    fwrite(STDERR, '[WARN] ' . $subject . PHP_EOL);
}

if ($statusSummary['critical'] || !$failWarn) {
    send_alert($alertRecipients, $subject, $body);
    exit($statusSummary['critical'] ? 2 : 1);
}

echo '[WARN] ' . $subject . PHP_EOL;
exit(0);

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

function fetch_health(string $url, int $timeout): array
{
    if (strpos($url, 'file://') === 0) {
        $path = substr($url, 7);
        if ($path === '') {
            return ['success' => false, 'error' => 'Invalid file path'];
        }
        $body = @file_get_contents($path);
        if ($body === false) {
            return ['success' => false, 'error' => 'Unable to read ' . $path];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid JSON in ' . $path];
        }
        return ['success' => true, 'payload' => $data];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['success' => false, 'error' => 'Unable to initialise curl'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'SFM-Monitor/1.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => $error !== '' ? $error : 'Unknown error'];
    }
    if ($code >= 400 || $code === 0) {
        return ['success' => false, 'error' => 'HTTP status ' . $code];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON'];
    }

    return ['success' => true, 'payload' => $data];
}

function summarise_payload(array $payload): array
{
    $messages = [];
    $critical = false;

    if (empty($payload['ok'])) {
        $critical = true;
        $messages[] = 'Root ok flag is false.';
    }

    $checks = $payload['checks'] ?? [];
    foreach ($checks as $check) {
        $status = $check['status'] ?? 'unknown';
        if ($status !== 'ok') {
            $line = ($check['name'] ?? 'unknown') . ' status=' . $status;
            if (isset($check['error'])) {
                $line .= ' error=' . $check['error'];
            }
            if (!empty($check['details'])) {
                $line .= ' details=' . json_encode($check['details']);
            }
            $messages[] = $line;
            if ($status === 'fail') {
                $critical = true;
            }
        }
    }

    return [
        'ok' => empty($messages),
        'critical' => $critical,
        'messages' => $messages,
    ];
}

function resolve_recipients(): array
{
    $candidates = [];
    $main = getenv('SFM_HEALTH_ALERT_EMAIL');
    if ($main !== false && $main !== '') {
        $candidates[] = $main;
    }
    $default = getenv('SFM_ALERT_EMAIL') ?: '';
    $candidates[] = $default;
    $envList = getenv('SFM_ALERT_EMAILS');
    if ($envList !== false && $envList !== '') {
        $candidates = array_merge($candidates, preg_split('/[;,]+/', $envList) ?: []);
    }

    $valid = [];
    foreach ($candidates as $candidate) {
        $email = filter_var(trim((string)$candidate), FILTER_VALIDATE_EMAIL);
        if ($email) {
            $valid[$email] = true;
        }
    }

    return array_keys($valid);
}

function send_alert(array $recipients, string $subject, string $body): void
{
    if (!$recipients) {
        return;
    }
    foreach ($recipients as $recipient) {
        @mail($recipient, $subject, $body, "Content-Type: text/plain; charset=UTF-8\r\n");
    }
}
