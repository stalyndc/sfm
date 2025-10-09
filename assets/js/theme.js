(() => {
  const storageKey = 'sfm-theme';

  const safeStorage = {
    get(key) {
      try {
        return window.localStorage?.getItem(key) ?? null;
      } catch (err) {
        console.warn('localStorage unavailable', err);
        return null;
      }
    },
    set(key, value) {
      try {
        window.localStorage?.setItem(key, value);
      } catch (err) {
        console.warn('Failed to persist preference', err);
      }
    },
  };

  function updateToggle(theme) {
    const toggle = document.querySelector('#themeToggle');
    if (!toggle) return;
    const next = theme === 'dark' ? 'light' : 'dark';
    const icon = theme === 'dark' ? '☀️' : '🌙';
    toggle.dataset.theme = theme;
    const label = `Switch to ${next} mode`;
    toggle.title = label;
    toggle.setAttribute('aria-label', label);
    toggle.setAttribute('aria-checked', theme === 'light' ? 'true' : 'false');
    const iconSpan = toggle.querySelector('.theme-toggle-icon');
    if (iconSpan) iconSpan.textContent = icon;
  }

  function syncThemeColor(theme) {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    const color = theme === 'light' ? '#f6f8fc' : '#0b1320';
    meta.setAttribute('content', color);
  }

  function applyTheme(theme, persist = true) {
    const normalized = (theme === 'light' || theme === 'dark') ? theme : 'dark';
    document.documentElement.setAttribute('data-bs-theme', normalized);
    updateToggle(normalized);
    syncThemeColor(normalized);
    if (persist) safeStorage.set(storageKey, normalized);
  }

  function resolveInitialTheme() {
    const stored = safeStorage.get(storageKey);
    if (stored === 'light' || stored === 'dark') return stored;
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
      return 'light';
    }
    return 'dark';
  }

  function init() {
    const toggle = document.querySelector('#themeToggle');
    const initialTheme = document.documentElement.getAttribute('data-bs-theme') || resolveInitialTheme();
    applyTheme(initialTheme, false);

    toggle?.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-bs-theme');
      const next = current === 'light' ? 'dark' : 'light';
      applyTheme(next);
    });

    if (window.matchMedia) {
      try {
        const media = window.matchMedia('(prefers-color-scheme: light)');
        const sync = (event) => {
          const stored = safeStorage.get(storageKey);
          if (stored === 'light' || stored === 'dark') return;
          applyTheme(event.matches ? 'light' : 'dark', false);
        };
        if (typeof media.addEventListener === 'function') {
          media.addEventListener('change', sync);
        } else if (typeof media.addListener === 'function') {
          media.addListener(sync);
        }
      } catch (err) {
        console.warn('Unable to observe color-scheme preference', err);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
