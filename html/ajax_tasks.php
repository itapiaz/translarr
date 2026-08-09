<?php
// html/ajax_tasks.php — Endpoint de gestión de tareas en background

require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'status';

// Rate limiting para acciones de tareas (30 por minuto)
rateLimitRequire('tasks', 30, 60);

switch ($action) {

    // --------------------------------------------------
    // Conteo de tareas activas (para el spinner del navbar)
    // --------------------------------------------------
    case 'running_count':
        $count = $pdo->query("SELECT COUNT(*) FROM background_tasks WHERE status IN ('running', 'pending')")->fetchColumn();
        echo json_encode(['count' => (int)$count]);
        break;

    // --------------------------------------------------
    // Lista de tareas del día (para el panel offcanvas)
    // --------------------------------------------------
    case 'status':
        $stmt = $pdo->query("
            SELECT id, type, status, result, payload, created_at, started_at, finished_at
            FROM background_tasks
            WHERE created_at >= datetime('now', '-24 hours')
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Extraer media_title del payload para tareas de traducción
        foreach ($tasks as &$t) {
            if ($t['type'] === 'translate' && $t['payload']) {
                $pl = json_decode($t['payload'], true);
                if ($pl) {
                    $label = $pl['media_title'] ?? '';
                    $season = (int)($pl['season'] ?? 0);
                    $episode = (int)($pl['episode'] ?? 0);
                    $seriesId = $pl['series_id'] ?? '';
                    $mediaId = $pl['media_id'] ?? '';
                    $jobId = $pl['job_id'] ?? '';

                    // Progreso real de la traducción (partes completadas)
                    $t['progress'] = null;
                    if ($jobId !== '' && in_array($t['status'], ['pending', 'running'], true)) {
                        $pj = $pdo->prepare("SELECT total_chunks, completed_chunks FROM translation_jobs WHERE job_id=?");
                        $pj->execute([$jobId]);
                        $jr = $pj->fetch(PDO::FETCH_ASSOC);
                        if ($jr) {
                            $t['progress'] = [
                                'completed_chunks' => (int)$jr['completed_chunks'],
                                'total_chunks'     => (int)$jr['total_chunks'],
                            ];
                        }
                    }

                    // Intentar obtener el título de la serie desde la BD
                    if (!empty($seriesId)) {
                        $st = $pdo->prepare("SELECT title FROM series WHERE id = ?");
                        $st->execute([$seriesId]);
                        $sr = $st->fetch(PDO::FETCH_ASSOC);
                        if ($sr && !empty($sr['title'])) {
                            $label = $sr['title'];
                        }
                    }
                    // Fallback: si no se encontró serie pero es episodio, intentar desde el series_id del episodio
                    if (empty($label) && !empty($mediaId)) {
                        $st2 = $pdo->prepare("SELECT s.title FROM episodes e JOIN series s ON s.id=e.series_id WHERE e.id = ?");
                        $st2->execute([$mediaId]);
                        $sr2 = $st2->fetch(PDO::FETCH_ASSOC);
                        if ($sr2 && !empty($sr2['title'])) {
                            $label = $sr2['title'];
                        }
                    }

                    if (!empty($season) || !empty($episode)) {
                        $label .= ' S' . str_pad($season, 2, '0', STR_PAD_LEFT);
                        $label .= 'E' . str_pad($episode, 2, '0', STR_PAD_LEFT);
                    }
                    $t['result'] = ($t['result'] ? $t['result'] . ' | ' : '') . $label;
                }
            }
            unset($t);
        }

        // Última ejecución exitosa de scan_media
        $lastScan = $pdo->query("
            SELECT finished_at FROM background_tasks
            WHERE type='scan_media' AND status='done'
            ORDER BY finished_at DESC LIMIT 1
        ")->fetchColumn();

        // Calcular próxima ejecución estimada basada en el intervalo guardado
        $intervalMinutes = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='scan_interval_minutes'")->fetchColumn() ?: 60);
        $nextScan = null;
        if ($lastScan) {
            $nextScan = date('Y-m-d H:i:s', strtotime($lastScan) + ($intervalMinutes * 60));
        }

        echo json_encode([
            'tasks' => $tasks,
            'last_scan' => $lastScan,
            'next_scan' => $nextScan,
            'interval_minutes' => $intervalMinutes
        ]);
        break;

    // --------------------------------------------------
    // Disparar un escaneo manual inmediato
    // --------------------------------------------------
    case 'trigger':
        // Crear archivo de trigger (el worker lo detecta en <5 segundos)
        $triggerFile = '/config/scan_trigger.now';
        if (file_exists($triggerFile)) {
            echo json_encode(['success' => false, 'message' => 'Ya hay un escaneo pendiente o en curso.']);
            break;
        }
        file_put_contents($triggerFile, date('c'));
        echo json_encode(['success' => true, 'message' => 'Escaneo forzado solicitado. El worker lo ejecutará en breve.']);
        break;

    // --------------------------------------------------
    // Historial resumido (para Settings)
    // --------------------------------------------------
    case 'history':
        $stmt = $pdo->query("
            SELECT id, type, status, result, created_at, started_at, finished_at
            FROM background_tasks
            WHERE type='scan_media'
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['tasks' => $tasks]);
        break;

    // --------------------------------------------------
    // Historial de traducciones (para el dashboard/logs)
    // --------------------------------------------------
    case 'translation_history':
        $stmt = $pdo->query("
            SELECT id, media_id, media_title, media_type, season, episode, status, result, created_at, finished_at
            FROM translation_log
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Corregir media_title para episodios: buscar el título de la serie
        foreach ($logs as &$log) {
            if ($log['media_type'] === 'episode' && !empty($log['media_id'])) {
                $st = $pdo->prepare("SELECT s.title FROM episodes e JOIN series s ON s.id=e.series_id WHERE e.id = ?");
                $st->execute([$log['media_id']]);
                $sr = $st->fetch(PDO::FETCH_ASSOC);
                if ($sr && !empty($sr['title'])) {
                    $log['media_title'] = $sr['title'];
                }
            }
        }
        unset($log);
        echo json_encode(['logs' => $logs]);
        break;

    // --------------------------------------------------
    // Progreso de traducciones activas (para Logs)
    // --------------------------------------------------
    case 'translation_progress':
        $tasks = $pdo->query("SELECT id, status, payload FROM background_tasks WHERE type='translate' AND status IN ('pending','running')")->fetchAll(PDO::FETCH_ASSOC);
        $progress = [];
        foreach ($tasks as $t) {
            $pl = json_decode($t['payload'] ?? '', true);
            if (!is_array($pl)) continue;
            $logId = (int)($pl['log_id'] ?? 0);
            $jobId = $pl['job_id'] ?? '';
            if (!$logId) continue;
            $completed = 0;
            $total = 0;
            if ($jobId !== '') {
                $row = $pdo->prepare("SELECT total_chunks, completed_chunks FROM translation_jobs WHERE job_id=?");
                $row->execute([$jobId]);
                $jr = $row->fetch(PDO::FETCH_ASSOC);
                if ($jr) {
                    $total = (int)$jr['total_chunks'];
                    $completed = (int)$jr['completed_chunks'];
                }
            }
            $progress[$logId] = [
                'task_status'     => $t['status'],
                'completed_chunks'=> $completed,
                'total_chunks'    => $total,
            ];
        }
        echo json_encode(['status' => 'success', 'progress' => $progress]);
        break;

    // --------------------------------------------------
    // Conteo de traducciones pendientes
    // --------------------------------------------------
    case 'pending_translations':
        $count = $pdo->query("SELECT COUNT(*) FROM translation_log WHERE status = 'pending'")->fetchColumn();
        echo json_encode(['count' => (int)$count]);
        break;

    default:
        echo json_encode(['error' => 'Acción no reconocida.']);
}
