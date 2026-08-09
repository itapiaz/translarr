<?php
// html/includes/SubtitleScanner.php
// Detección de subtítulos en el filesystem junto al archivo de vídeo.

class SubtitleScanner {

    private const LANG_ALIASES = [
        'es' => ['es', 'spa', 'esp', 'spanish', 'lat', 'latam', 'mex', 'mx', 'cast', 'castellano', 'latin'],
        'en' => ['en', 'eng', 'english'],
    ];

    /**
     * Detecta el idioma de un archivo de subtítulos por las etiquetas del nombre.
     * Devuelve 'es', 'en' o null si no hay etiqueta reconocible.
     */
    public static function detectLanguage(string $filename): ?string {
        $name = strtolower(basename($filename));
        $name = preg_replace('/\.(srt|vtt|sub|ass|ssa)$/i', '', $name);
        $parts = preg_split('/[.\-_]/', $name);

        foreach ($parts as $p) {
            foreach (self::LANG_ALIASES as $lang => $aliases) {
                if (in_array($p, $aliases, true)) {
                    return $lang;
                }
            }
        }
        return null;
    }

    /**
     * Heurística de contenido: intenta determinar si un .srt está en español.
     */
    public static function isSpanishSubtitleContent(string $file): bool {
        $content = @file_get_contents($file);
        if (!$content) {
            return false;
        }
        $esWords = [
            'el', 'la', 'los', 'las', 'una', 'unos', 'pero', 'porque', 'tambien',
            'está', 'esta', 'como', 'cuando', 'nunca', 'siempre', 'todo', 'ella',
            'ellos', 'este', 'estos', 'aqui', 'aquí', 'ya', 'hay', 'muy', 'más',
        ];
        $hits = 0;
        foreach ($esWords as $w) {
            if (preg_match('/\b' . $w . '\b/iu', $content)) {
                $hits++;
            }
        }
        return $hits >= 3;
    }

    /**
     * Subtítulos sidecar de un archivo de vídeo.
     * @return array<int, array{code2:string, name:string, path:string}>
     */
    public static function findSubtitlesForVideo(string $videoPath): array {
        if ($videoPath === '' || !is_file($videoPath)) {
            return [];
        }
        $dir = dirname($videoPath);
        $videoBase = strtolower(pathinfo($videoPath, PATHINFO_FILENAME));

        $subtitles = [];
        foreach (glob($dir . '/*.srt') ?: [] as $srt) {
            $base = strtolower(pathinfo($srt, PATHINFO_FILENAME));
            if (strpos($base, $videoBase) !== 0) {
                continue; // no es un subtítulo de este vídeo
            }
            $lang = self::detectLanguage($srt);
            if ($lang === null) {
                // Sin etiqueta: clasificar por contenido (convención: asumir 'en' si no es español)
                $lang = self::isSpanishSubtitleContent($srt) ? 'es' : 'en';
            }
            $subtitles[] = [
                'code2' => $lang,
                'name' => strtoupper($lang),
                'path' => $srt,
            ];
        }
        return $subtitles;
    }

    /**
     * Todos los .srt de una carpeta (fallback cuando no hay video_path).
     * @return array<int, array{code2:string, name:string, path:string}>
     */
    public static function findSubtitlesInFolder(string $folder): array {
        if ($folder === '' || !is_dir($folder)) {
            return [];
        }
        $subtitles = [];
        foreach (glob(rtrim($folder, '/') . '/*.srt') ?: [] as $srt) {
            $lang = self::detectLanguage($srt);
            if ($lang === null) {
                $lang = self::isSpanishSubtitleContent($srt) ? 'es' : 'en';
            }
            $subtitles[] = [
                'code2' => $lang,
                'name' => strtoupper($lang),
                'path' => $srt,
            ];
        }
        return $subtitles;
    }

    /**
     * Primer archivo de vídeo de una carpeta (para películas sin movieFile path).
     */
    public static function findVideoInFolder(string $folder): string {
        if ($folder === '' || !is_dir($folder)) {
            return '';
        }
        $exts = ['mkv', 'mp4', 'avi', 'mov', 'm4v', 'webm', 'ts', 'wmv'];
        foreach ($exts as $ext) {
            $files = glob(rtrim($folder, '/') . '/*.' . $ext) ?: [];
            if ($files) {
                return $files[0];
            }
        }
        return '';
    }

    /**
     * Indica si un listado de subtítulos ya incluye español.
     */
    public static function hasSpanish(array $subtitles): bool {
        foreach ($subtitles as $s) {
            $l = strtolower($s['code2'] ?? '');
            if ($l === 'es' || strpos($l, 'spa') !== false || strpos($l, 'spanish') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Devuelve el subtítulo en inglés de un listado, o null.
     */
    public static function englishSubtitle(array $subtitles): ?array {
        foreach ($subtitles as $s) {
            $l = strtolower($s['code2'] ?? '');
            if (in_array($l, ['en', 'eng', 'english'], true)) {
                return $s;
            }
        }
        return null;
    }
}
