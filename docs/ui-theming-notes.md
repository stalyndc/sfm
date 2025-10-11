## UI theming notes (updated October 11, 2025)

- Added `--sfm-primary-blue` (`#3282B8`) as the shared Bootstrap primary hue across pages. Both dark and light data themes now map `--bs-primary` and the accordion active/focus variables to this color for consistent UI chrome.
- Accordion buttons receive explicit border and inset shadow tweaks so the new blue renders with sufficient contrast in both themes.
- The landing hero headline uses `clamp(1.85rem, 3.8vw, 2.35rem)` to stay below the previous size on large displays while remaining readable on mobile.
- Navbar branding now relies on the `site-title` rule (instead of Bootstrap sizing utilities) so the clamp-based font size applies everywhere.
- Placeholder illustration includes a radial accent animation; reference `accentDrift` keyframes near the bottom of `assets/css/style.css` if further tuning is needed.
- Primary CTA (`#generateBtn`) text weight moved to `font-weight: 700` to emphasize the action while keeping the existing gradient treatment.
- Cron refresh logging bug fixed (`cron_refresh.php` now defines the append/trim helpers before use) so `/storage/logs/cron_refresh.log` populates and the admin “Last refresh run” card shows live data.
