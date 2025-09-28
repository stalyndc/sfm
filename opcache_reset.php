<?php
declare(strict_types=1);

// Allow CLI execution without extra tokens for convenience.
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    $expected = getenv('OPCACHE_RESET_TOKEN');
    $provided = $_GET['token'] ?? '';

    if (!$expected || !is_string($expected) || !hash_equals($expected, (string)$provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}

$ok = true;
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => (bool)$ok,
    'mode' => $isCli ? 'cli' : 'http',
]);
