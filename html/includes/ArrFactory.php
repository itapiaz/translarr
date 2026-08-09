<?php
// html/includes/ArrFactory.php

require_once __DIR__ . '/ArrClient.php';
require_once __DIR__ . '/SonarrAPI.php';
require_once __DIR__ . '/RadarrAPI.php';

class ArrFactory {
    public static function sonarr($url = null, $apiKey = null): SonarrAPI {
        if ($url === null) {
            $url = defined('SONARR_URL') ? SONARR_URL : '';
        }
        if ($apiKey === null) {
            $apiKey = defined('SONARR_API_KEY') ? SONARR_API_KEY : '';
        }
        return new SonarrAPI($url, $apiKey);
    }

    public static function radarr($url = null, $apiKey = null): RadarrAPI {
        if ($url === null) {
            $url = defined('RADARR_URL') ? RADARR_URL : '';
        }
        if ($apiKey === null) {
            $apiKey = defined('RADARR_API_KEY') ? RADARR_API_KEY : '';
        }
        return new RadarrAPI($url, $apiKey);
    }
}
