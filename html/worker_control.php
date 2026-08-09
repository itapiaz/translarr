<?php
// worker_control.php — Control y diagnóstico del worker de traducción
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/security.php';

header('Content-Type: text/plain; charset=utf-8');
$action = $_GET['action'] ?? 'status';

// ============================================================
// STATUS: Ver estado actual de tareas y worker
// ============================================================
if ($action === 'status') {
    echo "=== ESTADO DEL WORKER Y TAREAS ===\n\n";

    // DB path
    echo "DB Path: {$dbPath}\n";

    // Lock file
    $lock = '/config/worker.lock';
    echo "Lock file: " . (file_exists($lock) ? "EXISTS (worker probablemente corriendo)" : "NO existe (worker detenido)") . "\n";

    // Trigger file
    echo "Trigger file: " . (file_exists('/config/scan_trigger.now') ? "EXISTS (escaneo pendiente)" : "no existe") . "\n\n";

    // background_tasks pending/running
    echo "--- background_tasks ---\n";
    $tasks = $pdo->query("SELECT id, type, status, payload, created_at, started_at, finished_at FROM background_tasks WHERE type='translate' ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tasks)) {
        echo "  No hay tareas de traducción.\n";
    } else {
        foreach ($tasks as $t) {
            $pl = json_decode($t['payload'], true);
            $title = $pl['media_title'] ?? '?';
            $s = str_pad($pl['season'] ?? 0, 2, '0', STR_PAD_LEFT);
            $e = str_pad($pl['episode'] ?? 0, 2, '0', STR_PAD_LEFT);
            echo "  [{$t['status']}] ID:{$t['id']} | $title S{$s}E{$e} | created:{$t['created_at']}\n";
        }
    }

    echo "\n--- translation_log ---\n";
    $logs = $pdo->query("SELECT id, media_title, media_type, season, episode, status, result, created_at FROM translation_log ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as $l) {
        $s = str_pad($l['season'], 2, '0', STR_PAD_LEFT);
        $e = str_pad($l['episode'], 2, '0', STR_PAD_LEFT);
        echo "  [{$l['status']}] {$l['media_title']} S{$s}E{$e} | {$l['created_at']} | {$l['result']}\n";
    }

    echo "\n--- DeepSeek API Key ---\n";
    $dsKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='deepseek_api_key'")->fetchColumn();
    if (empty($dsKey)) {
        echo "  ❌ VACÍA — ve a Configuración > DeepSeek AI\n";
    } elseif (isEncrypted($dsKey)) {
        $dec = decryptValue($dsKey);
        echo "  " . (empty($dec) ? "❌ Encriptada pero vacía al desencriptar" : "✅ Configurada (sk-" . substr($dec, 3, 6) . "...)") . "\n";
    } else {
        echo "  ✅ Configurada (sin encriptar): " . substr($dsKey, 0, 10) . "...\n";
    }
}

// ============================================================
// RESET: Volver tareas atascadas a 'pending' para retry
// ============================================================
if ($action === 'reset') {
    echo "=== RESET DE TAREAS ATASCADAS ===\n\n";

    // Reset background_tasks running/pending
    $n1 = $pdo->exec("UPDATE background_tasks SET status='pending', started_at=NULL WHERE type='translate' AND status IN ('running','pending')");
    echo "✅ background_tasks reseteadas: $n1\n";

    // Reset translation_log
    $n2 = $pdo->exec("UPDATE translation_log SET status='pending', result=NULL WHERE status IN ('running','pending','error')");
    echo "✅ translation_log reseteadas: $n2\n";

    echo "\nAhora reinicia el worker y reintenta la traducción.\n";
}

