<?php
/**
 * includes/admin_auth.php
 *
 * Lightweight admin authentication helpers.
 * Keeps credentials in secure/admin-credentials.php and stores
 * authenticated state in the existing PHP session.
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function sfm_admin_boot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    sec_boot_session();

    if (!defined('ADMIN_USERNAME') || !defined('ADMIN_PASSWORD')) {
        $secureDir = sfm_secure_dir();
        $candidates = [];
        if ($secureDir !== null) {
            $candidates[] = $secureDir . '/admin-credentials.php';
        }
        // Last-resort fallback for unusual layouts
        $candidates[] = dirname(__DIR__) . '/secure/admin-credentials.php';
        $candidates[] = dirname(dirname(__DIR__)) . '/secure/admin-credentials.php';

        $credFile = null;
        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $credFile = $path;
                break;
            }
        }

        if ($credFile === null) {
            throw new RuntimeException('Admin credentials file is missing.');
        }
        require_once $credFile;
    }

    if (!defined('ADMIN_USERNAME') || !defined('ADMIN_PASSWORD')) {
        throw new RuntimeException('Admin credentials are not configured.');
    }

    $booted = true;
}

function sfm_admin_is_logged_in(): bool
{
    sfm_admin_boot();
    return !empty($_SESSION['sfm_admin_logged_in']);
}

function sfm_admin_login(string $username, string $password): bool
{
    sfm_admin_boot();

    $expectedUser = (string)ADMIN_USERNAME;
    $expectedPass = (string)ADMIN_PASSWORD;

    $userOk = hash_equals($expectedUser, $username);
    $passOk = hash_equals($expectedPass, $password);

    if ($userOk && $passOk) {
        session_regenerate_id(true);
        $_SESSION['sfm_admin_logged_in'] = true;
        $_SESSION['sfm_admin_username']  = $expectedUser;
        $_SESSION['sfm_admin_logged_at'] = time();
        return true;
    }

    return false;
}

function sfm_admin_logout(): void
{
    sfm_admin_boot();
    unset($_SESSION['sfm_admin_logged_in'], $_SESSION['sfm_admin_username'], $_SESSION['sfm_admin_logged_at']);
    session_regenerate_id(true);
}

function sfm_admin_require_login(): void
{
    if (!sfm_admin_is_logged_in()) {
        header('Location: /admin/?login=1');
        exit;
    }
}
