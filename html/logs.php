<?php
// html/logs.php
// Actividad del sistema: historial de traducciones, escaneos y errores de aplicación.
require_once 'includes/header.php';
require_once 'includes/security.php';

$message = '';
$status = '';

// Vaciar únicamente errores de aplicación (system_logs), con CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
        if (csrf_validate()) {
            $pdo->exec("DELETE FROM system_logs");
            $message = "Errores de aplicación vaciados correctamente.";
            $status = "success";
        } else {
            $message = "Token de seguridad inválido o expirado. Recarga la página.";
            $status = "danger";
        }
    }
}

// Errores de aplicación (system_logs) — solo se muestran si hay registros.
$logs = [];
$hasSystemLogs = false;
try {
    $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 100");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasSystemLogs = count($logs) > 0;
} catch (Exception $e) {
    $logs = [];
}

// Historial de traducciones con resolución de título de serie en un solo LEFT JOIN.
$translationLogs = [];
try {
    $stmt = $pdo->query("
        SELECT tl.id, tl.media_id, tl.media_title, tl.media_type, tl.season, tl.episode,
               tl.status, tl.result, tl.provider, tl.model, tl.created_at, tl.finished_at,
               COALESCE(s.title, tl.media_title) AS display_title
        FROM translation_log tl
        LEFT JOIN episodes e ON tl.media_type IN ('episode', 'series') AND e.id = tl.media_id
        LEFT JOIN series s ON s.id = e.series_id
        ORDER BY tl.created_at DESC
        LIMIT 50
    ");
    $translationLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $translationLogs = [];
}

// Historial de escaneos (background_tasks).
$scanHistory = [];
try {
    $stmt = $pdo->query("
        SELECT id, type, status, result, created_at, started_at, finished_at
        FROM background_tasks
        WHERE type = 'scan_media'
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $scanHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $scanHistory = [];
}

// Resumen de actividad.
$summary = [
    'pending'  => 0,
    'running'  => 0,
    'done'     => 0,
    'error'    => 0,
    'lastScan' => null,
];
try {
    $summary['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM background_tasks WHERE status='pending' AND type='translate'")->fetchColumn();
    $summary['running'] = (int)$pdo->query("SELECT COUNT(*) FROM background_tasks WHERE status IN ('running','pending') AND type='translate'")->fetchColumn();
    $summary['done']    = (int)$pdo->query("SELECT COUNT(*) FROM translation_log WHERE status='completed'")->fetchColumn();
    $summary['error']   = (int)$pdo->query("SELECT COUNT(*) FROM translation_log WHERE status='error'")->fetchColumn();
    $summary['lastScan']= $pdo->query("SELECT finished_at FROM background_tasks WHERE type='scan_media' AND status='done' ORDER BY finished_at DESC LIMIT 1")->fetchColumn();
} catch (Exception $e) {
}

// Últimas 200 líneas del worker log para diagnóstico avanzado (plegado).
$workerLogs = "";
$workerLogPath = '/config/worker.log';
if (file_exists($workerLogPath)) {
    $lines = @file($workerLogPath);
    if ($lines) {
        $workerLogs = implode('', array_slice($lines, -200));
    }
} else {
    $workerLogs = "El archivo worker.log no existe aún.";
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fa fa-terminal text-primary"></i> Actividad del Sistema</h2>
    <div>
        <a href="settings.php" class="btn btn-outline-light btn-sm"><i class="fa fa-arrow-left"></i> Volver a Ajustes</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $status ?> alert-dismissible fade show">
        <i class="fa <?= $status === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- RESUMEN -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card glass-card h-100">
            <div class="card-body text-center">
                <div class="fs-3 fw-bold text-info"><?= (int)$summary['running'] ?></div>
                <div class="text-muted small">Traducciones en curso / pendientes</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card glass-card h-100">
            <div class="card-body text-center">
                <div class="fs-3 fw-bold text-success"><?= (int)$summary['done'] ?></div>
                <div class="text-muted small">Traducciones completadas</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card glass-card h-100">
            <div class="card-body text-center">
                <div class="fs-3 fw-bold <?= $summary['error'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= (int)$summary['error'] ?></div>
                <div class="text-muted small">Traducciones con error</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card glass-card h-100">
            <div class="card-body text-center">
                <div class="fs-6 fw-bold text-warning"><?= $summary['lastScan'] ? '<small class="utc-date">' . htmlspecialchars($summary['lastScan']) . '</small>' : 'Sin escaneo' ?></div>
                <div class="text-muted small">Último escaneo</div>
            </div>
        </div>
    </div>
</div>
<!-- HISTORIAL DE TRADUCCIONES -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card glass-card">
            <div class="card-header bg-dark border-secondary text-success fw-bold">
                <i class="fa fa-language"></i> Historial de Traducciones
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background: var(--card-bg); z-index: 1;">
                            <tr>
                                <th>Medio</th>
                                <th style="width:80px;">Tipo</th>
                                <th style="width:80px;">Episodio</th>
                                <th style="width:110px;">Proveedor</th>
                                <th style="width:120px;">Estado</th>
                                <th style="width:140px;">Iniciado</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($translationLogs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fa fa-inbox fa-2x mb-2" style="opacity: 0.3;"></i><br>
                                        No hay traducciones registradas aún.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($translationLogs as $tl): ?>
                                    <?php
                                        $statusBadge = $tl['status'] === 'completed'
                                            ? '<span class="badge bg-success"><i class="fa fa-check"></i> Completado</span>'
                                            : ($tl['status'] === 'error'
                                                ? '<span class="badge bg-danger"><i class="fa fa-exclamation-circle"></i> Error</span>'
                                                : ($tl['status'] === 'running'
                                                    ? '<span class="badge bg-info badge-running"><i class="fa fa-refresh fa-spin"></i> Ejecutando</span>'
                                                    : '<span class="badge bg-warning text-dark"><i class="fa fa-clock-o"></i> Pendiente</span>'));
                                        $isMovie = ($tl['media_type'] === 'movies' || $tl['media_type'] === 'movie');
                                        $typeIcon = $isMovie ? '<i class="fa fa-film"></i> Película' : '<i class="fa fa-tv"></i> Serie';
                                        $epInfo = ($tl['media_type'] === 'series' || $tl['media_type'] === 'episode')
                                            ? 'T' . ($tl['season'] ?: '?') . ' E' . ($tl['episode'] ?: '?')
                                            : '-';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($tl['display_title'] ?: $tl['media_title'] ?: 'Sin título') ?></strong></td>
                                        <td><?= $typeIcon ?></td>
                                        <td><small class="text-muted"><?= $epInfo ?></small></td>
                                        <td><?= !empty($tl['provider']) ? '<span class="badge bg-info text-dark">' . htmlspecialchars(ucfirst($tl['provider'])) . ($tl['model'] ? ' · ' . htmlspecialchars($tl['model']) : '') . '</span>' : '<span class="text-muted">-</span>' ?></td>
                                        <td><?= $statusBadge ?></td>
                                        <td class="text-muted"><small class="utc-date"><?= htmlspecialchars($tl['created_at']) ?></small></td>
                                        <td class="translation-result" data-log-id="<?= (int)$tl['id'] ?>" data-status="<?= htmlspecialchars($tl['status']) ?>">
                                            <?php if (in_array($tl['status'], ['pending', 'running'], true)): ?>
                                                <span class="translation-progress-label"><i class="fa fa-clock-o me-1"></i>En cola / ejecutando...</span>
                                            <?php else: ?>
                                                <code class="text-muted bg-dark p-1 rounded" style="word-break: break-all; font-size:0.75rem;"><?= htmlspecialchars(substr($tl['result'] ?: ($tl['status'] === 'completed' ? 'Completado' : 'En espera...'), 0, 120)) ?></code>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- HISTORIAL DE ESCANEOS -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card glass-card">
            <div class="card-header bg-dark border-secondary text-info fw-bold">
                <i class="fa fa-refresh"></i> Historial de Escaneos
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background: var(--card-bg); z-index: 1;">
                            <tr>
                                <th style="width:160px;">Finalizado</th>
                                <th style="width:100px;">Estado</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($scanHistory)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted">No hay escaneos registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($scanHistory as $sc): ?>
                                    <tr>
                                        <td class="text-muted"><small class="utc-date"><?= htmlspecialchars($sc['finished_at'] ?: $sc['created_at']) ?></small></td>
                                        <td><span class="badge bg-success">OK</span></td>
                                        <td><code class="text-muted bg-dark p-1 rounded" style="word-break: break-all; font-size:0.75rem;"><?= htmlspecialchars($sc['result'] ?: '-') ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- ERRORES DE APLICACIÓN (SOLO SI HAY) -->
<?php if ($hasSystemLogs): ?>
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card glass-card">
                <div class="card-header bg-dark border-secondary text-danger fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-exclamation-triangle"></i> Errores de Aplicación</span>
                    <form method="POST" class="m-0" onsubmit="return confirm('¿Vaciar los errores de aplicación?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa fa-trash"></i> Vaciar errores</button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-dark table-hover mb-0" style="font-size: 0.85rem;">
                            <thead style="position: sticky; top: 0; background: var(--card-bg); z-index: 1;">
                                <tr>
                                    <th style="width: 150px;">Fecha y Hora</th>
                                    <th style="width: 120px;">Acción</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="text-muted"><small class="utc-date"><?= htmlspecialchars($log['created_at']) ?></small></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                                        <td><code class="text-danger bg-dark p-1 rounded" style="word-break: break-all; white-space: pre-wrap;"><?= htmlspecialchars($log['message']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<!-- DIAGNÓSTICO AVANZADO: WORKER LOG PLEGADO -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card glass-card">
            <div class="card-header bg-dark border-secondary text-warning fw-bold">
                <i class="fa fa-file-text-o"></i>
                <a class="text-warning text-decoration-none" data-bs-toggle="collapse" href="#workerLogCollapse" role="button" aria-expanded="false" aria-controls="workerLogCollapse">
                    Diagnóstico avanzado — Consola del worker (últimas 200 líneas)
                </a>
            </div>
            <div class="collapse" id="workerLogCollapse">
                <div class="card-body p-0">
                    <textarea id="workerLogText" class="form-control bg-dark text-light font-monospace border-0 rounded-0"
                              style="height: 400px; resize: none; font-size: 0.75rem; padding: 1rem;" readonly><?= htmlspecialchars($workerLogs) ?></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Convertir fechas de las tablas de UTC a hora local
    document.querySelectorAll('.utc-date').forEach(el => {
        if (!el.textContent.trim()) return;
        const dateStr = el.textContent.trim().replace(' ', 'T') + 'Z';
        const date = new Date(dateStr);
        if (!isNaN(date)) {
            el.textContent = date.toLocaleString('es-ES', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
            }).replace(',', '');
        }
    });

    // Convertir fechas en el textarea del worker (si está visible)
    const textarea = document.getElementById('workerLogText');
    if (textarea) {
        let text = textarea.value;
        text = text.replace(/\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\]/g, function(match, p1, p2) {
            const date = new Date(p1 + 'T' + p2 + 'Z');
            if (!isNaN(date)) {
                const pad = n => String(n).padStart(2, '0');
                return '[' + date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' +
                       pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()) + ']';
            }
            return match;
        });
        textarea.value = text;
        textarea.scrollTop = textarea.scrollHeight;
    }

    // ===== Progreso dinámico de traducciones =====
    function updateTranslationProgress() {
        const resultCells = document.querySelectorAll('.translation-result[data-status="pending"], .translation-result[data-status="running"]');
        if (resultCells.length === 0) return;

        fetch('ajax_tasks.php?action=translation_progress')
            .then(r => r.json())
            .then(data => {
                if (!data.progress) return;
                let stillActive = false;
                resultCells.forEach(cell => {
                    const logId = cell.dataset.logId;
                    const pr = data.progress[logId];
                    if (!pr) return;
                    const label = cell.querySelector('.translation-progress-label');
                    if (!label) return;
                    stillActive = true;
                    if (pr.total_chunks > 0) {
                        label.innerHTML = '<i class="fa fa-refresh fa-spin me-1"></i>' + pr.completed_chunks + ' / ' + pr.total_chunks + ' partes';
                        // Barra de progreso compacta
                        const pct = Math.min(100, Math.round((pr.completed_chunks / pr.total_chunks) * 100));
                        label.innerHTML += '<div class="progress mt-1" style="height:4px;width:120px;"><div class="progress-bar bg-info" style="width:' + pct + '%"></div></div>';
                    } else {
                        label.innerHTML = '<i class="fa fa-clock-o me-1"></i>En cola / ejecutando...';
                    }
                });
                return stillActive;
            })
            .catch(() => {});
    }

    updateTranslationProgress();
    // Polling mientras haya traducciones activas
    setInterval(updateTranslationProgress, 5000);
});
</script>

<?php require_once 'includes/footer.php'; ?>
