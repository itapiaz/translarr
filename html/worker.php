<?php
/**
 * worker.php — Worker Loop Infinito
 * USO: php /app/www/public/worker.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

$lockFile = '/config/worker.lock';
@unlink($lockFile);
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle) { echo "ERROR: lock file\n"; exit(1); }
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) { echo "Worker ya corre.\n"; exit(0); }

define('WORKER_RUNNING', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/ArrFactory.php';
require_once __DIR__ . '/includes/SubtitleScanner.php';
require_once __DIR__ . '/includes/security.php';

$lastScanTime = 0;
$triggerFile = '/config/scan_trigger.now';

function workerLog($msg) { echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n"; }

function freshPDO(): PDO {
    global $dbPath;
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA busy_timeout=5000;");
    return $pdo;
}

// === MIGRACIONES DE ESQUEMA ===
// Garantizar que la BD tiene todas las columnas necesarias,
// independientemente de cuándo fue creada la tabla.
workerLog("=== Worker loop iniciado === DB: {$dbPath}");
$_migPdo = freshPDO();
$_migrations = [
    "ALTER TABLE background_tasks ADD COLUMN started_at DATETIME",
    "ALTER TABLE background_tasks ADD COLUMN finished_at DATETIME",
    "ALTER TABLE background_tasks ADD COLUMN payload TEXT",
    "ALTER TABLE background_tasks ADD COLUMN result TEXT",
    "ALTER TABLE translation_log ADD COLUMN started_at DATETIME",
    "ALTER TABLE media_cache ADD COLUMN overview TEXT",
    "ALTER TABLE media_cache ADD COLUMN folder_path TEXT",
    "ALTER TABLE media_cache ADD COLUMN sonarr_series_id TEXT",
    "ALTER TABLE media_cache ADD COLUMN sonarr_episode_id TEXT",
    "ALTER TABLE media_cache ADD COLUMN tvdb_id TEXT",
    "ALTER TABLE media_cache ADD COLUMN radarr_id TEXT",
    "ALTER TABLE media_cache ADD COLUMN tmdb_id TEXT",
    "ALTER TABLE media_cache ADD COLUMN video_path TEXT",
];
foreach ($_migrations as $_sql) {
    try { $_migPdo->exec($_sql); } catch (Exception $_e) { /* columna ya existe, ignorar */ }
}
$_migPdo = null;
unset($_migrations, $_sql, $_e);
// === FIN MIGRACIONES ===


