<?php
/**
 * Copy to secure/sfm-secrets.php and adjust values.
 */

declare(strict_types=1);

define('SFM_SECURE_PATH', __DIR__);

define('SFM_RATELIMIT_DIR', __DIR__ . '/ratelimits');
if (!is_dir(SFM_RATELIMIT_DIR)) {
    @mkdir(SFM_RATELIMIT_DIR, 0755, true);
}

return [
    'app_name'        => 'SimpleFeedMaker',
    'pepper'          => 'generate-a-random-64-char-string',
    'admin_email'     => 'alerts@example.com',
    'allowed_origins' => [
        'https://simplefeedmaker.com',
    ],
    'ratelimit'       => [
        'window_seconds' => 60,
        'max_requests'   => 30,
    ],
];

// No closing PHP tag
