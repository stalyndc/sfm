/**
 * /public_html/ocho/assets/js/main.js
 *
 * One-step generator UI logic (no preview).
 * Expects the DOM structure rendered by index.php (same IDs).
 * Posts to ./generate.php and renders a result card with:
 *   - feed URL (Copy / Open)
 *   - Validate link (RSS or JSONFeed validator)
 *   - New feed button
 */

(() => {
  // ---------- tiny helpers ----------
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  const form       = $('#feedForm');
  const urlInput   = $('#url');
  const limitInput = $('#limit');
  const formatSel  = $('#format');
  const genBtn     = $('#generateBtn');
  const clearBtn   = $('#clearBtn');
  const hintCard   = $('#hintCard');
  const resultCard = $('#resultCard');
  const resultBox  = $('#resultBox');
  const csrfField  = $('input[name="csrf_token"]');

  if (!form || !urlInput || !genBtn || !resultCard || !resultBox) {
    // Page isn’t the generator shell; nothing to do.
    return;
  }

  function setBusy(isBusy) {
    genBtn.disabled      = isBusy;
    urlInput.disabled    = isBusy;
    limitInput.disabled  = isBusy;
    formatSel.disabled   = isBusy;
    genBtn.innerHTML     = isBusy ? '<span class="spinner"></span>Generating…' : 'Generate feed';
  }

  function isLikelyUrl(s) {
    try {
      const u = new URL(s);
      return /^https?:$/i.test(u.protocol);
    } catch {
      return false;
    }
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

    // Wire result actions
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
      hintCard?.classList.remove('d-none');
      urlInput.focus();
    });

    hintCard?.classList.add('d-none');
    resultCard.classList.remove('d-none');
  }

  // ---------- events ----------
  clearBtn?.addEventListener('click', () => {
    form.reset();
    urlInput.focus();
  });

  genBtn.addEventListener('click', async () => {
    const url = urlInput.value.trim();
    if (!isLikelyUrl(url)) {
      urlInput.focus();
      urlInput.classList.add('is-invalid');
      setTimeout(() => urlInput.classList.remove('is-invalid'), 900);
      return;
    }

    const fd = new FormData();
    fd.set('url', url);
    fd.set('limit', String(Math.max(1, Math.min(50, parseInt(limitInput.value || '10', 10)))));
    fd.set('format', formatSel.value);
    if (csrfField?.value) {
      fd.set('csrf_token', csrfField.value);
    }

    setBusy(true);
    try {
      const headers = { 'Accept': 'application/json' };
      if (csrfField?.value) {
        headers['X-CSRF-Token'] = csrfField.value;
      }

      const res  = await fetch('generate.php', {
        method: 'POST',
        body: fd,
        headers,
        credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({ ok: false, message: 'Invalid JSON from server.' }));

      if (!data || data.ok === false) {
        resultBox.innerHTML = `<div class="alert alert-danger"><strong>Oops.</strong> ${String(data?.message || 'Generation failed.')}</div>`;
        resultCard.classList.remove('d-none');
        hintCard?.classList.add('d-none');
        return;
      }
      renderResult(data);
    } catch (err) {
      resultBox.innerHTML = `<div class="alert alert-danger"><strong>Network error.</strong> ${String(err && err.message || err || '')}</div>`;
      resultCard.classList.remove('d-none');
      hintCard?.classList.add('d-none');
    } finally {
      setBusy(false);
    }
  });
})();
