<?php
// html/ajax_test_connection.php
// Prueba de conexión para Sonarr/Radarr (POST, requiere sesión y CSRF).
ini_set('display_errors', 0);
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/ArrFactory.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

csrf_require();
rateLimitRequire('test_connection', 10, 60);

$service = $_POST['service'] ?? '';
$url = trim($_POST['url'] ?? '');
$apiKey = trim($_POST['api_key'] ?? '');

if (!in_array($service, ['sonarr', 'radarr'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Servicio inválido.']);
    exit;
}

if ($url === '') {
    echo json_encode(['status' => 'error', 'message' => 'Indica la URL del servicio.']);
    exit;
}

// Si no se escribió una clave nueva, usar la guardada
if ($apiKey === '') {
    $apiKey = $service === 'sonarr' ? SONARR_API_KEY : RADARR_API_KEY;
}

try {
    $client = $service === 'sonarr'
        ? ArrFactory::sonarr($url, $apiKey)
        : ArrFactory::radarr($url, $apiKey);

    $result = $client->testConnection();
    echo json_encode([
        'status' => 'success',
        'message' => $result['message'],
        'version' => $result['version'],
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
