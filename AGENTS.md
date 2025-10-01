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
  1. Run `php secure/scripts/deploy_courier.php` (add `--dry-run` to preview, `--build-assets` to trigger `npm run build`).
  2. The script runs `composer install --no-dev`, `composer test` (use `--skip-tests` to bypass), optionally builds assets, reports the `/assets` footprint, and bundles the repo into `build/releases/simplefeedmaker-<timestamp>.zip` without runtime folders (`storage/`, `secure/logs/`, `secure/ratelimits/`).
  3. Use `--stage-dir=/path` to copy the zip into a shared drop folder, `--upload-sftp-*` flags to push straight to the server (or `--upload-cmd="…{file}"` for a custom command), and `--post-deploy-url=https://simplefeedmaker.com/health.php` for an instant smoke test.
  4. If you prefer manual deploys, upload the generated zip via Hostinger file manager or `sftp`, extract, and confirm file permissions (`644` files, `755` dirs`).
- **Output:** a repeatable release package with secrets preserved on the server only.

### CI Sentinel
- **Purpose:** auto-run `composer test` (lint + security audit) on every push or pull request.
- **Trigger:** GitHub Actions workflow `CI` (`.github/workflows/ci.yml`).
- **Script:**
  1. Check out the repo and set up PHP 8.2 with required extensions.
  2. Run `composer validate`, `composer install --no-dev`, then `composer test`.
- **Output:** fast feedback in GitHub if syntax breaks or security advisories appear.
- **Enforce:** In GitHub → *Settings → Branches*, add a protection rule for `main` (and any release branches) that requires the `CI` status check to pass. Enable “Require branches to be up to date before merging” to rerun tests after rebases.

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
  2. For each offender, append an entry to `secure/ratelimits/README.md` with timestamp + reason and update the `.htaccess` blocklist when invoked with `--block`.
  3. Add `--notify` (or configure `SFM_ALERT_EMAIL`) so the script emails stalyn@disla.net when thresholds trip; run hourly via cron, e.g. `5 * * * * php secure/scripts/rate_limit_inspector.php --threshold=150 --top=10 --block`.
- **Output:** documented abuse handling and optional automated blocks.

### Log Sanitizer
- **Purpose:** remove personal data from logs before archiving.
- **Trigger:** after each `Feed Cleanup Runner` pass or weekly.
- **Script:**
  1. Run `php secure/scripts/log_sanitizer.php` (add `--dry-run` to preview, `--retention=30` to change archive window).
  2. The script redacts email addresses, phone numbers, and sensitive query params from `secure/logs/*.log`.
  3. Sanitized logs older than 14 days are gzipped into `secure/logs/archive/` and removed from the live directory.
  4. Configure `SFM_ALERT_EMAIL` or pass `--notify` so redactions and archive activity email stalyn@disla.net; cron example: `30 2 * * 1 php secure/scripts/log_sanitizer.php`.
- **Output:** compliance-friendly logs with smaller footprint.

### Disaster Drill
- **Purpose:** guarantee we can rebuild quickly.
- **Trigger:** quarterly calendar reminder.
- **Script:**
  1. Run `php secure/scripts/disaster_drill.php` (add `--json` for machine-readable output, `--backups=/path` and `--checksum-file=/path/checksums.json` to validate external backups).
  2. On a fresh clone, follow the script guidance: copy `.example` templates to real secrets, run `composer install --no-dev`, and boot the site.
  3. Restore production database snapshot (if any) to staging, run smoke tests, and reconcile backup checksums before signing off the drill.
- **Output:** documented recovery steps stay current.
