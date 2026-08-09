<?php
// html/ajax_remote_paths.php
// Obtiene las carpetas raíz configuradas en Sonarr/Radarr (para sugerir rutas).
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'includes/ArrFactory.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

// Rate limiting (30 consultas por minuto)
rateLimitRequire('remote_paths', 30, 60);

$service = $_GET['service'] ?? 'sonarr';

try {
    $client = $service === 'radarr'
        ? ArrFactory::radarr()
        : ArrFactory::sonarr();

    $paths = $client->getRootFolders();
    echo json_encode(['status' => 'success', 'paths' => $paths]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
