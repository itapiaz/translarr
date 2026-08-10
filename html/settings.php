<?php
// html/settings.php
require_once 'includes/header.php';
require_once 'includes/security.php';

$message = '';
$status = '';

// Obtener los valores actuales desde la BD (antes del POST para usarlos en comparación)
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$currentSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);


// Procesar formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $message = "Token de seguridad inválido o expirado. Recarga la página.";
        $status = "danger";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        // === FORMULARIO 3: Cambio de contraseña ===
        rateLimitRequire('password_change', 3, 300);
        $newPassword = $_POST['new_password'] ?? '';
        if ($newPassword) {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed, $_SESSION['user_id']])) {
                $message = "Contraseña actualizada correctamente.";
                $status = "success";
            } else {
                $message = "Error al actualizar la contraseña.";
                $status = "danger";
            }
        }
    } elseif (isset($_POST['form']) && in_array($_POST['form'], ['sonarr', 'radarr'], true)) {
        // === FORMULARIO 1: Configuración de Sonarr / Radarr ===
        rateLimitRequire('settings_save', 10, 60);
        $svc = $_POST['form']; // 'sonarr' | 'radarr'

        $url = trim($_POST[$svc . '_url'] ?? '');
        $enabled = (($_POST[$svc . '_enabled'] ?? '0') === '1') ? '1' : '0';
        $rawApiKey = trim($_POST[$svc . '_api_key'] ?? '');

        $storedApiKey = $currentSettings[$svc . '_api_key'] ?? '';
        if (isEncrypted($storedApiKey)) {
            $decryptedStored = decryptValue($storedApiKey);
            $apiKeyChanged = ($rawApiKey !== $decryptedStored);
        } else {
            $apiKeyChanged = ($rawApiKey !== $storedApiKey);
        }
        $apiKey = ($apiKeyChanged && $rawApiKey !== '')
            ? encryptValue($rawApiKey)
            : $storedApiKey;

        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $pdo->beginTransaction();
            $stmt->execute([$svc . '_url', $url]);
            $stmt->execute([$svc . '_api_key', $apiKey]);
            $stmt->execute([$svc . '_enabled', $enabled]);
            $pdo->commit();
            $message = ucfirst($svc) . " configurado correctamente.";
            $status = "success";

            // Auto-lanzar escaneo si el servicio quedó configurado
            if ($url !== '' && $apiKey !== '') {
                @touch('/config/scan_trigger.now');
                $message .= " Escaneo encolado en background.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error al guardar: " . $e->getMessage();
            $status = "danger";
        }
    } elseif (isset($_POST['form']) && $_POST['form'] === 'ai') {
        // === FORMULARIO 2: IA / Traducción (multi-proveedor) ===
        rateLimitRequire('settings_save', 10, 60);

        $systemPrompt = trim($_POST['system_prompt'] ?? '');
        $chunkSize = trim($_POST['chunk_size'] ?? '50');
        $provider = in_array($_POST['translation_provider'] ?? '', ['deepseek', 'gemini', 'openai', 'mistral'], true)
            ? $_POST['translation_provider']
            : 'deepseek';
        $model = trim($_POST['translation_model'] ?? '');

        // Modelos de respaldo en orden: pares proveedor/modelo seleccionados en la UI.
        $fallbackModels = [];
        $rawFallbackModelsJson = trim($_POST['translation_fallback_models'] ?? '');
        $decodedFallback = json_decode($rawFallbackModelsJson, true);
        if (is_array($decodedFallback)) {
            foreach ($decodedFallback as $item) {
                $fp = strtolower(trim($item['provider'] ?? ''));
                $fm = trim($item['model'] ?? '');
                if (!in_array($fp, ['deepseek', 'gemini', 'openai', 'mistral'], true)) continue;
                if ($fm === '') continue;
                // Evitar duplicados de una misma combinación proveedor/modelo
                $exists = false;
                foreach ($fallbackModels as $existing) {
                    if ($existing['provider'] === $fp && $existing['model'] === $fm) { $exists = true; break; }
                }
                if (!$exists) $fallbackModels[] = ['provider' => $fp, 'model' => $fm];
            }
        }
        $fallbackModelsJson = json_encode($fallbackModels);

        // Guardar cada API key (conservando la cifrada si no cambió)
        $keys = ['deepseek', 'gemini', 'openai', 'mistral'];
        $storedKeys = [];
        foreach ($keys as $k) {
            $raw = trim($_POST[$k . '_api_key'] ?? '');
            $stored = $currentSettings[$k . '_api_key'] ?? '';
            $storedDecrypted = (!empty($stored) && isEncrypted($stored)) ? decryptValue($stored) : $stored;
            $storedKeys[$k] = ($raw !== '' && $raw !== $storedDecrypted)
                ? encryptValue($raw)
                : $stored;
        }

        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $pdo->beginTransaction();
            foreach ($keys as $k) {
                $stmt->execute([$k . '_api_key', $storedKeys[$k]]);
            }
            $stmt->execute(['translation_provider', $provider]);
            $stmt->execute(['translation_model', $model]);
            $stmt->execute(['translation_fallback_models', $fallbackModelsJson]);
            $stmt->execute(['translation_fallback_providers', '']);
            $stmt->execute(['system_prompt', $systemPrompt]);
            $stmt->execute(['chunk_size', $chunkSize]);
            $autoTranslateBatch = max(1, min(200, (int)($_POST['auto_translate_batch_size'] ?? 5)));
            $stmt->execute(['auto_translate_batch_size', (string)$autoTranslateBatch]);
            $pdo->commit();
            $message = "Configuración de IA guardada correctamente.";
            $status = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error al guardar: " . $e->getMessage();
            $status = "danger";
        }
    }
}