// ============================================================
// REQUEUE: Reconstruir background_tasks para tareas huérfanas
// (translation_log pending sin background_task correspondiente)
// ============================================================
if ($action === 'requeue') {
    echo "=== RE-ENCOLANDO TAREAS HUÉRFANAS ===\n\n";

    // Buscar translation_log pendientes
    $pending = $pdo->query("SELECT * FROM translation_log WHERE status='pending' ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pending)) {
        echo "No hay tareas pendientes en translation_log.\n";
    } else {
        $queued = 0;
        foreach ($pending as $log) {
            // Buscar el translation_job correspondiente por path
            $jobStmt = $pdo->prepare("SELECT * FROM translation_jobs WHERE path = ? ORDER BY created_at DESC LIMIT 1");
            $jobStmt->execute([$log['subtitle_path']]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                echo "  ⚠️  Sin job para: {$log['media_title']} S{$log['season']}E{$log['episode']} (path: {$log['subtitle_path']})\n";
                echo "      El archivo de subtitle_path ya no está en translation_jobs (expiró). Cancela y re-inicia la traducción.\n";
                continue;
            }

            $chunks = json_decode($job['chunks'], true);
            $totalChunks = is_array($chunks) ? count($chunks) : 0;

            $payload = json_encode([
                'job_id'       => $job['job_id'],
                'log_id'       => $log['id'],
                'path'         => $job['path'],
                'type'         => $job['type'],
                'media_id'     => $job['media_id'],
                'series_id'    => $job['series_id'],
                'media_title'  => $log['media_title'],
                'season'       => $log['season'],
                'episode'      => $log['episode'],
                'total_chunks' => $totalChunks
            ]);

            $pdo->prepare("INSERT INTO background_tasks (type, status, payload, created_at) VALUES ('translate', 'pending', ?, CURRENT_TIMESTAMP)")
                ->execute([$payload]);

            echo "  ✅ Encolado: {$log['media_title']} S" . str_pad($log['season'], 2, '0', STR_PAD_LEFT) . "E" . str_pad($log['episode'], 2, '0', STR_PAD_LEFT) . " (job: {$job['job_id']})\n";
            $queued++;
        }
        echo "\n✅ Total encoladas: $queued\n";
        echo "El worker las procesará en los próximos 5 segundos.\n";
    }
}

// ============================================================
// CANCEL: Cancelar todas las tareas pendientes
// ============================================================
if ($action === 'cancel') {
    echo "=== CANCELANDO TAREAS ===\n\n";
    $n1 = $pdo->exec("DELETE FROM background_tasks WHERE type='translate' AND status IN ('pending','running')");
    $n2 = $pdo->exec("UPDATE translation_log SET status='cancelled', result='Cancelado manualmente' WHERE status IN ('pending','running')");
    $pdo->exec("DELETE FROM translation_jobs WHERE created_at < datetime('now', '-10 minutes')");
    echo "✅ Tareas eliminadas: $n1 de background_tasks\n";
    echo "✅ Logs cancelados: $n2 de translation_log\n";
}

// ============================================================
// RESTART: Reiniciar el worker (si PHP tiene permiso exec)
// ============================================================
if ($action === 'restart') {
    echo "=== REINICIANDO WORKER ===\n\n";
    // Matar worker existente
    @exec("pkill -f worker.php 2>&1", $out1);
    echo "pkill: " . implode("\n", $out1) . "\n";
    sleep(1);
    // Iniciar nuevo worker
    $logFile = '/config/worker.log';
    $cmd = "php " . escapeshellarg(__DIR__ . '/worker.php') . " >> " . escapeshellarg($logFile) . " 2>&1 &";
    @exec($cmd, $out2);
    echo "Comando ejecutado: $cmd\n";
    echo "Verifica el worker.log en unos segundos.\n";
}

echo "\n\n--- Acciones disponibles ---\n";
echo "  ?action=status  — Ver estado\n";
echo "  ?action=requeue — Re-encolar tareas huérfanas (pendientes sin background_task)\n";
echo "  ?action=reset   — Resetear tareas atascadas (retry)\n";
echo "  ?action=cancel  — Cancelar todas las pendientes\n";
echo "  ?action=restart — Reiniciar el worker\n";
