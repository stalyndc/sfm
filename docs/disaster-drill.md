# Disaster Drill Runbook

Use this checklist each quarter to prove we can rebuild SimpleFeedMaker quickly
and safely.

## 1. Prepare a Clean Workspace

1. Clone the repository into a fresh directory.
2. Copy the `secure/*.example.php` templates to their real names and fill in
   non-sensitive placeholder values so the app boots.
3. Run `php secure/scripts/disaster_drill.php --dry-run` to confirm structure,
   then run it without `--dry-run` once fixes are applied.
   - By default the script records results to `SFM_DRILL_STATUS_FILE`
     (see `includes/config.php`); use `--no-record` to skip writing.

## 2. Install Dependencies

1. Execute `composer install --no-dev` to restore the autoloader in
   `secure/vendor/`.
2. If frontend assets change, run `npm install && npm run build`.
3. Run `composer test` to catch syntax or dependency issues early.

## 3. Restore Data and Smoke-Test

1. Restore the latest production database snapshot into staging (if the app uses
   one).
2. Generate a sample feed via the UI; confirm it succeeds and the resulting file
   lands in `feeds/`.
3. Hit `/health.php` and ensure `"ok": true`.

## 4. Verify Backups

1. Mount or download the latest backups for `secure/` and `storage/feeds`.
2. Run `php secure/scripts/disaster_drill.php --backups=/path/to/backups --checksum-file=/path/to/checksums.json`.
3. Review checksum mismatches and reconcile before signing off.

## 5. Document the Drill

- Record the drill date, participants, and outcomes in your ops log.
- File follow-up tickets for any warnings or failures raised by the script.
- Schedule the next drill (quarterly cadence).
