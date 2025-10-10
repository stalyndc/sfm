## Secret Configuration & Rotation Checklist

SimpleFeedMaker now expects all sensitive credentials to be supplied via
environment variables or private `*.local.php` files that live in `/secure`.
Use the steps below when deploying or rotating credentials.

---

### 1. Gather new secrets

- Generate a fresh admin username/password pair.
- Create a new database user (or rotate the existing password) with only the
  privileges SimpleFeedMaker requires.

### 2. Update admin credentials

- Copy `secure/admin-credentials.example.php` to `secure/admin-credentials.php`.
- Generate a BCrypt hash for the new password with `php secure/scripts/hash_password.php "super-secret"`.
- Paste the hash into `ADMIN_PASSWORD_HASH` and set `ADMIN_USERNAME`.
- Remove any legacy `ADMIN_PASSWORD` constant once the hash is in place (plain text passwords are no longer required).

### 3. Update database credentials

If you store database connection details in files, copy `secure/db-credentials.example.php`
to `secure/db-credentials.php` and fill in the values. These files are already
ignored by git, so they stay on the server only.

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
- Update `secure/cron.env` with the new alert recipients (`SFM_ALERT_EMAIL`,
  `SFM_HEALTH_ALERT_EMAIL`, etc.) so automation keeps sending notifications.
- If traffic sits behind Cloudflare or another proxy, populate
  `SFM_TRUSTED_PROXIES` in `secure/cron.env` with the edge IPs/CIDRs so rate
  limiting and abuse detection see the real client IP.

Repeat this process any time you suspect a credential may have been exposed.
