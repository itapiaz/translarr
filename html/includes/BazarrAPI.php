<?php
// html/includes/BazarrAPI.php

require_once 'MediaServerInterface.php';

class BazarrAPI implements MediaServerInterface {
    private $url;
    private $apiKey;

    public function __construct($url, $apiKey) {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
    }

    private function request($endpoint, $method = 'GET', $data = null) {
        $ch = curl_init();
        
        // Limpiar URL base en caso de que el usuario haya incluido /api al final
        $baseUrl = rtrim($this->url, '/');
        if (substr($baseUrl, -4) === '/api') {
            $baseUrl = substr($baseUrl, 0, -4);
        }
        
        // Soportar params con mayúsculas y minúsculas por si acaso (Bazarr suele usar minúsculas)
        $url = $baseUrl . '/api' . $endpoint;
        
        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Para evitar problemas locales con certificados auto-firmados si los hay
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Error en cURL: " . $error);
        }

        curl_close($ch);

        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = isset($decodedResponse['error']) ? $decodedResponse['error'] : 'Error en la solicitud a Bazarr (HTTP ' . $httpCode . ')';
            throw new Exception($errorMsg . " - URL solicitada: " . $url);
        }

        return $decodedResponse;
    }

    public function getMovies() {
        $response = $this->request('/movies');
        $data = isset($response['data']) ? $response['data'] : $response;
        $movies = [];
        foreach ($data as $m) {
            $poster = isset($m['poster']) && $m['poster'] ? $m['poster'] : '';
            if ($poster && strpos($poster, '/') === 0) {
                $poster = rtrim($this->url, '/') . $poster;
            }
            
            $hasSpanish = false;
            foreach ($m['subtitles'] ?? [] as $sub) {
                $langCode = strtolower($sub['code2'] ?? $sub['code'] ?? $sub['name'] ?? $sub['language'] ?? '');
                if ($langCode === 'es' || strpos($langCode, 'spanish') !== false || $langCode === 'spa') {
                    $hasSpanish = true;
                    break;
                }
            }

            $movies[] = [
                'id' => $m['radarrId'] ?? $m['id'] ?? 0,
                'title' => $m['title'] ?? 'Sin título',
                'year' => $m['year'] ?? '',
                'overview' => $m['overview'] ?? '',
                'folder_path' => isset($m['path']) ? dirname($m['path']) : '',
                'poster' => $poster,
                'has_spanish' => $hasSpanish
            ];
        }
        return $movies;
    }

    public function getSeries() {
        $response = $this->request('/series');
        $data = isset($response['data']) ? $response['data'] : $response;
        $series = [];
        foreach ($data as $s) {
            $poster = isset($s['poster']) && $s['poster'] ? $s['poster'] : '';
            if ($poster && strpos($poster, '/') === 0) {
                $poster = rtrim($this->url, '/') . $poster;
            }
            $series[] = [
                'id' => $s['sonarrSeriesId'] ?? $s['sonarrId'] ?? $s['id'] ?? 0,
                'title' => $s['title'] ?? 'Sin título',
                'year' => $s['year'] ?? '',
                'overview' => $s['overview'] ?? '',
                'folder_path' => $s['path'] ?? '',
                'poster' => $poster
            ];
        }
        return $series;
    }

    public function getEpisodes($seriesId) {
        $response = $this->request('/episodes?seriesid[]=' . urlencode($seriesId));
        $data = isset($response['data']) ? $response['data'] : $response;
        $episodes = [];
        foreach ($data as $e) {
            $hasSpanish = false;
            foreach ($e['subtitles'] ?? [] as $sub) {
                $langCode = strtolower($sub['code2'] ?? $sub['code'] ?? $sub['name'] ?? $sub['language'] ?? '');
                if ($langCode === 'es' || strpos($langCode, 'spanish') !== false || $langCode === 'spa') {
                    $hasSpanish = true;
                    break;
                }
            }

            $episodes[] = [
                'id' => $e['sonarrEpisodeId'] ?? $e['episodeid'] ?? $e['id'] ?? 0,
                'title' => $e['title'] ?? 'Sin título',
                'season' => $e['seasonNumber'] ?? $e['season'] ?? '',
                'episode' => $e['episodeNumber'] ?? $e['episode'] ?? '',
                'has_spanish' => $hasSpanish
            ];
        }
        return $episodes;
    }

    // Retorna los subtítulos para una película o episodio
    // $type = 'movies' o 'episodes'
    // Retorna los subtítulos para una película o episodio
    public function getSubtitles($type, $id) {
        if ($type === 'movies') {
            $response = $this->request('/movies?radarrid[]=' . urlencode($id));
            $data = isset($response['data']) ? $response['data'] : $response;
            if (!empty($data) && isset($data[0]['subtitles'])) {
                return $data[0]['subtitles'];
            }
            return [];
        } else {
            // Para episodios
            $response = $this->request('/episodes?episodeid[]=' . urlencode($id));
            $data = isset($response['data']) ? $response['data'] : $response;
            if (!empty($data) && isset($data[0]['subtitles'])) {
                return $data[0]['subtitles'];
            }
            return [];
        }
    }

    // Obtiene el contenido del subtítulo leyendo el archivo directamente del disco
    public function downloadSubtitle($type, $action, $path) {
        if (empty($path)) {
            throw new Exception("Ruta de subtítulo no proporcionada.");
        }
        
        if (!file_exists($path)) {
            throw new Exception("El archivo de subtítulo no se encuentra en la ruta: " . htmlspecialchars($path) . ". Asegúrate de que los volúmenes de medios estén correctamente mapeados en el contenedor Docker.");
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            throw new Exception("No se pudo leer el archivo de subtítulo en la ruta: " . htmlspecialchars($path) . ". Revisa los permisos de lectura.");
        }
        
        return $content;
    }

    public function refreshItem($id) {
        // Bazarr refresca usando su propia lógica o lo detecta.
        // También podemos hacer un POST a su API de sync si fuera necesario.
        return true;
    }

    public function supportsDirectWrite() {
        return false; // Bazarr usa su propia API de subida en el frontend (ajax_translate)
    }

    public function getRemotePaths() {
        return []; // Bazarr no requiere mapeo de rutas para nuestro flujo
    }
}
?>
