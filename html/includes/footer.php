<?php
// html/includes/footer.php
?>
    </div> <!-- /container -->
</div> <!-- /main-content -->

<footer class="text-center py-4 mt-auto" style="border-top: 1px solid rgba(255,255,255,0.1); background: rgba(18,18,18,0.8);">
    <div class="container">
        <p class="mb-0 text-muted"><small>&copy; <?= date('Y') ?> <?= APP_NAME ?>. Todos los derechos reservados.</small></p>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Scripts personalizados (si aplican) -->
<script>
    // Inicializar tooltips de Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>
</body>
</html>
