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
  const preferNativeToggle = $('#preferNative');
  const genBtn     = $('#generateBtn');
  const clearBtn   = $('#clearBtn');
  const hintCard   = $('#hintCard');
  const resultCard = $('#resultCard');
  const resultBox  = $('#resultBox');
  const csrfField  = $('input[name="csrf_token"]');
  const isHttps    = window.location.protocol === 'https:';
  const csrfCookie = 'sfm_csrf';

  const readCookie = (name) => {
    const prefix = `${name}=`;
    const parts = document.cookie ? document.cookie.split(';') : [];
    for (const part of parts) {
      const trimmed = part.trim();
      if (trimmed.startsWith(prefix)) return decodeURIComponent(trimmed.slice(prefix.length));
    }
    return '';
  };

  const setCookie = (name, value) => {
    const bits = [`${name}=${encodeURIComponent(value)}`, 'path=/', 'SameSite=Lax'];
    if (isHttps) bits.push('Secure');
    document.cookie = bits.join('; ');
  };

  const syncCsrfToken = () => {
    if (!csrfField) return;
    const cookieVal = readCookie(csrfCookie);
    if (cookieVal) {
      if (csrfField.value !== cookieVal) csrfField.value = cookieVal;
    } else if (csrfField.value) {
      setCookie(csrfCookie, csrfField.value);
    }
  };

  syncCsrfToken();

  if (!form || !urlInput || !genBtn || !resultCard || !resultBox) {
    // Page isn’t the generator shell; nothing to do.
    return;
  }

  function setBusy(isBusy) {
    genBtn.disabled      = isBusy;
    urlInput.disabled    = isBusy;
    limitInput.disabled  = isBusy;
    formatSel.disabled   = isBusy;
    if (preferNativeToggle) preferNativeToggle.disabled = isBusy;
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

  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (ch) => {
    switch (ch) {
      case '&': return '&amp;';
      case '<': return '&lt;';
      case '>': return '&gt;';
      case '"': return '&quot;';
      case "'": return '&#39;';
      default: return ch;
    }
  });

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
    const statusBreadcrumb = data.status_breadcrumb ? String(data.status_breadcrumb) : '';
    const usedNative = Boolean(data.used_native);
    const nativeSource = data.native_source ? String(data.native_source) : '';
    const validatorUrl = buildValidatorLink(feedUrl, format);
    const warnings = Array.isArray(data?.validation?.warnings) ? data.validation.warnings : [];

    const metaParts = [];
    metaParts.push(`Format: <span class="mono">${escapeHtml(format || 'rss')}</span>`);
    if (items !== null) metaParts.push(`Items: <span class="mono">${escapeHtml(String(items))}</span>`);
    if (statusBreadcrumb) metaParts.push(escapeHtml(statusBreadcrumb));
    const metaHtml = metaParts.length ? `<div class="muted mt-2">${metaParts.join(' · ')}</div>` : '';

    const nativeHtml = usedNative && nativeSource
      ? `<div class="muted small mt-1">Using site feed: <a class="link-highlight" href="${escapeHtml(nativeSource)}" target="_blank" rel="noopener">${escapeHtml(nativeSource)}</a></div>`
      : '';

    const warningsHtml = warnings.length
      ? `<div class="alert alert-warning mt-3 mb-0 d-flex align-items-center gap-2">
          <span class="badge bg-warning text-dark">Validator</span>
          <div class="small mb-0">Validator spotted ${warnings.length === 1 ? 'a warning' : 'some warnings'}. Most feeds still work fine; use <em>Validate feed</em> for details.</div>
        </div>`
      : '';

    resultBox.innerHTML = `
      <div class="mb-2">
        <label class="form-label">Your feed URL</label>
        <div class="input-group">
          <input id="feedUrlInput" type="text" class="form-control mono" value="${feedUrl}" readonly>
          <button id="copyBtn" class="btn btn-outline-secondary copy-btn" type="button">Copy</button>
          <a class="btn btn-outline-success" href="${feedUrl}" target="_blank" rel="noopener">Open</a>
        </div>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2">
        <a class="btn btn-outline-primary btn-sm" href="${validatorUrl}" target="_blank" rel="noopener">Validate feed</a>
        <button id="newBtn" type="button" class="btn btn-outline-secondary btn-icon btn-sm" aria-label="Start new feed" title="Start new feed">
          <span aria-hidden="true">↺</span>
        </button>
      </div>
      ${metaHtml}
      ${nativeHtml}
      ${warningsHtml}
    `;

    // Wire result actions
    const copyBtn = $('#copyBtn');
    const input   = $('#feedUrlInput');
    const newBtn  = $('#newBtn');

    copyBtn?.addEventListener('click', async () => {
      const showCopied = () => {
        copyBtn.textContent = 'Copied!';
        copyBtn.classList.add('copy-ok');
        copyBtn.blur();
        setTimeout(() => {
          copyBtn.textContent = 'Copy';
          copyBtn.classList.remove('copy-ok');
        }, 1400);
      };

      try {
        await navigator.clipboard.writeText(input.value);
        showCopied();
      } catch {
        input.select();
        document.execCommand('copy');
        if (typeof window.getSelection === 'function') {
          const sel = window.getSelection();
          sel?.removeAllRanges?.();
        }
        showCopied();
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
    syncCsrfToken();
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
    if (preferNativeToggle?.checked) {
      fd.set('prefer_native', '1');
    }
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
      syncCsrfToken();
    }
  });
})();
