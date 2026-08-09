<?php
// html/ajax_ai_models.php
// Listado / sincronización de modelos disponibles de un proveedor de IA.
// POST, requiere sesión y CSRF. Nunca expone API keys.
ini_set('display_errors', 0);
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/security.php';
require_once 'includes/TranslationProviderFactory.php';
require_once 'includes/TranslationModelRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}
csrf_require();
rateLimitRequire('ai_models', 10, 60);

$providerKey = strtolower(trim($_POST['provider'] ?? ''));
if (!in_array($providerKey, ['deepseek', 'gemini', 'openai', 'mistral'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Proveedor inválido.']);
    exit;
}

// Usar la API key enviada por el usuario (si la acaba de escribir).
// Si viene vacía, se usa la que ya está guardada cifrada en SQLite.
$apiKey = trim($_POST['api_key'] ?? '');
if ($apiKey === '') {
    $keySetting = $providerKey . '_api_key';
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
    $stmt->execute([$keySetting]);
    $apiKey = (string) $stmt->fetchColumn();
    if (!empty($apiKey) && isEncrypted($apiKey)) {
        $apiKey = decryptValue($apiKey);
    }
}

if (empty($apiKey)) {
    echo json_encode(['status' => 'error', 'message' => 'Guarda la API key de ' . $providerKey . ' antes de cargar modelos.']);
    exit;
}

$provider = TranslationProviderFactory::create($providerKey, $apiKey);
if (!$provider) {
    echo json_encode(['status' => 'error', 'message' => 'Proveedor no soportado.']);
    exit;
}

$action = $_POST['action'] ?? 'list';
try {
    if ($action === 'sync') {
        $models = $provider->listModels();
        TranslationModelRepository::sync($pdo, $providerKey, $models);
        echo json_encode([
            'status'  => 'success',
            'message' => 'Modelos actualizados (' . count($models) . ').',
            'models'  => $models,
        ]);
        exit;
    }

    // action = 'list' → desde caché
    $models = TranslationModelRepository::get($pdo, $providerKey);
    $sync = TranslationModelRepository::syncStatus($pdo, $providerKey);
    echo json_encode([
        'status'  => 'success',
        'models'  => $models,
        'sync'    => $sync,
    ]);
    exit;
} catch (Exception $e) {
    try {
        TranslationModelRepository::markSyncFailed($pdo, $providerKey, $e->getMessage());
    } catch (Exception $dbE) {
    }
    // Conservar caché previa si existe
    $models = TranslationModelRepository::get($pdo, $providerKey);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'models'  => $models,
    ]);
    exit;
}