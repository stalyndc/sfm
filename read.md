SimpleFeedMaker — Project Overview (for handoff to another AI)

Last updated: this doc describes what’s live and what we prototyped together. It’s written to be read by an AI or engineer taking over the project.

TL;DR

SimpleFeedMaker turns any article listing page into a feed you can subscribe to (RSS/Atom/JSON Feed) and enriches missing metadata (summary, date, author, hero image, tags) by revisiting a handful of article URLs.
It can also auto-discover official feeds advertised by the site and pass them through as-is when the user prefers native feeds. Generated feeds are linted before they are saved, so obvious XML/JSON mistakes are caught early.

Frontend: a single index.php page with a small form (URL, limit, format, “Prefer native feed”), posts to backend and shows the resulting feed URL plus any validation warnings. Google Analytics tracking is present.

Backend: generate.php accepts POST, fetches the page, extracts items, enriches missing metadata, validates the feed, saves it in `/feeds`, and returns structured JSON. Native feeds discovered via `<link rel="alternate">` can be reused when the user opts in.

Infra: shared hosting (Hostinger). Apache with .htaccess for HTTPS, CSP, caching, friendly error pages, correct JSON Feed MIME type, and directory protections.

SEO assets: robots.txt, trimmed sitemap.xml, humans.txt, basic head tags in index.php.

Utilities: HTTP client with a tiny on-disk cache, smarter extraction (JSON-LD + DOM heuristics), metadata enrichment, feed validation helpers, a health endpoint, and maintenance scripts to purge old feeds and sanitize logs.

Dependencies: managed via Composer (`composer.json` / `composer.lock`). The autoloader lives in `secure/vendor/` so production deployments keep libraries out of the public web root. Front-end dependencies are not bundled yet (simple Bootstrap-only UI).

Features

One-shot feed generation
POST the source page → we extract items → we build & save a feed → we return the feed URL.

Native feed autodiscovery (opt-in)
If the page advertises a feed via <link rel="alternate" type="…">, and the user checked Prefer native feed, we fetch that feed as-is and save it.

Three output formats
RSS 2.0 (.xml), Atom (.xml), JSON Feed (.json, served as application/feed+json).

Smarter extraction
Prefer JSON-LD (ItemList, Article/BlogPosting), fall back to DOM heuristics (article/card/heading patterns). Gentle de-duping and short text normalization.

Metadata enrichment
Re-fetches up to six article URLs to fill in summary/content, publish date, author, hero image, and tags when the listing view omits them.

Structured feedback & validation
422 responses include error codes, JSON-LD/DOM hit counts, validation warnings, and hints so users know *why* extraction failed.

Power-user overrides
POST fields `item_selector`, `title_selector`, and `summary_selector` (CSS) let operators target tricky layouts. We translate CSS → XPath, reuse selectors across pagination fetches, and report how many nodes matched.

Cheaper/faster fetches
Shared cURL wrapper with timeouts, HTTP/2 if available, transparent compression, and tiny file-cache with ETag/Last-Modified revalidation (stores in `/feeds/.httpcache`).

Safer defaults
Strong `.htaccess`: force HTTPS, CSP, disable directory listing, block dotfiles, friendly error pages, short cache for feeds, and deny script execution inside `/feeds`.

SEO basics
`robots.txt`, minimal `sitemap.xml` (homepage and `/feeds/`), head tags tidy-up, `humans.txt`.

Ops hooks (shipping)
`health.php` endpoint, `secure/scripts/cleanup_feeds.php` for cron, `secure/scripts/log_sanitizer.php` for log redaction/archival, and rate-limit/monitor agents wired through `scripts/automation/cron_runner.sh`.

Operational cadence
Hostinger cron runs `scripts/automation/cron_runner.sh monitor` (warn-only emails every 15 min), hourly rate-limit sweeps, daily feed cleanup, weekly log sanitizer, plus `php cron_refresh.php` every 30 min. Backups & disaster drill scripts live in `secure/scripts/`; configure `secure/cron.env` with `SFM_BACKUPS_DIR`, `SFM_CHECKSUM_FILE`, and override `PHP_BIN`/alert emails as needed.

Architecture & Layout

Repo root doubles as the public web root (e.g., `public_html/`). Secrets/logs live outside it in `/home/<account>/secure/`. `storage/` holds job metadata, logs, and drill status artifacts that should not ship with releases.
