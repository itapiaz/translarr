<?php
// html/includes/RadarrAPI.php

require_once __DIR__ . '/ArrClient.php';

/**
 * Cliente para la API de Radarr v3.
 * Fuente de verdad operativa de películas: extrae películas,
 * IDs TMDB, archivos de película y rutas locales.
 */
class RadarrAPI extends ArrClient {
    protected function resourceName(): string {
        return 'radarr';
    }

    /**
     * Lista estandarizada de películas:
     * [['id', 'tmdbId', 'title', 'year', 'overview', 'path', 'poster', 'hasFile', 'movieFileId', 'movieFile'], ...]
     * 'movieFile' es el objeto embebido que Radarr v3 incluye cuando la película tiene archivo
     * (contiene id, relativePath y path).
     */
    public function getMovies(): array {
        $resp = $this->request('/movie');
        $movies = [];
        foreach ($resp as $m) {
            $movies[] = [
                'id' => $m['id'] ?? 0,
                'tmdbId' => $m['tmdbId'] ?? '',
                'title' => $m['title'] ?? 'Sin título',
                'year' => $m['year'] ?? '',
                'overview' => $m['overview'] ?? '',
                'path' => $m['path'] ?? '',
                'poster' => $this->extractImage($m['images'] ?? [], 'poster'),
                'hasFile' => !empty($m['hasFile']),
                'movieFileId' => $m['movieFileId'] ?? null,
                'movieFile' => $m['movieFile'] ?? null,
            ];
        }
        return $movies;
    }

    /**
     * Archivos de película filtrados por movieId (Radarr exige el filtro).
     * [['id', 'movieId', 'path', 'relativePath'], ...]
     */
    public function getMovieFiles($movieId): array {
        $resp = $this->request('/moviefile?movieId=' . (int)$movieId);
        $files = [];
        foreach ($resp as $f) {
            $files[] = [
                'id' => $f['id'] ?? 0,
                'movieId' => $f['movieId'] ?? $movieId,
                'path' => $f['path'] ?? '',
                'relativePath' => $f['relativePath'] ?? '',
            ];
        }
        return $files;
    }
}
