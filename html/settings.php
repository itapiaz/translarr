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
    } elseif (isset($_POST['form']) && $_POST['form'] === 'server') {
        // === FORMULARIO 1: Servidor de Medios + Path Mapping ===
        rateLimitRequire('settings_save', 10, 60);
        
        $mediaServerType = trim($_POST['media_server_type'] ?? 'bazarr');
        $mediaServerUrl = trim($_POST['media_server_url'] ?? '');
        $rawMediaServerApiKey = trim($_POST['media_server_api_key'] ?? '');
        
        $storedMediaServerApiKey = $currentSettings['media_server_api_key'] ?? '';
        if (isEncrypted($storedMediaServerApiKey)) {
            $decryptedStored = decryptValue($storedMediaServerApiKey);
            $mediaServerApiKeyChanged = ($rawMediaServerApiKey !== $decryptedStored);
        } else {
            $mediaServerApiKeyChanged = ($rawMediaServerApiKey !== $storedMediaServerApiKey);
        }
        $mediaServerApiKey = ($mediaServerApiKeyChanged && !empty($rawMediaServerApiKey))
            ? encryptValue($rawMediaServerApiKey)
            : $storedMediaServerApiKey;
        
        $pathMappingMoviesFrom = trim($_POST['path_mapping_movies_from'] ?? $_POST['path_mapping_movies_from_custom'] ?? '');
        $pathMappingMoviesTo = trim($_POST['path_mapping_movies_to'] ?? '');
        $pathMappingSeriesFrom = trim($_POST['path_mapping_series_from'] ?? $_POST['path_mapping_series_from_custom'] ?? '');
        $pathMappingSeriesTo = trim($_POST['path_mapping_series_to'] ?? '');

        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $pdo->beginTransaction();
            $stmt->execute(['media_server_type', $mediaServerType]);
            $stmt->execute(['media_server_url', $mediaServerUrl]);
            $stmt->execute(['media_server_api_key', $mediaServerApiKey]);
            $stmt->execute(['path_mapping_movies_from', $pathMappingMoviesFrom]);
            $stmt->execute(['path_mapping_movies_to', $pathMappingMoviesTo]);
            $stmt->execute(['path_mapping_series_from', $pathMappingSeriesFrom]);
            $stmt->execute(['path_mapping_series_to', $pathMappingSeriesTo]);
            $pdo->commit();
            $message = "Servidor de medios configurado correctamente.";
            $status = "success";
            
            // Auto-lanzar escaneo
            if ($mediaServerUrl && $mediaServerApiKey) {
                $cacheCount = $pdo->query("SELECT COUNT(*) FROM media_cache")->fetchColumn();
                
                $serverChanged = $mediaServerApiKeyChanged || $mediaServerUrl !== $currentSettings['media_server_url'] || $mediaServerType !== $currentSettings['media_server_type'];

                if ($cacheCount == 0 || $serverChanged) {
                    if ($serverChanged) {
                        $pdo->exec("DELETE FROM media_cache");
                        $pdo->exec("DELETE FROM background_tasks WHERE type='scan_media'");
                        $message .= " Caché anterior eliminada.";
                    }
                    @touch('/config/scan_trigger.now');
                    $message .= " Escaneo encolado en background.";
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error al guardar: " . $e->getMessage();
            $status = "danger";
        }
    } elseif (isset($_POST['form']) && $_POST['form'] === 'ai') {
        // === FORMULARIO 2: DeepSeek AI ===
        rateLimitRequire('settings_save', 10, 60);
        
        $rawDeepseekApiKey = trim($_POST['deepseek_api_key'] ?? '');
        $systemPrompt = trim($_POST['system_prompt'] ?? '');
        $chunkSize = trim($_POST['chunk_size'] ?? '50');
        
        $storedDeepseekApiKey = $currentSettings['deepseek_api_key'] ?? '';
        if (isEncrypted($storedDeepseekApiKey)) {
            $decryptedStored = decryptValue($storedDeepseekApiKey);
            $deepseekApiKeyChanged = ($rawDeepseekApiKey !== $decryptedStored);
        } else {
            $deepseekApiKeyChanged = ($rawDeepseekApiKey !== $storedDeepseekApiKey);
        }
        $deepseekApiKey = ($deepseekApiKeyChanged && !empty($rawDeepseekApiKey))
            ? encryptValue($rawDeepseekApiKey)
            : $storedDeepseekApiKey;
        
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $pdo->beginTransaction();
            $stmt->execute(['deepseek_api_key', $deepseekApiKey]);
            $stmt->execute(['system_prompt', $systemPrompt]);
            $stmt->execute(['chunk_size', $chunkSize]);
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

// Fallback a bazarr_url si media_server_url no existe (para instalaciones previas)
$currentMediaServerType = $currentSettings['media_server_type'] ?? 'bazarr';
$currentMediaServerUrl = $currentSettings['media_server_url'] ?? $currentSettings['bazarr_url'] ?? '';
$currentMediaServerApiKeyRaw = $currentSettings['media_server_api_key'] ?? $currentSettings['bazarr_api_key'] ?? '';
// Desencriptar para mostrar en el formulario
$currentMediaServerApiKey = (!empty($currentMediaServerApiKeyRaw) && isEncrypted($currentMediaServerApiKeyRaw))
    ? decryptValue($currentMediaServerApiKeyRaw)
    : $currentMediaServerApiKeyRaw;

$currentDeepseekApiKeyRaw = $currentSettings['deepseek_api_key'] ?? '';
$currentDeepseekApiKey = (!empty($currentDeepseekApiKeyRaw) && isEncrypted($currentDeepseekApiKeyRaw))
    ? decryptValue($currentDeepseekApiKeyRaw)
    : $currentDeepseekApiKeyRaw;
$currentSystemPrompt = $currentSettings['system_prompt'] ?? '';
$currentChunkSize = $currentSettings['chunk_size'] ?? '50';
$currentPathMappingMoviesFrom = $currentSettings['path_mapping_movies_from'] ?? '';
$currentPathMappingMoviesTo = $currentSettings['path_mapping_movies_to'] ?? '';
$currentPathMappingSeriesFrom = $currentSettings['path_mapping_series_from'] ?? '';
$currentPathMappingSeriesTo = $currentSettings['path_mapping_series_to'] ?? '';
$currentAutoScan = $currentSettings['auto_scan_enabled'] ?? '1';
$currentScanInterval = $currentSettings['scan_interval_minutes'] ?? '60';
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
            <button class="nav-link active text-start mb-2" id="tab-server" data-bs-toggle="pill" data-bs-target="#pane-server" type="button" role="tab" style="color: #fff;"><i class="fa fa-server me-2 text-info"></i> Servidor de Medios</button>
            <button class="nav-link text-start mb-2" id="tab-ai" data-bs-toggle="pill" data-bs-target="#pane-ai" type="button" role="tab" style="color: #fff;"><i class="fa fa-bolt me-2 text-warning"></i> DeepSeek AI</button>
            <button class="nav-link text-start mb-2" id="tab-tasks" data-bs-toggle="pill" data-bs-target="#pane-tasks" type="button" role="tab" style="color: #fff;"><i class="fa fa-clock-o me-2 text-success"></i> Tareas Programadas</button>
            <button class="nav-link text-start mb-4" id="tab-security" data-bs-toggle="pill" data-bs-target="#pane-security" type="button" role="tab" style="color: #fff;"><i class="fa fa-lock me-2 text-danger"></i> Seguridad</button>
            <a href="logs.php" class="nav-link text-start border border-info text-info mt-2" style="background: rgba(0, 242, 254, 0.05);"><i class="fa fa-terminal me-2"></i> Logs del Sistema</a>
        </div>
    </div>

    <!-- Contenido -->
    <div class="col-md-9">
        
        <div class="tab-content" id="settings-tabContent">
                
                <!-- PANE 1: SERVIDOR DE MEDIOS -->
                <div class="tab-pane fade show active" id="pane-server" role="tabpanel">
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="form" value="server">
                        <?= csrf_field() ?>
                    <div class="card glass-card mb-4">
                        <div class="card-body p-4">
                            <h4 class="mb-4 text-info"><i class="fa fa-server"></i> Conexión con Servidor de Medios</h4>
                            
                            <div class="mb-3">
                                <label for="media_server_type" class="form-label">Motor de Medios</label>
                                <select class="form-select" id="media_server_type" name="media_server_type" onchange="updateServerLabels()">
                                    <option value="bazarr" <?= $currentMediaServerType === 'bazarr' ? 'selected' : '' ?>>Bazarr (Recomendado)</option>
                                    <option value="emby" <?= $currentMediaServerType === 'emby' ? 'selected' : '' ?>>Emby</option>
                                    <option value="jellyfin" <?= $currentMediaServerType === 'jellyfin' ? 'selected' : '' ?>>Jellyfin</option>
                                </select>
                                <div class="form-text text-muted">Selecciona la aplicación de la cual leeremos el catálogo de películas y series.</div>
                            </div>

                            <div class="mb-3">
                                <label id="lbl_media_server_url" for="media_server_url" class="form-label">URL del Servidor</label>
                                <input type="url" class="form-control" id="media_server_url" name="media_server_url"
                                       value="<?= htmlspecialchars($currentMediaServerUrl) ?>" 
                                       placeholder="Ej: http://192.168.1.100:6767" required>
                                <div class="form-text text-muted">Asegúrate de incluir http:// o https:// y el puerto.</div>
                            </div>

                            <div class="mb-4">
                                <label id="lbl_media_server_api_key" for="media_server_api_key" class="form-label">API Key / Token</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="media_server_api_key" name="media_server_api_key"
                                           value="<?= htmlspecialchars($currentMediaServerApiKey) ?>" 
                                           placeholder="Pega aquí la API Key o Token" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="media_server_api_key">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div id="path_mapping_section">
                                <hr style="border-color: rgba(255,255,255,0.1);" class="my-4">
                                <h4 class="mb-4 text-info"><i class="fa fa-folder-open"></i> Mapeo de Rutas (Path Mapping)</h4>
                                <p class="text-muted small mb-4">Si tu servidor de medios (Emby/Jellyfin) reporta las rutas de manera diferente a como están montadas en Translarr, configúralo aquí.</p>
                                
                                <h5 class="text-secondary"><i class="fa fa-film"></i> Películas</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="path_mapping_movies_from" class="form-label">Ruta Remota (En Emby/Jellyfin)</label>
                                        <select class="form-select remote-path-select" id="path_mapping_movies_from" name="path_mapping_movies_from" data-current="<?= htmlspecialchars($currentPathMappingMoviesFrom) ?>">
                                            <option value="">Selecciona o escribe una ruta...</option>
                                            <?php if ($currentPathMappingMoviesFrom): ?>
                                                <option value="<?= htmlspecialchars($currentPathMappingMoviesFrom) ?>" selected><?= htmlspecialchars($currentPathMappingMoviesFrom) ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <input type="text" class="form-control mt-2 remote-path-custom d-none" name="path_mapping_movies_from_custom" placeholder="O escribe la ruta manualmente">
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <label for="path_mapping_movies_to" class="form-label">Ruta Local (En Translarr)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="path_mapping_movies_to" name="path_mapping_movies_to"
                                                   value="<?= htmlspecialchars($currentPathMappingMoviesTo) ?>" 
                                                   placeholder="Ej: /home/media/movies">
                                            <button class="btn btn-outline-info btn-browse" type="button" data-target="path_mapping_movies_to"><i class="fa fa-folder-open"></i> Explorar...</button>
                                        </div>
                                    </div>
                                </div>

                                <h5 class="text-secondary"><i class="fa fa-tv"></i> Series</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="path_mapping_series_from" class="form-label">Ruta Remota (En Emby/Jellyfin)</label>
                                        <select class="form-select remote-path-select" id="path_mapping_series_from" name="path_mapping_series_from" data-current="<?= htmlspecialchars($currentPathMappingSeriesFrom) ?>">
                                            <option value="">Selecciona o escribe una ruta...</option>
                                            <?php if ($currentPathMappingSeriesFrom): ?>
                                                <option value="<?= htmlspecialchars($currentPathMappingSeriesFrom) ?>" selected><?= htmlspecialchars($currentPathMappingSeriesFrom) ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <input type="text" class="form-control mt-2 remote-path-custom d-none" name="path_mapping_series_from_custom" placeholder="O escribe la ruta manualmente">
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <label for="path_mapping_series_to" class="form-label">Ruta Local (En Translarr)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="path_mapping_series_to" name="path_mapping_series_to"
                                                   value="<?= htmlspecialchars($currentPathMappingSeriesTo) ?>" 
                                                   placeholder="Ej: /home/media/tvshows">
                                            <button class="btn btn-outline-info btn-browse" type="button" data-target="path_mapping_series_to"><i class="fa fa-folder-open"></i> Explorar...</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-end mb-4">
                        <button type="submit" class="btn btn-gradient btn-lg w-100 shadow"><i class="fa fa-save me-2"></i> Guardar Servidor de Medios</button>
                    </div>
                    </form>
                </div>

                <!-- PANE 2: DEEPSEEK AI -->
                <div class="tab-pane fade" id="pane-ai" role="tabpanel">
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="form" value="ai">
                        <?= csrf_field() ?>
                    <div class="card glass-card mb-4">
                        <div class="card-body p-4">
                            <h4 class="mb-4 text-warning"><i class="fa fa-bolt"></i> Conexión con DeepSeek AI</h4>

                            <div class="mb-4">
                                <label for="deepseek_api_key" class="form-label">API Key de DeepSeek</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="deepseek_api_key" name="deepseek_api_key"
                                           value="<?= htmlspecialchars($currentDeepseekApiKey) ?>" 
                                           placeholder="sk-...">
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="deepseek_api_key"><i class="fa fa-eye"></i></button>
                                </div>
                                <div class="form-text text-muted">La API Key de tu cuenta en DeepSeek para procesar las traducciones.</div>
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

                        </div>
                    </div>
                    <div class="text-end mb-4">
                        <button type="submit" class="btn btn-gradient btn-lg w-100 shadow"><i class="fa fa-save me-2"></i> Guardar DeepSeek AI</button>
                    </div>
                    </form>
                </div>

            <!-- PANE 3: TAREAS PROGRAMADAS -->
            <div class="tab-pane fade" id="pane-tasks" role="tabpanel">
                <div class="card glass-card mb-4">
                    <div class="card-body p-4">
                        <h4 class="mb-4 text-success"><i class="fa fa-clock-o"></i> Tareas Programadas</h4>
                        
                        <div class="alert alert-secondary mb-4 border-secondary d-flex align-items-center">
                            <i class="fa fa-info-circle fa-2x me-3 text-info"></i>
                            <div>
                                <strong>Escaneo automático de medios: <span class="badge bg-success ms-1">Activo</span></strong><br>
                                <small class="text-muted">El escaneo se ejecuta cada 60 minutos en background. Detecta nuevos subtítulos, aplica heurística de idioma y renueva la caché de medios.</small>
                            </div>
                            <button class="btn btn-gradient ms-auto" id="btnManualScan" onclick="manualScanFromSettings()">
                                <i class="fa fa-refresh me-1"></i>Ejecutar ahora
                            </button>
                        </div>

                        <h6 class="text-secondary mb-3"><i class="fa fa-history me-1"></i>Últimas ejecuciones</h6>
                        <div id="scanHistory" class="border border-secondary rounded p-0 overflow-hidden">
                            <div class="text-center text-muted py-4">
                                <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                                <p class="mt-2 small">Cargando historial...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PANE 4: SEGURIDAD -->
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

function updateServerLabels() {
    const type = document.getElementById('media_server_type').value;
    const lblUrl = document.getElementById('lbl_media_server_url');
    const lblApi = document.getElementById('lbl_media_server_api_key');
    const pathSection = document.getElementById('path_mapping_section');
    
    if (type === 'bazarr') {
        lblUrl.textContent = 'URL de Bazarr';
        lblApi.textContent = 'API Key de Bazarr';
        pathSection.style.display = 'none';
    } else if (type === 'emby') {
        lblUrl.textContent = 'URL de Emby';
        lblApi.textContent = 'API Key (Token) de Emby';
        pathSection.style.display = 'block';
    } else if (type === 'jellyfin') {
        lblUrl.textContent = 'URL de Jellyfin';
        lblApi.textContent = 'API Key (Token) de Jellyfin';
        pathSection.style.display = 'block';
    }
}

// Inicializar etiquetas
updateServerLabels();

// Autodescubrimiento de Rutas Remotas
function fetchRemotePaths() {
    const selects = document.querySelectorAll('.remote-path-select');
    const type = document.getElementById('media_server_type').value;
    const url = document.getElementById('media_server_url').value;
    const apiKey = document.getElementById('media_server_api_key').value;

    // Si falta URL o API Key, mostrar mensaje y salir
    if (!url || !apiKey) {
        selects.forEach(select => {
            select.innerHTML = '<option value="">Completa URL y API Key primero...</option>';
            select.value = '';
            select.removeAttribute('disabled');
            const customInput = select.nextElementSibling;
            if (customInput && customInput.classList) {
                customInput.classList.add('d-none');
                customInput.setAttribute('disabled', 'disabled');
            }
        });
        return;
    }

    selects.forEach(select => {
        select.innerHTML = '<option value="">Cargando rutas de Emby/Jellyfin...</option>';
        const customInput = select.nextElementSibling;
        if (customInput && customInput.classList) {
            customInput.classList.add('d-none');
            customInput.setAttribute('disabled', 'disabled');
        }
        select.removeAttribute('disabled');
    });

    const query = new URLSearchParams({ type, url, api_key: apiKey }).toString();

    fetch('ajax_remote_paths.php?' + query)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success' && data.paths && data.paths.length > 0) {
                selects.forEach(select => {
                    const currentValue = select.getAttribute('data-current');
                    let optionsHTML = '<option value="">Selecciona una ruta remota...</option>';
                    let valueFound = false;
                    
                    data.paths.forEach(path => {
                        const selected = (path === currentValue) ? 'selected' : '';
                        if (path === currentValue) valueFound = true;
                        optionsHTML += `<option value="${path}" ${selected}>${path}</option>`;
                    });
                    
                    optionsHTML += '<option value="custom">-- Otra (Escribir manualmente) --</option>';
                    select.innerHTML = optionsHTML;
                    select.removeAttribute('disabled');
                    
                    const customInput = select.nextElementSibling;
                    if (customInput && customInput.classList) {
                        customInput.classList.add('d-none');
                        customInput.setAttribute('disabled', 'disabled');
                    }
                    
                    /*if (currentValue && !valueFound) {
                        select.value = 'custom';
                        if (customInput && customInput.classList) {
                            customInput.classList.remove('d-none');
                            customInput.removeAttribute('disabled');
                            customInput.value = currentValue;
                        }
                        select.setAttribute('disabled', 'disabled');
                    }*/
                });
            } else {
                // Si no hay rutas, mostrar opción para escribir manualmente
                selects.forEach(select => {
                    select.innerHTML = '<option value="custom">-- Escribir manualmente --</option>';
                    select.value = 'custom';
                    const customInput = select.nextElementSibling;
                    if (customInput && customInput.classList) {
                        customInput.classList.remove('d-none');
                        customInput.value = select.getAttribute('data-current') || '';
                        customInput.removeAttribute('disabled');
                        customInput.placeholder = 'Ej: /media/movies';
                    }
                    select.setAttribute('disabled', 'disabled');
                });
            }
        })
        .catch(err => {
            console.error('Error fetching remote paths', err);
            selects.forEach(select => {
                select.innerHTML = '<option value="custom">Error de conexión</option>';
                select.value = 'custom';
                const customInput = select.nextElementSibling;
                if (customInput && customInput.classList) {
                    customInput.classList.remove('d-none');
                    customInput.value = select.getAttribute('data-current') || '';
                    customInput.removeAttribute('disabled');
                }
                select.setAttribute('disabled', 'disabled');
            });
        });
}

