<?php
/**
 * Copy to secure/admin-credentials.php and override with strong values.
 * The app reads both legacy SFM_* constants and plain ADMIN_* constants.
 */

declare(strict_types=1);

define('SFM_ADMIN_USERNAME', 'change-me');
define('SFM_ADMIN_PASSWORD', 'change-me-please-use-a-password-manager');

define('ADMIN_USERNAME', SFM_ADMIN_USERNAME);
define('ADMIN_PASSWORD', SFM_ADMIN_PASSWORD);

// No closing PHP tag
