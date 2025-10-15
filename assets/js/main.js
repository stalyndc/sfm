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

  const hasForm = Boolean(form && urlInput);
  const initialMarkup = (resultRegion && resultRegion.querySelector('[data-result-hint]'))
    ? resultRegion.innerHTML
    : '';

  const startButtonBusy = () => {
    const generateBtn = document.getElementById('generateBtn');
    if (generateBtn) {
      generateBtn.classList.add('is-busy');
      generateBtn.setAttribute('aria-busy', 'true');
      const label = generateBtn.querySelector('.btn-label');
      if (label) {
        label.dataset.original = label.textContent || 'Generate feed';
        label.textContent = 'Working…';
      }
    }
  };

  const stopButtonBusy = () => {
    const generateBtn = document.getElementById('generateBtn');
    if (generateBtn) {
      generateBtn.classList.remove('is-busy');
      generateBtn.removeAttribute('aria-busy');
      const label = generateBtn.querySelector('.btn-label');
      if (label && label.dataset.original) {
        label.textContent = label.dataset.original;
        delete label.dataset.original;
      }
    }
  };

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
    if (!resultRegion) {
      return;
    }
    if (initialMarkup) {
      resultRegion.innerHTML = initialMarkup;
      resultRegion.dataset.state = 'hint';
      return;
    }
    resultRegion.innerHTML = '';
  }

  if (hasForm) {
    clearBtn?.addEventListener('click', () => {
      form.reset();
      if (initialMarkup) {
        resetResultRegion();
      }
      urlInput.focus();
      stopButtonBusy();
    });
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-reset-feed]') : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    if (hasForm && initialMarkup) {
      form.reset();
      resetResultRegion();
      urlInput.focus();
      return;
    }
    const redirect = target.getAttribute('data-reset-url') || '/';
    window.location.href = redirect;
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

  if (hasForm) {
    const getCsrfField = () => {
      const field = form.querySelector('input[name="csrf_token"]');
      return field instanceof HTMLInputElement ? field : null;
    };

    const applyCsrfToken = (token) => {
      if (typeof token !== 'string' || token === '') {
        return;
      }
      const field = getCsrfField();
      if (field && field.value !== token) {
        field.value = token;
      }
    };

    const syncCsrfTokenFromXhr = (xhr) => {
      if (!xhr || typeof xhr.getResponseHeader !== 'function') {
        return null;
      }
      const nextToken = xhr.getResponseHeader('X-CSRF-Token');
      if (typeof nextToken === 'string' && nextToken !== '') {
        applyCsrfToken(nextToken);
        return nextToken;
      }
      return null;
    };

    let csrfRetryCount = 0;
    let csrfAutoResubmitting = false;

    const resetCsrfRetryState = () => {
      csrfRetryCount = 0;
      csrfAutoResubmitting = false;
    };

    const attemptCsrfAutoResubmit = (xhr) => {
      if (!window.htmx || !xhr || xhr.status !== 403) {
        return false;
      }
      const refreshed = syncCsrfTokenFromXhr(xhr);
      if (!refreshed || csrfRetryCount > 0) {
        return false;
      }
      csrfRetryCount = 1;
      csrfAutoResubmitting = true;
      window.setTimeout(() => {
        window.htmx.trigger(form, 'submit');
      }, 0);
      return true;
    };

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
        return;
      }
      startButtonBusy();
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
          return;
        }
        if (csrfAutoResubmitting) {
          csrfAutoResubmitting = false;
        } else {
          csrfRetryCount = 0;
        }
        startButtonBusy();
      });

      htmx.on('htmx:afterSwap', (evt) => {
        if (evt.detail.target !== resultRegion) {
          return;
        }
        stopButtonBusy();
        resetCsrfRetryState();
        resultRegion.dataset.state = 'result';
        const firstFocusable = resultRegion.querySelector('[data-focus-target], a, button');
        if (firstFocusable instanceof HTMLElement) {
          firstFocusable.focus({ preventScroll: true });
        }
      });

      htmx.on('htmx:afterRequest', (evt) => {
        if (evt.detail && evt.detail.xhr) {
          syncCsrfTokenFromXhr(evt.detail.xhr);
        }
        if (evt.target === form && evt.detail && evt.detail.successful) {
          resetCsrfRetryState();
        }
      });

      htmx.on('htmx:sendError', (evt) => {
        if (evt.target === form) {
          resetCsrfRetryState();
        }
        stopButtonBusy();
      });

      htmx.on('htmx:responseError', (evt) => {
        const xhr = evt.detail ? evt.detail.xhr : null;
        if (evt.target === form && attemptCsrfAutoResubmit(xhr)) {
          return;
        }
        if (xhr) {
          syncCsrfTokenFromXhr(xhr);
        }
        if (evt.target === form) {
          resetCsrfRetryState();
        }
        stopButtonBusy();
      });
    }
  }

  if (window.htmx) {
    const markRefreshBusy = (evt) => {
      const trigger = evt.target instanceof Element ? evt.target.closest('.btn-refresh') : null;
      if (!trigger) {
        return;
      }
      if (!trigger.dataset.originalLabel) {
        trigger.dataset.originalLabel = trigger.textContent.trim();
      }
      const busyLabel = trigger.getAttribute('data-refreshing-label') || 'Refreshing…';
      trigger.textContent = busyLabel;
      trigger.classList.add('is-busy');
    };

    const clearRefreshBusy = (evt) => {
      const trigger = evt.target instanceof Element ? evt.target.closest('.btn-refresh') : null;
      if (!trigger) {
        return;
      }
      const original = trigger.dataset.originalLabel;
      if (original) {
        trigger.textContent = original;
        delete trigger.dataset.originalLabel;
      }
      trigger.classList.remove('is-busy');
    };

    htmx.on('htmx:beforeRequest', markRefreshBusy);
    htmx.on('htmx:afterRequest', clearRefreshBusy);
    htmx.on('htmx:sendError', clearRefreshBusy);
    htmx.on('htmx:responseError', clearRefreshBusy);
  }
})();
