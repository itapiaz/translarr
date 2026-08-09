<?php
// html/includes/SonarrAPI.php

require_once __DIR__ . '/ArrClient.php';

/**
 * Cliente para la API de Sonarr v3.
 * Fuente de verdad operativa de series: extrae series, episodios,
 * IDs TVDB, archivos de episodio y rutas locales.
 */
class SonarrAPI extends ArrClient {
    protected function resourceName(): string {
        return 'sonarr';
    }

    /**
     * Lista estandarizada de series:
     * [['id', 'title', 'year', 'tvdbId', 'overview', 'path', 'poster'], ...]
     */
    public function getSeries(): array {
        $resp = $this->request('/series');
        $series = [];
        foreach ($resp as $s) {
            $series[] = [
                'id' => $s['id'] ?? 0,
                'title' => $s['title'] ?? 'Sin título',
                'year' => $s['year'] ?? '',
                'tvdbId' => $s['tvdbId'] ?? '',
                'overview' => $s['overview'] ?? '',
                'path' => $s['path'] ?? '',
                'poster' => $this->extractImage($s['images'] ?? [], 'poster'),
            ];
        }
        return $series;
    }

    /**
     * Lista estandarizada de episodios de una serie:
     * [['id', 'seriesId', 'title', 'season', 'episode', 'episodeFileId', 'tvdbEpisodeId'], ...]
     */
    public function getEpisodes($seriesId): array {
        $resp = $this->request('/episode?seriesId=' . (int)$seriesId);
        $eps = [];
        foreach ($resp as $e) {
            $eps[] = [
                'id' => $e['id'] ?? 0,
                'seriesId' => $e['seriesId'] ?? $seriesId,
                'title' => $e['title'] ?? '',
                'season' => $e['seasonNumber'] ?? 0,
                'episode' => $e['episodeNumber'] ?? 0,
                'episodeFileId' => $e['episodeFileId'] ?? null,
                'hasFile' => !empty($e['hasFile']),
                'tvdbEpisodeId' => $e['tvdbEpisodeId'] ?? '',
            ];
        }
        return $eps;
    }

    /**
     * Archivos de episodio de una serie:
     * [['id', 'seriesId', 'path', 'relativePath', 'episodeIds'], ...]
     */
    public function getEpisodeFiles($seriesId): array {
        $resp = $this->request('/episodefile?seriesId=' . (int)$seriesId);
        $files = [];
        foreach ($resp as $f) {
            $files[] = [
                'id' => $f['id'] ?? 0,
                'seriesId' => $f['seriesId'] ?? $seriesId,
                'path' => $f['path'] ?? '',
                'relativePath' => $f['relativePath'] ?? '',
                'episodeIds' => $f['episodeIds'] ?? [],
            ];
        }
        return $files;
    }
}
