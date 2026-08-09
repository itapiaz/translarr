<?php
// html/subtitles.php — Lee del caché, carga subtítulos bajo demanda vía AJAX
require_once 'includes/header.php';

$type = $_GET['type'] ?? 'movies';
$id   = $_GET['id']   ?? null;
$error = null;

if (!$id) {
    echo "<div class='alert alert-danger'>ID no proporcionado o inválido.</div>";
    require_once 'includes/footer.php';
    exit;
}

// ============================================================
// Leer desde caché — sin API ni disco
// ============================================================
$mediaTitle = 'Detalles de Subtítulos';

if ($type === 'series') {
    // Datos completos de la serie
    $row = $pdo->prepare("SELECT * FROM media_cache WHERE id=? AND type='series'");
    $row->execute([$id]);
    $seriesRow = $row->fetch(PDO::FETCH_ASSOC);
    if ($seriesRow) $mediaTitle = $seriesRow['title'];

    // Todos los episodios agrupados por temporada
    $stmt = $pdo->prepare("
        SELECT id, title, season, episode, has_spanish, subtitle_path, subtitle_lang
        FROM media_cache
        WHERE series_id=? AND type='episode'
        ORDER BY season ASC, episode ASC
    ");
    $stmt->execute([$id]);
    $allEpisodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seasons = [];
    foreach ($allEpisodes as $ep) {
        $seasons[(int)($ep['season'] ?? 0)][] = $ep;
    }
    ksort($seasons);

} else {
    // Película: obtener de caché
    $row = $pdo->prepare("SELECT * FROM media_cache WHERE id=? AND type='movie'");
    $row->execute([$id]);
    $movieRow = $row->fetch(PDO::FETCH_ASSOC);
    if ($movieRow) $mediaTitle = $movieRow['title'];
}
?>

<?php
$itemData = $type === 'series' ? $seriesRow : $movieRow;
$posterUrl = $itemData['poster_url'] ?? '';
$year = $itemData['year'] ?? '';
$overview = $itemData['overview'] ?? 'Sin descripción disponible. Por favor, ejecuta un Escaneo Manual de medios para obtener esta información.';
$folderPath = $itemData['folder_path'] ?? 'Ruta no disponible. Por favor, realiza un escaneo.';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0 text-muted"><i class="fa fa-closed-captioning text-primary me-2"></i> Gestión de Subtítulos</h2>
    <a href="javascript:history.back()" class="btn btn-outline-light btn-sm"><i class="fa fa-arrow-left"></i> Volver</a>
</div>

<div class="card glass-card mb-4" style="background: rgba(20,20,25,0.7); border: 1px solid rgba(255,255,255,0.1);">
    <div class="card-body d-flex flex-column flex-md-row gap-4">
        <?php if ($posterUrl): ?>
            <div style="flex-shrink: 0; width: 140px; text-align: center;">
                <img src="<?= htmlspecialchars($posterUrl) ?>" class="img-fluid rounded shadow-sm" alt="Poster" style="max-height: 210px; object-fit: cover;">
            </div>
        <?php endif; ?>
        <div class="flex-grow-1">
            <h2 class="mb-2 fw-bold text-white">
                <?= htmlspecialchars($mediaTitle) ?>
                <?php if ($year): ?>
                    <span class="badge bg-secondary ms-2" style="font-size: 0.45em; vertical-align: middle;"><?= htmlspecialchars($year) ?></span>
                <?php endif; ?>
            </h2>
            <div class="text-info mb-3 small font-monospace bg-dark p-2 rounded d-inline-block border border-info border-opacity-25">
                <i class="fa fa-folder-open me-2"></i><?= htmlspecialchars($folderPath) ?>
            </div>
            <p class="text-light text-opacity-75 mb-0" style="font-size: 0.95rem; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; text-align: justify;">
                <?= htmlspecialchars($overview) ?>
            </p>
            <?php if ($type === 'series'): ?>
                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-info" onclick="confirmTranslateAll('<?= htmlspecialchars($id) ?>', '<?= htmlspecialchars($type) ?>')">
                        <i class="fa fa-language me-1"></i> Traducir toda la serie
                    </button>
                    <small class="text-muted ms-2">Solo episodios con subtítulo en inglés y sin español</small>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($type === 'movies' && $movieRow): ?>
    <!-- ===== PELÍCULA ===== -->
    <div class="card glass-card">
        <div class="card-body">
            <!-- Subtítulos cargados via AJAX -->
            <div id="subs-movie-<?= htmlspecialchars($id) ?>" class="subs-lazy" data-ep-id="<?= htmlspecialchars($id) ?>" data-type="movies">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-info"></div>
                    <span class="ms-2 text-muted small">Cargando subtítulos...</span>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($type === 'series' && !empty($seasons)): ?>
    <!-- ===== SERIES: Acordeón de temporadas, episodios bajo demanda ===== -->
    <div class="accordion" id="seasonsAccordion">
        <?php foreach ($seasons as $seasonNum => $episodes): ?>
            <?php
                $label = $seasonNum > 0 ? 'Temporada ' . $seasonNum : 'Especiales';
                $epCount = count($episodes);
                $missingCount = count(array_filter($episodes, fn($e) => !$e['has_spanish']));
            ?>
            <div class="accordion-item" style="background: var(--card-bg); border-color: var(--card-border);">
                <h2 class="accordion-header" id="headingSeason<?= $seasonNum ?>">
                    <button class="accordion-button collapsed text-light" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseSeason<?= $seasonNum ?>"
                            style="background: rgba(0,0,0,0.2);">
                        <strong><?= $label ?></strong>
                        <span class="badge bg-secondary ms-3"><?= $epCount ?> Episodios</span>
                        <?php if ($missingCount > 0): ?>
                            <span class="badge bg-danger ms-2"><?= $missingCount ?> faltan ES</span>
                        <?php else: ?>
                            <span class="badge bg-success ms-2"><i class="fa fa-check"></i> Completa</span>
                        <?php endif; ?>
                    </button>
                </h2>
                <div id="collapseSeason<?= $seasonNum ?>" class="accordion-collapse collapse"
                     data-bs-parent="#seasonsAccordion">
                    <div class="accordion-body p-0">
                        <div class="accordion accordion-flush" id="episodesAccordion<?= $seasonNum ?>">
                            <?php foreach ($episodes as $ep): ?>
                                <?php
                                    $epNum   = (int)($ep['episode'] ?? 0);
                                    $epTitle = htmlspecialchars($ep['title'] ?? 'Episodio ' . $epNum);
                                    $epId    = $ep['id'];
                                    $hasSp   = (bool)$ep['has_spanish'];
                                ?>
                                <div class="accordion-item" style="background: transparent; border-color: rgba(255,255,255,0.05);">
                                    <h2 class="accordion-header" id="headingEp<?= $epId ?>">
                                        <button class="accordion-button collapsed text-light" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapseEp<?= $epId ?>"
                                                style="background: transparent; font-size: 0.95rem;"
                                                onclick="loadEpSubs('<?= htmlspecialchars($epId) ?>', '<?= htmlspecialchars($type) ?>')">
                                            <i class="fa fa-play-circle text-info me-2"></i>
                                            Episodio <?= sprintf('%02d', $epNum) ?>: <?= $epTitle ?>
                                            <?php if ($hasSp): ?>
                                                <span class="badge bg-success ms-3 fw-normal" style="font-size:0.68rem"><i class="fa fa-check"></i> ES</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger ms-3 fw-normal" style="font-size:0.68rem">Falta ES</span>
                                            <?php endif; ?>
                                        </button>
                                    </h2>
                                    <div id="collapseEp<?= $epId ?>" class="accordion-collapse collapse"
                                         data-bs-parent="#episodesAccordion<?= $seasonNum ?>">
                                        <div class="accordion-body" style="background: rgba(0,0,0,0.1);">
                                            <!-- Cargado bajo demanda -->
                                            <div id="subs-ep-<?= htmlspecialchars($epId) ?>" class="subs-lazy"
                                                 data-ep-id="<?= htmlspecialchars($epId) ?>"
                                                 data-type="<?= htmlspecialchars($type) ?>"
                                                 data-series-id="<?= htmlspecialchars($id) ?>">
                                                <div class="text-center py-2">
                                                    <div class="spinner-border spinner-border-sm text-info"></div>
                                                    <span class="ms-2 text-muted small">Cargando subtítulos...</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <div class="alert alert-info">No se encontraron datos en el caché. <button class="btn btn-sm btn-gradient ms-2" onclick="triggerScan()"><i class="fa fa-refresh me-1"></i>Escanear ahora</button></div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

<script>
// ===== Carga de subtítulos bajo demanda (AJAX) =====
const loadedSubs = new Set();
const translatingIds = new Set(); // Evita traducciones duplicadas

function loadEpSubs(epId, type) {
    if (loadedSubs.has(epId)) return;
    loadedSubs.add(epId);

    const container = document.getElementById('subs-ep-' + epId);
    if (!container || container.dataset.loaded) return;
    container.dataset.loaded = '1';

    const seriesId = container.dataset.seriesId || '';
    fetch('ajax_subtitles.php?ep_id=' + encodeURIComponent(epId) + '&type=' + encodeURIComponent(type) + '&series_id=' + encodeURIComponent(seriesId))
        .then(r => r.json())
        .then(data => {
            if (data.html) {
                container.innerHTML = data.html;
            } else {
                container.innerHTML = '<p class="text-muted small mb-0"><i class="fa fa-info-circle"></i> No hay subtítulos descargados.</p>';
            }
        })
        .catch(() => {
            container.innerHTML = '<p class="text-danger small mb-0">Error al cargar subtítulos.</p>';
        });
}

// Cargar subtítulos de películas automáticamente al entrar
document.querySelectorAll('.subs-lazy[data-type="movies"]').forEach(el => {
    const epId = el.dataset.epId;
    const seriesId = el.dataset.seriesId || '';
    fetch('ajax_subtitles.php?ep_id=' + encodeURIComponent(epId) + '&type=movies&series_id=' + encodeURIComponent(seriesId))
        .then(r => r.json())
        .then(data => { el.innerHTML = data.html || '<p class="text-muted small mb-0">Sin subtítulos.</p>'; })
        .catch(() => { el.innerHTML = '<p class="text-danger small mb-0">Error.</p>'; });
});

// Helper: envía POST como application/x-www-form-urlencoded
function postJSON(url, params) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params)
    }).then(r => r.json());
}