// Obtener los valores actuales desde la BD (AHORA, después de haber guardado)
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$currentSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Sonarr
$currentSonarrUrl = $currentSettings['sonarr_url'] ?? '';
$currentSonarrApiKeyRaw = $currentSettings['sonarr_api_key'] ?? '';
$currentSonarrApiKey = (!empty($currentSonarrApiKeyRaw) && isEncrypted($currentSonarrApiKeyRaw))
    ? decryptValue($currentSonarrApiKeyRaw)
    : $currentSonarrApiKeyRaw;
$currentSonarrEnabled = $currentSettings['sonarr_enabled'] ?? '0';

// Radarr
$currentRadarrUrl = $currentSettings['radarr_url'] ?? '';
$currentRadarrApiKeyRaw = $currentSettings['radarr_api_key'] ?? '';
$currentRadarrApiKey = (!empty($currentRadarrApiKeyRaw) && isEncrypted($currentRadarrApiKeyRaw))
    ? decryptValue($currentRadarrApiKeyRaw)
    : $currentRadarrApiKeyRaw;
$currentRadarrEnabled = $currentSettings['radarr_enabled'] ?? '0';

$currentDeepseekApiKeyRaw = $currentSettings['deepseek_api_key'] ?? '';
$currentDeepseekApiKey = (!empty($currentDeepseekApiKeyRaw) && isEncrypted($currentDeepseekApiKeyRaw))
    ? decryptValue($currentDeepseekApiKeyRaw)
    : $currentDeepseekApiKeyRaw;
$currentGeminiApiKeyRaw = $currentSettings['gemini_api_key'] ?? '';
$currentGeminiApiKey = (!empty($currentGeminiApiKeyRaw) && isEncrypted($currentGeminiApiKeyRaw))
    ? decryptValue($currentGeminiApiKeyRaw)
    : $currentGeminiApiKeyRaw;
$currentOpenaiApiKeyRaw = $currentSettings['openai_api_key'] ?? '';
$currentOpenaiApiKey = (!empty($currentOpenaiApiKeyRaw) && isEncrypted($currentOpenaiApiKeyRaw))
    ? decryptValue($currentOpenaiApiKeyRaw)
    : $currentOpenaiApiKeyRaw;
$currentMistralApiKeyRaw = $currentSettings['mistral_api_key'] ?? '';
$currentMistralApiKey = (!empty($currentMistralApiKeyRaw) && isEncrypted($currentMistralApiKeyRaw))
    ? decryptValue($currentMistralApiKeyRaw)
    : $currentMistralApiKeyRaw;
$currentTranslationProvider = $currentSettings['translation_provider'] ?? 'deepseek';
$currentTranslationModel = $currentSettings['translation_model'] ?? '';
$currentSystemPrompt = $currentSettings['system_prompt'] ?? '';
$currentChunkSize = $currentSettings['chunk_size'] ?? '50';
$currentPathMappingMoviesFrom = $currentSettings['path_mapping_movies_from'] ?? '';
$currentPathMappingMoviesTo = $currentSettings['path_mapping_movies_to'] ?? '';
$currentPathMappingSeriesFrom = $currentSettings['path_mapping_series_from'] ?? '';
$currentPathMappingSeriesTo = $currentSettings['path_mapping_series_to'] ?? '';
$currentAutoScan = $currentSettings['auto_scan_enabled'] ?? '1';
$currentScanInterval = $currentSettings['scan_interval_minutes'] ?? '60';
$currentAutoTranslateBatch = $currentSettings['auto_translate_batch_size'] ?? '5';

// Modelos registrados de IA (caché SQLite) y etiquetas de proveedor
require_once 'includes/TranslationProviderFactory.php';
require_once 'includes/TranslationModelRepository.php';
$providerLabels = ['deepseek' => 'DeepSeek', 'gemini' => 'Google Gemini', 'openai' => 'OpenAI', 'mistral' => 'Mistral AI'];

