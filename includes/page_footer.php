<?php
/** Shared footer for SimpleFeedMaker */
?>
  <footer class="border-top bg-body mt-5">
    <div class="container py-4 small text-secondary">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <span>© <?= date('Y'); ?> SimpleFeedMaker. Fast, clean, and reliable.</span>
        <nav class="d-flex gap-3">
          <a class="text-secondary text-decoration-none" href="/privacy/"><i class="bi bi-shield-check me-1"></i>Privacy</a>
          <a class="text-secondary text-decoration-none" href="/terms/"><i class="bi bi-file-text me-1"></i>Terms of Use</a>
        </nav>
      </div>
    </div>
  </footer>

  <script src="/assets/js/theme.js" defer></script>
  <!-- Bootstrap (defer JS parse) -->
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous" defer></script>
</body>
</html>
