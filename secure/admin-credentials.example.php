<?php
/**
 * Copy to secure/admin-credentials.local.php (NEVER commit the copy) and
 * override with strong values. The app reads both legacy SFM_* constants and
 * plain ADMIN_* constants.
 */

declare(strict_types=1);

define('ADMIN_USERNAME', 'change-me');

// Generate a BCrypt hash with: php secure/scripts/hash_password.php "your-password"
define('ADMIN_PASSWORD_HASH', 'replace-with-bcrypt-hash');

// Legacy fallbacks (retain for older deployments; safe to remove if unused)
define('SFM_ADMIN_USERNAME', ADMIN_USERNAME);
define('ADMIN_PASSWORD', '');
define('SFM_ADMIN_PASSWORD', ADMIN_PASSWORD);

// No closing PHP tag
