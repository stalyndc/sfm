<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/jobs.php';

sfm_admin_boot();

if (!sfm_admin_is_logged_in()) {
    header('Location: /admin/?logged-out=1');
    exit;
}

$pageTitle      = 'Selector Playground — SimpleFeedMaker';
$metaRobots     = 'noindex, nofollow';
$structuredData = [];

require __DIR__ . '/../includes/page_head.php';
require __DIR__ . '/../includes/page_header.php';
?>
<main class="py-4">
  <div class="container" style="max-width: 960px;">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
      <div>
        <h1 class="h4 fw-bold mb-1">Selector playground</h1>
        <p class="text-secondary mb-0">Test CSS selectors against a page before saving them to a job.</p>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/admin/">Back to jobs</a>
        <a class="btn btn-outline-secondary" href="/admin/?logout=1">Sign out</a>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <form id="selectorForm" class="vstack gap-3">
          <?= csrf_input(); ?>
          <div>
            <label for="sourceUrl" class="form-label">Source URL</label>
            <input type="url" class="form-control" id="sourceUrl" name="source_url" placeholder="https://example.com/news" required>
            <div class="form-text">We fetch the page once and run the extractor locally.</div>
          </div>
          <div class="row g-3">
            <div class="col-12 col-lg-4">
              <label for="itemSelector" class="form-label">Item selector</label>
              <input type="text" class="form-control" id="itemSelector" name="item_selector" placeholder="article.card">
              <div class="form-text">CSS to find each item container.</div>
            </div>
            <div class="col-12 col-lg-4">
              <label for="titleSelector" class="form-label">Title selector</label>
              <input type="text" class="form-control" id="titleSelector" name="title_selector" placeholder="h2 a">
              <div class="form-text">Optional. Scoped within each item.</div>
            </div>
            <div class="col-12 col-lg-4">
              <label for="summarySelector" class="form-label">Summary selector</label>
              <input type="text" class="form-control" id="summarySelector" name="summary_selector" placeholder="p.summary">
              <div class="form-text">Optional description/summary selector.</div>
            </div>
          </div>
          <div class="col-12 col-md-4 col-lg-3">
            <label for="limit" class="form-label">Preview items</label>
            <input type="number" class="form-control" id="limit" name="limit" min="1" max="20" value="5">
          </div>
          <div class="d-flex flex-column flex-sm-row gap-2 align-items-start">
            <button type="submit" class="btn btn-primary" id="runTestBtn">Run test</button>
            <button type="button" class="btn btn-outline-secondary" id="resetBtn">Clear</button>
          </div>
        </form>
      </div>
    </div>

    <div id="selectorOutput" class="mb-4"></div>
  </div>
</main>

<script>
(function() {
  const form = document.getElementById('selectorForm');
  const output = document.getElementById('selectorOutput');
  const runBtn = document.getElementById('runTestBtn');
  const resetBtn = document.getElementById('resetBtn');

  if (!form || !output) return;

  const toParams = (form) => {
    const data = new FormData(form);
    const params = new URLSearchParams();
    for (const [key, value] of data.entries()) {
      params.append(key, value.toString());
    }
    return params;
  };

  const renderItems = (items, debug) => {
    let html = '';
    html += '<div class="card shadow-sm">';
    html += '<div class="card-body">';
    html += '<h2 class="h5 fw-semibold mb-3">Preview</h2>';

    if (!items.length) {
      html += '<div class="alert alert-warning mb-0">No items matched the current selectors.</div>';
    } else {
      html += '<div class="vstack gap-3">';
      items.forEach((item) => {
        html += '<div class="p-3 border rounded">';
        html += '<div class="fw-semibold">' + (item.title ? escapeHtml(item.title) : 'Untitled') + '</div>';
        if (item.link) {
          html += '<div class="small text-secondary"><a href="' + escapeHtml(item.link) + '" target="_blank" rel="noopener">' + escapeHtml(item.link) + '</a></div>';
        }
        if (item.summary) {
          html += '<div class="small mt-2">' + escapeHtml(item.summary) + '</div>';
        }
        html += '</div>';
      });
      html += '</div>';
    }

    if (debug && Object.keys(debug).length) {
      html += '<hr class="my-4">';
      html += '<pre class="small bg-body-tertiary p-3 rounded overflow-auto">' + escapeHtml(JSON.stringify(debug, null, 2)) + '</pre>';
    }

    html += '</div></div>';
    output.innerHTML = html;
  };

  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, function (ch) {
    switch (ch) {
      case '&': return '&amp;';
      case '<': return '&lt;';
      case '>': return '&gt;';
      case '"': return '&quot;';
      case "'": return '&#39;';
      default: return ch;
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    output.innerHTML = '';

    const params = toParams(form);
    runBtn.disabled = true;
    runBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing…';

    try {
      const response = await fetch('/admin/selector_test.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: params.toString(),
      });

      const payload = await response.json();
      if (!payload.ok) {
        output.innerHTML = '<div class="alert alert-danger">' + escapeHtml(payload.error || 'Selector test failed.') + '</div>';
        return;
      }

      renderItems(payload.items || [], payload.debug || {});
    } catch (err) {
      output.innerHTML = '<div class="alert alert-danger">' + escapeHtml(err && err.message ? err.message : String(err)) + '</div>';
    } finally {
      runBtn.disabled = false;
      runBtn.innerHTML = 'Run test';
    }
  });

  resetBtn.addEventListener('click', () => {
    form.reset();
    output.innerHTML = '';
  });
})();
</script>

<?php require __DIR__ . '/../includes/page_footer.php';