// Modelos de respaldo configurados (pares proveedor/modelo) y su estado actual
$currentFallbackModelsJson = $currentSettings['translation_fallback_models'] ?? '';
$currentFallbackModels = [];
if ($currentFallbackModelsJson !== '') {
    $dec = json_decode($currentFallbackModelsJson, true);
    if (is_array($dec)) {
        foreach ($dec as $item) {
            $p = strtolower(trim($item['provider'] ?? ''));
            $m = trim($item['model'] ?? '');
            if ($p === '' || $m === '') continue;
            $currentFallbackModels[] = ['provider' => $p, 'model' => $m];
        }
    }
}

// Todos los modelos registrados y seleccionables, agrupados por proveedor,
// para el selector de modelos de respaldo. Se excluye el modelo principal exacto.
$fallbackAllModels = [];
foreach ($providerLabels as $pk => $pl) {
    $models = TranslationModelRepository::get($pdo, $pk);
    foreach ($models as $m) {
        if ((int)$m['is_selectable'] !== 1) continue;
        if ($pk === $currentTranslationProvider && $m['model_id'] === $currentTranslationModel) continue;
        $fallbackAllModels[] = [
            'provider'       => $pk,
            'provider_label' => $pl,
            'model_id'       => $m['model_id'],
            'display_name'   => $m['display_name'],
        ];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fa fa-cogs text-primary"></i> Configuración del Sistema</h2>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $status ?> alert-dismissible fade show" role="alert">
        <i class="fa <?= $status === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i> 
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Menú Lateral -->
    <div class="col-md-3 mb-4">
        <div class="nav flex-column nav-pills glass-card p-3" id="settings-tabs" role="tablist" aria-orientation="vertical">
            <button class="nav-link active text-start mb-2" id="tab-server" data-bs-toggle="pill" data-bs-target="#pane-server" type="button" role="tab" style="color: #fff;"><i class="fa fa-server me-2 text-info"></i> Sonarr / Radarr</button>
            <button class="nav-link text-start mb-2" id="tab-ai" data-bs-toggle="pill" data-bs-target="#pane-ai" type="button" role="tab" style="color: #fff;"><i class="fa fa-bolt me-2 text-warning"></i> IA / Traducción</button>
            <button class="nav-link text-start mb-4" id="tab-security" data-bs-toggle="pill" data-bs-target="#pane-security" type="button" role="tab" style="color: #fff;"><i class="fa fa-lock me-2 text-danger"></i> Seguridad</button>
            <a href="logs.php" class="nav-link text-start border border-info text-info mt-2" style="background: rgba(0, 242, 254, 0.05);"><i class="fa fa-terminal me-2"></i> Logs del Sistema</a>
        </div>
    </div>

    <!-- Contenido -->
    <div class="col-md-9">
        
        <div class="tab-content" id="settings-tabContent">
                
                <!-- PANE 1: SONARR / RADARR -->
                <div class="tab-pane fade show active" id="pane-server" role="tabpanel">

                    <!-- ===== SONARR ===== -->
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="form" value="sonarr">
                        <?= csrf_field() ?>
                        <div class="card glass-card mb-4">
                            <div class="card-body p-4">
                                <h4 class="mb-2 text-info"><i class="fa fa-tv"></i> Sonarr — Series</h4>
                                <p class="text-muted small mb-4">Fuente de series, episodios, IDs TVDB y rutas locales. La metadata se enriquecerá desde TheTVDB.</p>

                                <div class="form-check form-switch mb-4">
                                    <input class="form-check-input" type="checkbox" id="sonarr_enabled" name="sonarr_enabled" value="1" <?= $currentSonarrEnabled === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sonarr_enabled">Activar conexión con Sonarr</label>
                                </div>

                                <div class="mb-3">
                                    <label for="sonarr_url" class="form-label">URL de Sonarr</label>
                                    <input type="url" class="form-control" id="sonarr_url" name="sonarr_url"
                                           value="<?= htmlspecialchars($currentSonarrUrl) ?>"
                                           placeholder="Ej: http://192.168.1.100:8989">
                                    <div class="form-text text-muted">Incluye http:// o https:// y el puerto.</div>
                                </div>

                                <div class="mb-4">
                                    <label for="sonarr_api_key" class="form-label">API Key de Sonarr</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="sonarr_api_key" name="sonarr_api_key"
                                               value="<?= htmlspecialchars($currentSonarrApiKey) ?>"
                                               placeholder="Pega aquí la API Key">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="sonarr_api_key">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-outline-info btn-test-conn" data-service="sonarr">
                                    <i class="fa fa-plug me-1"></i> Probar conexión
                                </button>
                                <div id="test-result-sonarr" class="mt-2 small"></div>
                            </div>
                        </div>
                        <div class="text-end mb-4">
                            <button type="submit" class="btn btn-gradient btn-lg w-100 shadow"><i class="fa fa-save me-2"></i> Guardar Sonarr</button>
                        </div>
                    </form>

                    <!-- ===== RADARR ===== -->
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="form" value="radarr">
                        <?= csrf_field() ?>
                        <div class="card glass-card mb-4">
                            <div class="card-body p-4">
                                <h4 class="mb-2 text-primary"><i class="fa fa-film"></i> Radarr — Películas</h4>
                                <p class="text-muted small mb-4">Fuente de películas, IDs TMDB y rutas locales. La metadata se enriquecerá desde TMDB.</p>

                                <div class="form-check form-switch mb-4">
                                    <input class="form-check-input" type="checkbox" id="radarr_enabled" name="radarr_enabled" value="1" <?= $currentRadarrEnabled === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="radarr_enabled">Activar conexión con Radarr</label>
                                </div>

                                <div class="mb-3">
                                    <label for="radarr_url" class="form-label">URL de Radarr</label>
                                    <input type="url" class="form-control" id="radarr_url" name="radarr_url"
                                           value="<?= htmlspecialchars($currentRadarrUrl) ?>"
                                           placeholder="Ej: http://192.168.1.100:7878">
                                    <div class="form-text text-muted">Incluye http:// o https:// y el puerto.</div>
                                </div>

                                <div class="mb-4">
                                    <label for="radarr_api_key" class="form-label">API Key de Radarr</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="radarr_api_key" name="radarr_api_key"
                                               value="<?= htmlspecialchars($currentRadarrApiKey) ?>"
                                               placeholder="Pega aquí la API Key">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="radarr_api_key">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-outline-primary btn-test-conn" data-service="radarr">
                                    <i class="fa fa-plug me-1"></i> Probar conexión
                                </button>
                                <div id="test-result-radarr" class="mt-2 small"></div>
                            </div>
                        </div>
                        <div class="text-end mb-4">
                            <button type="submit" class="btn btn-gradient btn-lg w-100 shadow"><i class="fa fa-save me-2"></i> Guardar Radarr</button>
                        </div>
                    </form>
                </div>

                <!-- PANE 2: IA / TRADUCCIÓN (multi-proveedor) -->
                <div class="tab-pane fade" id="pane-ai" role="tabpanel">
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="form" value="ai">
                        <?= csrf_field() ?>
                    <div class="card glass-card mb-4">
                        <div class="card-body p-4">
                            <h4 class="mb-4 text-warning"><i class="fa fa-bolt"></i> IA / Traducción</h4>

                            <div class="mb-4">
                                <label for="translation_provider" class="form-label">Proveedor de traducción</label>
                                <select class="form-select" id="translation_provider" name="translation_provider">
                                    <?php foreach (['deepseek' => 'DeepSeek', 'gemini' => 'Google Gemini', 'openai' => 'OpenAI', 'mistral' => 'Mistral AI'] as $pk => $pl): ?>
                                        <option value="<?= $pk ?>" <?= $currentTranslationProvider === $pk ? 'selected' : '' ?>><?= $pl ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text text-muted">El proveedor y modelo se congelan en cada tarea al crearla.</div>
                            </div>

                            <?php foreach (['deepseek', 'gemini', 'openai', 'mistral'] as $pk): ?>
                                <?php $cur = $currentSettings[$pk . '_api_key'] ?? ''; ?>
                                <?php $curDecrypted = (!empty($cur) && isEncrypted($cur)) ? decryptValue($cur) : $cur; ?>
                                <div class="mb-4 provider-key" data-provider="<?= $pk ?>" <?= $currentTranslationProvider !== $pk ? 'style="display:none"' : '' ?>>
                                    <label for="<?= $pk ?>_api_key" class="form-label">API Key <?= ['deepseek' => 'DeepSeek', 'gemini' => 'Google Gemini', 'openai' => 'OpenAI', 'mistral' => 'Mistral AI'][$pk] ?></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="<?= $pk ?>_api_key" name="<?= $pk ?>_api_key"
                                               value="<?= htmlspecialchars($curDecrypted) ?>"
                                               placeholder="Clave de la API...">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="<?= $pk ?>_api_key"><i class="fa fa-eye"></i></button>
                                    </div>
                                    <div class="form-text text-muted">Se almacena cifrada. Solo se usa en servidor.</div>
                                </div>
                            <?php endforeach; ?>

                            <div class="mb-4">
                                <label for="translation_model" class="form-label">Modelo</label>
                                <div class="input-group">
                                    <select class="form-select" id="translation_model" name="translation_model">
                                        <?php if ($currentTranslationModel !== ''): ?>
                                            <option value="<?= htmlspecialchars($currentTranslationModel) ?>" selected><?= htmlspecialchars($currentTranslationModel) ?></option>
                                        <?php endif; ?>
                                        <option value="">(auto-seleccionar al traducir)</option>
                                        <?php
                                        $stmtM = $pdo->prepare("SELECT model_id, display_name, is_selectable FROM provider_models WHERE provider=? ORDER BY is_recommended DESC, display_name ASC");
                                        $stmtM->execute([$currentTranslationProvider]);
                                        foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m):
                                        ?>
                                            <option value="<?= htmlspecialchars($m['model_id']) ?>" <?= $currentTranslationModel === $m['model_id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-info" id="btn-sync-models"><i class="fa fa-refresh me-1"></i> Actualizar</button>
                                </div>
                                <div class="form-text text-muted" id="ai-model-feedback">Los modelos se cargan desde la API del proveedor. Usa "Actualizar" para refrescar la lista.</div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-success" id="btn-test-model"><i class="fa fa-plug me-1"></i> Probar conexión</button>
                                    <div class="form-text text-muted mt-1">Comprueba que la API key es válida y que el modelo seleccionado responde correctamente.</div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Modelos de respaldo (fallback)</label>
                                <div class="form-text text-muted mb-2">Si el proveedor/modelo activo falla (clave inválida, límite, red), TransLarr intentará estos modelos en el orden indicado. Solo se muestran modelos registrados y aptos para subtítulos.</div>
                                <div class="input-group mb-2">
                                    <select class="form-select" id="fallback_model_select">
                                        <option value="">— Selecciona un modelo —</option>
                                        <?php
                                        $lastProvider = null;
                                        foreach ($fallbackAllModels as $fm):
                                            if ($fm['provider'] !== $lastProvider):
                                                if ($lastProvider !== null) echo '</optgroup>';
                                                echo '<optgroup label="' . htmlspecialchars($fm['provider_label']) . '">';
                                                $lastProvider = $fm['provider'];
                                            endif;
                                        ?>
                                            <option value="<?= htmlspecialchars($fm['provider']) ?>::<?= htmlspecialchars($fm['model_id']) ?>"
                                                    data-label="<?= htmlspecialchars($fm['display_name']) ?>"><?= htmlspecialchars($fm['display_name']) ?></option>
                                        <?php endforeach;
                                            if ($lastProvider !== null) echo '</optgroup>';
                                        ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-info" id="btn-add-fallback"><i class="fa fa-plus me-1"></i> Añadir</button>
                                </div>
                                <ul class="list-group" id="fallback-list" style="max-height:260px;overflow-y:auto;"></ul>
                                <input type="hidden" id="translation_fallback_models" name="translation_fallback_models" value="<?= htmlspecialchars($currentFallbackModelsJson) ?>">
                                <div class="form-text text-muted mt-1">Los respaldos se intentarán en el orden de esta lista. Usa ↑/↓ para reordenar y × para quitar.</div>
                            </div>

                            <div class="mb-4">
                                <label for="system_prompt" class="form-label">System Prompt (Instrucciones para IA)</label>
                                <textarea class="form-control font-monospace" id="system_prompt" name="system_prompt" rows="6"><?= htmlspecialchars($currentSystemPrompt) ?></textarea>
                                <div class="form-text text-muted">Instrucciones base enviadas al modelo para guiar la traducción y el formato.</div>
                            </div>

                            <div class="mb-4">
                                <label for="chunk_size" class="form-label">Tamaño de Lote (Líneas por bloque)</label>
                                <input type="number" class="form-control" id="chunk_size" name="chunk_size"
                                       value="<?= htmlspecialchars($currentChunkSize) ?>" min="10" max="500">
                                <div class="form-text text-muted">Recomendado entre 50 y 100 para evitar timeouts.</div>
                            </div>

                            <hr class="border-secondary">
                            <h5 class="text-info mb-3"><i class="fa fa-magic me-2"></i> Traducción automática</h5>

                            <div class="mb-4">
                                <label for="auto_translate_batch_size" class="form-label">Máximo de traducciones automáticas por escaneo</label>
                                <input type="number" class="form-control" id="auto_translate_batch_size" name="auto_translate_batch_size"
                                       value="<?= htmlspecialchars($currentAutoTranslateBatch) ?>" min="1" max="200">
                                <div class="form-text text-muted">Límite de tareas que el worker puede encolar tras cada escaneo para películas y series que tengan activada su <em>Traducción automática</em> (en la página de cada contenido).</div>
                            </div>

                        </div>
                    </div>
                    <div class="text-end mb-4">
                        <button type="submit" class="btn btn-gradient btn-lg w-100 shadow"><i class="fa fa-save me-2"></i> Guardar IA / Traducción</button>
                    </div>
                    </form>
                </div><!-- /pane-ai -->

                <!-- PANE 3: SEGURIDAD -->
            <div class="tab-pane fade" id="pane-security" role="tabpanel">
                <div class="card glass-card mb-4">
                    <div class="card-body p-4">
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="action" value="update_password">
                            <?= csrf_field() ?>
                            <h4 class="mb-4 text-danger"><i class="fa fa-lock"></i> Seguridad de la Cuenta</h4>

                            <div class="mb-4">
                                <label for="new_password" class="form-label">Nueva Contraseña del Administrador</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       placeholder="Ingresa la nueva contraseña" minlength="4">
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fa fa-key me-2"></i> Cambiar Contraseña
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                </div>
            </div>
        </div><!-- /tab-content -->

    </div><!-- /col-md-9 -->
</div><!-- /row -->

<style>
/* Estilos para las nav-pills (Tabs Laterales) */
.nav-pills .nav-link {
    border-radius: 8px;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}
.nav-pills .nav-link:hover {
    background: rgba(255,255,255,0.05);
}
.nav-pills .nav-link.active {
    background: linear-gradient(135deg, rgba(79, 172, 254, 0.2), rgba(0, 242, 254, 0.2));
    border: 1px solid rgba(79, 172, 254, 0.5);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}
</style>



<?php require_once 'includes/footer.php'; ?>

<!-- Modal Explorador de Archivos -->
<div class="modal fade" id="browserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content glass-card">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-info"><i class="fa fa-folder-open"></i> Explorador Local</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div class="p-2 border-bottom border-secondary bg-dark">
            <span class="text-muted small">Ruta actual:</span><br>
            <strong id="browserCurrentPath" class="text-light">/</strong>
        </div>
        <div id="browserList" class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
            <!-- Contenido dinámico -->
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-info" id="btnSelectPath">Seleccionar esta carpeta</button>
      </div>
    </div>
  </div>
</div>

<script>
// Mostrar/ocultar botón de guardado general según la pestaña activa

document.querySelectorAll('.toggle-password').forEach(button => {
    button.addEventListener('click', function() {
        const targetId = this.getAttribute('data-target');
        const input = document.getElementById(targetId);
        const icon = this.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
});

// Prueba de conexión Sonarr/Radarr (POST con CSRF; la API key nunca viaja por URL)
document.querySelectorAll('.btn-test-conn').forEach(btn => {
    btn.addEventListener('click', function () {
        const service = this.getAttribute('data-service');
        const urlInput = document.getElementById(service + '_url');
        const keyInput = document.getElementById(service + '_api_key');
        const resultEl = document.getElementById('test-result-' + service);
        const csrfInput = document.querySelector('input[name="_csrf_token"]');

        const url = (urlInput.value || '').trim();
        const apiKey = (keyInput.value || '').trim();

        if (!url) {
            resultEl.innerHTML = '<span class="text-warning"><i class="fa fa-exclamation-triangle me-1"></i>Indica primero la URL.</span>';
            return;
        }

        resultEl.innerHTML = '<span class="text-info"><i class="fa fa-spinner fa-spin me-1"></i>Probando conexión...</span>';
        this.disabled = true;

        const body = new URLSearchParams({ service: service, url: url, api_key: apiKey });
        if (csrfInput) body.append('_csrf_token', csrfInput.value);

        fetch('ajax_test_connection.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    resultEl.innerHTML = '<span class="text-success"><i class="fa fa-check-circle me-1"></i>' + (data.message || 'Conexión exitosa.') + '</span>';
                } else {
                    resultEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>' + (data.message || 'Error de conexión.') + '</span>';
                }
            })
            .catch(() => {
                resultEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>Error de red.</span>';
            })
            .finally(() => {
                this.disabled = false;
            });
    });
});

// ===== IA / TRADUCCIÓN: cambio de proveedor, sincronizar y probar modelos =====
const csrfInput = document.querySelector('input[name="_csrf_token"]');
const csrfVal = () => (csrfInput ? csrfInput.value : '');

function uiAiFeedback(msg, type) {
    const el = document.getElementById('ai-model-feedback');
    if (!el) return;
    const cls = type === 'ok' ? 'text-success' : (type === 'err' ? 'text-danger' : 'text-muted');
    el.innerHTML = '<span class="' + cls + '">' + msg + '</span>';
}

// Mostrar/ocultar el campo de API key según el proveedor seleccionado
const providerSelect = document.getElementById('translation_provider');
if (providerSelect) {
    providerSelect.addEventListener('change', function () {
        const pk = this.value;
        document.querySelectorAll('.provider-key').forEach(el => {
            el.style.display = el.getAttribute('data-provider') === pk ? '' : 'none';
        });
        // Recargar modelos del proveedor seleccionado desde caché
        loadCachedModels(pk);
    });
}

function loadCachedModels(pk) {
    const modelSel = document.getElementById('translation_model');
    if (!modelSel) return;
    const body = new URLSearchParams({ action: 'list', provider: pk, _csrf_token: csrfVal() });
    fetch('ajax_ai_models.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            // Solo conservar el modelo actual si pertenece al proveedor seleccionado
            const current = modelSel.value;
            const currentBelongs = current === '' || (data.models || []).some(m => m.model_id === current);
            modelSel.innerHTML = '';

            // Opción por defecto
            const autoOpt = document.createElement('option');
            autoOpt.value = '';
            autoOpt.textContent = '(auto-seleccionar al traducir)';
            if (!currentBelongs || current === '') autoOpt.selected = true;
            modelSel.appendChild(autoOpt);

            if (data.models && data.models.length > 0) {
                data.models.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.model_id;
                    opt.textContent = m.display_name;
                    if (m.model_id === current) opt.selected = true;
                    modelSel.appendChild(opt);
                });
            }
            if (data.sync && data.sync.fetched_at) {
                uiAiFeedback('Última actualización: ' + data.sync.fetched_at + '.', 'ok');
            }
        })
        .catch(() => uiAiFeedback('No se pudo cargar los modelos.', 'err'));
}

