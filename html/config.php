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

    // Catálogo separado por entidad: movies / series / episodes
    $pdo->exec("CREATE TABLE IF NOT EXISTS movies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        radarr_id INTEGER UNIQUE NOT NULL,
        tmdb_id INTEGER,
        title TEXT NOT NULL,
        year TEXT,
        overview TEXT,
        poster_url TEXT,
        folder_path TEXT,
        video_path TEXT,
        has_file INTEGER DEFAULT 0,
        has_spanish INTEGER DEFAULT 0,
        has_english INTEGER DEFAULT 0,
        auto_translate INTEGER DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS series (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sonarr_series_id INTEGER UNIQUE NOT NULL,
        tvdb_id INTEGER,
        title TEXT NOT NULL,
        year TEXT,
        overview TEXT,
        poster_url TEXT,
        folder_path TEXT,
        auto_translate INTEGER DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS episodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        series_id INTEGER NOT NULL REFERENCES series(id),
        sonarr_episode_id INTEGER UNIQUE NOT NULL,
        tvdb_episode_id INTEGER,
        title TEXT,
        season INTEGER DEFAULT 0,
        episode INTEGER DEFAULT 0,
        video_path TEXT,
        has_file INTEGER DEFAULT 0,
        has_english INTEGER DEFAULT 0,
        has_spanish INTEGER DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_movies_has_file ON movies(has_file)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_episodes_series ON episodes(series_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_episodes_series_hasfile ON episodes(series_id, has_file)");
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

    // Migración: columna "is_ignored" para excluir películas/series de la monitorización
    try {
        $pdo->exec("ALTER TABLE movies ADD COLUMN is_ignored INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN is_ignored INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }

    // Migración: detección de subtítulo en inglés (para traducción automática)
    try {
        $pdo->exec("ALTER TABLE movies ADD COLUMN has_english INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE episodes ADD COLUMN has_english INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }

    // Migración: traducción automática por contenido (película/serie)
    try {
        $pdo->exec("ALTER TABLE movies ADD COLUMN auto_translate INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN auto_translate INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }

    // Migración: progreso de traducción por chunk en translation_jobs
    try {
        $pdo->exec("ALTER TABLE translation_jobs ADD COLUMN total_chunks INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE translation_jobs ADD COLUMN completed_chunks INTEGER DEFAULT 0");
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

    // Caché de modelos disponibles por proveedor de IA
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_models (
        provider TEXT NOT NULL,
        model_id TEXT NOT NULL,
        display_name TEXT NOT NULL,
        capabilities TEXT,
        is_recommended INTEGER DEFAULT 0,
        is_selectable INTEGER DEFAULT 1,
        raw_data TEXT,
        fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (provider, model_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_model_sync (
        provider TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        message TEXT,
        fetched_at DATETIME,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Columnas para registrar proveedor/modelo en el historial de traducciones
    foreach (['provider', 'model', 'input_tokens', 'output_tokens'] as $col) {
        try {
            $pdo->exec("ALTER TABLE translation_log ADD COLUMN $col TEXT");
        } catch (Exception $e) {
        }
    }

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
            ('gemini_api_key', ''),
            ('openai_api_key', ''),
            ('mistral_api_key', ''),
            ('translation_provider', 'deepseek'),
            ('translation_model', ''),
            ('translation_fallback_models', ''),
            ('translation_fallback_providers', ''),
            ('chunk_size', '50'),
            ('path_mapping_movies_from', ''),
            ('path_mapping_movies_to', ''),
            ('path_mapping_series_from', ''),
            ('path_mapping_series_to', ''),
            ('auto_scan_enabled', '1'),
            ('auto_translate_batch_size', '5'),
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
    $rawGeminiApiKey = $settings['gemini_api_key'] ?? '';
    $rawOpenaiApiKey = $settings['openai_api_key'] ?? '';
    $rawMistralApiKey = $settings['mistral_api_key'] ?? '';

    $sonarrApiKeyDecrypted = isEncrypted($rawSonarrApiKey)
        ? decryptValue($rawSonarrApiKey)
        : $rawSonarrApiKey;
    $radarrApiKeyDecrypted = isEncrypted($rawRadarrApiKey)
        ? decryptValue($rawRadarrApiKey)
        : $rawRadarrApiKey;
    $deepseekApiKeyDecrypted = isEncrypted($rawDeepseekApiKey)
        ? decryptValue($rawDeepseekApiKey)
        : $rawDeepseekApiKey;
    $geminiApiKeyDecrypted = isEncrypted($rawGeminiApiKey)
        ? decryptValue($rawGeminiApiKey)
        : $rawGeminiApiKey;
    $openaiApiKeyDecrypted = isEncrypted($rawOpenaiApiKey)
        ? decryptValue($rawOpenaiApiKey)
        : $rawOpenaiApiKey;
    $mistralApiKeyDecrypted = isEncrypted($rawMistralApiKey)
        ? decryptValue($rawMistralApiKey)
        : $rawMistralApiKey;

    define('SONARR_URL', rtrim($settings['sonarr_url'] ?? '', '/'));
    define('SONARR_API_KEY', $sonarrApiKeyDecrypted);
    define('SONARR_ENABLED', ($settings['sonarr_enabled'] ?? '0') === '1');
    define('RADARR_URL', rtrim($settings['radarr_url'] ?? '', '/'));
    define('RADARR_API_KEY', $radarrApiKeyDecrypted);
    define('RADARR_ENABLED', ($settings['radarr_enabled'] ?? '0') === '1');
    define('DEEPSEEK_API_KEY', $deepseekApiKeyDecrypted);
    define('GEMINI_API_KEY', $geminiApiKeyDecrypted);
    define('OPENAI_API_KEY', $openaiApiKeyDecrypted);
    define('MISTRAL_API_KEY', $mistralApiKeyDecrypted);
    define('CHUNK_SIZE', (int) ($settings['chunk_size'] ?? 50));
    define('DEEPSEEK_SYSTEM_PROMPT', $settings['system_prompt'] ?? '');
    define('TRANSLATION_PROVIDER', $settings['translation_provider'] ?? 'deepseek');
    define('TRANSLATION_MODEL', $settings['translation_model'] ?? '');
    define('TRANSLATION_FALLBACK_PROVIDERS', $settings['translation_fallback_providers'] ?? '');

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
        'deepseek_api_key' => '',
        'gemini_api_key' => '',
        'openai_api_key' => '',
        'mistral_api_key' => '',
        'translation_provider' => 'deepseek',
        'translation_model' => '',
        'translation_fallback_models' => '',
        'translation_fallback_providers' => '',
        'system_prompt' => '',
        'chunk_size' => '50',
        'auto_scan_enabled' => '1',
        'scan_interval_minutes' => '60',
        'auto_translate_batch_size' => '5',
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