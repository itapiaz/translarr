<?php
// html/ajax_auto_translate.php — Activar/Desactivar la traducción automática de una película o serie
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
rateLimitRequire('auto_translate', 30, 60);

$action = $_POST['action'] ?? '';
$type   = strtolower(trim($_POST['type'] ?? ''));
$id     = (int)($_POST['id'] ?? 0);

if (!in_array($action, ['enable', 'disable'], true)) {
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
    $newVal = ($action === 'enable') ? 1 : 0;
    $label  = ($normType === 'series') ? 'serie' : 'película';

    // Verificar que el registro existe
    $check = $pdo->prepare("SELECT id, title, is_ignored FROM {$table} WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => ($normType === 'series' ? 'Serie' : 'Película') . ' no encontrada.']);
        exit;
    }

    $pdo->prepare("UPDATE {$table} SET auto_translate = ? WHERE id = ?")->execute([$newVal, $id]);

    $msg = ($action === 'enable')
        ? "Traducción automática activada para \"{$row['title']}\"."
        : "Traducción automática desactivada para \"{$row['title']}\".";

    echo json_encode([
        'status' => 'success',
        'message' => $msg,
        'auto_translate' => (bool)$newVal,
        'title' => $row['title'],
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}