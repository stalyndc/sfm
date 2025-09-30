# Rate Limit Notes

This directory stores small, transient JSON files used for the naive, file-based
rate limiter (one file per client/IP). Keep it writable by PHP and ensure it
remains outside publicly served paths. If you mirror the project locally, add an
`.htaccess` deny rule or equivalent to prevent downloads.

## Usage

- Runtime code writes `<bucket>__<ip>.json` entries here; cleaning them manually is safe.
- After traffic spikes, run `php secure/scripts/rate_limit_inspector.php` to
  summarise the last hour and spot abusers quickly.
- Override defaults with `--dir=/path/to/ratelimits`, `--window=7200`,
  `--threshold=150`, `--top=10`, or add `--dry-run` to preview without writing.
- When incidents are confirmed, optionally extend the script to call a firewall
  API or patch `.htaccess` deny rules.

## Abuse Log

Entries below are appended automatically by `rate_limit_inspector.php` whenever
unique IP volume busts the configured threshold.
