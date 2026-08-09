<?php
// html/includes/EmbyJellyfinAPI.php

require_once 'MediaServerInterface.php';

class EmbyJellyfinAPI implements MediaServerInterface {
    private $url;
    private $apiKey;
    private $type; // 'emby' o 'jellyfin'

    public function __construct($url, $apiKey, $type) {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->type = $type;
    }

    private function request($endpoint, $method = 'GET', $data = null) {
        $ch = curl_init();
        
        $url = $this->url . $endpoint;
        
        $headers = [
            'X-Emby-Token: ' . $this->apiKey,
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

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        //file_put_contents('/config/api_debug.log', "URL: $url | Response: $response\n", FILE_APPEND);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Error en cURL (Emby/Jellyfin): " . $error);
        }

        curl_close($ch);

        $response = trim($response);// Eliminar espacios en blanco para evitar problemas con respuestas vacías o con solo espacios
        $decodedResponse = json_decode($response, true);

        // Si la respuesta no es un JSON válido, lanzar excepción con el error específico
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error al decodificar la respuesta JSON de Emby/Jellyfin: " . json_last_error_msg());
        }        
        
        if ($httpCode >= 400) {
            $errorMsg = 'Error en la solicitud a Emby/Jellyfin (HTTP ' . $httpCode . ')';
            throw new Exception($errorMsg . " - URL solicitada: " . $url);
        }

