# Automation Schedule & GitHub Guardrails

Keep SimpleFeedMaker healthy by pairing local agents with hosted automation and
GitHub branch protection.

## Branch Protection Checklist

1. In **GitHub → Settings → Branches**, create a protection rule for `main`
   (add any release branches as well).
2. Enable **Require a pull request before merging** and **Require approvals** so
   changes always flow through PR review.
3. Under **Require status checks to pass**, select the `CI` workflow and turn on
   **Require branches to be up to date before merging**. This guarantees the
   `composer test` smoke suite runs on the exact code that will ship.
4. (Optional but recommended) Enable **Dismiss stale pull request approvals**,
   **Require approval of the most recent reviewable push**, and restrict who can
   force-push or delete the branch.
5. Repeat the rule for any long-lived release branches so hotfixes follow the
   same guardrails.

## Cron & Agent Schedule

Run the CLI agents from the Hostinger account (or your preferred scheduler)
using the cadence below. Set `SFM_ALERT_EMAIL=stalyn@disla.net` so email
notifications reach the ops inbox.

| Cadence | Command |
| ------- | ------- |
| Hourly at :05 | `php secure/scripts/rate_limit_inspector.php --threshold=150 --top=10 --block --notify` |
| Daily at 02:00 | `php secure/scripts/cleanup_feeds.php --max-age=3d --quiet` |
| Weekly on Monday 02:30 | `php secure/scripts/log_sanitizer.php --notify` |
| Quarterly (manual/cron) | `php secure/scripts/disaster_drill.php --backups=/path/to/backups --checksum-file=/path/checksums.json` |

Notes:
- Adjust `--threshold`, `--top`, and `--retention` to match production load.
- Add `--stage-dir`/`--upload-cmd` options to `deploy_courier.php` when building
  releases so a fresh ZIP lands in your staging bucket automatically.
- Confirm cron jobs run with PHP 8.2+ and have permissions to write inside
  `secure/` and `storage/`.
