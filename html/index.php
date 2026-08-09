<?php
// html/index.php
require_once 'includes/header.php';

// ============================================================
// LEER TODO DESDE CACHÉ — Sin tocar la API ni el disco
// ============================================================

// Películas sin español (1 consulta SQL)
$incompleteMovies = $pdo->query("
    SELECT * FROM media_cache
    WHERE type='movie' AND has_spanish=0 AND has_file=1
    ORDER BY title ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalMovies = $pdo->query("SELECT COUNT(*) FROM media_cache WHERE type='movie' AND has_file=1")->fetchColumn();

// Series: agrupar con conteo de episodios sin español (1 consulta SQL con subquery)
$seriesWithMissing = $pdo->query("
    SELECT
        s.id,
        s.title,
        s.poster_url AS poster,
        COUNT(e.id)  AS missing_count
    FROM media_cache s
    LEFT JOIN media_cache e ON e.series_id = s.id AND e.type='episode' AND e.has_spanish=0 AND e.has_file=1
    WHERE s.type='series'
    GROUP BY s.id, s.title, s.poster_url
    HAVING missing_count > 0
    ORDER BY s.title ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalSeries = $pdo->query("
    SELECT COUNT(*) FROM media_cache s
    WHERE s.type='series'
      AND EXISTS (
          SELECT 1 FROM media_cache e
          WHERE e.series_id = s.id AND e.type='episode' AND e.has_file=1
      )
")->fetchColumn();

// Última actualización del caché
$lastUpdate = $pdo->query("SELECT MAX(updated_at) FROM media_cache")->fetchColumn();
$cacheEmpty = ($totalMovies == 0 && $totalSeries == 0);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fa fa-dashboard text-primary"></i> Dashboard</h2>
    <?php if ($lastUpdate): ?>
        <span class="badge bg-dark border border-secondary text-muted" style="font-size:0.7rem">
            <i class="fa fa-clock-o me-1"></i>Caché actualizado: <?= (new DateTime($lastUpdate))->format('d/m H:i') ?>
        </span>
    <?php endif; ?>
</div>

<?php if ($cacheEmpty): ?>
    <div class="alert alert-info d-flex align-items-center gap-3">
        <i class="fa fa-database fa-2x"></i>
        <div>
            <strong>Caché de medios vacío.</strong> Aún no se ha configurado el servidor o realizado el primer escaneo.<br>
            <button class="btn btn-sm btn-gradient mt-2" onclick="window.location.href='settings.php'">
                <i class="fa fa-cogs me-1"></i>Ir a Configuración
            </button>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Columna Películas -->
    <div class="col-md-6">
        <div class="card glass-card h-100">
            <div class="card-header border-bottom border-secondary d-flex justify-content-between align-items-center bg-transparent">
                <h4 class="mb-0 text-info"><i class="fa fa-film"></i> Películas</h4>
                <div>
                    <span class="badge bg-secondary me-2">Total: <?= $totalMovies ?></span>
                    <span class="badge bg-warning text-dark">Faltan ES: <?= count($incompleteMovies) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <tbody>
                            <?php foreach ($incompleteMovies as $movie): ?>
                                <?php
                                    $title   = htmlspecialchars($movie['title']);
                                    $year    = htmlspecialchars($movie['year'] ?? '');
                                    $poster  = $movie['poster_url'] ?: 'https://via.placeholder.com/150x225?text=No+Poster';
                                    $mediaId = $movie['id'];
                                ?>
                                <tr style="cursor: pointer;" onclick="window.location.href='subtitles.php?type=movies&id=<?= $mediaId ?>'" class="table-row-hover">
                                    <td style="width: 60px; padding: 5px;">
                                        <img src="<?= $poster ?>" alt="Poster" class="img-fluid rounded" style="width: 45px; height: 65px; object-fit: cover;" onerror="this.style.display='none'">
                                    </td>
                                    <td>
                                        <h6 class="mb-1"><?= $title ?></h6>
                                        <span class="text-muted small"><i class="fa fa-calendar"></i> <?= $year ?></span>
                                    </td>
                                    <td class="text-end pe-3 text-muted">
                                        <span class="badge bg-danger small">Falta ES</span>
                                        <i class="fa fa-chevron-right ms-2"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($incompleteMovies) && !$cacheEmpty): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5 text-muted">
                                        <i class="fa fa-check-circle-o fa-3x mb-3 text-success"></i><br>Todas las películas tienen subtítulos en español.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Columna Series -->
    <div class="col-md-6">
        <div class="card glass-card h-100">
            <div class="card-header border-bottom border-secondary d-flex justify-content-between align-items-center bg-transparent">
                <h4 class="mb-0 text-success"><i class="fa fa-tv"></i> Series</h4>
                <div>
                    <span class="badge bg-secondary me-2">Total: <?= $totalSeries ?></span>
                    <span class="badge bg-warning text-dark">Faltan: <?= count($seriesWithMissing) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <tbody>
                            <?php foreach ($seriesWithMissing as $s): ?>
                                <?php
                                    $title   = htmlspecialchars($s['title']);
                                    $poster  = $s['poster'] ?: 'https://via.placeholder.com/150x225?text=No+Poster';
                                    $mediaId = $s['id'];
                                    $missing = (int)$s['missing_count'];
                                ?>
                                <tr style="cursor: pointer;" onclick="window.location.href='subtitles.php?type=series&id=<?= $mediaId ?>'" class="table-row-hover">
                                    <td style="width: 60px; padding: 5px;">
                                        <img src="<?= $poster ?>" alt="Poster" class="img-fluid rounded" style="width: 45px; height: 65px; object-fit: cover;" onerror="this.style.display='none'">
                                    </td>
                                    <td>
                                        <h6 class="mb-1"><?= $title ?></h6>
                                        <span class="text-muted small"><i class="fa fa-exclamation-circle"></i> Traducción requerida</span>
                                    </td>
                                    <td class="text-end pe-3 text-muted">
                                        <span class="badge bg-danger"><?= $missing ?> episodios faltan ES</span>
                                        <i class="fa fa-chevron-right ms-2"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($seriesWithMissing) && !$cacheEmpty): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5 text-muted">
                                        <i class="fa fa-check-circle-o fa-3x mb-3 text-success"></i><br>Todas las series tienen subtítulos en español.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if (empty($seriesWithMissing) && $cacheEmpty): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5 text-muted">
                                        <i class="fa fa-folder-open-o fa-3x mb-3"></i><br>Sin datos en caché.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.table-row-hover:hover { background-color: rgba(255,255,255,0.05); }
</style>

<?php require_once 'includes/footer.php'; ?>
