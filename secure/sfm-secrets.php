<?php
/**
 * /home/<account>/secure/sfm-secrets.php
 * Central place for private config. Never web-accessible.
 *
 * Usage from app code:
 *   $SFM = require '/home/<account>/secure/sfm-secrets.php';
 *   $pepper = $SFM['pepper'];
 */

declare(strict_types=1);

/* Anchors for other helpers that may include this */
define('SFM_SECURE_PATH', __DIR__);
define('SFM_RATELIMIT_DIR', __DIR__ . '/ratelimits');

/* Ensure ratelimit dir exists (safe no-op if already present) */
if (!is_dir(SFM_RATELIMIT_DIR)) {
    @mkdir(SFM_RATELIMIT_DIR, 0755, true);
}

/* Return a simple array of secrets/settings */
return [
    // Human label
    'app_name' => 'SimpleFeedMaker',

    // Random per-install string (change to your own long random value)
    'pepper'   => 'change-me-to-a-long-random-string-64+chars',

    // Optional: notify address for errors
    'admin_email' => 'you@example.com',

    // CORS / CSRF allowlist (origins you’ll accept POSTs from)
    'allowed_origins' => [
        'https://simplefeedmaker.com',
        'https://www.simplefeedmaker.com',
    ],

    // Very light, file-based rate limiting defaults (per IP)
    'ratelimit' => [
        'window_seconds' => 60,   // sliding window
        'max_requests'   => 30,   // per window
    ],
];
