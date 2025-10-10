# SimpleFeedMaker

SimpleFeedMaker turns any article listing page into a subscription-friendly feed
(RSS, Atom, or JSON Feed). Paste a public URL, choose your format, and the app
returns a fresh feed URL you can drop into any reader.

## Highlights

- **One-shot feed generation** – `generate.php` fetches the page, extracts
  items, builds the feed, and saves it under `/feeds`.
- **Native feed passthrough** – If a site advertises a feed via
  `<link rel="alternate">`, the user can opt to reuse it instead of scraping.
- **Multi-format output** – RSS 2.0 and Atom served as XML, JSON Feed served with
  `application/feed+json`.
- **Automatic enrichment** – We re-fetch up to six article pages per job to fill
  in summaries, publish dates, authors, hero images, and tags when the source
  listing omits them.
- **Built-in validation** – Every feed passes through `includes/feed_validator.php`
  so malformed XML/JSON and missing required fields are caught before the file
  hits `/feeds`.
- **Richer extraction** – JSON‑LD first, DOM heuristics second. Optional CSS
  overrides (`item_selector`, `title_selector`, `summary_selector`) let power
  users target stubborn layouts. Failures come back as structured 4xx responses
  with hints and item counts.
- **Lean HTTP client** – HTTP/2 when available, compression, timeouts, and a
  tiny cache keyed by URL (with ETag/Last-Modified revalidation).
- **Hardening** – `.htaccess` forces HTTPS, sets CSP, blocks dotfiles,
  configures caching for feeds, and prevents script execution inside `/feeds`.
- **Ops tooling** – Health endpoint, daily feed cleanup, weekly log sanitizer,
  hourly rate-limit inspector, and automated refreshes keep generated feeds
  evergreen.

## Directory Layout

```
public_html/
├── generate.php          # POST endpoint powering the UI
├── cron_refresh.php      # Refresh generated feeds (cron safe)
├── scripts/automation/   # cron_runner.sh + helper agents
├── secure/               # non-public config, scripts, vendor/
├── storage/              # jobs, logs, drill status, etc.
└── feeds/                # generated feed files + HTTP cache
```

Secrets and host-specific config live under `secure/` (outside the web root in
production). Copy any `*.example.php` template, fill in values on the server,
and keep the real files out of git.

## Automation & Health

Schedule `scripts/automation/cron_runner.sh` from Hostinger with Bash:

| Cadence        | Command snippet |
| -------------- | --------------- |
| Every 15 min   | `scripts/automation/cron_runner.sh monitor` *(warn-only by default)* |
| Hourly @ :05   | `scripts/automation/cron_runner.sh hourly` |
| Daily 02:00    | `scripts/automation/cron_runner.sh daily` |
| Weekly Mon 02:30 | `scripts/automation/cron_runner.sh weekly` |
| Every 30 min   | `php cron_refresh.php` |

`secure/cron.env` can override `PHP_BIN`, `SFM_ALERT_EMAIL(S)` and provide paths
for backups (`SFM_BACKUPS_DIR`) and drill checksums (`SFM_CHECKSUM_FILE`).
Set `SFM_APP_NAME` if you need different branding, and `SFM_TRUSTED_PROXIES`
to a space/comma-separated list of proxy IPs or CIDRs before trusting forwarded
addresses.

The monitor hits `health.php` and emails only on failures; warnings are logged.
After adding backups or running the disaster drill, the health endpoint will
report `status":"ok"`.

## Local Development

```bash
composer install
php -S localhost:8080
```
Visit `http://localhost:8080/index.php`, generate a feed, and inspect the saved
file under `/feeds`.

Run lint + static analysis + smoke tests:

```bash
composer ci
```

## Notes for Operators

- To quiet initial health warnings, run:
  - `php secure/scripts/cleanup_feeds.php --max-age=3d --quiet`
  - `php secure/scripts/disaster_drill.php --json > storage/logs/disaster_drill.json`
  - Configure `SFM_BACKUPS_DIR` once backups exist.
- Monitor email noise is controlled via `--warn-only`; remove it if you want
  warnings in your inbox.
- Update `read.md` for a deeper AI/engineer handoff summary and roadmap.

Questions or issues? Check `docs/automation-and-branch-protection.md` and the
scripts under `secure/scripts/` for detailed usage.