// Puebla el combo directamente desde una lista de modelos (sin segunda consulta).
function populateModelsFromList(pk, models, message) {
    const modelSel = document.getElementById('translation_model');
    if (!modelSel) return;
    const current = modelSel.value;
    const currentBelongs = current === '' || models.some(m => m.id === current);
    modelSel.innerHTML = '';

    const autoOpt = document.createElement('option');
    autoOpt.value = '';
    autoOpt.textContent = '(auto-seleccionar al traducir)';
    if (!currentBelongs || current === '') autoOpt.selected = true;
    modelSel.appendChild(autoOpt);

    models.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.display_name || m.id;
        if (m.id === current) opt.selected = true;
        modelSel.appendChild(opt);
    });
    if (message) uiAiFeedback(message, 'ok');
}

// Botón "Actualizar" modelos
const btnSync = document.getElementById('btn-sync-models');
if (btnSync) {
    btnSync.addEventListener('click', function () {
        const pk = providerSelect.value;
        const keyInput = document.getElementById(pk + '_api_key');
        const apiKey = (keyInput ? keyInput.value : '').trim();
        this.disabled = true;
        uiAiFeedback('Actualizando modelos desde la API...', '');
        const body = new URLSearchParams({ action: 'sync', provider: pk, api_key: apiKey, _csrf_token: csrfVal() });
        fetch('ajax_ai_models.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    // Poblar el combo directamente con los modelos recién sincronizados
                    populateModelsFromList(pk, data.models || [], data.message);
                } else {
                    uiAiFeedback(data.message || 'Error al actualizar modelos.', 'err');
                    if (data.models && data.models.length > 0) loadCachedModels(pk);
                }
            })
            .catch(() => uiAiFeedback('Error de red al actualizar modelos.', 'err'))
            .finally(() => { this.disabled = false; });
    });
}

