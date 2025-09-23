## Secret Configuration & Rotation Checklist

SimpleFeedMaker now expects all sensitive credentials to be supplied via
environment variables or private `*.local.php` files that live in `/secure`.
Use the steps below when deploying or rotating credentials.

---

### 1. Gather new secrets

- Generate a fresh admin username/password pair.
- Create a new database user (or rotate the existing password) with only the
  privileges SimpleFeedMaker requires.

### 2. Update the hosting environment

Set the following environment variables through your hosting control panel or
web server configuration. The app reads them on every request.

```
SFM_ADMIN_USERNAME
SFM_ADMIN_PASSWORD
SFM_BASE_URL        # full URL, e.g. https://simplefeedmaker.com
SFM_DB_HOST         # optional — defaults to localhost
SFM_DB_USERNAME
SFM_DB_PASSWORD
SFM_DB_NAME
OPCACHE_RESET_TOKEN # optional — required only if you intend to call opcache_reset.php
```

> ℹ️ Hostinger lets you add PHP environment variables under *Advanced ➝ PHP
> Configuration ➝ Variables*. Other hosts will have similar controls.

### 3. (Optional) Provide `.local.php` fallbacks

If the host cannot inject environment variables, create private files instead:

- `secure/admin-credentials.local.php`
- `secure/db-credentials.local.php`

Each file should return an array with the relevant keys:

```php
<?php
return [
  'username' => 'admin-user',
  'password' => 'super-secret',
];
```

```php
<?php
return [
  'host'     => 'localhost',
  'username' => 'db-user',
  'password' => 'db-pass',
  'database' => 'db-name',
];
```

These files are already ignored by git (`secure/*.local.php`), so they stay on
the server only.

### 4. Deploy code & clear caches

- Sync the latest code to the server.
- Clear PHP opcache (if enabled) by calling `/opcache_reset.php?token=…` with
  the value of `OPCACHE_RESET_TOKEN`.
- Restart any application caches (Cloudflare, etc.) if you use them.

### 5. Smoke test

- Visit the site in a fresh private window.
- Generate a feed to confirm credentials were loaded correctly and CSRF passes.
- If your host provides logs, watch them for 5–10 minutes for any new warnings.

### 6. Clean up old secrets

- Remove or revoke the previous admin and database credentials.
- Store the new secrets in your password manager for future rotations.

Repeat this process any time you suspect a credential may have been exposed.
