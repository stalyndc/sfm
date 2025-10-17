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

---

## Hostinger Production Cron Jobs

**Environment:** Hostinger Shared Hosting  
**PHP Version:** 8.2 (`/opt/alt/php82/usr/bin/php`)  
**User:** `u261092072`  
**Domain Path:** `/home/u261092072/domains/simplefeedmaker.com/public_html/`

### Active Cron Jobs

#### 1. Health Monitoring (Every 15 minutes)
```bash
*/15 * * * * /bin/bash /home/u261092072/domains/simplefeedmaker.com/public_html/scripts/automation/cron_runner.sh monitor >> /home/u261092072/domains/simplefeedmaker.com/storage/logs/cron_monitor.log 2>&1
```
- **Purpose:** Continuous health monitoring and system checks
- **Log:** `storage/logs/cron_monitor.log`
- **Frequency:** Every 15 minutes (96 times per day)

#### 2. Hourly Tasks (Every 5 minutes)
```bash
*/5 * * * * /bin/bash /home/u261092072/domains/simplefeedmaker.com/public_html/scripts/automation/cron_runner.sh hourly >> /home/u261092072/domains/simplefeedmaker.com/storage/logs/cron_hourly.log 2>&1
```
- **Purpose:** Rate limiting cleanup, log management, cache refresh
- **Log:** `storage/logs/cron_hourly.log`
- **Frequency:** Every 5 minutes (288 times per day)

#### 3. Daily Maintenance (2:00 AM daily)
```bash
0 2 * * * /bin/bash /home/u261092072/domains/simplefeedmaker.com/public_html/scripts/automation/cron_runner.sh daily >> /home/u261092072/domains/simplefeedmaker.com/storage/logs/cron_daily.log 2>&1
```
- **Purpose:** Daily cleanup, log rotation, storage management
- **Log:** `storage/logs/cron_daily.log`
- **Frequency:** Daily at 2:00 AM server time

#### 4. Weekly Maintenance (2:00 AM and 2:30 AM Sundays)
```bash
0,30 2 * * * /bin/bash /home/u261092072/domains/simplefeedmaker.com/public_html/scripts/automation/cron_runner.sh weekly >/dev/null 2>&1
```
- **Purpose:** Deep cleanup, analytics processing, optimization tasks
- **Output:** Suppressed (logs to `/dev/null`)
- **Frequency:** Twice daily at 2:00 AM and 2:30 AM

#### 5. Feed Refresh (Every 30 minutes)
```bash
0,30 * * * * /opt/alt/php82/usr/bin/php /home/u261092072/domains/simplefeedmaker.com/public_html/cron_refresh.php >> /home/u261092072/domains/simplefeedmaker.com/storage/logs/cron_refresh.log 2>&1
```
- **Purpose:** Refresh cached feeds, update scheduled feed generation
- **Log:** `storage/logs/cron_refresh.log`
- **Frequency:** Every 30 minutes (48 times per day)
- **PHP:** Uses Hostinger PHP 8.2 binary

### Log File Locations
```bash
# Cron execution logs
/home/u261092072/domains/simplefeedmaker.com/storage/logs/
├── cron_monitor.log      # Health monitoring logs
├── cron_hourly.log       # Hourly task logs
├── cron_daily.log        # Daily maintenance logs
└── cron_refresh.log      # Feed refresh logs
```

### Cron Job Management

#### Adding New Cron Jobs
1. **Access Hostinger Control Panel**
2. **Navigate to "Cron Jobs"** under "Advanced" section
3. **Add new cron job** with format: `* * * * * command`
4. **Use full PHP path:** `/opt/alt/php82/usr/bin/php`
5. **Set proper output redirection:** `>> /path/to/log 2>&1`
6. **Test with dry run** first if possible

#### Modifying Existing Jobs
1. **Backup current configuration** (screenshot notes)
2. **Edit in Hostinger UI**
3. **Update corresponding script** if needed
4. **Monitor logs** after changes
5. **Run manual test** to verify functionality

#### Troubleshooting Common Issues

**Permission Denied:**
```bash
# Check file permissions
chmod 755 scripts/automation/cron_runner.sh
chmod 644 cron_refresh.php

# Verify ownership
ls -la scripts/automation/cron_runner.sh
```

**PHP Path Issues:**
```bash
# Verify PHP path
/opt/alt/php82/usr/bin/php --version

# Alternative if needed:
/usr/bin/php --version
```

**Log File Issues:**
```bash
# Check log directory exists and is writable
ls -la storage/logs/
mkdir -p storage/logs
chmod 755 storage/logs
```

**Script Not Found:**
```bash
# Verify script exists at specified path
ls -la /home/u261092072/domains/simplefeedmaker.com/public_html/scripts/automation/cron_runner.sh
ls -la /home/u261092072/domains/simplefeedmaker.com/public_html/cron_refresh.php
```

### Security Considerations

- **Log files may contain sensitive information** - ensure proper permissions
- **Cron jobs run as the web user** - limit script access to necessary files
- **Monitor log file sizes** - implement log rotation to prevent disk space issues
- **Error output is captured** - regularly review logs for security events

### Performance Impact

- **Total executions:** ~447 cron runs per day
- **Peak activity:** 2:00-2:30 AM daily (weekly maintenance)
- **Resource usage:** Generally low, but monitor during peak times
- **Disk usage:** Logs accumulate - implement cleanup in daily jobs

### Monitoring Cron Health

#### Manual Test Commands
```bash
# Test monitor script manually
cd /home/u261092072/domains/simplefeedmaker.com/public_html
./scripts/automation/cron_runner.sh monitor

# Test feed refresh
/opt/alt/php82/usr/bin/php cron_refresh.php

# Check recent cron logs
tail -f storage/logs/cron_monitor.log
tail -f storage/logs/cron_refresh.log
```

#### Automated Monitoring
- **Health Sentry** (`/health.php`) includes cron job status
- **Email alerts** configured via `SFM_ALERT_EMAIL` environment variables
- **Log file monitoring** for error patterns
- **External monitoring** services (UptimeRobot) should ping health endpoint

### Reference Configuration

**Current setup as of October 2025:**
- Hostinger Shared Hosting
- PHP 8.2 with required extensions
- User: `u261092072`
- Domain: `simplefeedmaker.com`
- Document root: `/home/u261092072/domains/simplefeedmaker.com/public_html/`

Update this section when:
- Switching to different hosting plan
- Changing PHP versions
- Modifying cron schedules
- Adding/changing automated scripts
- Updating user account details
