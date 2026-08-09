<?php
// html/ajax_subtitles.php
// Carga los subtítulos reales de UN episodio/película al hacer click (lazy loading)
ini_set('display_errors', 0);
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/SubtitleScanner.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

// Rate limiting (60 consultas por minuto)
rateLimitRequire('subtitles', 60, 60);

$epId    = $_GET['ep_id']    ?? null;
$type    = $_GET['type']     ?? 'series';
$seriesId = $_GET['series_id'] ?? null;

if (!$epId) {
    echo json_encode(['html' => '<p class="text-danger small mb-0">ID no proporcionado.</p>']);
    exit;
}

try {
    // Obtener el medio desde la caché para conocer sus rutas
    $row = $pdo->prepare("SELECT * FROM media_cache WHERE id = ?");
    $row->execute([$epId]);
    $media = $row->fetch(PDO::FETCH_ASSOC);

    // Detectar subtítulos desde el filesystem (junto al vídeo o en la carpeta)
    $subtitles = [];
    if ($media) {
        $videoPath = $media['video_path'] ?? '';
        if ($videoPath && is_file($videoPath)) {
            $subtitles = SubtitleScanner::findSubtitlesForVideo($videoPath);
        } elseif (!empty($media['folder_path']) && is_dir($media['folder_path'])) {
            $subtitles = SubtitleScanner::findSubtitlesInFolder($media['folder_path']);
        }
    }

    if (empty($subtitles)) {
        echo json_encode(['html' => '<p class="text-muted small mb-0"><i class="fa fa-info-circle me-1"></i>No hay subtítulos descargados.</p>']);
        exit;
    }

    // Detectar si ya tiene español
    $hasSpanish = SubtitleScanner::hasSpanish($subtitles);

    // Consultar historial de traducciones para este media
    $translationHistory = [];
    $histStmt = $pdo->prepare("SELECT status, result, finished_at FROM translation_log WHERE media_id = ? AND status IN ('completed', 'error') ORDER BY created_at DESC LIMIT 1");
    $histStmt->execute([$epId]);
    $lastTranslation = $histStmt->fetch(PDO::FETCH_ASSOC);

    // Verificar si HAY UNA TRADUCCIÓN PENDIENTE O EN EJECUCIÓN (para desactivar botón)
    $hasPending = false;
    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM translation_log WHERE media_id = ? AND status IN ('pending', 'running')");
    $pendingStmt->execute([$epId]);
    $hasPending = $pendingStmt->fetchColumn() > 0;

    $html  = '<div class="table-responsive">';
    $html .= '<table class="table table-dark table-hover table-sm align-middle mb-0">';
    $html .= '<thead><tr><th>Idioma</th><th>Detalles</th><th style="width:250px;">Traducción / Estado</th></tr></thead><tbody>';

    foreach ($subtitles as $sub) {
        $lang      = strtolower($sub['code2'] ?? $sub['name'] ?? $sub['language'] ?? 'desconocido');
        $langLabel = strtoupper($sub['name'] ?? $sub['code2'] ?? $lang);
        $isEnglish = in_array($lang, ['en', 'english', 'eng']);

        // Badges de detalles
        $details = '';
        if (!empty($sub['forced'])) $details .= '<span class="badge bg-warning text-dark me-1">Forced</span>';
        if (!empty($sub['hi']))     $details .= '<span class="badge bg-info text-dark me-1">SDH</span>';
        if (empty($details))        $details  = '<span class="badge bg-secondary">Normal</span>';

        $html .= '<tr>';
        $html .= '<td><i class="fa fa-language text-info"></i> ' . htmlspecialchars($langLabel) . '</td>';
        $html .= '<td>' . $details . '</td>';

        if ($isEnglish) {
            $path      = htmlspecialchars($sub['path'] ?? '');
            $typeEsc   = htmlspecialchars($type);
            $epIdEsc   = htmlspecialchars($epId);
            $seriesEsc = htmlspecialchars($seriesId ?? '');

            $btnLabel = $hasSpanish
                ? '<i class="fa fa-refresh"></i> Re-traducir a Español'
                : '<i class="fa fa-language"></i> Traducir a Español';
            $btnClass = $hasSpanish
                ? 'btn btn-outline-warning btn-sm translate-btn w-100'
                : 'btn btn-gradient btn-sm translate-btn w-100';

            // Si hay traducción pendiente o en ejecución, deshabilitar botón
            if ($hasPending) {
                $btnClass = 'btn btn-secondary btn-sm translate-btn w-100';
                $btnLabel = '<i class="fa fa-clock-o"></i> Traduciendo...';
            }

            $html .= '<td>
                <button class="' . $btnClass . '" onclick="startTranslation(\'' . $typeEsc . '\', \'' . $epIdEsc . '\', \'' . $seriesEsc . '\', \'' . $path . '\', this)"' . ($hasPending ? ' disabled' : '') . '>
                    ' . $btnLabel . '
                </button>';

            // Mostrar badge de última traducción si existe
            if ($lastTranslation && $lastTranslation['status'] === 'completed') {
                $finished = date('d/m H:i', strtotime($lastTranslation['finished_at']));
                $html .= '<div class="mt-1"><span class="badge bg-success bg-opacity-50 small"><i class="fa fa-check-circle"></i> Traducido ' . $finished . '</span></div>';
            } elseif ($lastTranslation && $lastTranslation['status'] === 'error') {
                $html .= '<div class="mt-1"><span class="badge bg-danger bg-opacity-50 small"><i class="fa fa-exclamation-circle"></i> Error anterior: ' . htmlspecialchars(substr($lastTranslation['result'] ?? '', 0, 40)) . '</span></div>';
            }

            $html .= '<div id="translate-status-' . $epIdEsc . '" class="mt-1"></div>
            </td>';
        } else {
            $html .= '<td><span class="text-success"><i class="fa fa-check"></i> Disponible</span></td>';
        }

        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    echo json_encode(['html' => $html]);

} catch (Exception $e) {
    echo json_encode(['html' => '<p class="text-danger small mb-0"><i class="fa fa-exclamation-triangle me-1"></i>' . htmlspecialchars($e->getMessage()) . '</p>']);
}