// Botón "Probar" el modelo seleccionado
const btnTest = document.getElementById('btn-test-model');
if (btnTest) {
    btnTest.addEventListener('click', function () {
        const pk = providerSelect.value;
        const model = document.getElementById('translation_model').value;
        const keyInput = document.getElementById(pk + '_api_key');
        const apiKey = (keyInput ? keyInput.value : '').trim();
        if (!model) {
            uiAiFeedback('Selecciona un modelo para probar.', 'err');
            return;
        }
        this.disabled = true;
        uiAiFeedback('Probando modelo ' + model + '...', '');
        const body = new URLSearchParams({ provider: pk, model: model, api_key: apiKey, _csrf_token: csrfVal() });
        fetch('ajax_ai_test.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(data => {
                uiAiFeedback(data.message || 'Resultado de la prueba.', data.status === 'success' ? 'ok' : 'err');
            })
            .catch(() => uiAiFeedback('Error de red al probar el modelo.', 'err'))
            .finally(() => { this.disabled = false; });
    });
}

// ===== Modelos de respaldo (fallback) =====
const FALLBACK_PROVIDER_LABELS = { deepseek: 'DeepSeek', gemini: 'Google Gemini', openai: 'OpenAI', mistral: 'Mistral AI' };
const fallbackModelSelect = document.getElementById('fallback_model_select');
const fallbackListEl = document.getElementById('fallback-list');
const fallbackInput = document.getElementById('translation_fallback_models');

