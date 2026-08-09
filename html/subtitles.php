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
    $row = $pdo->prepare("SELECT * FROM series WHERE id=?");
    $row->execute([$id]);
    $seriesRow = $row->fetch(PDO::FETCH_ASSOC);
    if ($seriesRow) $mediaTitle = $seriesRow['title'];

    // Todos los episodios agrupados por temporada
    $stmt = $pdo->prepare("
        SELECT id, title, season, episode, has_spanish
        FROM episodes
        WHERE series_id=? AND has_file=1
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
    $row = $pdo->prepare("SELECT * FROM movies WHERE id=?");
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
$isIgnored = (int)($itemData['is_ignored'] ?? 0) === 1;
?>
<input type="hidden" id="monitor-csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

<!-- Barra de acciones (tipo Sonarr/Radarr) -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-2">
    <?php if ($type === 'series'): ?>
        <button class="btn btn-sm btn-outline-info" onclick="confirmTranslateAll('<?= htmlspecialchars($id) ?>', '<?= htmlspecialchars($type) ?>')" <?= $isIgnored ? 'disabled' : '' ?> title="Traducir todos los episodios pendientes">
            <i class="fa fa-language me-1"></i> Traducir toda la serie
        </button>
    <?php endif; ?>
    <?php if ($isIgnored): ?>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="toggleMonitor('<?= htmlspecialchars($type) ?>', <?= htmlspecialchars($id) ?>, 'monitor')" title="Volver a monitorizar">
            <i class="fa fa-play me-1"></i> Volver a monitorizar
        </button>
    <?php else: ?>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="toggleMonitor('<?= htmlspecialchars($type) ?>', <?= htmlspecialchars($id) ?>, 'ignore')" title="No monitorizar">
            <i class="fa fa-ban me-1"></i> No monitorizar
        </button>
    <?php endif; ?>
    <?php
        $tvdbId = $itemData['tvdb_id'] ?? '';
        $tmdbId = $itemData['tmdb_id'] ?? '';
    ?>
    <?php if ($type === 'series' && $tvdbId): ?>
        <a class="btn btn-sm btn-outline-info" href="https://thetvdb.com/series/<?= htmlspecialchars(urlencode($tvdbId)) ?>" target="_blank" rel="noopener noreferrer" title="Ver en TheTVDB">
            <i class="fa fa-external-link me-1"></i> Ver en TheTVDB
        </a>
    <?php elseif ($type === 'movies' && $tmdbId): ?>
        <a class="btn btn-sm btn-outline-info" href="https://www.themoviedb.org/movie/<?= htmlspecialchars(urlencode($tmdbId)) ?>" target="_blank" rel="noopener noreferrer" title="Ver en TMDB">
            <i class="fa fa-external-link me-1"></i> Ver en TMDB
        </a>
    <?php endif; ?>
    </div>

    <a href="javascript:history.back()" class="btn btn-outline-light btn-sm ms-auto"><i class="fa fa-arrow-left"></i> Volver</a>
</div>

<div class="card glass-card mb-4" style="background: rgba(20,20,25,0.7); border: 1px solid rgba(255,255,255,0.1);">
    <div class="card-body d-flex flex-column flex-md-row gap-4">
        <?php if ($posterUrl): ?>
            <div style="flex-shrink: 0; width: 140px; text-align: center;">
                <img src="<?= htmlspecialchars($posterUrl) ?>" class="img-fluid rounded shadow-sm" alt="Poster" style="max-height: 210px; object-fit: cover;" onerror="this.style.display='none'">
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
        </div>
    </div>
</div>

<?php if ($type === 'movies' && $movieRow): ?>
    <!-- ===== PELÍCULA ===== -->
    <div class="card glass-card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-sm align-middle mb-0" style="font-size:0.9rem;">
                    <thead>
                        <tr class="text-muted small">
                            <th style="width:150px;">Subtítulos</th>
                            <th class="text-end" style="width:90px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr data-movie-id="<?= htmlspecialchars($id) ?>">
                            <td class="subs-badges">
                                <span class="badge es-badge me-1 bg-secondary">ES</span>
                                <span class="badge en-badge bg-secondary">EN</span>
                            </td>
                            <td class="text-end">
                                <button type="button" id="movie-translate-btn" class="btn btn-sm btn-outline-info translate-icon-btn" disabled title="Cargando...">
                                    <i class="fa fa-language"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
                        <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-0" style="font-size:0.9rem;">
                                <thead>
                                    <tr class="text-muted small">
                                        <th style="width:90px;">Episodio</th>
                                        <th>Título</th>
                                        <th style="width:150px;">Subtítulos</th>
                                        <th class="text-end" style="width:90px;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="season-episodes-<?= $seasonNum ?>">
                                    <?php foreach ($episodes as $ep): ?>
                                        <?php
                                            $epNum   = (int)($ep['episode'] ?? 0);
                                            $epTitle = htmlspecialchars($ep['title'] ?? 'Episodio ' . $epNum);
                                            $epId    = $ep['id'];
                                        ?>
                                        <tr data-ep-id="<?= $epId ?>" data-series-id="<?= htmlspecialchars($id) ?>" data-type="<?= htmlspecialchars($type) ?>" data-en-path="">
                                            <td class="text-info fw-semibold">E<?= sprintf('%02d', $epNum) ?></td>
                                            <td><?= $epTitle ?></td>
                                            <td class="subs-badges">
                                                <span class="badge es-badge me-1 bg-secondary">ES</span>
                                                <span class="badge en-badge bg-secondary">EN</span>
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-info translate-icon-btn" disabled title="Cargando...">
                                                    <i class="fa fa-language"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
// ===== Carga de subtítulos (batch por serie / película) =====
const translatingIds = new Set(); // Evita traducciones duplicadas

// Aplica el estado (badges ES/EN y botón) a una fila de episodio
function applyEpisodeStatus(row, info, isIgnored) {
    const esBadge = row.querySelector('.es-badge');
    const enBadge = row.querySelector('.en-badge');
    const btn = row.querySelector('.translate-icon-btn');
    if (!esBadge || !enBadge || !btn) return;

    row.dataset.enPath = info.english_path || '';

    // Badges de subtítulos
    esBadge.className = 'badge es-badge me-1 ' + (info.has_es ? 'bg-info' : 'bg-danger bg-opacity-25');
    enBadge.className = 'badge en-badge ' + (info.has_en ? 'bg-primary' : 'bg-danger bg-opacity-25');

    // Serie/película no monitorizada: sin acciones
    if (isIgnored) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-ban"></i>';
        btn.title = 'No monitorizada';
        return;
    }
    // Traducción en curso/pendiente
    if (info.translation_status) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-clock-o"></i>';
        btn.title = 'Traducción en curso...';
        return;
    }
    // Sin subtítulo en inglés: no se puede traducir
    if (!info.has_en) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-language"></i>';
        btn.title = 'No hay subtítulo en inglés';
        return;
    }
    btn.disabled = false;
    if (info.has_es) {
        btn.innerHTML = '<i class="fa fa-refresh"></i>';
        btn.title = 'Re-traducir a español';
    } else {
        btn.innerHTML = '<i class="fa fa-language"></i>';
        btn.title = 'Traducir a español';
    }
}

