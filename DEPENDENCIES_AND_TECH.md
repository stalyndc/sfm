Project: SimpleFeedMaker.com
Environment: Hostinger Shared Hosting
Stack: PHP + HTML + CSS + Vanilla JS
Goal: Enhance performance, UX, and maintainability without breaking PHP compatibility or requiring server-side Node.js.

1. Core PHP Dependencies (via Composer)

These libraries work perfectly on shared hosting and enhance reliability, caching, and data parsing.

Package Purpose Why It’s Useful
guzzlehttp/guzzle HTTP client Fetch remote HTML and feeds reliably with timeouts, headers, and redirects.
symfony/dom-crawler HTML parser Extract titles, links, or article blocks cleanly from pages.
symfony/css-selector CSS selectors for PHP Select elements in parsed HTML using CSS syntax (like JS querySelector).
symfony/cache Caching system Store feeds or HTML pages in a file-based cache for faster load times.
simplepie/simplepie Feed reader Parse and validate RSS/Atom feeds easily.
laminas/laminas-feed Feed generator Create new RSS/Atom feeds from data or pages.
vlucas/phpdotenv Environment management Securely store settings like API keys or cache TTL in .env file.
monolog/monolog (optional) Logging Record warnings, errors, and cache misses for debugging.
slim/slim (optional) Microframework Add clean routing and middleware without switching frameworks.

2. Front-End Enhancements (Browser-Side)

These tools/libraries don’t need server configuration — they run in the browser or compile locally.

Library / Tool Type How It Helps Hosting Impact
TypeScript JS superset Adds type safety, autocomplete, and clean modular JS. Build locally → output .js.
SCSS (Sass) CSS preprocessor Allows variables, nesting, and modular styling. Build locally → output .css.
Tailwind CSS Utility CSS framework Rapidly build modern responsive layouts. Build locally or use CDN.
HTMX Front-end interactivity Add AJAX/SPA-like behavior with HTML attributes — no heavy JS frameworks. Load via CDN (no Node).
Alpine.js Lightweight JS framework Add small dynamic behaviors like toggles, modals, reactive text. Load via CDN.
Font Awesome / Lucide Icons Add consistent icons easily. Use CDN or self-hosted.
Animate.css (optional) Animations Adds visual flair (fade, slide, bounce). Lightweight CSS library.

3. Local Build Tools (for Development Only)

These tools are optional and run on your Linux laptop before deployment.
They help build and bundle front-end assets.

Tool Role Notes
Node.js 22+ (via nvm) JS runtime for builds Required for TypeScript, SCSS, or Tailwind compilation.
npm Package manager Comes with Node; manages front-end packages.
Vite Fast dev server/bundler Compiles TS/SCSS → optimized JS/CSS quickly.
npm-run-all Script runner Lets you run multiple npm scripts in parallel (e.g., TS + SCSS watchers).

Build flow example:

npm run build # compiles TypeScript and SCSS
composer install --no-dev --optimize-autoloader

Then upload compiled assets + /vendor/.

4. UI / UX Libraries (CDN-Friendly)
   Library Use Case Example Benefit
   HTMX AJAX-like partial updates Turns your PHP form into live-updating results without full reload.
   Alpine.js Lightweight interactivity Create modals, tabs, toggles easily.
   Tippy.js (optional) Tooltips Add user hints without dependencies.
   SweetAlert2 (optional) Alerts Beautiful alerts and confirmation dialogs.
   LottieFiles Player (optional) Animations Add JSON-based vector animations for loaders.

All of these are available via <script> CDN imports (no builds required).

5. Performance / Caching Tools
   Tool / Method Purpose How to Use
   Symfony Cache (FilesystemAdapter) Store parsed feeds or pages Saves time on repeated requests.
   Cloudflare (Free CDN) Global caching and compression Add your domain and enable free caching.
   Gzip / Brotli compression Smaller HTML/CSS/JS responses Usually already active on Hostinger.
   Minify compiled assets Smaller load sizes Done automatically by Tailwind or Vite.

6. Security / Maintenance Add-ons
   Package / Tool Purpose Reason
   PHPMailer (optional) Send email alerts or feedback forms. Useful for contact or error reporting.
   Google reCAPTCHA v3 (optional) Spam protection Prevents automated abuse of the form.
   Dotenv + HTTPS enforcement Config + security Keep secrets out of public HTML/PHP.
   CSRF tokens (manual or Slim middleware) Form security Protects against cross-site requests.

7. Analytics / Logging
   Tool Use Benefit
   Plausible.io Privacy-friendly analytics Lightweight, fast, no cookies.
   Umami.is Open-source analytics Host yourself or use managed plan.
   Monolog (PHP) Logging library Log feed errors, cache events, etc.
   Google Search Console SEO monitoring Track indexing and feed discoverability.

8. Optional Enhancements (For Future Versions)
   Category Option Description
   Database SQLite Simple file-based database for saved feeds.
   Background Processing Cron (already set) Schedule cache refreshes, health checks.
   Serverless Support Cloudflare Workers / Netlify Functions Offload heavy parsing tasks.
   API Layer REST endpoints (PHP JSON output) Allow automation or mobile app integration.
   Progressive Web App (PWA) Manifest + Service Worker Enable offline caching for UI.

9. Recommended Tech Combinations
   Goal Recommended Tech Combo
   Faster feed generation PHP + Symfony Cache + Guzzle
   Modern UI Tailwind CSS + HTMX + Alpine.js
   Better maintainability TypeScript + SCSS build locally
   Error monitoring Monolog + Feed Health Panel
   SEO + visibility Cloudflare + RSS validation + meta tags

10. Summary for Codex

When Codex builds or enhances the project, it should:

Keep PHP as backend.

Use Composer for PHP dependencies.

Use TypeScript/SCSS/Tailwind only for local builds (compile → upload).

Use HTMX + Alpine.js from CDN for interactivity.

Use Symfony Cache + Guzzle for backend efficiency.

Optimize with Cloudflare and optional analytics.

Maintain full compatibility with Hostinger shared hosting.
