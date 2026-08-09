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
require_once __DIR__ . '/includes/TranslationProviderFactory.php';

// La conexión global abierta por config.php no se usa en el worker;
// cerrarla evita que retenga bloqueos sobre la BD durante el bucle.
$pdo = null;

$lastScanTime = 0;
$triggerFile = '/config/scan_trigger.now';

function workerLog($msg) { echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n"; }

function freshPDO(): PDO {
    global $dbPath;
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA busy_timeout=30000;");
    $pdo->exec("PRAGMA journal_mode=WAL;");
    return $pdo;
}

/**
 * Ejecuta una operación SQLite con reintentos ante bloqueos transitorios
 * ("database is locked" / "database table is locked"). Devuelve el resultado
 * de la última llamada o lanza la excepción si no prospera.
 */
function retryDb(callable $fn, int $attempts = 5, float $delaySec = 0.4) {
    $last = null;
    for ($i = 1; $i <= $attempts; $i++) {
        try {
            return $fn();
        } catch (Exception $e) {
            $last = $e;
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'locked') === false) {
                throw $e; // no es un bloqueo, no reintentar
            }
            if ($i < $attempts) {
                workerLog("  DB bloqueada, reintento $i/$attempts...");
                usleep((int) ($delaySec * 1000000));
            }
        }
    }
    throw $last;
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
];
foreach ($_migrations as $_sql) {
    try { $_migPdo->exec($_sql); } catch (Exception $_e) { /* columna ya existe, ignorar */ }
}
$_migPdo = null;
unset($_migrations, $_sql, $_e);
// === FIN MIGRACIONES ===

// === RECUPERACIÓN DE TAREAS HUÉRFANAS ===
// Al arrancar, cualquier tarea que quedó 'running' (por reinicio del worker,
// del contenedor o despliegue) se marca como 'error' para no bloquear la cola.
$orphanPdo = freshPDO();
try {
    $n1 = $orphanPdo->exec("UPDATE background_tasks SET status='error', result='Worker reiniciado o tarea interrumpida', finished_at=CURRENT_TIMESTAMP WHERE type='translate' AND status='running'");
    $n2 = $orphanPdo->exec("UPDATE translation_log SET status='error', result='Worker reiniciado o tarea interrumpida', finished_at=CURRENT_TIMESTAMP WHERE status='running'");
    if ($n1 > 0 || $n2 > 0) {
        workerLog("Recuperadas tareas huérfanas: background_tasks=$n1, translation_log=$n2");
    }
} catch (Exception $e) {
    workerLog("Error recuperando tareas huérfanas: ".$e->getMessage());
}
$orphanPdo = null;
// === FIN RECUPERACIÓN ===


