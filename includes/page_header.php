<?php
/** Shared site header */
$activeNav = $activeNav ?? '';
?>
<body>
  <header class="border-bottom bg-body">
    <div class="container d-flex align-items-center justify-content-between py-3 flex-wrap gap-2">
      <a href="/" class="text-decoration-none text-body site-title fs-4">SimpleFeedMaker</a>
      <nav class="site-nav d-flex gap-3 small">
        <a class="text-secondary text-decoration-none<?= $activeNav === 'blog' ? ' active' : ''; ?>" href="/blog/">Blog</a>
        <a class="text-secondary text-decoration-none<?= $activeNav === 'about' ? ' active' : ''; ?>" href="/about/">About</a>
        <a class="text-secondary text-decoration-none<?= $activeNav === 'faq' ? ' active' : ''; ?>" href="/faq/">FAQ</a>
        <a class="text-secondary text-decoration-none<?= $activeNav === 'privacy' ? ' active' : ''; ?>" href="/privacy/">Privacy</a>
      </nav>
    </div>
  </header>
