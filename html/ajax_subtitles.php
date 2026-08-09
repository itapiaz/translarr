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

// ============================================================
// Acción: estado masivo de una serie (un solo request)
// ============================================================
$action = $_GET['action'] ?? '';
if ($action === 'series_status') {
    $sid = (int)($_GET['series_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['status' => 'error', 'message' => 'ID de serie inválido.']); exit; }
    $ig = $pdo->prepare("SELECT is_ignored FROM series WHERE id = ?");
    $ig->execute([$sid]);
    $isIgnored = (int)$ig->fetchColumn() === 1;

    // Unir con la carpeta de la serie para el fallback de subtítulos
    $epStmt = $pdo->prepare("SELECT e.id, e.video_path, s.folder_path, e.has_spanish FROM episodes e JOIN series s ON s.id = e.series_id WHERE e.series_id=? AND e.has_file=1");
    $epStmt->execute([$sid]);
    $eps = $epStmt->fetchAll(PDO::FETCH_ASSOC);

    $ids = [];
    $out = [];
    foreach ($eps as $ep) {
        $subtitles = [];
        $videoPath = $ep['video_path'] ?? '';
        if ($videoPath && is_file($videoPath)) {
            $subtitles = SubtitleScanner::findSubtitlesForVideo($videoPath);
        } elseif (!empty($ep['folder_path']) && is_dir($ep['folder_path'])) {
            $subtitles = SubtitleScanner::findSubtitlesInFolder($ep['folder_path']);
        }
        $en = SubtitleScanner::englishSubtitle($subtitles);
        $hasEs = SubtitleScanner::hasSpanish($subtitles) || ((int)$ep['has_spanish'] === 1);
        $ids[] = (int)$ep['id'];
        $out[] = [
            'id'          => (int)$ep['id'],
            'has_es'      => $hasEs,
            'has_en'      => ($en !== null),
            'english_path'=> $en ? $en['path'] : null,
            'translation_status' => null,
        ];
    }

    // Estado de traducciones pendientes/en curso para estos episodios
    if (!empty($ids)) {
        $in = implode(',', $ids);
        $pend = $pdo->query("SELECT media_id, status FROM translation_log WHERE media_id IN ($in) AND status IN ('pending','running')")->fetchAll(PDO::FETCH_ASSOC);
        $pendMap = [];
        foreach ($pend as $p) $pendMap[(int)$p['media_id']] = $p['status'];
        foreach ($out as &$o) $o['translation_status'] = $pendMap[$o['id']] ?? null;
        unset($o);
    }

    echo json_encode(['status' => 'success', 'is_ignored' => $isIgnored, 'episodes' => $out]);
    exit;
}

// ============================================================
// Acción: estado de una película (un solo request)
// ============================================================
if ($action === 'movie_status') {
    $mid = (int)($epId ?? 0);
    if ($mid <= 0) { echo json_encode(['status' => 'error', 'message' => 'ID inválido.']); exit; }
    $ig = $pdo->prepare("SELECT is_ignored FROM movies WHERE id = ?");
    $ig->execute([$mid]);
    $isIgnored = (int)$ig->fetchColumn() === 1;

    $row = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
    $row->execute([$mid]);
    $media = $row->fetch(PDO::FETCH_ASSOC);

    $subtitles = [];
    if ($media) {
        $videoPath = $media['video_path'] ?? '';
        if ($videoPath && is_file($videoPath)) {
            $subtitles = SubtitleScanner::findSubtitlesForVideo($videoPath);
        } elseif (!empty($media['folder_path']) && is_dir($media['folder_path'])) {
            $subtitles = SubtitleScanner::findSubtitlesInFolder($media['folder_path']);
        }
    }
    $en = SubtitleScanner::englishSubtitle($subtitles);
    $hasEs = SubtitleScanner::hasSpanish($subtitles) || ((int)($media['has_spanish'] ?? 0) === 1);

    $translationStatus = null;
    $pendStmt = $pdo->prepare("SELECT status FROM translation_log WHERE media_id = ? AND status IN ('pending','running') ORDER BY created_at DESC LIMIT 1");
    $pendStmt->execute([$mid]);
    $translationStatus = $pendStmt->fetchColumn() ?: null;

    echo json_encode([
        'status' => 'success',
        'is_ignored' => $isIgnored,
        'has_es' => $hasEs,
        'has_en' => ($en !== null),
        'english_path' => $en ? $en['path'] : null,
        'translation_status' => $translationStatus,
    ]);
    exit;
}

if (!$epId) {
    echo json_encode(['html' => '<p class="text-danger small mb-0">ID no proporcionado.</p>']);
    exit;
}

try {
    // Obtener el medio desde la BD para conocer sus rutas
    if ($type === 'movies' || $type === 'movie') {
        $row = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
    } else {
        $row = $pdo->prepare("SELECT * FROM episodes WHERE id = ?");
    }
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

    // Comprobar si el elemento (o su serie) está marcado como "No monitorizar"
    $isIgnored = false;
    if ($type === 'movies' || $type === 'movie') {
        $ig = $pdo->prepare("SELECT is_ignored FROM movies WHERE id = ?");
        $ig->execute([$epId]);
        $isIgnored = (int)$ig->fetchColumn() === 1;
    } else {
        $ig = $pdo->prepare("SELECT s.is_ignored FROM episodes e JOIN series s ON s.id = e.series_id WHERE e.id = ?");
        $ig->execute([$epId]);
        $isIgnored = (int)$ig->fetchColumn() === 1;
    }

    if ($isIgnored) {
        echo json_encode(['html' => '<p class="text-muted small mb-0"><i class="fa fa-pause me-1"></i>Este elemento está marcado como <strong>No monitorizar</strong>. Se han desactivado las traducciones.</p>']);
        exit;
    }

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
