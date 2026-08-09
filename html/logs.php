<?php
// html/logs.php
require_once 'includes/header.php';

// Manejar acción de vaciar logs
if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    $pdo->exec("DELETE FROM system_logs");
    $message = "Los registros han sido vaciados correctamente.";
    $status = "success";
}

// Obtener los logs de la base de datos (últimos 100)
$stmt = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 100");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Leer archivo físico de logs del worker
$workerLogPath = '/config/worker.log';
$workerLogs = "";
if (file_exists($workerLogPath)) {
    $size = filesize($workerLogPath);
    $bytesToRead = min($size, 50 * 1024);
    if ($bytesToRead > 0) {
        $fp = fopen($workerLogPath, 'r');
        fseek($fp, -$bytesToRead, SEEK_END);
        $workerLogs = fread($fp, $bytesToRead);
        fclose($fp);
    }
} else {
    $workerLogs = "El archivo worker.log no existe aún.";
}

// Obtener historial de traducciones (translation_log)
$translationLogs = [];
try {
    $stmt = $pdo->query("
        SELECT id, media_id, media_title, media_type, season, episode, status, result, provider, model, created_at, finished_at 
        FROM translation_log 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $translationLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Corregir media_title para episodios: buscar título de la serie
    foreach ($translationLogs as &$tl) {
        if (($tl['media_type'] === 'episode' || $tl['media_type'] === 'series') && !empty($tl['media_id'])) {
            $st = $pdo->prepare("SELECT s.title FROM episodes e JOIN series s ON s.id=e.series_id WHERE e.id = ?");
            $st->execute([$tl['media_id']]);
            $sr = $st->fetch(PDO::FETCH_ASSOC);
            if ($sr && !empty($sr['title'])) {
                $tl['media_title'] = $sr['title'];
            }
        }
    }
    unset($tl);
} catch (Exception $e) {
    $translationLogs = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fa fa-terminal text-primary"></i> Logs del Sistema</h2>
    
    <div>
        <form method="POST" style="display: inline-block;" onsubmit="return confirm('¿Estás seguro de que deseas eliminar todos los registros permanentemente?');">
            <input type="hidden" name="action" value="clear_logs">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="fa fa-trash"></i> Vaciar Logs
            </button>
        </form>
        <a href="settings.php" class="btn btn-outline-light btn-sm ms-2"><i class="fa fa-arrow-left"></i> Volver a Ajustes</a>
    </div>
</div>

<?php if (isset($message)): ?>
    <div class="alert alert-<?= $status ?> alert-dismissible fade show">
        <i class="fa fa-check-circle"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- LOGS DE ERRORES (Base de Datos) -->
    <div class="col-md-6 mb-4">
        <div class="card glass-card h-100">
            <div class="card-header bg-dark border-secondary text-danger fw-bold">
                <i class="fa fa-database"></i> Errores de Aplicación (Traducción, N8N, BD)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background: var(--card-bg); z-index: 1;">
                            <tr>
                                <th style="width: 140px;">Fecha y Hora</th>
                                <th style="width: 110px;">Acción</th>
                                <th>Detalle del Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted">
                                        <i class="fa fa-check-circle fa-2x mb-2 text-success" style="opacity: 0.5;"></i><br>
                                        No hay errores registrados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="text-muted"><small class="utc-date"><?= htmlspecialchars($log['created_at']) ?></small></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                                        <td><code class="text-danger bg-dark p-1 rounded" style="word-break: break-all; white-space: pre-wrap;"><?= htmlspecialchars($log['message']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- LOGS DEL WORKER (Archivo) -->
    <div class="col-md-6 mb-4">
        <div class="card glass-card h-100">
            <div class="card-header bg-dark border-secondary text-warning fw-bold">
                <i class="fa fa-file-text-o"></i> Consola Worker (Últimas líneas)
            </div>
            <div class="card-body p-0">
                <textarea id="workerLogText" class="form-control bg-dark text-light font-monospace border-0 rounded-0" 
                          style="height: 500px; resize: none; font-size: 0.75rem; padding: 1rem;" 
                          readonly><?= htmlspecialchars($workerLogs) ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- TERCERA FILA: Historial de traducciones -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card glass-card">
            <div class="card-header bg-dark border-secondary text-success fw-bold">
                <i class="fa fa-language"></i> Historial de Traducciones Realizadas
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
                                        $typeIcon = ($tl['media_type'] === 'movies' || $tl['media_type'] === 'movie')
                                            ? '<i class="fa fa-film"></i> Película'
                                            : '<i class="fa fa-tv"></i> Serie';
                                        $epInfo = ($tl['media_type'] === 'series' || $tl['media_type'] === 'episode')
                                            ? 'T' . ($tl['season'] ?: '?') . ' E' . ($tl['episode'] ?: '?')
                                            : '-';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($tl['media_title'] ?: 'Sin título') ?></strong></td>
                                        <td><?= $typeIcon ?></td>
                                        <td><small class="text-muted"><?= $epInfo ?></small></td>
                                        <td><?= !empty($tl['provider']) ? '<span class="badge bg-info text-dark">' . htmlspecialchars(ucfirst($tl['provider'])) . ($tl['model'] ? ' · ' . htmlspecialchars($tl['model']) : '') . '</span>' : '<span class="text-muted">-</span>' ?></td>
                                        <td><?= $statusBadge ?></td>
                                        <td class="text-muted"><small class="utc-date"><?= htmlspecialchars($tl['created_at']) ?></small></td>
                                        <td><code class="text-muted bg-dark p-1 rounded" style="word-break: break-all; font-size:0.75rem;"><?= htmlspecialchars(substr($tl['result'] ?: ($tl['status'] === 'completed' ? 'Completado' : 'En espera...'), 0, 100)) ?></code></td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Convertir fechas de la tabla de UTC a hora local
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

    // Convertir fechas en el textarea del worker
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
        // Auto scroll al final
        textarea.scrollTop = textarea.scrollHeight;
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