// Mapa "provider::model" -> display_name para mostrar nombres legibles
const fallbackLabelMap = {};
if (fallbackModelSelect) {
    fallbackModelSelect.querySelectorAll('option').forEach(o => {
        if (o.value) fallbackLabelMap[o.value] = o.getAttribute('data-label') || o.textContent;
    });
}

// Lista de respaldos en memoria, inicializada desde el hidden input
let fallbackList = [];
if (fallbackInput) {
    try {
        const parsed = JSON.parse(fallbackInput.value || '[]');
        if (Array.isArray(parsed)) fallbackList = parsed;
    } catch (e) { fallbackList = []; }
}

function fallbackSyncInput() {
    if (!fallbackInput) return;
    fallbackInput.value = JSON.stringify(
        fallbackList.map(i => ({ provider: i.provider, model: i.model }))
    );
}

function fallbackRender() {
    if (!fallbackListEl) return;
    fallbackListEl.innerHTML = '';
    if (fallbackList.length === 0) {
        const li = document.createElement('li');
        li.className = 'list-group-item bg-transparent text-muted border-secondary';
        li.textContent = 'Sin modelos de respaldo configurados.';
        fallbackListEl.appendChild(li);
    } else {
        fallbackList.forEach((item, idx) => {
            const key = item.provider + '::' + item.model;
            const label = fallbackLabelMap[key] || item.model;
            const providerLabel = FALLBACK_PROVIDER_LABELS[item.provider] || item.provider;
            const li = document.createElement('li');
            li.className = 'list-group-item bg-transparent text-light border-secondary d-flex align-items-center';
            li.innerHTML =
                '<span class="badge bg-secondary me-2">' + (idx + 1) + '</span>' +
                '<span class="flex-grow-1"><strong>' + providerLabel + '</strong> — ' + label + '</span>' +
                '<button type="button" class="btn btn-sm btn-outline-light ms-1" data-act="up" title="Subir"><i class="fa fa-arrow-up"></i></button>' +
                '<button type="button" class="btn btn-sm btn-outline-light ms-1" data-act="down" title="Bajar"><i class="fa fa-arrow-down"></i></button>' +
                '<button type="button" class="btn btn-sm btn-outline-danger ms-1" data-act="remove" title="Quitar"><i class="fa fa-times"></i></button>';
            fallbackListEl.appendChild(li);
        });
    }
    fallbackSyncInput();
}

