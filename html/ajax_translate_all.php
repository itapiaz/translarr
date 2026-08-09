<?php
/**
 * ajax_translate_all.php — Encola traducción de TODA una serie
 *
 * IMPORTANTE: No almacena chunks en la BD (evita corrupción por blobs grandes).
 * Solo guarda la ruta del archivo; el worker lee y procesa el archivo en su turno.
 */
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'includes/SubtitleScanner.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

rateLimitRequire('translate_all', 5, 60);

$seriesId  = $_POST['series_id'] ?? '';
$type      = $_POST['type'] ?? 'series';
$batchSize = min((int)($_POST['batch_size'] ?? 20), 50); // máx 50 por lote

if (empty($seriesId)) {
    echo json_encode(['status' => 'error', 'message' => 'ID de serie no proporcionado.']);
    exit;
}

try {
    // Obtener título de la serie
    $stmtS = $pdo->prepare("SELECT title, is_ignored FROM series WHERE id = ?");
    $stmtS->execute([$seriesId]);
    $seriesRow = $stmtS->fetch(PDO::FETCH_ASSOC);
    $seriesTitle = $seriesRow['title'] ?? '';
    $seriesIgnored = (int)($seriesRow['is_ignored'] ?? 0) === 1;

    // Si la serie está marcada como "No monitorizar", no encolar nada
    if ($seriesIgnored) {
        echo json_encode([
            'status'     => 'error',
            'message'    => 'Esta serie está marcada como "No monitorizar". Vuelve a monitorizarla desde su página antes de traducir.',
            'encolados'  => 0,
            'sin_ingles' => 0,
            'ya_es'      => 0,
            'total'      => 0,
            'batch_size' => $batchSize,
        ]);
        exit;
    }

    // Obtener todos los episodios de la serie desde la BD
    $epsStmt = $pdo->prepare("SELECT * FROM episodes WHERE series_id = ? AND has_file=1 ORDER BY season ASC, episode ASC");
    $epsStmt->execute([$seriesId]);
    $episodes = $epsStmt->fetchAll(PDO::FETCH_ASSOC);

    $encolados = 0;
    $sinIngles = 0;
    $yaEspañol = 0;
    $toQueue   = []; // acumular antes de insertar

    foreach ($episodes as $ep) {
        $mediaId = $ep['id'];

        // Ya tiene español según caché?
        if (!empty($ep['has_spanish'])) {
            $yaEspañol++;
            continue;
        }

        // Detectar subtítulos desde el filesystem (junto al vídeo o en la carpeta)
        $videoPath = $ep['video_path'] ?? '';
        $subtitles = [];
        if ($videoPath && is_file($videoPath)) {
            $subtitles = SubtitleScanner::findSubtitlesForVideo($videoPath);
        } elseif (!empty($ep['folder_path']) && is_dir($ep['folder_path'])) {
            $subtitles = SubtitleScanner::findSubtitlesInFolder($ep['folder_path']);
        }

        // Buscar subtítulo en inglés
        $englishSub = SubtitleScanner::englishSubtitle($subtitles);
        if (!$englishSub || empty($englishSub['path'])) { $sinIngles++; continue; }

        // Double-check español
        if (SubtitleScanner::hasSpanish($subtitles)) { $yaEspañol++; continue; }

        $path = $englishSub['path'];
        if (!file_exists($path)) { $sinIngles++; continue; }

        $toQueue[] = [
            'mediaId'  => $mediaId,
            'path'     => $path,
            'season'   => (int)($ep['season']  ?? 0),
            'episode'  => (int)($ep['episode'] ?? 0),
        ];

        // Respetar límite de lote
        if (count($toQueue) >= $batchSize) break;
    }

    // ============================================================
    // Insertar todo en UNA SOLA transacción (evita WAL corruption)
    // ============================================================
    if (!empty($toQueue)) {
        // Limpiar jobs viejos primero
        $pdo->exec("DELETE FROM translation_jobs WHERE created_at < datetime('now', '-2 hours')");

        $stmtJob = $pdo->prepare("INSERT INTO translation_jobs (job_id, chunks, results, path, type, media_id, series_id) VALUES (?, '[]', '[]', ?, ?, ?, ?)");
        $stmtLog = $pdo->prepare("INSERT INTO translation_log (media_id, media_title, media_type, series_id, season, episode, subtitle_path, status) VALUES (?, ?, 'episode', ?, ?, ?, ?, 'pending')");
        $stmtBg  = $pdo->prepare("INSERT INTO background_tasks (type, status, payload, created_at) VALUES ('translate', 'pending', ?, CURRENT_TIMESTAMP)");

        $pdo->beginTransaction();
        try {
            foreach ($toQueue as $item) {
                $jobId = bin2hex(random_bytes(16));

                // translation_jobs: SOLO path, sin chunks (el worker lee el archivo)
                $stmtJob->execute([$jobId, $item['path'], $type, $item['mediaId'], $seriesId]);

                // translation_log
                $stmtLog->execute([$item['mediaId'], $seriesTitle, $seriesId, $item['season'], $item['episode'], $item['path']]);
                $logId = $pdo->lastInsertId();

                // background_task payload (pequeño: solo metadatos)
                $payload = json_encode([
                    'job_id'       => $jobId,
                    'log_id'       => (int)$logId,
                    'path'         => $item['path'],
                    'type'         => $type,
                    'media_id'     => $item['mediaId'],
                    'series_id'    => $seriesId,
                    'media_title'  => $seriesTitle,
                    'season'       => $item['season'],
                    'episode'      => $item['episode'],
                    'total_chunks' => 0 // el worker calculará esto al leer el archivo
                ]);
                $stmtBg->execute([$payload]);
                $encolados++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    echo json_encode([
        'status'     => 'success',
        'encolados'  => $encolados,
        'sin_ingles' => $sinIngles,
        'ya_es'      => $yaEspañol,
        'total'      => count($episodes),
        'batch_size' => $batchSize,
        'message'    => $encolados > 0
            ? "$encolados episodios encolados. El worker los traducirá automáticamente."
            : "No hay episodios pendientes de traducción."
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
