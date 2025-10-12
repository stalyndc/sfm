<?php
/**
 * Optional static DNS overrides for outbound HTTP requests.
 *
 * Copy to secure/http-overrides.php (kept out of git) and list any domains
 * whose public IPs need to be forced because shared-host DNS is unreliable.
 *
 * Format: return ['host.name' => ['ip', 'ip2'], ...];
 */

declare(strict_types=1);

return [
    // 'edition.cnn.com' => ['151.101.1.67', '151.101.65.67'],
];

// No closing PHP tag
