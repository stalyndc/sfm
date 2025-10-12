#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/includes/config.php';

$cases = [
    ['https://simplefeedmaker.com', 'https://simplefeedmaker.com', true],
    ['https://simplefeedmaker.com:443', 'https://simplefeedmaker.com', true],
    ['https://simplefeedmaker.com', 'simplefeedmaker.com', true],
    ['https://simplefeedmaker.com', 'https://simplefeedmaker.com:443', true],
    ['https://simplefeedmaker.com:8443', 'https://simplefeedmaker.com:8443', true],
    ['https://simplefeedmaker.com:8443', 'https://simplefeedmaker.com', false],
    ['https://attacker-simplefeedmaker.com', 'https://simplefeedmaker.com', false],
    ['https://simplefeedmaker.com.evil.com', 'https://simplefeedmaker.com', false],
    ['http://simplefeedmaker.com', 'https://simplefeedmaker.com', false],
];

$failures = [];
foreach ($cases as [$origin, $expected, $shouldPass]) {
    $result = sfm_origin_is_allowed($origin, $expected);
    if ($result !== $shouldPass) {
        $failures[] = sprintf('origin=%s expected=%s -> got %s', $origin, $expected, $result ? 'true' : 'false');
    }
}

if ($failures) {
    fwrite(STDERR, "Origin guard test failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  • ' . $failure . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "Origin guard tests passed.\n");
