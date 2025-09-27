<?php
/**
 * Copy to secure/db-credentials.local.php on the server when env vars are not available.
 */

declare(strict_types=1);

return [
    'host'     => 'localhost',
    'username' => 'db-user',
    'password' => 'db-password',
    'database' => 'db-name',
];

// No closing PHP tag
