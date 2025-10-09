## What’s next / improvement backlog

This list captures meaningful follow-up work now that enrichment, validation, and the maintenance agents are live.

### Product polish
- **Saved history & re-run shortcuts.** Store the last few successful jobs per browser (localStorage + signed tokens) so returning users can refresh feeds without retyping everything.
- **Preview extracted items.** Let power users inspect the first handful of parsed entries—including validation warnings—before committing to generate the feed file.
- **Better native-feed context.** When we fall back to an advertised feed, show the discovered URL, feed title, and last-modified timestamp in the result card.

### Observability & analytics
- **Lightweight usage metrics.** Track refresh counts, last success/failure, and top warnings per feed so operators know which feeds require attention.
- **Admin dashboard.** Surface health/cleanup/log-sanitizer status plus the rate-limit watch list inside `/admin/` instead of relying solely on emails.

### Reliability & DX
- **Background refresh UX.** Expose the `cron_refresh.php` job queue (next run time, failures) so we can monitor long-running feeds without tailing logs.
- **Extended smoke set.** Add more tricky URLs to `scripts/test_generate.php` (paywalled excerpts, infinite-scroll lists) and run them in CI.
- **Secrets guardrails in CI.** Run `php secure/scripts/secrets_guard.php --ci` during the GitHub workflow once we can ensure the secure templates are available in automation.
