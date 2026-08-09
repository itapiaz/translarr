<?php
// html/ajax_ai_test.php
// Prueba funcional de un modelo de IA concreto (POST, sesión + CSRF).
ini_set('display_errors', 0);
require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/security.php';
require_once 'includes/TranslationProviderFactory.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}
csrf_require();
rateLimitRequire('ai_test', 10, 60);

$providerKey = strtolower(trim($_POST['provider'] ?? ''));
$model = trim($_POST['model'] ?? '');
if (!in_array($providerKey, ['deepseek', 'gemini', 'openai', 'mistral'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Proveedor inválido.']);
    exit;
}
if ($model === '') {
    echo json_encode(['status' => 'error', 'message' => 'Indica un modelo a probar.']);
    exit;
}

$keySetting = $providerKey . '_api_key';
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
$stmt->execute([$keySetting]);
$apiKey = (string) $stmt->fetchColumn();
if (!empty($apiKey) && isEncrypted($apiKey)) {
    $apiKey = decryptValue($apiKey);
}
if (empty($apiKey)) {
    echo json_encode(['status' => 'error', 'message' => 'API key de ' . $providerKey . ' no configurada.']);
    exit;
}

$provider = TranslationProviderFactory::create($providerKey, $apiKey);
if (!$provider) {
    echo json_encode(['status' => 'error', 'message' => 'Proveedor no soportado.']);
    exit;
}

$result = $provider->test($model);
echo json_encode([
    'status'  => $result['ok'] ? 'success' : 'error',
    'message' => $result['message'],
    'model'   => $result['raw_model'],
]);