async function startTranslation(type, mediaId, seriesId, path, btnElement) {
    if (translatingIds.has(mediaId)) return;
    translatingIds.add(mediaId);

    btnElement.disabled = true;
    btnElement.innerHTML = '<i class="fa fa-clock-o me-1"></i>En cola...';

    // Buscar o crear el contenedor de estado
    let statusEl = document.getElementById('translate-status-' + mediaId);
    if (!statusEl) {
        statusEl = document.createElement('div');
        statusEl.id = 'translate-status-' + mediaId;
        statusEl.className = 'mt-1 small';
        btnElement.parentNode.appendChild(statusEl);
    }

    try {
        const dataInit = await postJSON('ajax_translate.php', {
            action: 'init', type, media_id: mediaId, series_id: seriesId, path
        });
        if (dataInit.status !== 'success') throw new Error(dataInit.message || 'Error al iniciar');

        statusEl.innerHTML = '<span class="text-info"><i class="fa fa-check-circle me-1"></i>Traducción agregada a la cola de tareas.</span>';

        // Consultar estado cada 10 segundos para actualizar cuando termine
        const jobId = dataInit.job_id;
        const logId = dataInit.log_id;

        const checkInterval = setInterval(async () => {
            const statusData = await postJSON('ajax_translate.php', { action: 'status', job_id: jobId, log_id: logId });
            if (statusData.translation_status === 'completed') {
                clearInterval(checkInterval);
                translatingIds.delete(mediaId);
                statusEl.innerHTML = '<span class="text-success"><i class="fa fa-check-circle me-1"></i>Traducción completada! <span class="text-muted">Recargando...</span></span>';
                setTimeout(() => { window.location.reload(); }, 2000);
            } else if (statusData.translation_status === 'error') {
                clearInterval(checkInterval);
                translatingIds.delete(mediaId);
                statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>Error: ' + (statusData.result || 'desconocido') + '</span>';
                btnElement.disabled = false;
                btnElement.innerHTML = '<i class="fa fa-language"></i> Reintentar';
            }
        }, 10000);

    } catch (e) {
        translatingIds.delete(mediaId);
        statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>Error: ' + e.message + '</span>';
        btnElement.disabled = false;
        btnElement.innerHTML = '<i class="fa fa-language"></i> Reintentar';
    }
}

async function confirmTranslateAll(seriesId, type) {
    const msg = '¿Estás seguro de que deseas traducir TODA la serie al español?\n\n'
        + 'Se procesarán solo los episodios que:\n'
        + '  ✓ Tengan subtítulo en INGLÉS\n'
        + '  ✓ NO tengan ya subtítulo en español\n\n'
        + 'Esta acción puede tomar varios minutos. ¿Deseas continuar?';

    if (!confirm(msg)) return;

    const btn = document.querySelector('button[onclick*="confirmTranslateAll"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Analizando...'; }

    try {
        const res = await fetch('ajax_translate_all.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ series_id: seriesId, type: type })
        });
        const data = await res.json();

        if (data.status === 'success') {
            let m = data.encolados + ' traducción(es) encolada(s).\n';
            if (data.sin_ingles > 0) m += data.sin_ingles + ' omitido(s) por no tener subtítulo en inglés.\n';
            if (data.ya_es > 0) m += data.ya_es + ' omitido(s) por ya tener español.\n';
            alert(m);
        } else {
            alert('Error: ' + (data.message || 'desconocido'));
        }
    } catch (e) {
        alert('Error de conexión: ' + e.message);
    }

    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-language me-1"></i> Traducir toda la serie'; }
}
</script>