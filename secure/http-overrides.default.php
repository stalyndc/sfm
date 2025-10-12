<?php
/**
 * Default HTTP host overrides bundled with the repo.
 *
 * These entries target CNN's Fastly edges so SimpleFeedMaker can fetch
 * cnn.com even when shared-host DNS is unreliable.
 *
 * To customize or extend, create `secure/http-overrides.php` (ignored by git)
 * or set the `SFM_HTTP_HOST_OVERRIDES` environment variable.
 */

declare(strict_types=1);

return [
    'www.cnn.com' => [
        '151.101.1.67',
        '151.101.65.67',
        '151.101.129.67',
        '151.101.193.67',
    ],
    'edition.cnn.com' => [
        '151.101.1.67',
        '151.101.65.67',
        '151.101.129.67',
        '151.101.193.67',
    ],
];

// No closing PHP tag