function doScanMedia(): string {
    workerLog("=== Escaneando (Sonarr/Radarr) ===");
    $pdo = freshPDO();

    // Recargar config desde BD (por si cambió entre ciclos)
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sonarr_url','sonarr_api_key','sonarr_enabled','radarr_url','radarr_api_key','radarr_enabled')");
    $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats = ['movies' => 0, 'series' => 0, 'episodes' => 0];
    $scanned = ['movies' => false, 'series' => false, 'episodes' => false];
    $scanTime = $pdo->query("SELECT datetime('now')")->fetchColumn();

    // ==================== SONARR (series y episodios) ====================
    $sonarrEnabled = (($cfg['sonarr_enabled'] ?? '0') === '1')
        || (($cfg['sonarr_url'] ?? '') !== '' && ($cfg['sonarr_api_key'] ?? '') !== '');
    if (!$sonarrEnabled) {
        workerLog("Sonarr no configurado. Omitido.");
    } else {
        try {
            $sonarrKey = $cfg['sonarr_api_key'] ?? '';
            if (isEncrypted($sonarrKey)) $sonarrKey = decryptValue($sonarrKey);
            $sonarr = ArrFactory::sonarr($cfg['sonarr_url'] ?? '', $sonarrKey);

            $seriesList = $sonarr->getSeries();

            $upsertSeries = $pdo->prepare("INSERT INTO media_cache(id,type,title,year,tvdb_id,sonarr_series_id,poster_url,overview,folder_path,updated_at) VALUES(?,'series',?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET title=excluded.title,year=excluded.year,tvdb_id=excluded.tvdb_id,sonarr_series_id=excluded.sonarr_series_id,poster_url=excluded.poster_url,overview=excluded.overview,folder_path=excluded.folder_path,updated_at=CURRENT_TIMESTAMP");

            $pdo->beginTransaction();
            foreach ($seriesList as $s) {
                $upsertSeries->execute(['series:' . $s['id'], $s['title'], $s['year'], $s['tvdbId'], $s['id'], $s['poster'], $s['overview'], $s['path']]);
                $stats['series']++;
            }
            $pdo->commit();
            $scanned['series'] = true;

            $upsertEp = $pdo->prepare("INSERT INTO media_cache(id,type,series_id,title,season,episode,tvdb_id,sonarr_series_id,sonarr_episode_id,video_path,folder_path,has_spanish,updated_at) VALUES(?,'episode',?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET series_id=excluded.series_id,title=excluded.title,season=excluded.season,episode=excluded.episode,tvdb_id=excluded.tvdb_id,sonarr_series_id=excluded.sonarr_series_id,sonarr_episode_id=excluded.sonarr_episode_id,video_path=excluded.video_path,folder_path=excluded.folder_path,has_spanish=excluded.has_spanish,updated_at=CURRENT_TIMESTAMP");
            foreach ($seriesList as $s) {
                try {
                    $eps = $sonarr->getEpisodes($s['id']);
                    $files = $sonarr->getEpisodeFiles($s['id']);
                    $fileById = [];
                    foreach ($files as $f) {
                        $p = $f['path'];
                        if ($p === '' && $f['relativePath'] !== '') {
                            $p = rtrim($s['path'], '/') . '/' . ltrim($f['relativePath'], '/');
                        }
                        if ($p !== '') $fileById[$f['id']] = $p;
                    }
                    $pdo->beginTransaction();
                    foreach ($eps as $ep) {
                        $videoPath = isset($fileById[$ep['episodeFileId']]) ? $fileById[$ep['episodeFileId']] : '';
                        $hasSpanish = 0;
                        if ($videoPath && is_file($videoPath)) {
                            $hasSpanish = SubtitleScanner::hasSpanish(SubtitleScanner::findSubtitlesForVideo($videoPath)) ? 1 : 0;
                        }
                        $upsertEp->execute(['episode:' . $ep['id'], 'series:' . $s['id'], $ep['title'], (int)$ep['season'], (int)$ep['episode'], $ep['tvdbEpisodeId'], $s['id'], $ep['id'], $videoPath, $s['path'], $hasSpanish]);
                        $stats['episodes']++;
                    }
                    $pdo->commit();
                    $scanned['episodes'] = true;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    workerLog("  Error eps {$s['title']}: " . $e->getMessage());
                }
            }
            workerLog("Sonarr: {$stats['series']} series, {$stats['episodes']} episodios.");
        } catch (Exception $e) {
            workerLog("Error Sonarr: " . $e->getMessage());
        }
    }

    // ==================== RADARR (películas) ====================
    $radarrEnabled = (($cfg['radarr_enabled'] ?? '0') === '1')
        || (($cfg['radarr_url'] ?? '') !== '' && ($cfg['radarr_api_key'] ?? '') !== '');
    if (!$radarrEnabled) {
        workerLog("Radarr no configurado. Omitido.");
    } else {
        try {
            $radarrKey = $cfg['radarr_api_key'] ?? '';
            if (isEncrypted($radarrKey)) $radarrKey = decryptValue($radarrKey);
            $radarr = ArrFactory::radarr($cfg['radarr_url'] ?? '', $radarrKey);

            $movies = $radarr->getMovies();

            $upsertMovie = $pdo->prepare("INSERT INTO media_cache(id,type,title,year,tmdb_id,radarr_id,poster_url,overview,folder_path,video_path,has_spanish,updated_at) VALUES(?,'movie',?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET title=excluded.title,year=excluded.year,tmdb_id=excluded.tmdb_id,radarr_id=excluded.radarr_id,poster_url=excluded.poster_url,overview=excluded.overview,folder_path=excluded.folder_path,video_path=excluded.video_path,has_spanish=excluded.has_spanish,updated_at=CURRENT_TIMESTAMP");

            $pdo->beginTransaction();
            foreach ($movies as $m) {
                // 1) Radarr v3 embebe movieFile en el objeto de la película
                $videoPath = '';
                $mf = $m['movieFile'] ?? null;
                if (is_array($mf)) {
                    $videoPath = $mf['path'] ?? '';
                    if ($videoPath === '' && !empty($mf['relativePath']) && !empty($m['path'])) {
                        $videoPath = rtrim($m['path'], '/') . '/' . ltrim($mf['relativePath'], '/');
                    }
                }
                // 2) Fallback: consultar el archivo por movieId (Radarr exige el filtro)
                if ($videoPath === '') {
                    try {
                        $mfList = $radarr->getMovieFiles($m['id']);
                        if (!empty($mfList[0])) {
                            $f0 = $mfList[0];
                            $videoPath = $f0['path'];
                            if ($videoPath === '' && $f0['relativePath'] !== '' && !empty($m['path'])) {
                                $videoPath = rtrim($m['path'], '/') . '/' . ltrim($f0['relativePath'], '/');
                            }
                        }
                    } catch (Exception $e) {
                        // Sin archivo por API; se intentará localmente
                    }
                }
                // 3) Último recurso: buscar un vídeo en la carpeta
                if ($videoPath === '') {
                    $videoPath = SubtitleScanner::findVideoInFolder($m['path'] ?? '');
                }
                $hasSpanish = 0;
                if ($videoPath && is_file($videoPath)) {
                    $hasSpanish = SubtitleScanner::hasSpanish(SubtitleScanner::findSubtitlesForVideo($videoPath)) ? 1 : 0;
                }
                $upsertMovie->execute(['movie:' . $m['id'], $m['title'], $m['year'], $m['tmdbId'], $m['id'], $m['poster'], $m['overview'], $m['path'], $videoPath, $hasSpanish]);
                $stats['movies']++;
            }
            $pdo->commit();
            $scanned['movies'] = true;
            workerLog("Radarr: {$stats['movies']} películas.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            workerLog("Error Radarr: " . $e->getMessage());
        }
    }
        
    // Limpiar solo los tipos que se escanearon correctamente
    $pdo = freshPDO();
    $d = 0;
    if ($scanned['series'] || $scanned['episodes']) {
        $d += $pdo->exec("DELETE FROM media_cache WHERE type IN ('series','episode') AND updated_at < '$scanTime'");
    }
    if ($scanned['movies']) {
        $d += $pdo->exec("DELETE FROM media_cache WHERE type='movie' AND updated_at < '$scanTime'");
    }
    $pdo = null;
    workerLog("Limpieza: $d registros.");
    $r = "{$stats['movies']} movies, {$stats['series']} series, {$stats['episodes']} eps.";
    workerLog("OK. $r");
    return $r;
}

function doTrans(int $taskId, string $payloadJson): void {
    $pl = json_decode($payloadJson, true);
    $jobId = $pl['job_id'] ?? '';
    $logId = $pl['log_id'] ?? 0;
    workerLog("Traduciendo $jobId...");
    
    // Marcar como 'running' en translation_log
    if ($logId) {
        $px = freshPDO();
        $px->prepare("UPDATE translation_log SET status='running', started_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$logId]);
        $px = null;
    }
    
    // Leer job
    $pdo = freshPDO();
    $st = $pdo->prepare("SELECT * FROM translation_jobs WHERE job_id=?");
    $st->execute([$jobId]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    $pdo = null;
    if (!$job) { workerLog("  Error: Job no encontrado"); return; }
    
    $chunks = json_decode($job['chunks'], true);

    // Si no hay chunks pre-almacenados, leer el archivo del disco (nuevo flujo)
    if (empty($chunks)) {
        $filePath = $job['path'] ?? $pl['path'] ?? '';
        if (empty($filePath) || !file_exists($filePath)) {
            workerLog("  Error: Archivo no encontrado: $filePath");
            markError($logId, "Archivo no encontrado: $filePath");
            return;
        }
        $content = @file_get_contents($filePath);
        if ($content === false || empty(trim($content))) {
            workerLog("  Error: Archivo vacío o ilegible: $filePath");
            markError($logId, "Archivo vacío o ilegible");
            return;
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $det = mb_detect_encoding($content, 'UTF-8, ISO-8859-1, WINDOWS-1252', true);
            $content = mb_convert_encoding($content, 'UTF-8', $det ?: 'ISO-8859-1');
        }
        $content = str_replace("\r\n", "\n", strip_tags($content));
        $blocks  = array_values(array_filter(explode("\n\n", $content), fn($b) => trim($b) !== ''));
        $chunks  = array_chunk($blocks, CHUNK_SIZE ?: 50);
        workerLog("  Archivo leído: " . count($blocks) . " bloques → " . count($chunks) . " chunks");
    }

    if (empty($chunks)) { workerLog("  Error: Sin contenido para traducir"); markError($logId, "Sin chunks"); return; }

    
    // Leer DeepSeek config fresca desde BD (el worker puede haber arrancado antes de guardarla)
    $pdoCfg = freshPDO();
    $cfgRows = $pdoCfg->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('deepseek_api_key','system_prompt')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdoCfg = null;
    $dsKey = $cfgRows['deepseek_api_key'] ?? '';
    if (isEncrypted($dsKey)) $dsKey = decryptValue($dsKey);
    $dsPrompt = $cfgRows['system_prompt'] ?? DEEPSEEK_SYSTEM_PROMPT;
    
    if (empty($dsKey)) {
        workerLog("  Error: API Key de DeepSeek no configurada. Ve a Configuracion > DeepSeek AI.");
        markError($logId, "API Key de DeepSeek vacia");
        return;
    }
    
    $results = [];
    $total = count($chunks);
    
    for ($i = 0; $i < $total; $i++) {
        workerLog("  Chunk ".($i+1)."/$total...");
        $req = ['model'=>'deepseek-chat','messages'=>[['role'=>'system','content'=>$dsPrompt],['role'=>'user','content'=>implode("\n\n",$chunks[$i])]],'temperature'=>0.3];
        $ch = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($req),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$dsKey],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>300]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($cerr) { workerLog("  Error DeepSeek: $cerr"); markError($logId, "DeepSeek: $cerr"); return; }
        if ($code >= 400) { $e = json_decode($resp,true); $msg = $e['error']['message']??$resp; workerLog("  Error DeepSeek HTTP $code: $msg"); markError($logId, "DeepSeek HTTP $code: $msg"); return; }
        
        $d = json_decode($resp,true);
        $t = $d['choices'][0]['message']['content'] ?? null;
        if (!$t) { workerLog("  Error: Resp vacia"); markError($logId, "Resp vacia chunk ".($i+1)); return; }
        
        $lines = explode("\n", str_replace("\r\n","\n",$t));
        $clean = []; $in = false;
        foreach ($lines as $l) { if (strpos(trim($l),'```')===0) { $in=!$in; continue; } if($in||strpos($t,'```')===false) $clean[]=$l; }
        $final = strpos($t,'```')!==false ? implode("\n",$clean) : trim($t);
        if ($i===0 && preg_match('/^(?:.*?\n)*?(1\n\d{2}:\d{2}:\d{2},\d{3} -->)/s',$final,$m,PREG_OFFSET_CAPTURE)) { $final=substr($final,$m[1][1]); }
        $results[$i] = trim($final);
        
        $pdo = freshPDO();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE translation_jobs SET results=? WHERE job_id=?")->execute([json_encode($results),$jobId]);
        $pdo->commit();
        $pdo = null;
    }
    
    // Guardar SRT final
    $finalSrt = trim(implode("\n\n",$results));
    
    // Guardar el SRT final en el filesystem, junto al subtítulo original
    $origPath = $job['path'];
    $stype = strtolower(trim($job['type']));
    $from = ($stype==='movies'||$stype==='movie') ? (defined('PATH_MAPPING_MOVIES_FROM')?PATH_MAPPING_MOVIES_FROM:'') : (defined('PATH_MAPPING_SERIES_FROM')?PATH_MAPPING_SERIES_FROM:'');
    $to = ($stype==='movies'||$stype==='movie') ? (defined('PATH_MAPPING_MOVIES_TO')?PATH_MAPPING_MOVIES_TO:'') : (defined('PATH_MAPPING_SERIES_TO')?PATH_MAPPING_SERIES_TO:'');
    $wp = $origPath;
    if ($from !== '' && $to !== '' && strpos($origPath, $from) === 0) $wp = $to . substr($origPath, strlen($from));

    try {
        $dir = dirname($wp);
        $fn = basename($wp);
        $parts = explode('.', $fn);
        $ext = array_pop($parts);
        if (count($parts) > 1 && strlen(end($parts)) <= 3) array_pop($parts);
        $parts[] = 'es';
        $parts[] = $ext;
        $np = $dir . DIRECTORY_SEPARATOR . implode('.', $parts);

        file_put_contents($np, $finalSrt);
        workerLog("  Guardado: $np");
    } catch (Exception $e) {
        workerLog("  Error guardando: ".$e->getMessage());
        markError($logId, "Guardado: ".$e->getMessage());
        return;
    }
    
    // Marcar completado
    $pdo = freshPDO();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE media_cache SET has_spanish=1 WHERE id=?")->execute([$job['media_id']]);
    if ($logId) $pdo->prepare("UPDATE translation_log SET status='completed',finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$logId]);
    $pdo->prepare("DELETE FROM translation_jobs WHERE job_id=?")->execute([$jobId]);
    $pdo->commit();
    $pdo = null;
    workerLog("  OK ($total chunks)");
}

function markError(int $logId, string $msg): void {
    if (!$logId) return;
    $pdo = freshPDO();
    $pdo->prepare("UPDATE translation_log SET status='error',result=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$msg,$logId]);
    $pdo = null;
}

// === LOOP ===
while (true) {
    if (file_exists($triggerFile)) {
        @unlink($triggerFile);
        $r = doScanMedia();
        $lastScanTime = time();
        $p2 = freshPDO();
        $p2->prepare("INSERT INTO background_tasks(type,status,result,created_at,finished_at) VALUES('scan_media','done',?,datetime('now'),datetime('now'))")->execute([$r]);
        $p2 = null;
    }
    
    if ($lastScanTime === 0) {
        $p2 = freshPDO();
        $l = $p2->query("SELECT MAX(finished_at) FROM background_tasks WHERE type='scan_media' AND status='done'")->fetchColumn();
        $p2 = null;
        $lastScanTime = $l ? strtotime($l) : 0;
    }
    try {
        $p2 = freshPDO();
        $im = (int)($p2->query("SELECT setting_value FROM settings WHERE setting_key='scan_interval_minutes'")->fetchColumn() ?: 60);
        $p2 = null;
        $si = $im * 60;
    } catch (Exception $e) { $si = 3600; }
    
    if ($lastScanTime > 0 && (time() - $lastScanTime) >= $si) {
        $r = doScanMedia();
        $lastScanTime = time();
        $p2 = freshPDO();
        $p2->prepare("INSERT INTO background_tasks(type,status,result,created_at,finished_at) VALUES('scan_media','done',?,datetime('now'),datetime('now'))")->execute([$r]);
        $p2 = null;
    }
    
    try {
        $p2 = freshPDO();
        $task = $p2->query("SELECT id,payload FROM background_tasks WHERE status='pending' AND type='translate' ORDER BY created_at ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $p2 = null;
        if ($task) {
            $p3 = freshPDO();
            $p3->prepare("UPDATE background_tasks SET status='running',started_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$task['id']]);
            $p3 = null;
            doTrans((int)$task['id'], $task['payload']);
            $p4 = freshPDO();
            $p4->prepare("UPDATE background_tasks SET status='done',finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$task['id']]);
            $p4 = null;
        }
    } catch (Exception $e) { workerLog("Error: ".$e->getMessage()); }
    
    sleep(5);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
