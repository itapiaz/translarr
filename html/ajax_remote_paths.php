<?php
// html/ajax_remote_paths.php
require_once 'config.php';
require_once 'includes/MediaServerFactory.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

// Rate limiting (30 consultas por minuto)
rateLimitRequire('remote_paths', 30, 60);

try {
    $type = $_GET['type'] ?? MEDIA_SERVER_TYPE;
    $url = $_GET['url'] ?? MEDIA_SERVER_URL;
    $apiKey = $_GET['api_key'] ?? MEDIA_SERVER_API_KEY;

    if ($type === 'bazarr') {
        require_once 'includes/BazarrAPI.php';
        $api = new BazarrAPI($url, $apiKey);
    } else {
        require_once 'includes/EmbyJellyfinAPI.php';
        $api = new EmbyJellyfinAPI($url, $apiKey, $type);
    }
    
    $paths = $api->getRemotePaths();
    echo json_encode(['status' => 'success', 'paths' => $paths]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
