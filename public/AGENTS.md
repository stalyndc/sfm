## Intro

SimpleFeedMaker.com — Automation, Security, and Operations Playbook
Stack: HTML, CSS, JS, PHP, Bootstrap on Hostinger shared hosting
Goal: “run smooth and secure” with lightweight agents (automations + checks) you can copy-paste.

## Agent Library

### Secrets Guard
- **Purpose:** keep credentials out of git and alert when new secret placeholders are needed.
- **Trigger:** run locally whenever `.gitignore` or any file in `secure/` changes.
- **Script:**
  1. Execute `php secure/scripts/secrets_guard.php`.
  2. The script validates `.gitignore` coverage, confirms example templates exist, and fails if any real secrets are tracked.
  3. When it fails, update `.gitignore`, add the missing template, or remove the tracked secret file, then rerun.
- **Output:** ensures developers share templates, never real credentials (exit 0 = safe, non‑zero = action needed).

### Deploy Courier
- **Purpose:** ship a safe release to Hostinger from `main`.
- **Trigger:** manual when we greenlight a release.
- **Script:**
  1. Run `composer install --no-dev` locally if PHP libraries change (store vendor/ outside webroot).
  2. Run `npm run build` if frontend assets ever grow; otherwise verify `public/` still lightweight.
  3. Create a zip from `public/` plus required `secure/` stubs; exclude runtime folders (`storage/`, `secure/logs/`, `secure/ratelimits/`).
  4. Upload via Hostinger file manager or `sftp`, extract, and confirm file permissions (`644` files, `755` dirs).
- **Output:** a repeatable release package with secrets preserved on the server only.

### Feed Cleanup Runner
- **Purpose:** stop old feed files from filling storage.
- **Trigger:** cron on the server (daily) or manual if disk alarms fire.
- **Script:**
  1. Execute `php secure/scripts/cleanup_feeds.php --max-age=3d`.
  2. After run, scan `storage/logs/cleanup.log` for errors; if missing, record success in `secure/logs/app.log`.
- **Output:** storage stays lean; audit trail in logs.

### Health Sentry
- **Purpose:** alert when the public app fails.
- **Trigger:** every 5 minutes from any uptime checker (UptimeRobot, Cronitor, etc.).
- **Script:**
  1. Request `https://simplefeedmaker.com/health.php`.
  2. Validate HTTP 200 and `scope.ok === true`; the payload also surfaces recent cleanup-log age and storage status.
  3. Alert on two consecutive failures (or if the endpoint returns HTTP 503 / `ok:false`) and include the JSON body for context.
- **Output:** fast signal on outages or upstream fetch issues.

### Rate Limit Inspector
- **Purpose:** detect abuse by watching `storage/ratelimits/`.
- **Trigger:** hourly cron or manual when traffic spikes.
- **Script:**
  1. Count unique IPs from JSON filenames; if >100/hour, compile top offenders.
  2. For each offender, append an entry to `secure/ratelimits/README.md` with timestamp + reason.
  3. Optionally call Hostinger firewall API / .htaccess deny list update.
- **Output:** documented abuse handling and optional automated blocks.

### Log Sanitizer
- **Purpose:** remove personal data from logs before archiving.
- **Trigger:** after each `Feed Cleanup Runner` pass or weekly.
- **Script:**
  1. Search `secure/logs/*.log` for email, phone, or query parameters using regex.
  2. Redact sensitive tokens (`foo@example.com` → `[email]`).
  3. Compress sanitized logs older than 14 days to `/secure/logs/archive/`.
- **Output:** compliance-friendly logs with smaller footprint.

### Disaster Drill
- **Purpose:** guarantee we can rebuild quickly.
- **Trigger:** quarterly calendar reminder.
- **Script:**
  1. Clone repo fresh; confirm `public/` boots with `.env.example`/`secure/*.example.php` values.
  2. Restore production database snapshot (if any) to staging and run smoke tests.
  3. Verify backups of `secure/` and `storage/feeds` exist and match checksum log.
- **Output:** documented recovery steps stay current.
