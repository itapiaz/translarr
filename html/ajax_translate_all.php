<?php
/**
 * ajax_translate_all.php — Encola traducción de TODA una serie
 *
 * IMPORTANTE: No almacena chunks en la BD (evita corrupción por blobs grandes).
 * Solo guarda la ruta del archivo; el worker lee y procesa el archivo en su turno.
 */
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'includes/MediaServerFactory.php';
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
    // Leer config fresh de BD (el API puede haber cambiado)
    $cfgRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('media_server_type','media_server_url','media_server_api_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $msType   = $cfgRows['media_server_type'] ?? '';
    $msUrl    = rtrim($cfgRows['media_server_url'] ?? '', '/');
    $msApiKey = $cfgRows['media_server_api_key'] ?? '';
    if (isEncrypted($msApiKey)) $msApiKey = decryptValue($msApiKey);
    $api = MediaServerFactory::getAPI($msType, $msUrl, $msApiKey);

    // Obtener título de la serie
    $stmtS = $pdo->prepare("SELECT title FROM media_cache WHERE id = ? AND type='series'");
    $stmtS->execute([$seriesId]);
    $seriesTitle = $stmtS->fetchColumn() ?: '';

    // Obtener todos los episodios de la serie
    $episodes = $api->getEpisodes($seriesId);

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

        // Obtener subtítulos del episodio
        $subtitles = $api->getSubtitles('series', $mediaId);

        // Buscar subtítulo en inglés
        $englishSub = null;
        foreach ($subtitles as $sub) {
            $lang = strtolower($sub['code2'] ?? $sub['name'] ?? $sub['language'] ?? '');
            if (in_array($lang, ['en', 'eng', 'english'])) {
                $englishSub = $sub;
                break;
            }
        }
        if (!$englishSub || empty($englishSub['path'])) { $sinIngles++; continue; }

        // Double-check español
        $hasSpanish = false;
        foreach ($subtitles as $sub) {
            $lang = strtolower($sub['code2'] ?? $sub['name'] ?? $sub['language'] ?? '');
            if (in_array($lang, ['es', 'spa']) || strpos($lang, 'spanish') !== false) {
                $hasSpanish = true;
                break;
            }
        }
        if ($hasSpanish) { $yaEspañol++; continue; }

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