        return $decodedResponse;
    }

    private function translatePath($path, $type) {
        $from = $type === 'movies' ? (defined('PATH_MAPPING_MOVIES_FROM') ? PATH_MAPPING_MOVIES_FROM : '') : (defined('PATH_MAPPING_SERIES_FROM') ? PATH_MAPPING_SERIES_FROM : '');
        $to = $type === 'movies' ? (defined('PATH_MAPPING_MOVIES_TO') ? PATH_MAPPING_MOVIES_TO : '') : (defined('PATH_MAPPING_SERIES_TO') ? PATH_MAPPING_SERIES_TO : '');

        if ($from !== '' && $to !== '') {
            if (strpos($path, $from) === 0) {
                return $to . substr($path, strlen($from));
            }
        }
        return $path;
    }

    private function safeRename($old, $new) {
        if (@rename($old, $new)) {
            // En recursos compartidos SMB/CIFS, a veces rename() devuelve true
            // pero el archivo no se renombra si hay un bloqueo oplock fantasma.
            clearstatcache(true, $new);
            if (file_exists($new)) return true;
        }
        
        // Plan B: Copia manual a nivel de bytes
        $content = @file_get_contents($old);
        if ($content !== false) {
            $bytes = @file_put_contents($new, $content);
            if ($bytes !== false) {
                @unlink($old);
                return true;
            }
        }
        return false;
    }

    private function isSpanishSubtitleContent($filePath) {
        $content = @file_get_contents($filePath, false, null, 0, 8192); // Leer primeros 8KB
        if (!$content) return false;
        
        // Asegurar que el contenido sea UTF-8 (vital para archivos viejos .avi/.srt en ISO-8859-1)
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if (!$encoding) $encoding = 'ISO-8859-1';
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        
        // Quitar etiquetas HTML (comunes en subs como <i>)
        $content = strip_tags($content);
        
        // Convertir a minúsculas
        $contentLower = mb_strtolower($content, 'UTF-8');
        
        // Quitar acentos para simplificar la búsqueda (qué -> que)
        $unwanted_array = array('á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n');
        $contentLower = strtr($contentLower, $unwanted_array);
        
        // Reemplazar todo lo que no sea una letra por un espacio (elimina \n, \r, comas, puntos, etc)
        $contentClean = preg_replace('/[^\p{L}]+/u', ' ', $contentLower);
        if ($contentClean === null) {
            // Failsafe si preg_replace /u falla
            $contentClean = preg_replace('/[^a-z]+/', ' ', $contentLower);
        }
        
        // Añadir espacios al inicio y final para que coincidan las palabras completas
        $contentClean = ' ' . $contentClean . ' ';
        
        // Palabras muy comunes exclusivas o muy frecuentes en español
        $esWords = [' que ', ' de ', ' la ', ' el ', ' en ', ' y ', ' a ', ' los ', ' se ', ' del ', ' las ', ' por ', ' un ', ' con ', ' no '];
        // Palabras muy comunes en inglés
        $enWords = [' the ', ' be ', ' to ', ' of ', ' and ', ' a ', ' in ', ' that ', ' have ', ' i ', ' it ', ' for ', ' not ', ' with ', ' you '];
        
        $esCount = 0;
        $enCount = 0;
        
        foreach ($esWords as $w) $esCount += substr_count($contentClean, $w);
        foreach ($enWords as $w) $enCount += substr_count($contentClean, $w);
        
        // Si hay significativamente más palabras en español
        return ($esCount > $enCount && $esCount > 3);
    }

    public function getMovies() {
        $response = $this->request('/Items?IncludeItemTypes=Movie&Recursive=true&Fields=Path,PremiereDate,ProductionYear,Overview');
        $items = isset($response['Items']) ? $response['Items'] : [];
        $movies = [];
        foreach ($items as $m) {
            $year = '';
            if (isset($m['PremiereDate'])) {
                $year = substr($m['PremiereDate'], 0, 4);
            } elseif (isset($m['ProductionYear'])) {
                $year = $m['ProductionYear'];
            }
            
            $hasSpanish = false;
            if (!empty($m['Path'])) {
                $localPath = $this->translatePath($m['Path'], 'movies');
                $dir = dirname($localPath);
                
                // Buscar subtítulos específicos para ESTE archivo (ignorando otros en la misma carpeta)
                $baseNameNoExt = pathinfo($localPath, PATHINFO_FILENAME);
                $allSrt = [];
                if (is_dir($dir)) {
                    $files = @scandir($dir);
                    if ($files !== false) {
                        foreach ($files as $f) {
                            if (strpos($f, $baseNameNoExt) === 0 && preg_match('/\.srt$/i', $f)) {
                                $allSrt[] = $dir . DIRECTORY_SEPARATOR . $f;
                            }
                        }
                    }
                }
                
                if (!empty($allSrt)) {
                    foreach ($allSrt as $srt) {
                        $lower = strtolower(basename($srt));
                        if (strpos($lower, '.es.') !== false || strpos($lower, '.spa.') !== false || strpos($lower, 'spanish') !== false) {
                            $hasSpanish = true;
                            break;
                        } else {
                            // Si el archivo no tiene código de idioma (ej. Pelicula.srt)
                            $parts = explode('.', basename($srt));
                            if (count($parts) == 2 || (count($parts) >= 3 && strlen($parts[count($parts)-2]) > 3 && strpos($lower, 'forced') === false)) {
                                if ($this->isSpanishSubtitleContent($srt)) {
                                    $hasSpanish = true;
                                    // Optimización de rendimiento: Renombrar archivo añadiendo .es
                                    $pInfo = pathinfo($srt);
                                    $newName = $pInfo['dirname'] . DIRECTORY_SEPARATOR . $pInfo['filename'] . '.es.' . $pInfo['extension'];
                                    $this->safeRename($srt, $newName);
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            
            $movies[] = [
                'id' => $m['Id'],
                'title' => $m['Name'] ?? 'Sin título',
                'year' => $year,
                'overview' => $m['Overview'] ?? '',
                'folder_path' => isset($m['Path']) ? dirname($m['Path']) : '',
                'poster' => $this->url . '/Items/' . $m['Id'] . '/Images/Primary',
                'has_spanish' => $hasSpanish
            ];
        }
        return $movies;
    }

    public function getSeries() {
        $response = $this->request('/Items?IncludeItemTypes=Series&Recursive=true&Fields=Path,PremiereDate,ProductionYear,Overview');
        $items = isset($response['Items']) ? $response['Items'] : [];
        $series = [];
        foreach ($items as $s) {
            $year = '';
            if (isset($s['PremiereDate'])) {
                $year = substr($s['PremiereDate'], 0, 4);
            } elseif (isset($s['ProductionYear'])) {
                $year = $s['ProductionYear'];
            }
            
            $series[] = [
                'id' => $s['Id'],
                'title' => $s['Name'] ?? 'Sin título',
                'year' => $year,
                'overview' => $s['Overview'] ?? '',
                'folder_path' => $s['Path'] ?? '',
                'poster' => $this->url . '/Items/' . $s['Id'] . '/Images/Primary'
            ];
        }
        return $series;
    }

    public function getEpisodes($seriesId) {
        $response = $this->request('/Shows/' . $seriesId . '/Episodes?Fields=Path');
        $items = isset($response['Items']) ? $response['Items'] : [];
        $episodes = [];
        foreach ($items as $e) {
            $hasSpanish = false;
            if (!empty($e['Path'])) {
                $localPath = $this->translatePath($e['Path'], 'series');
                $dir = dirname($localPath);
                
                // Buscar subtítulos específicos para ESTE episodio
                $baseNameNoExt = pathinfo($localPath, PATHINFO_FILENAME);
                $allSrt = [];
                if (is_dir($dir)) {
                    $files = @scandir($dir);
                    if ($files !== false) {
                        foreach ($files as $f) {
                            if (strpos($f, $baseNameNoExt) === 0 && preg_match('/\.srt$/i', $f)) {
                                $allSrt[] = $dir . DIRECTORY_SEPARATOR . $f;
                            }
                        }
                    }
                }

                if (!empty($allSrt)) {
                    foreach ($allSrt as $srt) {
                        $lower = strtolower(basename($srt));
                        if (strpos($lower, '.es.') !== false || strpos($lower, '.spa.') !== false || strpos($lower, 'spanish') !== false) {
                            $hasSpanish = true;
                            break;
                        } else {
                            $parts = explode('.', basename($srt));
                            if (count($parts) == 2 || (count($parts) >= 3 && strlen($parts[count($parts)-2]) > 3 && strpos($lower, 'forced') === false)) {
                                if ($this->isSpanishSubtitleContent($srt)) {
                                    $hasSpanish = true;
                                    // Optimización de rendimiento: Renombrar archivo añadiendo .es
                                    $pInfo = pathinfo($srt);
                                    $newName = $pInfo['dirname'] . DIRECTORY_SEPARATOR . $pInfo['filename'] . '.es.' . $pInfo['extension'];
                                    $this->safeRename($srt, $newName);
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            $episodes[] = [
                'id' => $e['Id'],
                'title' => $e['Name'] ?? 'Sin título',
                'season' => $e['ParentIndexNumber'] ?? '',
                'episode' => $e['IndexNumber'] ?? '',
                'has_spanish' => $hasSpanish
            ];
        }
        return $episodes;
    }

    public function getSubtitles($type, $id) {
        // Para Emby/Jellyfin, lo más confiable para subtítulos externos es obtener la ruta física del video
        // y buscar archivos .srt en el mismo directorio.
        $response = $this->request('/Items?Ids=' . $id . '&Fields=Path');
        $items = isset($response['Items']) ? $response['Items'] : [];
        if (empty($items)) return [];
        
        $item = $items[0];
        if (empty($item['Path'])) return [];
        
        $localPath = $this->translatePath($item['Path'], $type);
        $dir = dirname($localPath);
        $filename = pathinfo($localPath, PATHINFO_FILENAME);
        
        $subtitles = [];
        
        // Buscar subtítulos SRT externos en la misma carpeta
        $files = [];
        if (is_dir($dir)) {
            $dirFiles = @scandir($dir);
            if ($dirFiles !== false) {
                foreach ($dirFiles as $f) {
                    if (strpos($f, $filename) === 0 && preg_match('/\.srt$/i', $f)) {
                        $files[] = $dir . DIRECTORY_SEPARATOR . $f;
                    }
                }
            }
        }
        
        if (!empty($files)) {
            foreach ($files as $file) {
                $langCode = 'Desconocido';
                $name = basename($file);
                $parts = explode('.', $name);
                if (count($parts) >= 3) {
                    $langCode = $parts[count($parts) - 2];
                } else {
                    // Si no tiene código en el nombre, intentar detectar
                    if ($this->isSpanishSubtitleContent($file)) {
                        $langCode = 'es';
                        $pInfo = pathinfo($file);
                        $newName = $pInfo['dirname'] . DIRECTORY_SEPARATOR . $pInfo['filename'] . '.es.' . $pInfo['extension'];
                        if ($this->safeRename($file, $newName)) {
                            $file = $newName; // Actualizar la ruta si el renombrado fue exitoso
                        }
                    } else {
                        $langCode = 'en'; // Asumir inglés por defecto si no es español y no tiene código
                    }
                }
                
                $subtitles[] = [
                    'code2' => strtolower($langCode),
                    'name' => strtoupper($langCode),
                    'path' => $file
                ];
            }
        }
        
        return $subtitles;
    }

    public function downloadSubtitle($type, $action, $path) {
        if (empty($path)) {
            throw new Exception("Ruta de subtítulo no proporcionada.");
        }
        
        if (!file_exists($path)) {
            throw new Exception("El archivo de subtítulo no se encuentra en la ruta: " . htmlspecialchars($path));
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            throw new Exception("No se pudo leer el archivo de subtítulo en la ruta: " . htmlspecialchars($path));
        }
        
        return $content;
    }

    public function refreshItem($id) {
        try {
            $this->request('/Items/' . $id . '/Refresh', 'POST');
            return true;
        } catch (Exception $e) {
            // Ignorar errores en refresh, a veces responden 204 No Content y nuestro cliente espera JSON
            return true;
        }
    }

    public function supportsDirectWrite() {
        return true; // Emby/Jellyfin necesitan que escribamos directamente al disco
    }

    public function getRemotePaths() {
        $paths = [];
        
        try {
            // Forma oficial y confiable de obtener rutas en Emby/Jellyfin
            $resp = $this->request('/Library/VirtualFolders');
            
            if (is_array($resp)) {
                foreach ($resp as $folder) {
                    if (isset($folder['Locations']) && is_array($folder['Locations'])) {
                        foreach ($folder['Locations'] as $loc) {
                            if (!empty($loc)) {
                                $paths[] = $loc;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Ignorar y retornar lo que tengamos
        }

        // Limpiar duplicados y vacíos
        $paths = array_values(array_unique(array_filter($paths)));
        sort($paths);
        return $paths;
    }
}
?>