if (fallbackListEl) {
    fallbackListEl.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const li = btn.closest('li');
        const idx = Array.prototype.indexOf.call(fallbackListEl.children, li);
        if (idx < 0) return;
        const act = btn.getAttribute('data-act');
        if (act === 'up' && idx > 0) {
            [fallbackList[idx - 1], fallbackList[idx]] = [fallbackList[idx], fallbackList[idx - 1]];
        } else if (act === 'down' && idx < fallbackList.length - 1) {
            [fallbackList[idx + 1], fallbackList[idx]] = [fallbackList[idx], fallbackList[idx + 1]];
        } else if (act === 'remove') {
            fallbackList.splice(idx, 1);
        }
        fallbackRender();
    });
}

const btnAddFallback = document.getElementById('btn-add-fallback');
if (btnAddFallback && fallbackModelSelect) {
    btnAddFallback.addEventListener('click', function () {
        const val = fallbackModelSelect.value;
        if (!val) {
            uiAiFeedback('Selecciona un modelo para añadir como respaldo.', 'err');
            return;
        }
        const sep = val.indexOf('::');
        const provider = val.slice(0, sep);
        const model = val.slice(sep + 2);
        const primaryProvider = providerSelect.value;
        const primaryModel = document.getElementById('translation_model').value;
        if (provider === primaryProvider && model === primaryModel) {
            uiAiFeedback('Ese ya es el modelo principal.', 'err');
            return;
        }
        if (fallbackList.some(i => i.provider === provider && i.model === model)) {
            uiAiFeedback('Ese modelo ya está en la lista de respaldo.', 'err');
            return;
        }
        fallbackList.push({ provider: provider, model: model });
        fallbackRender();
        fallbackModelSelect.value = '';
        uiAiFeedback('Modelo de respaldo añadido.', 'ok');
    });
}

