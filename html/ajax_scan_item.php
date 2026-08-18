<?php
// html/ajax_scan_item.php — Reescanea el disco de UNA película o serie y actualiza la BD.
// Alternativa ligera al escaneo global (worker) para refrescar subtítulos de un solo contenido.
ini_set('display_errors', 0);
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/SubtitleScanner.php';
require_once 'includes/security.php';
require_once 'includes/ArrFactory.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}
csrf_require();
rateLimitRequire('scan_item', 20, 60);
set_time_limit(120);

$type = strtolower(trim($_POST['type'] ?? ''));
$id   = (int)($_POST['id'] ?? 0);

if (!in_array($type, ['movies', 'series'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Tipo inválido.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID inválido.']);
    exit;
}

/**
 * Encola traducciones (tienen EN, no tienen ES) de los candidatos dados.
 * Réplica acotada de autoEnqueueTranslations() del worker:
 * excluye lo ya pendiente/en curso y respeta auto_translate_batch_size.
 */
function enqueueTranslations(PDO $pdo, array $candidates): int {
    if (empty($candidates)) return 0;

    $batch = max(1, (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='auto_translate_batch_size'")->fetchColumn() ?: 5));

    // Excluir elementos ya en cola o traduciéndose
    $busy = [];
    foreach ($pdo->query("SELECT media_id, media_type FROM translation_log WHERE status IN ('pending','running')")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $busy[$b['media_type'] . ':' . $b['media_id']] = true;
    }

    $toQueue = [];
    foreach ($candidates as $c) {
        if (isset($busy[$c['type'] . ':' . $c['media_id']])) continue;
        $toQueue[] = $c;
        if (count($toQueue) >= $batch) break;
    }
    if (empty($toQueue)) return 0;

    $enqueued = 0;
    try {
        $stmtJob = $pdo->prepare("INSERT INTO translation_jobs (job_id, chunks, results, path, type, media_id, series_id) VALUES (?, '[]', '[]', ?, ?, ?, ?)");
        $stmtLog = $pdo->prepare("INSERT INTO translation_log (media_id, media_title, media_type, series_id, season, episode, subtitle_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmtBg  = $pdo->prepare("INSERT INTO background_tasks (type, status, payload, created_at) VALUES ('translate', 'pending', ?, CURRENT_TIMESTAMP)");
        $pdo->beginTransaction();
        foreach ($toQueue as $item) {
            $jobId = bin2hex(random_bytes(16));
            $stmtJob->execute([$jobId, $item['path'], $item['type'], $item['media_id'], $item['series_id']]);
            $stmtLog->execute([$item['media_id'], $item['title'], $item['type'], $item['series_id'], $item['season'], $item['episode'], $item['path']]);
            $logId = $pdo->lastInsertId();
            $payload = json_encode([
                'job_id' => $jobId, 'log_id' => (int)$logId, 'path' => $item['path'], 'type' => $item['type'],
                'media_id' => $item['media_id'], 'series_id' => $item['series_id'], 'media_title' => $item['title'],
                'season' => $item['season'], 'episode' => $item['episode'], 'total_chunks' => 0,
            ]);
            $stmtBg->execute([$payload]);
            $enqueued++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 0; // No fatal: el escaneo en sí ya se completó
    }
    return $enqueued;
}

/**
 * Crea el cliente Sonarr/Radarr leyendo settings (misma lógica que el worker).
 * Devuelve null si el servicio no está configurado.
 */
function arrClient(PDO $pdo, string $which) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('{$which}_url','{$which}_api_key','{$which}_enabled')");
    $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $enabled = (($cfg[$which . '_enabled'] ?? '0') === '1')
        || (($cfg[$which . '_url'] ?? '') !== '' && ($cfg[$which . '_api_key'] ?? '') !== '');
    if (!$enabled) return null;
    $key = $cfg[$which . '_api_key'] ?? '';
    if (isEncrypted($key)) $key = decryptValue($key);
    return ($which === 'sonarr')
        ? ArrFactory::sonarr($cfg[$which . '_url'] ?? '', $key)
        : ArrFactory::radarr($cfg[$which . '_url'] ?? '', $key);
}


try {
    if ($type === 'movies') {
        // ==================== PELÍCULA ====================
        $stmt = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
        $stmt->execute([$id]);
        $movie = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movie) {
            echo json_encode(['status' => 'error', 'message' => 'Película no encontrada.']);
            exit;
        }

        $source = 'disco';
        $apiError = null;
        $videoPath = $movie['video_path'] ?? '';

        // --- Refresh vía Radarr API: metadata + archivo real ---
        $radarr = arrClient($pdo, 'radarr');
        if ($radarr && !empty($movie['radarr_id'])) {
            try {
                $rm = $radarr->getMovie((int)$movie['radarr_id']);
                $pdo->prepare("UPDATE movies SET tmdb_id=?, title=?, year=?, overview=?, poster_url=?, folder_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$rm['tmdbId'] !== '' ? (int)$rm['tmdbId'] : null, $rm['title'], $rm['year'], $rm['overview'], $rm['poster'], $rm['path'], $id]);
                $movie['title'] = $rm['title'];
                $movie['folder_path'] = $rm['path'];

                $apiVideo = '';
                $mf = $rm['movieFile'] ?? null;
                if (is_array($mf)) {
                    $apiVideo = $mf['path'] ?? '';
                    if ($apiVideo === '' && !empty($mf['relativePath']) && !empty($rm['path'])) {
                        $apiVideo = rtrim($rm['path'], '/') . '/' . ltrim($mf['relativePath'], '/');
                    }
                }
                if ($apiVideo === '') {
                    try {
                        $mfList = $radarr->getMovieFiles((int)$movie['radarr_id']);
                        if (!empty($mfList[0])) {
                            $f0 = $mfList[0];
                            $apiVideo = $f0['path'];
                            if ($apiVideo === '' && $f0['relativePath'] !== '' && !empty($rm['path'])) {
                                $apiVideo = rtrim($rm['path'], '/') . '/' . ltrim($f0['relativePath'], '/');
                            }
                        }
                    } catch (Exception $e) { /* sin archivo en Radarr */ }
                }
                if ($apiVideo !== '') $videoPath = $apiVideo;
                $source = 'radarr+disco';
            } catch (Exception $e) {
                $apiError = $e->getMessage();
            }
        }

        // Re-descubrir el video en disco si la ruta sigue sin existir (renombrados)
        if ($videoPath === '' || !is_file($videoPath)) {
            $found = SubtitleScanner::findVideoInFolder($movie['folder_path'] ?? '');
            if ($found !== '') $videoPath = $found;
        }
        $hasFile = ($videoPath !== '' && is_file($videoPath)) ? 1 : 0;

        $subs = [];
        if ($hasFile) {
            $subs = SubtitleScanner::findSubtitlesForVideo($videoPath);
        } elseif (!empty($movie['folder_path']) && is_dir($movie['folder_path'])) {
            $subs = SubtitleScanner::findSubtitlesInFolder($movie['folder_path']);
        }
        $hasEs = SubtitleScanner::hasSpanish($subs) ? 1 : 0;
        $en = SubtitleScanner::englishSubtitle($subs);
        $hasEn = $en ? 1 : 0;

        $upd = $pdo->prepare("UPDATE movies SET video_path=?, has_file=?, has_spanish=?, has_english=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $upd->execute([$videoPath, $hasFile, $hasEs, $hasEn, $id]);

        // Auto-traducción acotada a esta película
        $enqueued = 0;
        if ($hasFile && $hasEn && !$hasEs
            && (int)($movie['auto_translate'] ?? 0) === 1
            && (int)($movie['is_ignored'] ?? 0) === 0
            && !empty($en['path']) && is_file($en['path'])) {
            $enqueued = enqueueTranslations($pdo, [[
                'type' => 'movie', 'media_id' => $id, 'series_id' => 0,
                'season' => 0, 'episode' => 0, 'title' => $movie['title'], 'path' => $en['path'],
            ]]);
        }

        $msg = 'Escaneo completado: ' . ($hasFile ? 'archivo localizado' : 'archivo NO encontrado')
            . ' · ' . ($hasEs ? 'con ES' : 'sin ES') . ' · ' . ($hasEn ? 'con EN' : 'sin EN');
        if ($enqueued > 0) $msg .= " · $enqueued traducción encolada";
        if ($apiError) $msg .= ' · Radarr no disponible (solo disco)';

        echo json_encode([
            'status' => 'success', 'scanned' => 1, 'source' => $source,
            'with_es' => $hasEs, 'with_en' => $hasEn, 'enqueued' => $enqueued,
            'message' => $msg,
        ]);
        exit;
    }

    // ==================== SERIE ====================
    $stmt = $pdo->prepare("SELECT * FROM series WHERE id = ?");
    $stmt->execute([$id]);
    $series = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$series) {
        echo json_encode(['status' => 'error', 'message' => 'Serie no encontrada.']);
        exit;
    }

    $source = 'disco';
    $apiError = null;
    $newEps = 0;
    $removedEps = 0;

    // --- Refresh vía Sonarr API: metadata + descubrir episodios nuevos/movidos/eliminados ---
    $sonarr = arrClient($pdo, 'sonarr');
    if ($sonarr && !empty($series['sonarr_series_id'])) {
        try {
            $sonarrId = (int)$series['sonarr_series_id'];

            // Metadata de la serie
            $ss = $sonarr->getSeriesById($sonarrId);
            $pdo->prepare("UPDATE series SET tvdb_id=?, title=?, year=?, overview=?, poster_url=?, folder_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$ss['tvdbId'] !== '' ? (int)$ss['tvdbId'] : null, $ss['title'], $ss['year'], $ss['overview'], $ss['poster'], $ss['path'], $id]);
            $series['title'] = $ss['title'];

            // Episodios y archivos desde Sonarr
            $apiEps = $sonarr->getEpisodes($sonarrId);
            $files = $sonarr->getEpisodeFiles($sonarrId);
            $fileById = [];
            foreach ($files as $f) {
                $p = $f['path'];
                if ($p === '' && $f['relativePath'] !== '') {
                    $p = rtrim($ss['path'], '/') . '/' . ltrim($f['relativePath'], '/');
                }
                if ($p !== '') $fileById[$f['id']] = $p;
            }

            // IDs ya conocidos en BD (para contar nuevos)
            $known = $pdo->prepare("SELECT sonarr_episode_id FROM episodes WHERE series_id=?");
            $known->execute([$id]);
            $knownIds = array_map('intval', $known->fetchAll(PDO::FETCH_COLUMN));

            // Upsert estructural (sin flags de subtítulos: los calcula el escaneo de disco posterior)
            $upsertEp = $pdo->prepare("INSERT INTO episodes (series_id, sonarr_episode_id, tvdb_episode_id, title, season, episode, video_path, has_file, updated_at) VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(sonarr_episode_id) DO UPDATE SET series_id=excluded.series_id,title=excluded.title,season=excluded.season,episode=excluded.episode,tvdb_episode_id=excluded.tvdb_episode_id,video_path=excluded.video_path,has_file=excluded.has_file,updated_at=CURRENT_TIMESTAMP");
            $apiIds = [];
            foreach ($apiEps as $ep) {
                $videoPath = $fileById[$ep['episodeFileId']] ?? '';
                $upsertEp->execute([$id, (int)$ep['id'], $ep['tvdbEpisodeId'] !== '' ? (int)$ep['tvdbEpisodeId'] : null, $ep['title'], (int)$ep['season'], (int)$ep['episode'], $videoPath, $ep['hasFile'] ? 1 : 0]);
                $apiIds[] = (int)$ep['id'];
                if (!in_array((int)$ep['id'], $knownIds, true)) $newEps++;
            }

            // Episodios que ya no existen en Sonarr
            if (!empty($apiIds)) {
                $removedEps = (int)$pdo->exec("DELETE FROM episodes WHERE series_id=$id AND sonarr_episode_id NOT IN (" . implode(',', $apiIds) . ")");
            }
            $source = 'sonarr+disco';
        } catch (Exception $e) {
            $apiError = $e->getMessage();
        }
    }

    $epStmt = $pdo->prepare("SELECT id, title, season, episode, video_path FROM episodes WHERE series_id = ?");
    $epStmt->execute([$id]);
    $episodes = $epStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($episodes)) {
        echo json_encode(['status' => 'error', 'message' => 'La serie no tiene episodios catalogados.' . ($apiError ? ' Sonarr no disponible: ' . $apiError : '')]);
        exit;
    }

    $autoTranslate = (int)($series['auto_translate'] ?? 0) === 1;
    $isIgnored = (int)($series['is_ignored'] ?? 0) === 1;

    $upd = $pdo->prepare("UPDATE episodes SET has_file=?, has_spanish=?, has_english=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $scanned = 0; $withEs = 0; $withEn = 0; $missing = 0;
    $candidates = [];

    foreach ($episodes as $ep) {
        $videoPath = $ep['video_path'] ?? '';
        $hasFile = ($videoPath !== '' && is_file($videoPath)) ? 1 : 0;

        $subs = [];
        if ($hasFile) {
            $subs = SubtitleScanner::findSubtitlesForVideo($videoPath);
        }
        $hasEs = SubtitleScanner::hasSpanish($subs) ? 1 : 0;
        $en = SubtitleScanner::englishSubtitle($subs);
        $hasEn = $en ? 1 : 0;

        $upd->execute([$hasFile, $hasEs, $hasEn, (int)$ep['id']]);
        $scanned++;
        if (!$hasFile) $missing++;
        if ($hasEs) $withEs++;
        if ($hasEn) $withEn++;

        if ($hasFile && $hasEn && !$hasEs && $autoTranslate && !$isIgnored
            && !empty($en['path']) && is_file($en['path'])) {
            $candidates[] = [
                'type' => 'episode', 'media_id' => (int)$ep['id'], 'series_id' => $id,
                'season' => (int)$ep['season'], 'episode' => (int)$ep['episode'],
                'title' => $ep['title'] ?? '', 'path' => $en['path'],
            ];
        }
    }

    $enqueued = enqueueTranslations($pdo, $candidates);

    $msg = "Escaneo completado: $scanned episodios revisados · $withEs con ES · $withEn con EN";
    if ($newEps > 0) $msg .= " · $newEps episodio(s) nuevo(s)";
    if ($removedEps > 0) $msg .= " · $removedEps eliminado(s)";
    if ($missing > 0) $msg .= " · $missing sin archivo";
    if ($enqueued > 0) $msg .= " · $enqueued traducción(es) encolada(s)";
    if ($apiError) $msg .= ' · Sonarr no disponible (solo disco)';

    echo json_encode([
        'status' => 'success', 'scanned' => $scanned, 'source' => $source,
        'new_episodes' => $newEps, 'removed_episodes' => $removedEps,
        'with_es' => $withEs, 'with_en' => $withEn, 'enqueued' => $enqueued,
        'message' => $msg,
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
