<?php
/**
 * SimpleFeedMaker — index.php (SEO + small perf polish)
 * - Clean <head> (canonical, OG/Twitter, robots, theme-color, preconnect)
 * - Lightweight page; single form → POST to generate.php
 * - Same UI/flow as before; no breaking changes
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>SimpleFeedMaker — Create RSS or JSON feeds from any URL</title>

  <!-- Robots & basic SEO -->
  <meta name="robots" content="index,follow,max-image-preview:large" />
  <meta name="description" content="SimpleFeedMaker turns any web page into a feed. Paste a URL, choose RSS or JSON Feed, and get a clean, valid feed in seconds." />
  <meta name="keywords" content="RSS feed generator, JSON Feed, website to RSS, create RSS feed, feed builder" />
  <link rel="canonical" href="https://simplefeedmaker.com/">
  <link rel="alternate" href="https://simplefeedmaker.com/" hreflang="en" />

  <!-- Brand / PWA-ish niceties -->
  <meta name="theme-color" content="#0b1320" />
  <meta name="color-scheme" content="dark light" />

  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://simplefeedmaker.com/">
  <meta property="og:title" content="SimpleFeedMaker — Create RSS or JSON feeds from any URL">
  <meta property="og:description" content="Turn any page into a clean feed—no coding required.">
  <meta property="og:image" content="https://simplefeedmaker.com/img/simplefeedmaker-og.png">
  <meta property="og:site_name" content="SimpleFeedMaker">
  <meta property="og:locale" content="en_US">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="https://simplefeedmaker.com/">
  <meta name="twitter:title" content="SimpleFeedMaker — Create RSS or JSON feeds from any URL">
  <meta name="twitter:description" content="Turn any page into a clean feed—no coding required.">
  <meta name="twitter:image" content="https://simplefeedmaker.com/img/simplefeedmaker-og.png">

  <!-- Preconnects (fonts / js CDN) -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="dns-prefetch" href="//fonts.googleapis.com" />
  <link rel="dns-prefetch" href="//fonts.gstatic.com" />
  <link rel="dns-prefetch" href="//cdn.jsdelivr.net" />
  <link rel="dns-prefetch" href="//www.googletagmanager.com" />

  <!-- Work Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;600;700&display=swap" rel="stylesheet">

  <!-- Emoji favicon 📡 -->
  <link rel="icon" href="data:image/svg+xml,
    %3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E
      %3Ctext y='0.9em' font-size='90'%3E%F0%9F%93%A1%3C/text%3E
    %3C/svg%3E">

  <!-- Bootstrap -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
    crossorigin="anonymous">

  <!-- Minimal page polish -->
  <style>
    :root { --sfm-accent:#1e90ff; }
    html,body { height:100%; }
    body { font-family:"Work Sans",system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif; }
    .site-title { font-weight:700; letter-spacing:.2px; }
    .card { border:1px solid rgba(255,255,255,.08); }
    .muted { color: var(--bs-secondary-color); }
    .mono  { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; }
    .spinner { width:1rem;height:1rem;border:.2rem solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;display:inline-block;vertical-align:-.2rem;margin-right:.35rem}
    @keyframes spin { to { transform: rotate(360deg);} }
    .copy-ok { background: rgba(30,144,255,.12) !important; border-color: rgba(30,144,255,.35) !important; }
    .footnote { font-size:.9rem; }
  </style>

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-YZ2SN3R4PX"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-YZ2SN3R4PX');
  </script>

  <!-- JSON-LD (Website) -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "url": "https://simplefeedmaker.com/",
    "name": "SimpleFeedMaker",
    "description": "Create RSS or JSON feeds from any URL in seconds.",
    "inLanguage": "en"
  }
  </script>
</head>
<body>

  <!-- Header -->
  <header class="border-bottom bg-body">
    <div class="container d-flex align-items-center justify-content-between py-3">
      <a href="/" class="text-decoration-none text-body site-title fs-4">SimpleFeedMaker</a>
    </div>
  </header>

  <!-- Main -->
  <main class="py-4">
    <div class="container">
      <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <h1 class="h4 fw-bold mb-2">Turn any page into a feed</h1>
              <p class="muted mb-4">Paste a URL, choose RSS or JSON, and click Generate. That’s it.</p>

              <!-- One tiny form -->
              <form id="feedForm" class="vstack gap-3" novalidate>
                <div>
                  <label for="url" class="form-label">Source URL</label>
                  <input type="url" class="form-control" id="url" name="url"
                         placeholder="https://example.com/news" required inputmode="url" autocomplete="url">
                  <div class="form-text">Blog, news listing, category page, etc.</div>
                </div>

                <div class="row g-3">
                  <div class="col-6">
                    <label for="limit" class="form-label">Items (max)</label>
                    <input type="number" id="limit" name="limit" class="form-control" min="1" max="50" value="10" inputmode="numeric">
                  </div>
                  <div class="col-6">
                    <label for="format" class="form-label">Format</label>
                    <select id="format" name="format" class="form-select">
                      <option value="rss" selected>RSS</option>
                      <option value="jsonfeed">JSON Feed</option>
                    </select>
                  </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                  <button id="generateBtn" type="button" class="btn btn-primary">
                    Generate feed
                  </button>
                  <button id="clearBtn" type="button" class="btn btn-outline-secondary">
                    Clear
                  </button>
                </div>

                <noscript>
                  <div class="alert alert-warning mt-2 mb-0">Enable JavaScript to generate feeds.</div>
                </noscript>
              </form>
            </div>
          </div>

          <div class="footnote text-secondary mt-3">
            We don’t need your login or API keys. Your feed URL will look like <span class="mono">/feeds/xxxx.xml</span> or <span class="mono">/feeds/xxxx.json</span>.
          </div>
        </div>

        <!-- Result -->
        <div class="col-12 col-lg-5">
          <div id="resultCard" class="card shadow-sm d-none">
            <div class="card-body">
              <h2 class="h5 fw-semibold mb-3">Feed ready</h2>

              <div id="resultBox" class="vstack gap-3">
                <!-- JS will populate -->
              </div>
            </div>
          </div>

          <div id="hintCard" class="card shadow-sm">
            <div class="card-body">
              <div class="muted">Your result will appear here after you click <strong>Generate feed</strong>.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- Footer -->
  <footer class="border-top bg-body mt-4">
    <div class="container py-3 small text-secondary text-center">
      &copy; <?php echo date('Y'); ?> SimpleFeedMaker. Fast, clean, and reliable.
    </div>
  </footer>

  <!-- Bootstrap (defer JS parse) -->
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous" defer></script>

  <!-- Tiny inline app logic -->
  <script>
    const $  = (s, r=document) => r.querySelector(s);
    const $$ = (s, r=document) => [...r.querySelectorAll(s)];

    const form       = $('#feedForm');
    const urlInput   = $('#url');
    const limitInput = $('#limit');
    const formatSel  = $('#format');
    const genBtn     = $('#generateBtn');
    const clearBtn   = $('#clearBtn');
    const hintCard   = $('#hintCard');
    const resultCard = $('#resultCard');
    const resultBox  = $('#resultBox');

    function setBusy(isBusy) {
      genBtn.disabled   = isBusy;
      urlInput.disabled = isBusy;
      limitInput.disabled = isBusy;
      formatSel.disabled  = isBusy;
      genBtn.innerHTML  = isBusy ? '<span class="spinner"></span>Generating…' : 'Generate feed';
    }

    function isLikelyUrl(s) {
      try { const u = new URL(s); return /^https?:$/i.test(u.protocol); } catch { return false; }
    }

    function buildValidatorLink(feedUrl, format) {
      const isJson = (String(format).toLowerCase() === 'jsonfeed') || /\.json($|\?)/i.test(feedUrl);
      return isJson
        ? `https://validator.jsonfeed.org/?url=${encodeURIComponent(feedUrl)}`
        : `https://validator.w3.org/feed/check.cgi?url=${encodeURIComponent(feedUrl)}`;
    }

    function renderResult(data) {
      const feedUrl = String(data.feed_url || '');
      const format  = String(data.format || '').toLowerCase();
      const items   = (data.items ?? null);

      const validatorUrl = buildValidatorLink(feedUrl, format);

      resultBox.innerHTML = `
        <div class="mb-2">
          <label class="form-label">Your feed URL</label>
          <div class="input-group">
            <input id="feedUrlInput" type="text" class="form-control mono" value="${feedUrl}" readonly>
            <button id="copyBtn" class="btn btn-outline-secondary" type="button">Copy</button>
            <a class="btn btn-outline-success" href="${feedUrl}" target="_blank" rel="noopener">Open</a>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-primary btn-sm" href="${validatorUrl}" target="_blank" rel="noopener">Validate feed</a>
          <button id="newBtn" type="button" class="btn btn-outline-secondary btn-sm">New feed</button>
        </div>

        <div class="muted mt-2">
          Format: <span class="mono">${format || 'rss'}</span>
          ${items !== null ? ` — Items: <span class="mono">${items}</span>` : ''}
        </div>
      `;

      const copyBtn = $('#copyBtn');
      const input   = $('#feedUrlInput');
      const newBtn  = $('#newBtn');

      copyBtn?.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(input.value);
          copyBtn.textContent = 'Copied!';
          copyBtn.classList.add('copy-ok');
          setTimeout(() => { copyBtn.textContent = 'Copy'; copyBtn.classList.remove('copy-ok'); }, 1200);
        } catch {
          input.select();
          document.execCommand('copy');
          copyBtn.textContent = 'Copied!';
          setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1200);
        }
      });

      newBtn?.addEventListener('click', () => {
        form.reset();
        resultCard.classList.add('d-none');
        hintCard.classList.remove('d-none');
        urlInput.focus();
      });

      hintCard.classList.add('d-none');
      resultCard.classList.remove('d-none');
    }

    clearBtn.addEventListener('click', () => {
      form.reset();
      urlInput.focus();
    });

    genBtn.addEventListener('click', async () => {
      const url = urlInput.value.trim();
      if (!isLikelyUrl(url)) {
        urlInput.focus();
        urlInput.classList.add('is-invalid');
        setTimeout(()=>urlInput.classList.remove('is-invalid'), 1000);
        return;
      }

      const fd = new FormData();
      fd.set('url', url);
      fd.set('limit', String(Math.max(1, Math.min(50, parseInt(limitInput.value || '10', 10)))));
      fd.set('format', formatSel.value);

      setBusy(true);
      try {
        const res  = await fetch('generate.php', { method:'POST', body: fd, headers: { 'Accept':'application/json' } });
        const data = await res.json().catch(()=>({ok:false,message:'Invalid JSON from server.'}));

        if (!data || data.ok === false) {
          resultBox.innerHTML = `<div class="alert alert-danger"><strong>Oops.</strong> ${String(data?.message || 'Generation failed.')}</div>`;
          resultCard.classList.remove('d-none');
          hintCard.classList.add('d-none');
          return;
        }
        renderResult(data);
      } catch (err) {
        resultBox.innerHTML = `<div class="alert alert-danger"><strong>Network error.</strong> ${String(err && err.message || err || '')}</div>`;
        resultCard.classList.remove('d-none');
        hintCard.classList.add('d-none');
      } finally {
        setBusy(false);
      }
    });
  </script>
</body>
</html>