// Lógica de select vs custom input
document.querySelectorAll('.remote-path-select').forEach(select => {
    select.addEventListener('change', function() {
        const customInput = this.nextElementSibling;

        if (this.value === 'custom') {
            // Mostrar input custom, deshabilitar el select para que no envíe su valor
            customInput.classList.remove('d-none');
            customInput.removeAttribute('disabled');
            this.setAttribute('disabled', 'disabled');
        } else {
            // Ocultar input custom, habilitar el select
            customInput.classList.add('d-none');
            customInput.setAttribute('disabled', 'disabled');
            this.removeAttribute('disabled');
        }
    });
});

// Cargar rutas iniciales solo si hay URL y API Key
if (document.getElementById('media_server_type').value !== 'bazarr') {
    const initialUrl = document.getElementById('media_server_url').value;
    const initialKey = document.getElementById('media_server_api_key').value;
    if (initialUrl && initialKey) {
        fetchRemotePaths();
    } else {
        // Mostrar mensaje en los selects
        document.querySelectorAll('.remote-path-select').forEach(select => {
            select.innerHTML = '<option value="">Completa URL y API Key para cargar rutas...</option>';
        });
    }
}

// Al cambiar el servidor, ocultar/mostrar y actualizar
document.getElementById('media_server_type').addEventListener('change', function() {
    updateServerLabels();
    if (this.value !== 'bazarr') {
        fetchRemotePaths();
    }
});

