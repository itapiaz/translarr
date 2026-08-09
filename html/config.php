<?php
// html/config.php - Versión SQLite para LinuxServer Nginx

// Configuración de rutas (Ruta interna del contenedor LSIO)
$dbPath = '/config/translarr.sqlite';

// Configuración de la aplicación
define('APP_NAME', 'Translarr');
define('APP_URL', 'http://localhost:4646');

// Iniciar sesión global (solo si no es CLI)
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Conexión a la base de datos (PDO SQLite)
try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 60);

    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("PRAGMA busy_timeout=60000;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        action VARCHAR(100),
        message TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS translation_jobs (
        job_id TEXT PRIMARY KEY,
        chunks TEXT NOT NULL,
        results TEXT NOT NULL,
        path TEXT,
        type TEXT,
        media_id TEXT,
        series_id TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS media_cache (
        id TEXT PRIMARY KEY, type TEXT NOT NULL, series_id TEXT,
        title TEXT NOT NULL, year TEXT, poster_url TEXT,
        has_spanish INTEGER DEFAULT 0,
        has_file INTEGER DEFAULT 0,
        subtitle_path TEXT, subtitle_lang TEXT,
        season INTEGER DEFAULT 0, episode INTEGER DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sonarr_series_id TEXT, sonarr_episode_id TEXT, tvdb_id TEXT,
        radarr_id TEXT, tmdb_id TEXT, video_path TEXT
    )");

    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN season INTEGER DEFAULT 0");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN episode INTEGER DEFAULT 0");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN overview TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN folder_path TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN sonarr_series_id TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN sonarr_episode_id TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN tvdb_id TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN radarr_id TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN tmdb_id TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN video_path TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE media_cache ADD COLUMN has_file INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS background_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL, status TEXT DEFAULT 'pending',
        payload TEXT, result TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME, finished_at DATETIME
    )");
    // Migraciones para background_tasks (por si la tabla fue creada sin estas columnas)
    try {
        $pdo->exec("ALTER TABLE background_tasks ADD COLUMN started_at DATETIME");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE background_tasks ADD COLUMN finished_at DATETIME");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE background_tasks ADD COLUMN payload TEXT");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE background_tasks ADD COLUMN result TEXT");
    } catch (Exception $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        endpoint TEXT NOT NULL, ip TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits ON rate_limits(endpoint, ip, created_at)");
    } catch (Exception $e) {
    }

    // Tabla de log de traducciones (historial de lo que se ha traducido)
    $pdo->exec("CREATE TABLE IF NOT EXISTS translation_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        media_id TEXT,
        media_title TEXT,
        media_type TEXT,
        series_id TEXT,
        season INTEGER DEFAULT 0,
        episode INTEGER DEFAULT 0,
        subtitle_path TEXT,
        original_lang TEXT,
        status TEXT DEFAULT 'pending',
        result TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME
    )");

    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $pwd = password_hash('admin', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password) VALUES ('admin', '$pwd')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES 
            ('sonarr_url', ''),
            ('sonarr_api_key', ''),
            ('sonarr_enabled', '0'),
            ('radarr_url', ''),
            ('radarr_api_key', ''),
            ('radarr_enabled', '0'),
            ('deepseek_api_key', ''),
            ('chunk_size', '50'),
            ('path_mapping_movies_from', ''),
            ('path_mapping_movies_to', ''),
            ('path_mapping_series_from', ''),
            ('path_mapping_series_to', ''),
            ('auto_scan_enabled', '1'),
            ('scan_interval_minutes', '60')");
    }

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!isset($settings['system_prompt'])) {
        $defaultPrompt = "You are an expert subtitle translator...";
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_prompt', ?)");
        $stmt->execute([$defaultPrompt]);
        $settings['system_prompt'] = $defaultPrompt;
    }

    require_once __DIR__ . '/includes/security.php';

    $rawSonarrApiKey = $settings['sonarr_api_key'] ?? '';
    $rawRadarrApiKey = $settings['radarr_api_key'] ?? '';
    $rawDeepseekApiKey = $settings['deepseek_api_key'] ?? '';

    $sonarrApiKeyDecrypted = isEncrypted($rawSonarrApiKey)
        ? decryptValue($rawSonarrApiKey)
        : $rawSonarrApiKey;
    $radarrApiKeyDecrypted = isEncrypted($rawRadarrApiKey)
        ? decryptValue($rawRadarrApiKey)
        : $rawRadarrApiKey;
    $deepseekApiKeyDecrypted = isEncrypted($rawDeepseekApiKey)
        ? decryptValue($rawDeepseekApiKey)
        : $rawDeepseekApiKey;

    define('SONARR_URL', rtrim($settings['sonarr_url'] ?? '', '/'));
    define('SONARR_API_KEY', $sonarrApiKeyDecrypted);
    define('SONARR_ENABLED', ($settings['sonarr_enabled'] ?? '0') === '1');
    define('RADARR_URL', rtrim($settings['radarr_url'] ?? '', '/'));
    define('RADARR_API_KEY', $radarrApiKeyDecrypted);
    define('RADARR_ENABLED', ($settings['radarr_enabled'] ?? '0') === '1');
    define('DEEPSEEK_API_KEY', $deepseekApiKeyDecrypted);
    define('CHUNK_SIZE', (int) ($settings['chunk_size'] ?? 50));
    define('DEEPSEEK_SYSTEM_PROMPT', $settings['system_prompt'] ?? '');

    define('PATH_MAPPING_MOVIES_FROM', $settings['path_mapping_movies_from'] ?? '');
    define('PATH_MAPPING_MOVIES_TO', $settings['path_mapping_movies_to'] ?? '');
    define('PATH_MAPPING_SERIES_FROM', $settings['path_mapping_series_from'] ?? '');
    define('PATH_MAPPING_SERIES_TO', $settings['path_mapping_series_to'] ?? '');
    define('AUTO_SCAN_ENABLED', ($settings['auto_scan_enabled'] ?? '1') === '1');
    define('SCAN_INTERVAL_MINUTES', (int) ($settings['scan_interval_minutes'] ?? 60));

    $defaultSettings = [
        'sonarr_url' => '',
        'sonarr_api_key' => '',
        'sonarr_enabled' => '0',
        'radarr_url' => '',
        'radarr_api_key' => '',
        'radarr_enabled' => '0',
        'auto_scan_enabled' => '1',
        'scan_interval_minutes' => '60',
    ];
    foreach ($defaultSettings as $key => $default) {
        if (!isset($settings[$key])) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $default]);
            $settings[$key] = $default;
        }
    }

} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>