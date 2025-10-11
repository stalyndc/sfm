<?php
/** Shared site header */
$activeNav = $activeNav ?? '';
?>
<body>
  <header class="border-bottom bg-body">
    <div class="container d-flex align-items-center justify-content-between py-3 flex-wrap gap-2">
      <a href="/" class="text-decoration-none text-body site-title">SimpleFeedMaker</a>
      <div class="d-flex align-items-center gap-3">
        <nav class="site-nav d-flex gap-3 small">
          <a class="text-secondary text-decoration-none<?= $activeNav === 'blog' ? ' active' : ''; ?>" href="/blog/">Blog</a>
          <a class="text-secondary text-decoration-none<?= $activeNav === 'about' ? ' active' : ''; ?>" href="/about/">About</a>
          <a class="text-secondary text-decoration-none<?= $activeNav === 'faq' ? ' active' : ''; ?>" href="/faq/">FAQ</a>
        </nav>
        <button id="themeToggle" type="button" class="btn btn-outline-secondary btn-icon" role="switch" aria-checked="false" aria-label="Switch theme" title="Switch theme">
          <span class="theme-toggle-icon" aria-hidden="true">🌙</span>
        </button>
      </div>
    </div>
  </header>