// Render inicial
fallbackRender();

// Explorador Local
const browserModal = new bootstrap.Modal(document.getElementById('browserModal'));

document.querySelectorAll('.btn-browse').forEach(btn => {
    btn.addEventListener('click', function() {
        currentTargetInput = document.getElementById(this.getAttribute('data-target'));
        let startPath = currentTargetInput.value || '/';
        loadDirectory(startPath);
        browserModal.show();
    });
});

function loadDirectory(path) {
    const list = document.getElementById('browserList');
    list.innerHTML = '<div class="p-4 text-center"><div class="spinner-border text-info"></div></div>';
    
    fetch('ajax_directory_browser.php?path=' + encodeURIComponent(path))
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('browserCurrentPath').textContent = data.currentPath;
                list.innerHTML = '';
                
                data.directories.forEach(dir => {
                    const icon = dir.isParent ? 'fa-level-up' : 'fa-folder';
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'list-group-item list-group-item-action bg-transparent text-light border-secondary';
                    a.innerHTML = `<i class="fa ${icon} text-warning me-2"></i> ${dir.name}`;
                    a.onclick = function(e) {
                        e.preventDefault();
                        loadDirectory(dir.path);
                    };
                    list.appendChild(a);
                });
                
                if (data.directories.length === 0) {
                    list.innerHTML = '<div class="p-3 text-muted text-center">Carpeta vacía</div>';
                }
            } else {
                list.innerHTML = `<div class="p-3 text-danger text-center"><i class="fa fa-exclamation-triangle"></i> ${data.message}</div>`;
            }
        })
        .catch(err => {
            list.innerHTML = '<div class="p-3 text-danger text-center">Error de red</div>';
        });
}

document.getElementById('btnSelectPath').addEventListener('click', function() {
    if (currentTargetInput) {
        currentTargetInput.value = document.getElementById('browserCurrentPath').textContent;
    }
    browserModal.hide();
});

</script>
