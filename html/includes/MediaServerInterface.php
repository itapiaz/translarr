<?php
// html/includes/MediaServerInterface.php

interface MediaServerInterface {
    /**
     * Devuelve un array de películas estandarizado:
     * [ ['id' => '...', 'title' => '...', 'year' => '...', 'poster' => '...', 'has_spanish' => true/false], ... ]
     */
    public function getMovies();

    /**
     * Devuelve un array de series estandarizado:
     * [ ['id' => '...', 'title' => '...', 'year' => '...', 'poster' => '...'], ... ]
     */
    public function getSeries();

    /**
     * Devuelve un array de episodios para una serie estandarizado:
     * [ ['id' => '...', 'title' => '...', 'season' => '...', 'episode' => '...', 'has_spanish' => true/false], ... ]
     */
    public function getEpisodes($seriesId);

    /**
     * Devuelve los subtítulos para un item
     * [ ['code2' => 'en', 'name' => 'English', 'path' => '/ruta/archivo.srt'], ... ]
     */
    public function getSubtitles($type, $id);

    /**
     * Obtiene el contenido del subtítulo.
     */
    public function downloadSubtitle($type, $action, $path);

    /**
     * Refresca los metadatos del item en el servidor
     */
    public function refreshItem($id);

    /**
     * Devuelve una lista de rutas base remotas usadas por el servidor (ej. ["/movies", "/tv"])
     */
    public function getRemotePaths();

    /**
     * Indica si el servidor requiere escritura directa a disco (Emby/Jellyfin)
     * o si usa una API de subida propia (Bazarr).
     */
    public function supportsDirectWrite();
}
?>