function doScanMedia(): string {
    workerLog("=== Escaneando (Sonarr/Radarr) ===");
    $stats = ['movies' => 0, 'series' => 0, 'episodes' => 0];
    $scanned = ['movies' => false, 'series' => false, 'episodes' => false];

    // Leer config
    $cfgPdo = freshPDO();
    $stmt = $cfgPdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sonarr_url','sonarr_api_key','sonarr_enabled','radarr_url','radarr_api_key','radarr_enabled')");
    $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $scanTime = $cfgPdo->query("SELECT datetime('now')")->fetchColumn();
    $cfgPdo = null;

    // Vaciar el WAL antes del escaneo para evitar checkpoints intermedios
    $ck = freshPDO();
    @$ck->exec("PRAGMA wal_checkpoint(TRUNCATE)");
    $ck = null;

    // ==================== SONARR ====================
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

            // Upsert de series (autocommit)
            $pdo = freshPDO();
            $upsertSeries = $pdo->prepare("INSERT INTO series (sonarr_series_id, tvdb_id, title, year, overview, poster_url, folder_path, updated_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(sonarr_series_id) DO UPDATE SET tvdb_id=excluded.tvdb_id,title=excluded.title,year=excluded.year,overview=excluded.overview,poster_url=excluded.poster_url,folder_path=excluded.folder_path,updated_at=CURRENT_TIMESTAMP");
            $getSeriesId = $pdo->prepare("SELECT id FROM series WHERE sonarr_series_id=?");
            $seriesIdMap = [];
            foreach ($seriesList as $s) {
                $upsertSeries->execute([(int)$s['id'], $s['tvdbId'] !== '' ? (int)$s['tvdbId'] : null, $s['title'], $s['year'], $s['overview'], $s['poster'], $s['path']]);
                $getSeriesId->execute([(int)$s['id']]);
                $seriesIdMap[$s['id']] = (int)$getSeriesId->fetchColumn();
                $stats['series']++;
            }
            $pdo = null;
            $scanned['series'] = true;

            // Upsert de episodios (autocommit)
            $pdo = freshPDO();
            $upsertEp = $pdo->prepare("INSERT INTO episodes (series_id, sonarr_episode_id, tvdb_episode_id, title, season, episode, video_path, has_file, has_spanish, updated_at) VALUES(?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(sonarr_episode_id) DO UPDATE SET series_id=excluded.series_id,title=excluded.title,season=excluded.season,episode=excluded.episode,tvdb_episode_id=excluded.tvdb_episode_id,video_path=excluded.video_path,has_file=excluded.has_file,has_spanish=excluded.has_spanish,updated_at=CURRENT_TIMESTAMP");
            foreach ($seriesList as $s) {
                $seriesDbId = $seriesIdMap[$s['id']] ?? null;
                if (!$seriesDbId) continue;
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
                    foreach ($eps as $ep) {
                        $videoPath = isset($fileById[$ep['episodeFileId']]) ? $fileById[$ep['episodeFileId']] : '';
                        $hasSpanish = 0;
                        if ($videoPath && is_file($videoPath)) {
                            $hasSpanish = SubtitleScanner::hasSpanish(SubtitleScanner::findSubtitlesForVideo($videoPath)) ? 1 : 0;
                        }
                        $upsertEp->execute([$seriesDbId, (int)$ep['id'], $ep['tvdbEpisodeId'] !== '' ? (int)$ep['tvdbEpisodeId'] : null, $ep['title'], (int)$ep['season'], (int)$ep['episode'], $videoPath, $ep['hasFile'] ? 1 : 0, $hasSpanish]);
                        $stats['episodes']++;
                    }
                    $scanned['episodes'] = true;
                } catch (Exception $e) {
                    workerLog("  Error eps {$s['title']}: " . $e->getMessage());
                }
            }
            $pdo = null;
            workerLog("Sonarr: {$stats['series']} series, {$stats['episodes']} episodios.");
        } catch (Exception $e) {
            workerLog("Error Sonarr: " . $e->getMessage());
        }
    }

    // ==================== RADARR ====================
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

            $pdo = freshPDO();
            $upsertMovie = $pdo->prepare("INSERT INTO movies (radarr_id, tmdb_id, title, year, overview, poster_url, folder_path, video_path, has_file, has_spanish, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(radarr_id) DO UPDATE SET tmdb_id=excluded.tmdb_id,title=excluded.title,year=excluded.year,overview=excluded.overview,poster_url=excluded.poster_url,folder_path=excluded.folder_path,video_path=excluded.video_path,has_file=excluded.has_file,has_spanish=excluded.has_spanish,updated_at=CURRENT_TIMESTAMP");

            foreach ($movies as $m) {
                $videoPath = '';
                $mf = $m['movieFile'] ?? null;
                if (is_array($mf)) {
                    $videoPath = $mf['path'] ?? '';
                    if ($videoPath === '' && !empty($mf['relativePath']) && !empty($m['path'])) {
                        $videoPath = rtrim($m['path'], '/') . '/' . ltrim($mf['relativePath'], '/');
                    }
                }
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
                    }
                }
                if ($videoPath === '') {
                    $videoPath = SubtitleScanner::findVideoInFolder($m['path'] ?? '');
                }
                $hasSpanish = 0;
                if ($videoPath && is_file($videoPath)) {
                    $hasSpanish = SubtitleScanner::hasSpanish(SubtitleScanner::findSubtitlesForVideo($videoPath)) ? 1 : 0;
                }
                $upsertMovie->execute([(int)$m['id'], $m['tmdbId'] !== '' ? (int)$m['tmdbId'] : null, $m['title'], $m['year'], $m['overview'], $m['poster'], $m['path'], $videoPath, $m['hasFile'] ? 1 : 0, $hasSpanish]);
                $stats['movies']++;
            }
            $pdo = null;
            $scanned['movies'] = true;
            workerLog("Radarr: {$stats['movies']} películas.");
        } catch (Exception $e) {
            workerLog("Error Radarr: " . $e->getMessage());
        }
    }

    // Limpieza de registros obsoletos (solo tipos escaneados bien)
    $d = 0;
    $pdo = freshPDO();
    if ($scanned['series'] || $scanned['episodes']) {
        $d += $pdo->exec("DELETE FROM series WHERE updated_at < '$scanTime'");
        $d += $pdo->exec("DELETE FROM episodes WHERE updated_at < '$scanTime'");
    }
    if ($scanned['movies']) {
        $d += $pdo->exec("DELETE FROM movies WHERE updated_at < '$scanTime'");
    }
    $pdo = null;

    // Checkpoint final para compactar el WAL
    $ck = freshPDO();
    @$ck->exec("PRAGMA wal_checkpoint(TRUNCATE)");
    $ck = null;

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

    // Si la película o la serie del episodio está "No monitorizar", cancelar la tarea
    $jobType = strtolower(trim($job['type'] ?? ''));
    $isMovieJob = ($jobType === 'movies' || $jobType === 'movie');
    $mediaId = $job['media_id'] ?? $pl['media_id'] ?? '';
    $seriesId = $job['series_id'] ?? $pl['series_id'] ?? '';
    $ignored = false;
    $ignPdo = freshPDO();
    try {
        if ($isMovieJob) {
            $ig = $ignPdo->prepare("SELECT COUNT(*) FROM movies WHERE id=? AND is_ignored=1");
            $ig->execute([$mediaId]);
            $ignored = (int)$ig->fetchColumn() > 0;
        } else {
            $sid = $seriesId;
            if ($sid === '' && $mediaId !== '') {
                $sidQ = $ignPdo->prepare("SELECT series_id FROM episodes WHERE id=?");
                $sidQ->execute([$mediaId]);
                $sid = (string)$sidQ->fetchColumn();
            }
            if ($sid !== '') {
                $ig = $ignPdo->prepare("SELECT COUNT(*) FROM series WHERE id=? AND is_ignored=1");
                $ig->execute([$sid]);
                $ignored = (int)$ig->fetchColumn() > 0;
            }
        }
    } catch (Exception $e) {}
    $ignPdo = null;
    if ($ignored) {
        workerLog("  Tarea cancelada: elemento marcado como no monitorizar.");
        // Cancelar el log de traducción
        if ($logId) {
            $c = freshPDO();
            $c->prepare("UPDATE translation_log SET status='cancelled', result='Marcada como no monitorizar', finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$logId]);
            $c = null;
        }
        return;
    }
    
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

    
    // Leer config fresca del proveedor desde BD (el worker puede haber arrancado antes de guardarla)
    $pdoCfg = freshPDO();
    $cfgRows = $pdoCfg->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('system_prompt','translation_provider','translation_model','translation_fallback_models','translation_fallback_providers','deepseek_api_key','gemini_api_key','openai_api_key','mistral_api_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdoCfg = null;

    // Proveedor y modelo: congelados en la tarea si existen, si no usar la config actual
    $primaryKey = $pl['provider'] ?? ($cfgRows['translation_provider'] ?? 'deepseek');
    $primaryModel = $pl['model'] ?? ($cfgRows['translation_model'] ?? '');
    $systemPrompt = $cfgRows['system_prompt'] ?? DEEPSEEK_SYSTEM_PROMPT;

    // Construir lista de candidatos: principal primero, luego fallbacks.
    // Si hay modelos de respaldo explícitos (proveedor/modelo), se usan tal cual.
    // Si no, se usa el comportamiento legacy por proveedor con selección automática.
    $fallbackModels = [];
    $rawFbModels = $cfgRows['translation_fallback_models'] ?? '';
    if ($rawFbModels !== '') {
        $dec = json_decode($rawFbModels, true);
        if (is_array($dec)) {
            foreach ($dec as $f) {
                $p = strtolower(trim($f['provider'] ?? ''));
                $m = trim($f['model'] ?? '');
                if ($p === '' || $m === '') continue;
                $fallbackModels[] = ['key' => $p, 'model' => $m];
            }
        }
    }

    $fallbackList = []; // [ ['key'=>, 'model'=>] ]
    if (!empty($fallbackModels)) {
        $fallbackList = $fallbackModels;
    } else {
        foreach (explode(',', $cfgRows['translation_fallback_providers'] ?? '') as $k) {
            $k = trim($k);
            if ($k === '') continue;
            $fallbackList[] = ['key' => $k, 'model' => ''];
        }
    }

    $candidates = []; // [ ['key'=>, 'provider'=>obj, 'model'=>] ]

    // Helper para resolver el modelo cuando viene vacío (auto-selección).
    $resolveModel = function ($provider, $m) {
        if ($m !== '') return $m;
        try {
            $available = $provider->listModels();
            foreach ($available as $mm) { if (!empty($mm['is_recommended'])) return $mm['id']; }
            return $available[0]['id'] ?? '';
        } catch (Exception $e) {
            throw $e;
        }
    };

    // 1) Proveedor principal (congelado en la tarea o actual)
    $primaryApiKey = $cfgRows[$primaryKey . '_api_key'] ?? '';
    if (!empty($primaryApiKey) && isEncrypted($primaryApiKey)) $primaryApiKey = decryptValue($primaryApiKey);
    if (!empty($primaryApiKey)) {
        $provider = TranslationProviderFactory::create($primaryKey, $primaryApiKey);
        if ($provider) {
            try {
                $m = $resolveModel($provider, $primaryModel);
                if ($m !== '') {
                    $candidates[] = ['key' => $primaryKey, 'provider' => $provider, 'model' => $m];
                }
            } catch (Exception $e) {
                workerLog("  [$primaryKey] no se pudo listar modelos: ".$e->getMessage());
            }
        }
    }

    // 2) Fallbacks en el orden configurado
    foreach ($fallbackList as $f) {
        $k = $f['key'];
        // No repetir el modelo principal exacto (mismo proveedor y modelo).
        if ($k === $primaryKey && $f['model'] === $primaryModel) continue;
        $apiKey = $cfgRows[$k . '_api_key'] ?? '';
        if (!empty($apiKey) && isEncrypted($apiKey)) $apiKey = decryptValue($apiKey);
        if (empty($apiKey)) continue;

        $provider = TranslationProviderFactory::create($k, $apiKey);
        if (!$provider) continue;

        try {
            $m = $resolveModel($provider, $f['model']);
        } catch (Exception $e) {
            workerLog("  [$k] no se pudo listar modelos: ".$e->getMessage());
            continue;
        }
        if ($m === '') continue;

        $candidates[] = ['key' => $k, 'provider' => $provider, 'model' => $m];
    }

    if (empty($candidates)) {
        workerLog("  Error: no hay proveedor con API key configurada para traducir.");
        markError($logId, "Sin proveedor configurado");
        return;
    }
    workerLog("  Candidatos: ".implode(', ', array_map(fn($c) => $c['key'].'/'.$c['model'], $candidates)));

    $results = [];
    $total = count($chunks);

    // Registrar el total de partes para el progreso visible en Logs
    try {
        $pdoT = freshPDO();
        $pdoT->prepare("UPDATE translation_jobs SET total_chunks=? WHERE job_id=?")->execute([$total, $jobId]);
        $pdoT = null;
    } catch (Exception $e) {}

    for ($i = 0; $i < $total; $i++) {
        $chunkStart = microtime(true);
        workerLog("  Chunk ".($i+1)."/$total...");
        $translated = false;
        $lastErr = '';
        // Intentar con cada candidato, empezando por el principal
        foreach ($candidates as $cand) {
            $candStart = microtime(true);
            try {
                $out = $cand['provider']->translate($cand['model'], $systemPrompt, implode("\n\n", $chunks[$i]));
            } catch (Exception $e) {
                $lastErr = $e->getMessage();
                $dur = round(microtime(true) - $candStart, 1);
                workerLog("    {$cand['key']} falló ({$dur}s): ".$e->getMessage());
                // Reordenar: mover el candidato que falló al final para no repetirlo sin motivo
                $first = array_shift($candidates);
                $candidates[] = $first;
                continue;
            }
            $t = $out['content'] ?? '';
            if ($t === '') {
                $lastErr = "Respuesta vacía";
                workerLog("    {$cand['key']} devolvió vacío.");
                $first = array_shift($candidates);
                $candidates[] = $first;
                continue;
            }
            $providerKey = $cand['key'];
            $model = $cand['model'];
            $translated = true;
            $dur = round(microtime(true) - $candStart, 1);
            workerLog("    {$cand['key']} OK ({$dur}s)");
            break;
        }

        if (!$translated) {
            $chunkDur = round(microtime(true) - $chunkStart, 1);
            workerLog("  Error: todos los proveedores fallaron en chunk ".($i+1)." ({$chunkDur}s): $lastErr");
            markError($logId, "Error en chunk ".($i+1)."/$total: $lastErr");
            return;
        }

        $lines = explode("\n", str_replace("\r\n","\n",$t));
        $clean = []; $in = false;
        foreach ($lines as $l) { if (strpos(trim($l),'```')===0) { $in=!$in; continue; } if($in||strpos($t,'```')===false) $clean[]=$l; }
        $final = strpos($t,'```')!==false ? implode("\n",$clean) : trim($t);
        if ($i===0 && preg_match('/^(?:.*?\n)*?(1\n\d{2}:\d{2}:\d{2},\d{3} -->)/s',$final,$m,PREG_OFFSET_CAPTURE)) { $final=substr($final,$m[1][1]); }
        $results[$i] = trim($final);
        
        $pdo = freshPDO();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE translation_jobs SET results=?, completed_chunks=? WHERE job_id=?")->execute([json_encode($results), $i + 1, $jobId]);
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
    try {
        retryDb(function () use ($job, $jobId, $logId, $providerKey, $model) {
            $pdo = freshPDO();
            $pdo->beginTransaction();
            $jobType = strtolower(trim($job['type'] ?? ''));
            if ($jobType === 'movie' || $jobType === 'movies') {
                $pdo->prepare("UPDATE movies SET has_spanish=1 WHERE id=?")->execute([$job['media_id']]);
            } else {
                $pdo->prepare("UPDATE episodes SET has_spanish=1 WHERE id=?")->execute([$job['media_id']]);
            }
            if ($logId) {
                $upd = $pdo->prepare("UPDATE translation_log SET status='completed', finished_at=CURRENT_TIMESTAMP, provider=?, model=? WHERE id=?");
                $upd->execute([$providerKey, $model, $logId]);
            }
            $pdo->prepare("DELETE FROM translation_jobs WHERE job_id=?")->execute([$jobId]);
            $pdo->commit();
            $pdo = null;
        });
    } catch (Exception $e) {
        workerLog("  Error marcando completado: ".$e->getMessage());
        markError($logId, "Completado con error de BD: ".$e->getMessage());
        return;
    }
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
