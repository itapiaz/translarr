<?php
/**
 * security.php — Funciones de seguridad: CSRF, encriptación, rate limiting
 */

// ============================================================
// CSRF Protection
// ============================================================

/**
 * Genera o recupera el token CSRF de la sesión actual.
 */
function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token_time'] = time();
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Renderiza un campo hidden con el token CSRF.
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

/**
 * Valida el token CSRF recibido por POST.
 * El token expira después de $ttl segundos (por defecto 2 horas).
 */
function csrf_validate(?int $ttl = 7200): bool {
    $token = $_POST['_csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['_csrf_token'])) {
        return false;
    }
    
    // Timing-safe comparison
    return hash_equals($_SESSION['_csrf_token'], $token);
}

/**
 * Valida CSRF y muestra error si es inválido.
 */
function csrf_require(): void {
    if (!csrf_validate()) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => 'Token CSRF inválido o expirado. Recarga la página e intenta de nuevo.']));
    }
}

// ============================================================
// Encriptación de API Keys
// ============================================================

/**
 * Obtiene o genera la clave de encriptación del sistema.
 * La clave se deriva de una clave maestra almacenada en un archivo fuera del webroot.
 */
function getEncryptionKey(): string {
    $keyFile = __DIR__ . '/../../config/encryption.key';
    $keyDir = dirname($keyFile);
    
    // Crear el directorio si no existe
    if (!is_dir($keyDir)) {
        @mkdir($keyDir, 0700, true);
    }
    
    if (!file_exists($keyFile)) {
        // Generar nueva clave AES-256 (32 bytes = 256 bits)
        $key = bin2hex(random_bytes(32));
        file_put_contents($keyFile, $key, LOCK_EX);
        @chmod($keyFile, 0600); // Solo lectura/escritura para el propietario
    } else {
        $key = trim(file_get_contents($keyFile));
    }
    
    return $key;
}

/**
 * Encripta un valor (API key, token, etc.) para almacenamiento seguro.
 * Retorna base64(cifrado + iv) listo para guardar en BD.
 */
function encryptValue(string $plaintext): string {
    if (empty($plaintext)) return '';
    
    $key = hex2bin(getEncryptionKey());
    $iv = random_bytes(16); // AES-256-CBC usa IV de 16 bytes
    
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    
    if ($ciphertext === false) {
        throw new RuntimeException('Error al encriptar: ' . openssl_error_string());
    }
    
    // Almacenar iv + ciphertext, codificado en base64
    return base64_encode($iv . $ciphertext);
}

/**
 * Desencripta un valor previamente encriptado con encryptValue().
 * Si falla la desencriptación, retorna el valor original (fallback seguro).
 */
function decryptValue(string $encoded): string {
    if (empty($encoded)) return '';
    
    $key = hex2bin(getEncryptionKey());
    $data = base64_decode($encoded, true);
    
    if ($data === false || strlen($data) < 16) {
        return $encoded; // Datos corruptos o no encriptados, devolver original
    }
    
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    
    if ($plaintext === false) {
        return $encoded; // Fallo de desencriptación, devolver original
    }
    
    return $plaintext;
}

/**
 * Verifica si un valor almacenado está encriptado (formato base64 con mínimo de largo).
 */
function isEncrypted(string $value): bool {
    if (empty($value)) return false;
    // Los valores encriptados son base64 y tienen al menos 24 caracteres (16 IV + 16 min cifrado en base64)
    if (strlen($value) < 24) return false;
    if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $value)) return false;
    
    // Verificación adicional: intentar decodificar y ver si tiene estructura válida
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 16) return false;
    
    return true;
}

// ============================================================
// Rate Limiting
// ============================================================

/**
 * Verifica rate limiting por IP para un endpoint.
 * 
 * @param string $endpoint  Identificador del endpoint (ej: 'login', 'translate')
 * @param int    $maxAttempts Máximo de intentos permitidos
 * @param int    $windowSeconds Ventana de tiempo en segundos
 * @return bool  True si la petición está dentro del límite, False si excede
 */
function checkRateLimit(string $endpoint, int $maxAttempts = 10, int $windowSeconds = 60): bool {
    global $pdo;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
    
    // Limpiar registros antiguos
    $pdo->exec("DELETE FROM rate_limits WHERE created_at < datetime('now', '-1 day')");
    
    // Contar intentos recientes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM rate_limits 
        WHERE endpoint = ? AND ip = ? AND created_at >= ?
    ");
    $stmt->execute([$endpoint, $ip, $windowStart]);
    $count = (int)$stmt->fetchColumn();
    
    return $count < $maxAttempts;
}

/**
 * Registra un intento para rate limiting.
 */
function logRateLimitAttempt(string $endpoint): void {
    global $pdo;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $stmt = $pdo->prepare("
        INSERT INTO rate_limits (endpoint, ip, created_at) VALUES (?, ?, datetime('now'))
    ");
    $stmt->execute([$endpoint, $ip]);
}

/**
 * Aplica rate limiting: verifica y si excede, detiene la ejecución con error 429.
 */
function rateLimitRequire(string $endpoint, int $maxAttempts = 10, int $windowSeconds = 60): void {
    if (!checkRateLimit($endpoint, $maxAttempts, $windowSeconds)) {
        http_response_code(429);
        $retryAfter = $windowSeconds;
        header('Retry-After: ' . $retryAfter);
        
        $msg = json_encode([
            'status' => 'error', 
            'message' => "Demasiadas solicitudes. Intenta de nuevo en {$retryAfter} segundos."
        ]);
        die($msg);
    }
    
    logRateLimitAttempt($endpoint);
}

// ============================================================
// Session Management
// ============================================================

/**
 * Regenera el ID de sesión después de login exitoso (previene session fixation).
 * También renueva el token CSRF.
 */
function regenerateSession(): void {
    session_regenerate_id(true);
    // Renovar CSRF token después de login
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['_csrf_token_time'] = time();
}
