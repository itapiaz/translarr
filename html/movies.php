<?php
// html/movies.php
require_once 'includes/header.php';

// Leer desde la caché de la BD (instantáneo).
// Se muestran también las marcadas como "No monitorizar" (con badge) para poder revertirlas.
$movies = $pdo->query("SELECT * FROM movies WHERE has_file=1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$cacheEmpty = empty($movies);

// Última actualización del caché
$lastUpdate = $pdo->query("
    SELECT MAX(updated_at) FROM movies WHERE has_file=1
")->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fa fa-film text-primary"></i> Películas</h2>
    <div class="d-flex align-items-center gap-2">
        <?php if ($lastUpdate): ?>
            <span class="badge bg-dark border border-secondary text-muted" style="font-size:0.7rem">
                <i class="fa fa-clock-o me-1"></i>Actualizado <?= (new DateTime($lastUpdate))->format('d/m H:i') ?>
            </span>
        <?php endif; ?>
        <span class="badge bg-secondary" id="movies-count"><?= count($movies) ?> Encontradas</span>
    </div>
</div>

<div class="mb-4">
    <div class="input-group">
        <span class="input-group-text" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.15);color:#aaa;">
            <i class="fa fa-search"></i>
        </span>
        <input type="text" id="movies-search" class="form-control" placeholder="Buscar película..."
               style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.15);color:#fff;"
               oninput="filterMovies(this.value)" autocomplete="off">
        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('movies-search').value='';filterMovies('');"
                style="border-color:rgba(255,255,255,0.15);color:#aaa;" title="Limpiar">
            <i class="fa fa-times"></i>
        </button>
    </div>
</div>

<?php if ($cacheEmpty): ?>
    <div class="alert alert-info d-flex align-items-center gap-3">
        <i class="fa fa-database fa-2x"></i>
        <div>
            <strong>Caché de medios vacío.</strong> No se han escaneado películas todavía.<br>
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
                    <?php foreach ($movies as $movie): ?>
                        <?php 
                            $title = htmlspecialchars($movie['title']);
                            $year = htmlspecialchars($movie['year']);
                            $poster  = $movie['poster_url'] ?: 'https://via.placeholder.com/150x225?text=No+Poster';
                            $overview = htmlspecialchars($movie['overview'] ?? '');
                            $mediaId = $movie['id'];
                        ?>
                        <tr style="cursor: pointer;" onclick="window.location.href='subtitles.php?type=movies&id=<?= $mediaId ?>'" class="table-row-hover movie-row" data-title="<?= strtolower($title) ?>">
                            <td style="width: 80px;">
                                <img src="<?= $poster ?>" alt="Poster" class="img-fluid rounded" style="width: 60px; height: 90px; object-fit: cover;" onerror="this.style.display='none'">
                            </td>
                            <td>
                                <h5 class="mb-1"><?= $title ?>
                                    <?php if ((int)$movie['is_ignored'] === 1): ?>
                                        <span class="badge bg-secondary ms-2" title="No monitorizada"><i class="fa fa-pause me-1"></i>No monitorizada</span>
                                    <?php endif; ?>
                                </h5>
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
                    <?php if (empty($movies) && !$error): ?>
                        <tr>
                            <td colspan="3" class="text-center py-5 text-muted">
                                <i class="fa fa-folder-open-o fa-3x mb-3"></i><br>No se encontraron películas.
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
function filterMovies(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('.movie-row');
    let visible = 0;
    rows.forEach(row => {
        const title = row.dataset.title || '';
        const show = q === '' || title.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('movies-count').textContent = visible + ' Encontradas';
    const noResults = document.getElementById('movies-no-results');
    if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
}
</script>
