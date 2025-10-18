## UI theming notes (updated October 11, 2025)

- Added `--sfm-primary-blue` (`#3282B8`) as the shared Bootstrap primary hue across pages. Both dark and light data themes now map `--bs-primary` and the accordion active/focus variables to this color for consistent UI chrome.
- Accordion buttons receive explicit border and inset shadow tweaks so the new blue renders with sufficient contrast in both themes.
- The landing hero headline uses `clamp(1.85rem, 3.8vw, 2.35rem)` to stay below the previous size on large displays while remaining readable on mobile.
- Navbar branding now relies on the `site-title` rule (instead of Bootstrap sizing utilities) so the clamp-based font size applies everywhere.
- Placeholder illustration includes a radial accent animation; reference `accentDrift` keyframes near the bottom of `assets/css/style.css` if further tuning is needed.
- Primary CTA (`#generateBtn`) text weight moved to `font-weight: 700` to emphasize the action while keeping the existing gradient treatment.
- Primary CTA busy state: when wiring HTMX or other async handlers, set the button label via `startButtonBusy()` / `stopButtonBusy()` helpers in `assets/js/main.js`. They expect the `.btn-label` span to keep its original text in `dataset.original`; avoid overwriting that value so the button can revert after requests settle.
- Cron refresh logging bug fixed (`cron_refresh.php` now defines the append/trim helpers before use) so `/storage/logs/cron_refresh.log` populates and the admin “Last refresh run” card shows live data.
- Adjusted the light-theme placeholder halo behind the RSS illustration to use a softer dual-gradient + subtle shadow so it sits cleanly on white cards.
