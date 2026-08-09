<?php
// html/includes/ArrClient.php
// Cliente HTTP base para las APIs de Sonarr y Radarr (v3).

abstract class ArrClient {
    protected $url;
    protected $apiKey;

    public function __construct($url, $apiKey) {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Nombre de recurso para mensajes: 'sonarr' | 'radarr'
     */
    abstract protected function resourceName(): string;

    /**
     * Petición HTTP a la API. Valida el status HTTP antes de decodificar JSON.
     */
    protected function request($endpoint, $method = 'GET', $data = null) {
        $url = $this->url . '/api/v3' . $endpoint;

        $headers = [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $label = ucfirst($this->resourceName());

        if ($curlError) {
            throw new Exception("{$label}: error de conexión ({$curlError})");
        }

        // Respuestas sin cuerpo (204, etc.)
        if ($response === '' || $response === false) {
            if ($httpCode >= 400) {
                throw new Exception("{$label}: HTTP {$httpCode} sin respuesta");
            }
            return [];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("{$label}: respuesta no JSON (HTTP {$httpCode}). " . substr(strip_tags($response), 0, 200));
        }

        if ($httpCode >= 400) {
            $msg = $decoded['message']
                ?? $decoded[0]['errorMessage']
                ?? $decoded['error']
                ?? "{$label}: HTTP {$httpCode}";
            throw new Exception($msg . ' - URL: ' . $url);
        }

        return $decoded;
    }

    /**
     * Prueba de conexión y autenticación contra el sistema.
     */
    public function testConnection(): array {
        $resp = $this->request('/system/status');
        return [
            'ok' => true,
            'version' => $resp['version'] ?? 'unknown',
            'message' => ucfirst($this->resourceName()) . ' ' . ($resp['version'] ?? '') . ' conectado correctamente.',
        ];
    }

    /**
     * Carpetas raíz configuradas en el servicio (para sugerir rutas).
     */
    public function getRootFolders(): array {
        $resp = $this->request('/rootfolder');
        $paths = [];
        foreach ($resp as $f) {
            if (!empty($f['path'])) {
                $paths[] = $f['path'];
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * Extrae la URL de una imagen del listado de imágenes de Sonarr/Radarr.
     */
    protected function extractImage($images, $coverType): string {
        foreach ($images ?? [] as $img) {
            if (($img['coverType'] ?? '') === $coverType && !empty($img['url'])) {
                return $img['url'];
            }
        }
        return '';
    }
}
