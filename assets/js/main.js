/**
 * assets/js/main.js
 *
 * HTMX-enhanced generator interactions:
 *  - Inline validation prior to submit
 *  - Copy-to-clipboard helpers
 *  - Resetting result panel without full reload
 */

(() => {
  const form = document.getElementById('feedForm');
  const urlInput = document.getElementById('url');
  const clearBtn = document.getElementById('clearBtn');
  const resultRegion = document.getElementById('resultRegion');

  if (!form || !urlInput || !resultRegion) {
    return;
  }

  const initialMarkup = resultRegion.innerHTML;

  function isLikelyUrl(value) {
    try {
      const parsed = new URL(value);
      return /^https?:$/i.test(parsed.protocol);
    } catch (err) {
      return false;
    }
  }

  function markUrlInvalid() {
    urlInput.classList.add('is-invalid');
    setTimeout(() => urlInput.classList.remove('is-invalid'), 900);
    urlInput.focus();
  }

  function resetResultRegion() {
    resultRegion.innerHTML = initialMarkup;
    resultRegion.dataset.state = 'hint';
  }

  clearBtn?.addEventListener('click', () => {
    form.reset();
    resetResultRegion();
    urlInput.focus();
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-reset-feed]') : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    resetResultRegion();
    urlInput.focus();
  });

  document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-copy-text]') : null;
    if (!trigger) {
      return;
    }
    event.preventDefault();
    const text = trigger.getAttribute('data-copy-text') || '';
    if (!text) {
      return;
    }

    const fallbackCopy = () => {
      const dummy = document.createElement('textarea');
      dummy.value = text;
      dummy.setAttribute('readonly', 'readonly');
      dummy.style.position = 'absolute';
      dummy.style.left = '-9999px';
      document.body.appendChild(dummy);
      dummy.select();
      try {
        document.execCommand('copy');
        flashCopied(trigger);
      } catch (err) {
        console.warn('Copy failed', err);
      }
      document.body.removeChild(dummy);
    };

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(text)
        .then(() => flashCopied(trigger))
        .catch(() => fallbackCopy());
    } else {
      fallbackCopy();
    }
  });

  function flashCopied(button) {
    const defaultLabel = button.getAttribute('data-copy-label') || 'Copy';
    const doneLabel = button.getAttribute('data-copy-done-label') || 'Copied!';
    button.classList.add('copy-ok');
    button.setAttribute('aria-live', 'polite');
    button.textContent = doneLabel;
    setTimeout(() => {
      button.classList.remove('copy-ok');
      button.textContent = defaultLabel;
    }, 1400);
  }

  form.addEventListener('submit', (event) => {
    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
      return;
    }
    const raw = urlInput.value.trim();
    if (!isLikelyUrl(raw)) {
      event.preventDefault();
      markUrlInvalid();
    }
  });

  if (window.htmx) {
    htmx.on('htmx:beforeRequest', (evt) => {
      if (evt.target !== form) {
        return;
      }
      if (!form.checkValidity()) {
        evt.preventDefault();
        form.reportValidity();
        return;
      }
      const raw = urlInput.value.trim();
      if (!isLikelyUrl(raw)) {
        evt.preventDefault();
        markUrlInvalid();
      }
    });

    htmx.on('htmx:afterSwap', (evt) => {
      if (evt.detail.target !== resultRegion) {
        return;
      }
      resultRegion.dataset.state = 'result';
      const firstFocusable = resultRegion.querySelector('[data-focus-target], a, button');
      if (firstFocusable instanceof HTMLElement) {
        firstFocusable.focus({ preventScroll: true });
      }
    });
  }
})();
