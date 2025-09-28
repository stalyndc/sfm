#!/usr/bin/env php
<?php
/**
 * hash_password.php
 *
 * Helper utility to generate password_hash() output for admin credentials.
 * Usage:
 *   php secure/scripts/hash_password.php "SuperSecret123!"
 */

declare(strict_types=1);

if (!extension_loaded('openssl') && !extension_loaded('sodium')) {
    fwrite(STDERR, "Warning: neither openssl nor sodium extensions are loaded; password_hash will still work but entropy may rely on /dev/urandom.\n");
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php secure/scripts/hash_password.php <plain-password>\n");
    exit(1);
}

$password = (string)$argv[1];
if ($password === '') {
    fwrite(STDERR, "Password cannot be empty.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Failed to generate password hash.\n");
    exit(1);
}

echo $hash, PHP_EOL;
exit(0);
