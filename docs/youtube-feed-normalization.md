## YouTube Native Feed Normalization

When a job pulls the official YouTube Atom feed (detected via the `youtube.com` host or the `xmlns:yt` namespace), we now normalize the payload before saving it. The raw YouTube Atom document fails strict validators (missing `<updated>`, non-standard namespaces, etc.), so we convert it into SimpleFeedMaker’s standard RSS output.

### How it works

1. `sfm_normalize_feed()` in `includes/feed_builder.php` inspects any downloaded feed body.
2. If the feed looks like YouTube, it extracts channel metadata and entries via DOMXPath.
3. The function rebuilds the data as RSS using `build_rss()` and reports `note => "youtube normalized"`.
4. Both `generate.php` (initial download) and `includes/job_refresh.php` (cron refresh) call the helper and persist the transformed XML.

### Operational notes

- Jobs and logs append the `youtube normalized` note so operators know the content was rewritten.
- The stored RSS now passes external validators; failure streaks from `<channel>` errors should clear on the next run.
- Future normalizers can follow the same pattern: extend `sfm_normalize_feed()` with additional vendor-specific branches.
