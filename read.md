SimpleFeedMaker — Project Overview (for handoff to another AI)

Last updated: this doc describes what’s live and what we prototyped together. It’s written to be read by an AI or engineer taking over the project.

TL;DR

SimpleFeedMaker turns any article listing page into a feed you can subscribe to (RSS/Atom/JSON Feed).
It can also auto-discover official feeds advertised by the site and pass them through as-is when the user prefers native feeds.

Frontend: a single index.php page with a small form (URL, limit, format, “Prefer native feed”), posts to backend and shows the resulting feed URL. Google Analytics tracking is present.

Backend: generate.php accepts POST, fetches the page, extracts items, builds feeds, saves them in /feeds. Optionally autodiscovers a native feed and uses that instead.

Infra: shared hosting (Hostinger). Apache with .htaccess for HTTPS, CSP, caching, friendly error pages, correct JSON Feed MIME type, and directory protections.

SEO assets: robots.txt, trimmed sitemap.xml, humans.txt, basic head tags in index.php.

Utilities: HTTP client with a tiny on-disk cache, smarter extraction (JSON-LD + DOM heuristics), optional logging scaffolding, a health endpoint, and a cleanup script to purge old feeds.

Features

One-shot feed generation
POST the source page → we extract items → we build & save a feed → we return the feed URL.

Native feed autodiscovery (opt-in)
If the page advertises a feed via <link rel="alternate" type="…">, and the user checked Prefer native feed, we fetch that feed as-is and save it.

Three output formats
RSS 2.0 (.xml), Atom (.xml), JSON Feed (.json, served as application/feed+json).

Smarter extraction
Prefer JSON-LD (ItemList, Article/BlogPosting), fall back to DOM heuristics (article/card/heading patterns). Gentle de-duping and short text normalization.

Cheaper/faster fetches
Shared cURL wrapper with timeouts, HTTP/2 if available, transparent compression, and tiny file-cache with ETag/Last-Modified revalidation (stores in /feeds/.httpcache).

Safer defaults
Strong .htaccess: force HTTPS, CSP, disable directory listing, block dotfiles, friendly error pages, short cache for feeds, and deny script execution inside /feeds.

SEO basics
robots.txt, minimal sitemap.xml (homepage and /feeds/), head tags tidy-up, humans.txt.

Ops hooks (scaffolded)
health.php endpoint, scripts/cleanup_feeds.php for cron, optional request/parse logs (toggleable), daily rotation (planned), privacy-safe redaction (planned).

Architecture & Layout

Repo root doubles as the public web root (e.g., public_html/). Secrets/logs live outside it in /home/<account>/secure/.