// Al cambiar la URL o API Key, si no estamos en bazarr, volver a buscar rutas
document.getElementById('media_server_url').addEventListener('blur', function() {
    if (document.getElementById('media_server_type').value !== 'bazarr') fetchRemotePaths();
});
document.getElementById('media_server_api_key').addEventListener('blur', function() {
    if (document.getElementById('media_server_type').value !== 'bazarr') fetchRemotePaths();
});

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

// ===== HISTORIAL DE ESCANEOS =====
async function loadScanHistory() {
    try {
        const res = await fetch('ajax_tasks.php?action=history');
        const data = await res.json();
        const el = document.getElementById('scanHistory');
        if (!data.tasks || data.tasks.length === 0) {
            el.innerHTML = '<p class="text-muted text-center small mb-0">No hay ejecuciones recientes.</p>';
            return;
        }
        let html = '<table class="table table-dark table-sm mb-0" style="font-size:0.82rem">';
        html += '<thead><tr><th>Tipo</th><th>Estado</th><th>Resultado</th><th>Inicio</th><th>Duración</th></tr></thead><tbody>';
        data.tasks.forEach(t => {
            const statusMap = {pending:'<span class="badge bg-secondary">Pendiente</span>',running:'<span class="badge bg-info">Ejecutando</span>',done:'<span class="badge bg-success">OK</span>',error:'<span class="badge bg-danger">Error</span>'};
            const duration = (t.started_at && t.finished_at)
                ? Math.round((new Date(t.finished_at + ' UTC') - new Date(t.started_at + ' UTC')) / 1000) + 's'
                : '-';
            const start = t.started_at ? new Date(t.started_at + ' UTC').toLocaleString('es') : '-';
            const typeMap = {scan_media: 'Escaneo', translate: 'Traducción', rename_subtitle: 'Renombrado'};
            html += `<tr>
                <td>${typeMap[t.type] || t.type}</td>
                <td>${statusMap[t.status] || t.status}</td>
                <td class="text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${t.result || '-'}</td>
                <td>${start}</td>
                <td>${duration}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    } catch(e) {
        document.getElementById('scanHistory').innerHTML = '<p class="text-danger small mb-0">Error al cargar historial.</p>';
    }
}

async function manualScanFromSettings() {
    const btn = document.getElementById('btnManualScan');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div>Iniciando...';
    try {
        const res = await fetch('ajax_tasks.php?action=trigger');
        const data = await res.json();
        if (data.success) {
            setTimeout(() => loadScanHistory(), 2000);
        } else {
            alert(data.message || 'Error al iniciar el escaneo.');
        }
    } catch(e) {
        alert('Error de conexión.');
    }
    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-refresh me-1"></i>Ejecutar ahora';
    }, 2500);
}

// Cargar historial al cargar la página
loadScanHistory();

</script>
