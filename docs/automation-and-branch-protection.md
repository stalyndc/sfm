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
using the cadence below. Set `SFM_ALERT_EMAIL` to your operations inbox so email
notifications reach the right team.

| Cadence | Command |
| ------- | ------- |
| Every 15 minutes | `scripts/automation/cron_runner.sh monitor` |
| Hourly at :05 | `scripts/automation/cron_runner.sh hourly` |
| Daily at 02:00 | `scripts/automation/cron_runner.sh daily` |
| Weekly on Monday 02:30 | `scripts/automation/cron_runner.sh weekly` |
| Quarterly (manual/cron) | `scripts/automation/cron_runner.sh quarterly` |

Notes:
- Copy `secure/cron.env.example` to `secure/cron.env`, tweak values (health URL,
  alert emails, SFTP password), and source it from cron so alerts/backups are
  configured consistently.
- The runner uses whichever `SFM_ALERT_EMAIL` you provide in `secure/cron.env`;
  no default address is assumed.
- `monitor` automatically passes `--quiet --warn-only` to
  `secure/scripts/monitor_health.php`; remove `--warn-only` in cron if you want
  warnings delivered by email as well.
- Adjust thresholds (`--threshold`, `--top`, `--retention`) to match production
  load.
- Add `--stage-dir`/`--upload-cmd` options to `deploy_courier.php` when building
  releases so a fresh ZIP lands in your staging bucket automatically.
- Confirm cron jobs run with PHP 8.2+ and have permissions to write inside
  `secure/` and `storage/`.

## Branch Protection via CLI

You can script the GitHub guardrails with the GitHub CLI (`gh`):

```sh
gh api \
  --method PUT \
  -H "Accept: application/vnd.github+json" \
  "/repos/<owner>/<repo>/branches/main/protection" \
  -f required_status_checks.contexts[]="CI" \
  -f required_status_checks.strict=true \
  -F enforce_admins=true \
  -F required_pull_request_reviews.dismiss_stale_reviews=true \
  -F required_pull_request_reviews.require_code_owner_reviews=true \
  -F required_pull_request_reviews.required_approving_review_count=1 \
  -F restrictions="null"
```

Replace `<owner>` / `<repo>` and adjust approval counts as needed. Re-run for
each protected branch.
