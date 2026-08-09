<?php
// html/ajax_directory_browser.php
require_once 'config.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

// Rate limiting (30 consultas por minuto)
rateLimitRequire('directory_browser', 30, 60);

$path = $_GET['path'] ?? '/';
// Seguridad básica: evitar subir niveles usando ..
if (strpos($path, '..') !== false) {
    $path = '/';
}

if (!is_dir($path)) {
    echo json_encode(['status' => 'error', 'message' => 'Directorio no encontrado']);
    exit;
}

$items = @scandir($path);
if ($items === false) {
    echo json_encode(['status' => 'error', 'message' => 'Permiso denegado']);
    exit;
}

$directories = [];
foreach ($items as $item) {
    if ($item === '.') continue;
    
    $fullPath = rtrim($path, '/') . '/' . $item;
    
    if ($item === '..') {
        if ($path !== '/') {
            $directories[] = [
                'name' => '.. (Subir un nivel)',
                'path' => dirname($path),
                'isParent' => true
            ];
        }
        continue;
    }

    if (is_dir($fullPath)) {
        $directories[] = [
            'name' => $item,
            'path' => $fullPath,
            'isParent' => false
        ];
    }
}

// Ordenar alfabéticamente
usort($directories, function($a, $b) {
    if (isset($a['isParent']) && $a['isParent']) return -1;
    if (isset($b['isParent']) && $b['isParent']) return 1;
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode([
    'status' => 'success',
    'currentPath' => rtrim($path, '/') ?: '/',
    'directories' => $directories
]);
?>