// Carga el estado de todos los episodios de una serie (una sola petición)
async function loadSeriesStatus(seriesId) {
    try {
        const res = await fetch('ajax_subtitles.php?action=series_status&series_id=' + encodeURIComponent(seriesId));
        const data = await res.json();
        if (data.status !== 'success') return;
        const map = {};
        (data.episodes || []).forEach(e => map[e.id] = e);
        document.querySelectorAll('tr[data-ep-id]').forEach(row => {
            const info = map[row.dataset.epId];
            if (info) applyEpisodeStatus(row, info, !!data.is_ignored);
        });
    } catch (e) { /* silencioso */ }
}

// Carga el estado de una película
async function loadMovieStatus(mediaId, type) {
    try {
        const res = await fetch('ajax_subtitles.php?action=movie_status&ep_id=' + encodeURIComponent(mediaId) + '&type=' + encodeURIComponent(type));
        const data = await res.json();
        if (data.status !== 'success') return;
        const esBadge = document.querySelector('.es-badge');
        const enBadge = document.querySelector('.en-badge');
        const btn = document.getElementById('movie-translate-btn');
        if (!esBadge || !enBadge || !btn) return;
        esBadge.className = 'badge es-badge me-1 ' + (data.has_es ? 'bg-info' : 'bg-danger bg-opacity-25');
        enBadge.className = 'badge en-badge ' + (data.has_en ? 'bg-primary' : 'bg-danger bg-opacity-25');
        btn.dataset.enPath = data.english_path || '';
        btn.dataset.mediaId = mediaId;
        btn.dataset.type = type;
        btn.dataset.seriesId = '';
        if (data.is_ignored) {
            btn.disabled = true; btn.innerHTML = '<i class="fa fa-ban"></i>'; btn.title = 'No monitorizada';
        } else if (data.translation_status) {
            btn.disabled = true; btn.innerHTML = '<i class="fa fa-clock-o"></i>'; btn.title = 'Traducción en curso...';
        } else if (!data.has_en) {
            btn.disabled = true; btn.innerHTML = '<i class="fa fa-language"></i>'; btn.title = 'No hay subtítulo en inglés';
        } else {
            btn.disabled = false;
            if (data.has_es) { btn.innerHTML = '<i class="fa fa-refresh"></i>'; btn.title = 'Re-traducir a español'; }
            else { btn.innerHTML = '<i class="fa fa-language"></i>'; btn.title = 'Traducir a español'; }
        }
    } catch (e) { /* silencioso */ }
}

