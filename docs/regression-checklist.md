# Regression Checklist — Feed Generator

Use this checklist whenever you touch `generate.php`, `includes/feed_builder.php`,
`includes/feed_validator.php`, or the extraction/enrichment helpers. The goal is
catching silent regressions before they reach production.

## 1. Pre-change snapshot

- Stage or copy the sections you plan to edit so you can compare them after the
  change. Avoid deleting validation/error-handling blocks outright.
- Open the current diff (`git status -sb`) and make sure you understand any
  existing modifications before adding more.

## 2. Automated smoke tests

Run the CLI harness before and after your edits. It exercises HTML extraction,
feed building, and validation.

```sh
php scripts/test_generate.php "https://www.cnet.com/tech/computing/laptops/"
php scripts/test_generate.php "https://physicsworld.com/p/progress-in-series/"
php scripts/test_generate.php "https://www.theverge.com/reviews"
```

All runs should report `ok => 1` with an empty `errors` array. Investigate any
failure before proceeding.

Tips:
- Add new "tricky" URLs to the list when you discover them.
- When a failure happens, inspect `/tmp/test_rss.xml` (written by the script) to
  understand what changed in the generated feed.

## 3. Web UI spot check

1. Visit the local or staging instance and generate a feed with each smoke URL.
2. Confirm the badge copy in the result card appears only when warnings exist
   and that errors render inside the red alert box (no blank states).
3. Toggle "Prefer native feed" for at least one URL to ensure native discovery
   still works.

## 4. Repo and tooling checks

- Run `php composer.phar test` (or `composer test`) to ensure coding standards
  and security advisories remain clean.
- If `.gitignore` or any file in `secure/` moved, run `php secure/scripts/secrets_guard.php`.
- Verify `feeds/` only contains the temporary files the smoke script wrote;
  remove leftover artifacts before committing.

## 5. Post-change review

- Re-run the smoke tests after your final edits. Keep the outputs in the PR
  description or notes so reviewers see the evidence.
- Review `git diff` for the touched files, scanning for large deletions of
  validation code or error handling.
- When everything passes, run `php secure/scripts/deploy_courier.php --dry-run`
  if a release is imminent to ensure packaging still works.

Keep this document updated as we learn about new regressions or add tooling.
