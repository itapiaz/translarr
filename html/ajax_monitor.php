<?php
// html/ajax_monitor.php — Marcar/desmarcar una película o serie como "No monitorizar"
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}
csrf_require();
rateLimitRequire('monitor', 30, 60);

$action = $_POST['action'] ?? '';
$type   = strtolower(trim($_POST['type'] ?? ''));
$id     = (int)($_POST['id'] ?? 0);

if (!in_array($action, ['ignore', 'monitor'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Acción inválida.']);
    exit;
}
if (!in_array($type, ['movie', 'movies', 'series'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Tipo inválido.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID inválido.']);
    exit;
}

try {
    $normType = ($type === 'series') ? 'series' : 'movies';
    $table = ($normType === 'series') ? 'series' : 'movies';
    $newVal = ($action === 'ignore') ? 1 : 0;

    // Verificar que el registro existe
    $check = $pdo->prepare("SELECT id, title FROM {$table} WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => ($normType === 'series' ? 'Serie' : 'Película') . ' no encontrada.']);
        exit;
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE {$table} SET is_ignored = ? WHERE id = ?");
    $upd->execute([$newVal, $id]);

    // Al ignorar, cancelar traducciones pendientes de este elemento (o de sus episodios)
    if ($action === 'ignore') {
        // Buscar IDs de tareas/logs afectados evaluando el payload en PHP (portable)
        $matchTaskIds = [];
        $taskRows = $pdo->query("SELECT id, payload FROM background_tasks WHERE type='translate' AND status IN ('pending','running')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($taskRows as $tr) {
            $pl = json_decode($tr['payload'] ?? '', true);
            if (!is_array($pl)) continue;
            $mediaId = (string)($pl['media_id'] ?? '');
            $seriesId = (string)($pl['series_id'] ?? '');
            $mtype = (string)($pl['type'] ?? '');
            $isMovie = in_array($mtype, ['movies', 'movie'], true);
            if ($normType === 'series' && $seriesId === (string)$id) {
                $matchTaskIds[] = (int)$tr['id'];
            } elseif ($normType !== 'series' && $isMovie && $mediaId === (string)$id) {
                $matchTaskIds[] = (int)$tr['id'];
            }
        }
        if (!empty($matchTaskIds)) {
            $inIds = implode(',', $matchTaskIds);
            $pdo->exec("UPDATE background_tasks SET status='cancelled', result='Marcada como no monitorizada', finished_at=CURRENT_TIMESTAMP WHERE id IN ($inIds)");
        }

        if ($normType === 'series') {
            $pdo->exec(
                "UPDATE translation_log SET status='cancelled', result='Marcada como no monitorizada', finished_at=CURRENT_TIMESTAMP
                 WHERE series_id = '$id' AND status IN ('pending','running')"
            );
        } else {
            $pdo->exec(
                "UPDATE translation_log SET status='cancelled', result='Marcada como no monitorizada', finished_at=CURRENT_TIMESTAMP
                 WHERE media_id = '$id' AND media_type IN ('movies','movie') AND status IN ('pending','running')"
            );
        }
    }
    $pdo->commit();

    $label = $normType === 'series' ? 'serie' : 'película';
    $msg = ($action === 'ignore')
        ? "{$row['title']} ya no se monitorizará."
        : "{$row['title']} volverá a monitorizarse.";

    echo json_encode([
        'status'  => 'success',
        'message' => $msg,
        'ignored' => (bool)$newVal,
        'title'   => $row['title'],
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}