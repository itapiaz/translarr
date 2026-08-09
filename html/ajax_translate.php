<?php
// html/ajax_translate.php - v8 con error handlers globales
ini_set('display_errors', 0);
set_time_limit(300); // Permitir hasta 5 minutos para llamadas lentas a DeepSeek

// Capturar CUALQUIER error (incluso fatales) y devolverlo como JSON
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => 'error', 'message' => 'PHP Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']]);
    }
});

set_exception_handler(function ($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['status' => 'error', 'message' => 'Uncaught: ' . $e->getMessage()]);
    exit;
});

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once 'config.php';
require_once 'includes/security.php';

// Rate limiting para traducción (10 peticiones por minuto)
rateLimitRequire('translate', 10, 60);

$DEEPSEEK_KEY  = DEEPSEEK_API_KEY;
$SYSTEM_PROMPT = DEEPSEEK_SYSTEM_PROMPT;
$CHUNK_SIZE    = CHUNK_SIZE;

// Asegurar tabla translation_jobs
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS translation_jobs (
        job_id TEXT PRIMARY KEY,
        chunks TEXT NOT NULL,
        results TEXT NOT NULL,
        path TEXT, type TEXT, media_id TEXT, series_id TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Table Error: ' . $e->getMessage()]);
    exit;
}

// Auth: temporalmente deshabilitado - la app es de red local privada
// TODO: implementar token-based auth en SQLite
// $userId = getUserIdFromSession();

$action = $_POST['action'] ?? '';

// Ping rápido para verificar que el script funciona sin file I/O
if ($action === 'ping') {
    echo json_encode(['status' => 'success', 'message' => 'v8 activo']);
    exit;
}

