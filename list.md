## What’s missing / what could be improved (or considered)

# I will this this myself later.

---

Based on list.md, here are practical features we can tackle next:

Smarter feedback: surface meaningful parser errors and let users tweak grab settings (items-per-page, CSS selectors) before giving up.

Feed enrichment: detect optional metadata—author, publish date, images, tags—and include it when available to improve downstream reader display.

Compliance guardrails: integrate automated RSS/JSON-feed validation plus robots.txt checks to keep generated feeds standards-friendly and respectful.

Usage insight: log and expose lightweight feed analytics (refresh counts, last success/failure) so creators can monitor reach and reliability.

UI polish: add a preview/test mode with saved history or favorites, helping returning users re-run their most common feeds without reconfiguration.

---

Smarter failure feedback: when parsing dies today we just bail. Add structured error messages (e.g. “couldn’t find article links”, “blocked by robots.txt”) so users know what to tweak. Pair it with optional overrides like custom CSS selectors or item limits.

Metadata enrichment pass: extend sfm_enrich_items_with_article_metadata so we capture author, hero image, and tags when they appear. That makes the feeds nicer in readers and opens the door to filtering later.

Validation hook: integrate a quick RSS/JSON Feed validator (there are PHP libs you can shell out to) so every generated feed is checked before we save it. Bubble validation warnings back to the UI.

Saved history / preview UX: a simple dashboard showing the last few feeds you built, with a “re-run” button, would make the product much stickier and surface scrape failures early.
