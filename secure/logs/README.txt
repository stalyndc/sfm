# Secure Logs

Runtime logs live here and stay out of the public web root. Git ignores the
actual log files; this README documents retention so all teammates follow the
same playbook.

## Sanitizing & Archiving

- Run `php secure/scripts/log_sanitizer.php` after every cleanup or on a weekly
  cadence. It redacts emails, phone numbers, and sensitive query parameters.
- Use `--dry-run` when previewing changes, `--dir=/path/to/logs` for overrides,
  and `--retention=30` to change the archive window (default 14 days).
- Sanitised logs older than the retention window are gzipped into
  `secure/logs/archive/` and removed from the live directory to save space.
- Archived files inherit the original filename with a `.gz` suffix. Keep the
  archive outside of version control alongside your backups.

## Manual Hygiene

- Avoid copying raw logs into tickets or email; run the sanitizer first.
- Purge the archive directory periodically if older logs are no longer needed,
  following your compliance requirements.
