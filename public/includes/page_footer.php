<?php
/** Shared footer for SimpleFeedMaker */
?>
  <footer class="border-top bg-body mt-5">
    <div class="container py-4 small text-secondary text-center d-flex flex-column flex-sm-row justify-content-center align-items-center gap-2">
      <span>© <?= date('Y'); ?> SimpleFeedMaker. Fast, clean, and reliable.</span>
      <span class="d-none d-sm-inline">•</span>
      <span class="d-flex gap-3">
        <a class="text-secondary text-decoration-none" href="/about/">About</a>
        <a class="text-secondary text-decoration-none" href="/faq/">FAQ</a>
        <a class="text-secondary text-decoration-none" href="/privacy/">Privacy</a>
      </span>
    </div>
  </footer>

  <!-- Bootstrap (defer JS parse) -->
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous" defer></script>
</body>
</html>
