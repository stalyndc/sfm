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
  1. Run `composer install --no-dev` locally; this writes vendor files into `secure/vendor/` while keeping `composer.json` and `composer.lock` in git.
  2. Execute `composer test` for a quick syntax + dependency security check before shipping.
  3. Run `npm run build` if frontend assets ever grow; otherwise verify the root-level `/assets` bundle stays lightweight.
  4. Create a zip from the web root (repo root) plus required `secure/` stubs; exclude runtime folders (`storage/`, `secure/logs/`, `secure/ratelimits/`).
  5. Upload via Hostinger file manager or `sftp`, extract, and confirm file permissions (`644` files, `755` dirs).
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
  2. For each offender, append an entry to `secure/ratelimits/README.md` with timestamp + reason.
  3. Optionally call Hostinger firewall API / .htaccess deny list update.
- **Output:** documented abuse handling and optional automated blocks.

### Log Sanitizer
- **Purpose:** remove personal data from logs before archiving.
- **Trigger:** after each `Feed Cleanup Runner` pass or weekly.
- **Script:**
  1. Run `php secure/scripts/log_sanitizer.php` (add `--dry-run` to preview, `--retention=30` to change archive window).
  2. The script redacts email addresses, phone numbers, and sensitive query params from `secure/logs/*.log`.
  3. Sanitized logs older than 14 days are gzipped into `secure/logs/archive/` and removed from the live directory.
- **Output:** compliance-friendly logs with smaller footprint.

### Disaster Drill
- **Purpose:** guarantee we can rebuild quickly.
- **Trigger:** quarterly calendar reminder.
- **Script:**
  1. Clone repo fresh; confirm the root web directory boots with `.env.example`/`secure/*.example.php` values.
  2. Restore production database snapshot (if any) to staging and run smoke tests.
  3. Verify backups of `secure/` and `storage/feeds` exist and match checksum log.
- **Output:** documented recovery steps stay current.
