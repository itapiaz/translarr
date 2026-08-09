<?php
// html/series.php
require_once 'includes/header.php';

// Leer desde la caché de la BD (instantáneo)
$series = $pdo->query("
    SELECT s.* FROM series s
    WHERE EXISTS (
        SELECT 1 FROM episodes e
        WHERE e.series_id = s.id AND e.has_file=1
    )
    ORDER BY s.title ASC
")->fetchAll(PDO::FETCH_ASSOC);
$cacheEmpty = empty($series);

// Última actualización del caché
$lastUpdate = $pdo->query("
    SELECT MAX(updated_at) FROM series
")->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fa fa-tv text-primary"></i> Series</h2>
    <div class="d-flex align-items-center gap-2">
        <?php if ($lastUpdate): ?>
            <span class="badge bg-dark border border-secondary text-muted" style="font-size:0.7rem">
                <i class="fa fa-clock-o me-1"></i>Actualizado <?= (new DateTime($lastUpdate))->format('d/m H:i') ?>
            </span>
        <?php endif; ?>
        <span class="badge bg-secondary" id="series-count"><?= count($series) ?> Encontradas</span>
    </div>
</div>

<div class="mb-4">
    <div class="input-group">
        <span class="input-group-text" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.15);color:#aaa;">
            <i class="fa fa-search"></i>
        </span>
        <input type="text" id="series-search" class="form-control" placeholder="Buscar serie..."
               style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.15);color:#fff;"
               oninput="filterSeries(this.value)" autocomplete="off">
        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('series-search').value='';filterSeries('');"
                style="border-color:rgba(255,255,255,0.15);color:#aaa;" title="Limpiar">
            <i class="fa fa-times"></i>
        </button>
    </div>
</div>

<?php if ($cacheEmpty): ?>
    <div class="alert alert-info d-flex align-items-center gap-3">
        <i class="fa fa-database fa-2x"></i>
        <div>
            <strong>Caché de medios vacío.</strong> No se han escaneado series todavía.<br>
            <button class="btn btn-sm btn-gradient mt-2" onclick="triggerScan()">
                <i class="fa fa-refresh me-1"></i>Escanear ahora
            </button>
        </div>
    </div>
<?php endif; ?>

<div class="card glass-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <tbody>
                    <?php foreach ($series as $s): ?>
                        <?php 
                            $title = htmlspecialchars($s['title']);
                            $year = htmlspecialchars($s['year']);
                            $poster = $s['poster_url'] ?: 'https://via.placeholder.com/150x225?text=No+Poster';
                            $overview = htmlspecialchars($s['overview'] ?? '');
                            $mediaId = $s['id'];
                        ?>
                        <tr style="cursor: pointer;" onclick="window.location.href='subtitles.php?type=series&id=<?= $mediaId ?>'" class="table-row-hover series-row" data-title="<?= strtolower($title) ?>">
                            <td style="width: 80px;">
                                <img src="<?= $poster ?>" alt="Poster" class="img-fluid rounded" style="width: 60px; height: 90px; object-fit: cover;" onerror="this.style.display='none'">
                            </td>
                            <td>
                                <h5 class="mb-1"><?= $title ?></h5>
                                <span class="text-muted"><i class="fa fa-calendar"></i> <?= $year ?></span>
                                <?php if ($overview): ?>
                                    <div class="text-muted small mt-1" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= $overview ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 text-muted" style="vertical-align:middle;">
                                <i class="fa fa-chevron-right"></i>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($series) && !$error): ?>
                        <tr>
                            <td colspan="3" class="text-center py-5 text-muted">
                                <i class="fa fa-folder-open-o fa-3x mb-3"></i><br>No se encontraron series.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
function filterSeries(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('.series-row');
    let visible = 0;
    rows.forEach(row => {
        const title = row.dataset.title || '';
        const show = q === '' || title.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('series-count').textContent = visible + ' Encontradas';
}
</script>
