# Secure Configuration

This directory stays **out of the web root** and contains only private runtime
configuration. Everything here is ignored by git except for these docs and the
`.example.php` templates.

Keep the workflow below so secrets never leave the server:

1. Copy the relevant `*.example.php` file to its real name (drop the `.example`).
2. Fill in the values locally on the server or in your `.local` overrides.
3. Never commit the real files. The `.gitignore` in the repo blocks them already.

Files you may want to provide:

- `admin-credentials.php` — defines `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` (generate with `php secure/scripts/hash_password.php`).
- `db-credentials.php` — defines `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_NAME`.
- `sfm-secrets.php` — optional array for extra shared secrets.
- `cron.env` — sourced by `scripts/automation/cron_runner.sh`. Use it to set `PHP_BIN` (e.g. `/opt/alt/php82/usr/bin/php`), `SFM_ALERT_EMAIL`, comma/semicolon-delimited `SFM_ALERT_EMAILS`, and automation-specific values such as `SFM_BACKUPS_DIR` and `SFM_CHECKSUM_FILE` so the health monitor and disaster drill know where to look.

Health/monitoring defaults

- `scripts/automation/cron_runner.sh monitor` now passes `--warn-only` so routine warnings stay in the log; it still emails on failures. Override by exporting `MONITOR_OPTS` or passing additional flags in cron if you need a different behaviour.
- The health endpoint checks for recent backups and drill status. If you do not manage off-site backups yet, either point `SFM_BACKUPS_DIR` at a directory populated by another job or temporarily disable that check inside `health.php` to avoid noisy alerts.
- `secure/scripts/log_sanitizer.php` redacts PII, gzips archived logs into `secure/logs/archive/`, and is wired to the weekly cron job via `cron_runner.sh`.

Rotation checklist lives in `docs/deployment-secrets.md`.

If new secrets are required, create a matching `*.example.php` so teammates know
which keys to supply without exposing real values.

Run `php secure/scripts/secrets_guard.php` anytime you modify ignore rules or
add new secret files; it will fail fast if something risky slips into git.
