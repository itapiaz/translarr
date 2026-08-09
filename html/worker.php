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
require_once __DIR__ . '/includes/MediaServerFactory.php';
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
];
foreach ($_migrations as $_sql) {
    try { $_migPdo->exec($_sql); } catch (Exception $_e) { /* columna ya existe, ignorar */ }
}
$_migPdo = null;
unset($_migrations, $_sql, $_e);
// === FIN MIGRACIONES ===


function doScanMedia(): string {
    workerLog("=== Escaneando ===");
    $pdo = freshPDO();
    
    // Recargar config desde BD (por si cambió entre ciclos)
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('media_server_type','media_server_url','media_server_api_key')");
    $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $type = $cfg['media_server_type'] ?? MEDIA_SERVER_TYPE;
    $url = $cfg['media_server_url'] ?? MEDIA_SERVER_URL;
    $apiKey = $cfg['media_server_api_key'] ?? MEDIA_SERVER_API_KEY;
    
    // Desencriptar si está encriptado
    if (isEncrypted($apiKey)) {
        $apiKey = decryptValue($apiKey);
    }
    
    $api = MediaServerFactory::getAPI($type, $url, $apiKey);
    $stats = ['movies' => 0, 'series' => 0, 'episodes' => 0];
    try {
        $scanTime = $pdo->query("SELECT datetime('now')")->fetchColumn();
        
        // Peliculas
        try {
            $movies = $api->getMovies();
            $pdo->beginTransaction();
            $s = $pdo->prepare("INSERT INTO media_cache(id,type,title,year,poster_url,has_spanish,overview,folder_path,updated_at) VALUES(?,'movie',?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET title=excluded.title,year=excluded.year,poster_url=excluded.poster_url,has_spanish=excluded.has_spanish,overview=excluded.overview,folder_path=excluded.folder_path,updated_at=CURRENT_TIMESTAMP");
            foreach ($movies as $m) {
                $hs = isset($m['has_spanish']) ? (int)$m['has_spanish'] : 0;
                $s->execute([$m['id'],$m['title']??'',$m['year']??'',$m['poster']??'',$hs,$m['overview']??'',$m['folder_path']??'']);
                $stats['movies']++;
            }
            $pdo->commit();
            $pdo = null;
            workerLog("Peliculas: {$stats['movies']}");
        } catch (Exception $e) { if($pdo&&$pdo->inTransaction())$pdo->rollBack(); $pdo=null; workerLog("Error peliculas: ".$e->getMessage()); }
        
        // Series
        $pdo = freshPDO();
        try {
            $series = $api->getSeries();
            foreach ($series as $show) {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO media_cache(id,type,title,poster_url,overview,folder_path,updated_at) VALUES(?,'series',?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET title=excluded.title,poster_url=excluded.poster_url,overview=excluded.overview,folder_path=excluded.folder_path,updated_at=CURRENT_TIMESTAMP")->execute([$show['id'],$show['title']??'',$show['poster']??'',$show['overview']??'',$show['folder_path']??'']);
                $pdo->commit();
                $stats['series']++;
                try {
                    $eps = $api->getEpisodes($show['id']);
                    $pdo->beginTransaction();
                    $se = $pdo->prepare("INSERT INTO media_cache(id,type,series_id,title,season,episode,poster_url,has_spanish,folder_path,updated_at) VALUES(?,'episode',?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET title=excluded.title,season=excluded.season,episode=excluded.episode,poster_url=excluded.poster_url,has_spanish=excluded.has_spanish,folder_path=excluded.folder_path,updated_at=CURRENT_TIMESTAMP");
                    foreach ($eps as $ep) {
                        $hs = isset($ep['has_spanish']) ? (int)$ep['has_spanish'] : 0;
                        $se->execute([$ep['id'],$show['id'],$ep['title']??'',(int)($ep['season']??0),(int)($ep['episode']??0),$show['poster']??'',$hs,$ep['folder_path']??'']);
                        $stats['episodes']++;
                    }
                    $pdo->commit();
                } catch (Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); workerLog("Error eps {$show['title']}: ".$e->getMessage()); }
            }
            workerLog("Series: {$stats['series']}, Epis: {$stats['episodes']}");
        } catch (Exception $e) { workerLog("Error series: ".$e->getMessage()); }
        $pdo = null;
        
        // Limpiar
        $pdo = freshPDO();
        $d = $pdo->exec("DELETE FROM media_cache WHERE updated_at<'$scanTime'");
        $pdo = null;
        workerLog("Limpieza: $d registros.");
        $r = "{$stats['movies']} movies, {$stats['series']} series, {$stats['episodes']} eps.";
        workerLog("OK. $r");
        return $r;
    } catch (Exception $e) { $pdo=null; return "Error: ".$e->getMessage(); }
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
    
    // Recargar config desde BD
    $px = freshPDO();
    $stmt = $px->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('media_server_type','media_server_url','media_server_api_key')");
    $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $px = null;
    $t = $cfg['media_server_type'] ?? MEDIA_SERVER_TYPE;
    $u = $cfg['media_server_url'] ?? MEDIA_SERVER_URL;
    $k = $cfg['media_server_api_key'] ?? MEDIA_SERVER_API_KEY;
    if (isEncrypted($k)) $k = decryptValue($k);
    $api = MediaServerFactory::getAPI($t, $u, $k);
    $origPath = $job['path'];
    $stype = strtolower(trim($job['type']));
    
    try {
        if ($api->supportsDirectWrite()) {
            $from = ($stype==='movies'||$stype==='movie') ? (defined('PATH_MAPPING_MOVIES_FROM')?PATH_MAPPING_MOVIES_FROM:'') : (defined('PATH_MAPPING_SERIES_FROM')?PATH_MAPPING_SERIES_FROM:'');
            $to = ($stype==='movies'||$stype==='movie') ? (defined('PATH_MAPPING_MOVIES_TO')?PATH_MAPPING_MOVIES_TO:'') : (defined('PATH_MAPPING_SERIES_TO')?PATH_MAPPING_SERIES_TO:'');
            $wp = $origPath;
            if ($from!=='' && $to!=='' && strpos($origPath,$from)===0) $wp = $to.substr($origPath,strlen($from));
            $dir = dirname($wp);
            $fn = basename($wp);
            $parts = explode('.',$fn);
            $ext = array_pop($parts);
            if (count($parts)>1 && strlen(end($parts))<=3) array_pop($parts);
            $parts[]='es'; $parts[]=$ext;
            $np = $dir.DIRECTORY_SEPARATOR.implode('.',$parts);
            file_put_contents($np,$finalSrt);
            $api->refreshItem($job['media_id']);
            workerLog("  Guardado: $np");
        } else {
            $tmp = '/tmp/translarr_'.uniqid().'.es.srt';
            file_put_contents($tmp,$finalSrt);
            $url = ($stype==='series'||$stype==='episode') ? MEDIA_SERVER_URL."/api/episodes/subtitles?episodeid={$job['media_id']}&seriesid={$job['series_id']}&language=es" : MEDIA_SERVER_URL."/api/movies/subtitles?radarrid={$job['media_id']}&language=es";
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['X-API-KEY: '.MEDIA_SERVER_API_KEY],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($tmp,'text/plain',basename($job['path']))],CURLOPT_RETURNTRANSFER=>true]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            @unlink($tmp);
            if ($code>=400 && $code!==204) throw new Exception("Bazarr HTTP $code");
        }
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