// Inicialización según el tipo de página
(function () {
    const type = '<?= htmlspecialchars($type) ?>';
    const id = <?= (int)$id ?>;
    if (type === 'series') {
        loadSeriesStatus(id);
    } else {
        loadMovieStatus(id, type);
    }
})();

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

    // Estado: enviando solicitud (solo spinner)
    btnElement.disabled = true;
    btnElement.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    btnElement.title = 'Encolando traducción...';

    try {
        const dataInit = await postJSON('ajax_translate.php', {
            action: 'init', type, media_id: mediaId, series_id: seriesId, path
        });
        if (dataInit.status !== 'success') throw new Error(dataInit.message || 'Error al iniciar');

        // Encolada: un único icono de reloj
        btnElement.innerHTML = '<i class="fa fa-clock-o"></i>';
        btnElement.title = 'Traducción en cola';

        // Consultar estado cada 10 segundos para actualizar cuando termine
        const jobId = dataInit.job_id;
        const logId = dataInit.log_id;

        const checkInterval = setInterval(async () => {
            const statusData = await postJSON('ajax_translate.php', { action: 'status', job_id: jobId, log_id: logId });
            if (statusData.translation_status === 'completed') {
                clearInterval(checkInterval);
                translatingIds.delete(mediaId);
                btnElement.innerHTML = '<i class="fa fa-check-circle"></i>';
                btnElement.title = 'Traducción completada';
                setTimeout(() => { window.location.reload(); }, 1500);
            } else if (statusData.translation_status === 'error') {
                clearInterval(checkInterval);
                translatingIds.delete(mediaId);
                btnElement.disabled = false;
                btnElement.innerHTML = '<i class="fa fa-exclamation-triangle"></i>';
                btnElement.title = 'Error al traducir. Pulsa para reintentar.';
                btnElement.classList.remove('btn-outline-info', 'btn-outline-warning');
                btnElement.classList.add('btn-outline-danger');
            }
        }, 10000);

    } catch (e) {
        translatingIds.delete(mediaId);
        btnElement.disabled = false;
        btnElement.innerHTML = '<i class="fa fa-exclamation-triangle"></i>';
        btnElement.title = 'Error: ' + (e.message || 'desconocido') + ' - Pulsa para reintentar';
        btnElement.classList.remove('btn-outline-info', 'btn-outline-warning');
        btnElement.classList.add('btn-outline-danger');
    }
}

// Delegación de clics para botones de epísodio y película (solo-icono)
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.translate-icon-btn');
    if (!btn || btn.disabled) return;
    const isMovieBtn = btn.id === 'movie-translate-btn';
    const type = isMovieBtn ? (btn.dataset.type || 'movies') : (btn.closest('tr').dataset.type || 'series');
    const mediaId = isMovieBtn ? btn.dataset.mediaId : btn.closest('tr').dataset.epId;
    const seriesId = isMovieBtn ? (btn.dataset.seriesId || '') : (btn.closest('tr').dataset.seriesId || '');
    const path = btn.dataset.enPath || (isMovieBtn ? '' : btn.closest('tr').dataset.enPath);
    if (!path) { alert('No hay subtítulo en inglés para traducir.'); return; }
    startTranslation(type, mediaId, seriesId, path, btn);
});

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

// ===== No monitorizar / Volver a monitorizar =====
async function toggleMonitor(type, id, action) {
    const title = document.querySelector('.fw-bold.text-white')?.textContent?.trim() || 'este elemento';
    const verb = (action === 'ignore') ? 'No monitorizar' : 'Volver a monitorizar';
    let msg;
    if (action === 'ignore') {
        msg = '¿Dejar de monitorizar "' + title + '"?\n\nNo aparecerá como pendiente y se cancelarán sus traducciones en cola.';
        if (!confirm(msg)) return;
    }
    const csrf = document.getElementById('monitor-csrf')?.value || '';
    const btn = event?.target?.closest('button');
    if (btn) btn.disabled = true;

    try {
        const res = await fetch('ajax_monitor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: action, type: type, id: id, _csrf_token: csrf })
        });
        const data = await res.json();
        if (data.status === 'success') {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'desconocido'));
        }
    } catch (e) {
        alert('Error de conexión: ' + e.message);
    }
    if (btn) btn.disabled = false;
}
</script>