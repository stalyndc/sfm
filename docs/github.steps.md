# GitHub Branch Protection & Automation Guide

Follow these steps on GitHub after pulling the latest code.

## 1. Protect the `main` Branch
1. Open the repo on GitHub and click **Settings** (you need maintainer access).
2. Choose **Branches** from the sidebar, then **Add branch protection rule**.
3. Set **Branch name pattern** to `main`.
4. Enable:
   - **Require a pull request before merging**
     - Keep approvals at `1` (raise if desired).
     - Tick **Dismiss stale pull request approvals**.
     - Tick **Require approval of the most recent reviewable push**.
   - **Require status checks to pass before merging**
     - Click **Edit**, select `CI`, and enable **Require branches to be up to date**.
   - Optional: **Include administrators**.
5. Save the rule. Repeat for release branches (e.g. `release/*`) if you have them.

> Tip: Use the GitHub CLI snippet in `docs/automation-and-branch-protection.md`
> as an alternative to the web UI—replace `<owner>` and `<repo>` first.

## 2. Confirm CI Workflow
1. Visit the **Actions** tab → select `CI`.
2. Ensure the latest run on `main` succeeded. Trigger **Run workflow** if needed.

## 3. Prepare Cron Environment (Hostinger or SSH)
1. Copy `secure/cron.env.example` to `secure/cron.env`:
   ```bash
   cp secure/cron.env.example secure/cron.env
   ```
2. Edit `secure/cron.env` with your details:
   ```bash
   export SFM_ALERT_EMAIL="stalyn@disla.net"
   export SFM_BACKUPS_DIR="/home/<account>/backups/sfm"      # optional
   export SFM_CHECKSUM_FILE="/home/<account>/backups/sfm/checksums.json"  # optional
   export PHP_BIN="/opt/alt/php82/usr/bin/php"               # Hostinger PHP 8.2 path
   ```
   - On Hostinger, confirm the PHP path in **hPanel → Advanced → PHP Info**.
   - Upload `secure/cron.env` to the server alongside the repo so cron jobs can source it.

## 4. Install Cron Jobs on Hostinger
You can do this from hPanel (recommended) or via SSH.

### Option A: hPanel UI
1. Log in to hPanel.
2. Navigate to **Advanced → Cron Jobs**.
3. Create each cron job with the schedule and command below (adjust the account path):
   - **Hourly at :05**
     ```
     /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh hourly >/dev/null 2>&1
     ```
   - **Daily 02:00**
     ```
     /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh daily >/dev/null 2>&1
     ```
   - **Weekly Monday 02:30**
     ```
     /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh weekly >/dev/null 2>&1
     ```
   - **Quarterly (1st day every 3rd month)**
     ```
     /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh quarterly >/dev/null 2>&1
     ```
4. Save each job; Hostinger shows them in a list after creation.

### Option B: SSH `crontab`
1. SSH into the Hostinger account.
2. Run `crontab -e` and paste:
   ```cron
   5 * * * *    /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh hourly >/dev/null 2>&1
   0 2 * * *    /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh daily >/dev/null 2>&1
   30 2 * * 1   /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh weekly >/dev/null 2>&1
   0 3 1 */3 *  /opt/alt/php82/usr/bin/php /home/<account>/simplefeedmaker/scripts/automation/cron_runner.sh quarterly >/dev/null 2>&1
   ```
3. Save and run `crontab -l` to verify the entries.

### Verify Cron Jobs
- In hPanel, open **Cron Jobs → Logs** after the first hour/day to confirm there are no errors.
- Via SSH, run `grep cron /var/log/cron` or check the cron user’s mail (`mail` command) for any failure messages.

## 5. Optional Checklist Issue
Create an issue titled “Enable Branch Protection & Automation” with:
- [ ] Branch protection rule active for `main`.
- [ ] CI workflow passing and required.
- [ ] Cron runner installed for hourly/daily/weekly/quarterly jobs.
- [ ] `secure/cron.env` populated.

Tick boxes as you finish each task to show completion.