try {
    if ($action === 'init') {
        $path      = $_POST['path']      ?? '';
        $type      = $_POST['type']      ?? '';
        $media_id  = $_POST['media_id']  ?? '';
        $series_id = $_POST['series_id'] ?? '';

        if (empty($path))        throw new Exception("Ruta de archivo vacía.");
        if (!file_exists($path)) throw new Exception("Archivo no encontrado: $path");

        $content = @file_get_contents($path);
        if ($content === false)  throw new Exception("Error leyendo el archivo.");

        // Forzar UTF-8 si no lo es
        if (!mb_check_encoding($content, 'UTF-8')) {
            $detected = mb_detect_encoding($content, 'UTF-8, ISO-8859-1, WINDOWS-1252', true);
            $content = mb_convert_encoding($content, 'UTF-8', $detected ?: 'ISO-8859-1');
        }

        $content = strip_tags(str_replace(">\n", ">\n", $content));
        $content = str_replace("\r\n", "\n", $content);
        
        if (empty(trim($content))) {
            throw new Exception("El archivo de subtítulos está vacío o es ilegible después de la conversión.");
        }
        
        $blocks  = array_values(array_filter(explode("\n\n", $content), function($b) { return trim($b) !== ''; }));
        $chunks  = array_chunk($blocks, $CHUNK_SIZE);
        $jobId   = bin2hex(random_bytes(16));

        $encodedChunks = json_encode($chunks);
        if ($encodedChunks === false) {
            throw new Exception("Error codificando fragmentos a JSON: " . json_last_error_msg());
        }

        // Guardar el trabajo de traducción en translation_jobs
        $pdo->prepare("INSERT INTO translation_jobs (job_id, chunks, results, path, type, media_id, series_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$jobId, $encodedChunks, json_encode([]), $path, $type, $media_id, $series_id]);

        // Obtener título del media para el log
        $mediaTitle = '';
        $season = 0;
        $episode = 0;
        $isMovie = ($type === 'movies' || $type === 'movie');
        if ($isMovie) {
            $stmt = $pdo->prepare("SELECT title FROM movies WHERE id = ?");
            $stmt->execute([$media_id]);
            $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($mediaRow) $mediaTitle = $mediaRow['title'] ?? '';
        } else {
            $stmt = $pdo->prepare("SELECT title, season, episode, series_id FROM episodes WHERE id = ?");
            $stmt->execute([$media_id]);
            $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($mediaRow) {
                $mediaTitle = $mediaRow['title'] ?? '';
                $season = (int)($mediaRow['season'] ?? 0);
                $episode = (int)($mediaRow['episode'] ?? 0);
            }
        }

        // Si es episodio, usar el título de la serie en vez del título del episodio
        if (!$isMovie && !empty($series_id)) {
            $stmtS = $pdo->prepare("SELECT title FROM series WHERE id = ?");
            $stmtS->execute([$series_id]);
            $seriesTitle = $stmtS->fetchColumn();
            if ($seriesTitle) $mediaTitle = $seriesTitle;
        }

        // Construir etiqueta descriptiva para el panel de tareas
        $taskLabel = $mediaTitle;
        if ($type === 'episode' || $type === 'series') {
            $taskLabel .= " S" . str_pad($season, 2, '0', STR_PAD_LEFT) . "E" . str_pad($episode, 2, '0', STR_PAD_LEFT);
        }

        // Registrar en translation_log como "pending"
        $pdo->prepare("INSERT INTO translation_log (media_id, media_title, media_type, series_id, season, episode, subtitle_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')")
            ->execute([$media_id, $mediaTitle, $type, $series_id, $season, $episode, $path]);

        $logId = $pdo->lastInsertId();

        // Encolar tarea de traducción en background
        $payload = json_encode([
            'job_id'   => $jobId,
            'log_id'   => $logId,
            'path'     => $path,
            'type'     => $type,
            'media_id' => $media_id,
            'series_id'=> $series_id,
            'media_title' => $mediaTitle,
            'season'   => $season,
            'episode'  => $episode,
            'total_chunks' => count($chunks)
        ]);
        $pdo->prepare("INSERT INTO background_tasks (type, status, payload, created_at) VALUES ('translate', 'pending', ?, CURRENT_TIMESTAMP)")
            ->execute([$payload]);

        // Limpiar jobs viejos
        $pdo->exec("DELETE FROM translation_jobs WHERE created_at < datetime('now', '-2 hours')");

        echo json_encode(['status' => 'success', 'total_chunks' => count($chunks), 'job_id' => $jobId, 'log_id' => $logId]);
        exit;
    }

    if ($action === 'process') {
        // Ya no se procesa desde el navegador, se hace en background
        $jobId = $_POST['job_id'] ?? '';
        echo json_encode(['status' => 'queued', 'job_id' => $jobId, 'message' => 'Traducción encolada en background. Revisa el worker.log para el progreso.']);
        exit;
    }

    if ($action === 'finalize') {
        // Ya no se finaliza desde el navegador, el worker lo hace
        $jobId = $_POST['job_id'] ?? '';
        echo json_encode(['status' => 'queued', 'job_id' => $jobId, 'message' => 'Finalización en background.']);
        exit;
    }

    if ($action === 'status') {
        // Consultar estado de una traducción
        $jobId = $_POST['job_id'] ?? '';
        $logId = $_POST['log_id'] ?? 0;
        
        if ($logId) {
            $stmt = $pdo->prepare("SELECT status, result, finished_at FROM translation_log WHERE id = ?");
            $stmt->execute([$logId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT tl.status, tl.result, tl.finished_at
                FROM translation_log tl
                WHERE tl.subtitle_path IN (SELECT path FROM translation_jobs WHERE job_id = ?)
                ORDER BY tl.created_at DESC LIMIT 1
            ");
            $stmt->execute([$jobId]);
        }
        $status = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($status) {
            echo json_encode([
                'status' => 'success',
                'translation_status' => $status['status'],
                'result' => $status['result'],
                'finished_at' => $status['finished_at']
            ]);
        } else {
            echo json_encode(['status' => 'success', 'translation_status' => 'pending', 'message' => 'En cola de espera...']);
        }
        exit;
    }

    if ($action === 'history') {
        // Obtener historial de traducciones
        $type = $_POST['media_type'] ?? '';
        $mediaId = $_POST['media_id'] ?? '';
        
        $query = "SELECT * FROM translation_log WHERE 1=1";
        $params = [];
        if ($type) {
            $query .= " AND media_type = ?";
            $params[] = $type;
        }
        if ($mediaId) {
            $query .= " AND media_id = ?";
            $params[] = $mediaId;
        }
        $query .= " ORDER BY created_at DESC LIMIT 20";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'logs' => $logs]);
        exit;
    }

    throw new Exception("Acción inválida: '$action'");

} catch (Exception $e) {
    try {
        $pdo->prepare("INSERT INTO system_logs (action, message) VALUES (?, ?)")
            ->execute([$action ?? 'unknown', $e->getMessage()]);
    } catch (Exception $dbE) {}
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
